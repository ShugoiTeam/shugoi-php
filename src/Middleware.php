<?php
declare(strict_types=1);

namespace Shugoi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;

class Middleware implements PsrMiddlewareInterface
{
    public ?string $publicRenderUrl = null;

    public function __construct(
        private readonly Config $config,
        private readonly Core $core,
        private readonly ApiClient $api,
        private readonly ConfigCache $configCache,
        private readonly GuardCache $guardCache,
        private readonly HtmlStore $htmlStore,
        private readonly CspBuilder $cspBuilder,
        private readonly GuardInjector $injector,
        private readonly TokenSigner $tokenSigner,
        private readonly ?Pow $pow = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // Render endpoint — GET/HEAD uniquement (round 13, parité module Node).
        if (str_ends_with($path, '/__shugoi/render')) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                return $this->methodNotAllowed();
            }
            return $this->handleRender($request);
        }

        // Challenge page — GET/HEAD uniquement.
        if ($path === '/__sg_challenge' && !in_array($method, ['GET', 'HEAD'], true)) {
            return $this->methodNotAllowed();
        }

        // SkipPaths (SSR direct) : on laisse l'app servir la page sans challenge ni skeleton.
        // Parité middleware.ts : le check est AVANT core.evaluate (un skipPath hors allowlist
        // ne doit PAS passer par le challenge PoW ni la protection des assets).
        if ($this->config->autoInject && $this->config->siteKey !== '') {
            try {
                $cfg = $this->configCache->get($this->config->internalUrl);
                $skip = $cfg['skipPaths'] ?? [];
                if (in_array($path, $skip, true)) {
                    return $handler->handle($request);
                }
            } catch (\Throwable) {
            }
        }

        $csp = $this->cspBuilder->build();

        $ua = $request->getHeaderLine('User-Agent');
        $ip = $this->clientIp($request);
        $acceptLanguage = $request->getHeaderLine('Accept-Language') ?: null;
        $secFetchDest = $request->getHeaderLine('Sec-Fetch-Dest') ?: null;
        $secFetchMode = $request->getHeaderLine('Sec-Fetch-Mode') ?: null;
        $host = $request->getHeaderLine('Host') ?: null;
        $query = $request->getQueryParams();

        $block = $this->core->evaluate([
            'path' => $path,
            'ua' => $ua,
            'ip' => $ip,
            'host' => $host,
            'acceptLanguage' => $acceptLanguage,
            'secFetchDest' => $secFetchDest,
            'secFetchMode' => $secFetchMode,
            'sgProof' => (isset($query['sg_proof']) && is_string($query['sg_proof'])) ? $query['sg_proof'] : null,
            'sgOk' => $this->cookieValue($request, '__sg_ok'),
            'sgAuthorized' => $this->cookieValue($request, '__sg_authorized'),
            'forwardedPrefix' => $request->getHeaderLine('X-Forwarded-Prefix') ?: null,
        ]);

        if ($block !== null) {
            $headers = ['Content-Type' => $block['contentType']];
            if ($this->config->csp && $csp !== '') {
                $headers['Content-Security-Policy'] = $csp;
            }
            if (!empty($block['headers'])) {
                $headers = array_merge($headers, $block['headers']);
            }
            return new Response($block['status'], $headers, $block['body']);
        }

        $response = $handler->handle($request);

        // CSP fusionnée avec celle éventuellement posée par l'app (parité middleware.ts).
        if ($this->config->csp) {
            $existing = $response->getHeaderLine('Content-Security-Policy');
            $response = $response->withHeader('Content-Security-Policy', $existing === '' ? $csp : CspBuilder::merge($existing, $csp));
        }

        // PoW validé → pose le cookie __sg_ok (navigations suivantes sans challenge).
        if (isset($query['sg_proof']) && is_string($query['sg_proof'])) {
            $okCookie = $this->pow()->sgOkCookie($query['sg_proof'], $ip, $ua);
            if ($okCookie !== null) {
                $response = $response->withAddedHeader('Set-Cookie', $okCookie);
            }
        }

        $body = (string)$response->getBody();
        if (
            $this->config->autoInject && $this->config->splitRender
            && !$this->core->isTrustedBot($ua, $ip) && !$this->core->isAllowlisted($path)
        ) {
            $contentType = $response->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'text/html') || $contentType === '') {
                if (!empty($body) && str_contains($body, '<html')) {
                    try {
                        $renderUrl = $this->publicRenderUrl ?: ($host ?: 'localhost') . '/__shugoi/render';
                        $body = $this->injector->inject($body, $path, $ua, $ip, $host ?? '', $acceptLanguage, $renderUrl);
                    } catch (\Throwable $e) {
                        if ($this->config->debug) {
                            error_log("Shugoi inject failed: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        $response->getBody()->rewind();
        $response->getBody()->write($body);

        return $response;
    }

    private function handleRender(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $token = (string)($params['token'] ?? '');
        $mid = (string)($params['mid'] ?? '');
        $grant = (string)($params['grant'] ?? '');
        $ip = $this->clientIp($request);

        $data = $this->renderData($token, $mid, $grant, $ip);

        $headers = ['Content-Type' => 'application/json'];
        if (isset($data['html'])) {
            // Anti-fuite du grant : strict-origin-when-cross-origin (jamais le grant dans
            // le Referer cross-origin). Contenu protégé : jamais mis en cache (round 6).
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, no-transform';
            $headers['Pragma'] = 'no-cache';
            // Cookie __sg_authorized : autorise ensuite le chargement des assets protégés.
            $headers['Set-Cookie'] = $this->pow()->sgAuthorizedCookie();
        }
        return new Response(200, $headers, json_encode($data));
    }

    /** Parité renderResponseData + handleRender (render.ts) — grant + token vérifiés. */
    private function renderData(string $token, string $mid, string $grant, string $ip): array
    {
        $len = strlen($token);
        if ($len < 16 || $len > 300) return ['error' => 'not_found'];

        // CRITIQUE 1 (§7bis) : le render d'un site ne sert que les tokens de SON siteKey.
        $tokSiteKey = explode(':', $token)[0];
        if ($tokSiteKey !== $this->config->siteKey) return ['error' => 'not_found'];

        // Expiration explicite du token (parité render.ts).
        $tokTs = (int)(explode(':', $token)[1] ?? '0');
        if ($tokTs > 0 && $this->nowMs() - $tokTs > TokenSigner::TOKEN_TTL_MS) return ['error' => 'not_found'];

        // Anti-bypass "token-only" : sans grant valide (lié au siteKey), pas de HTML.
        if (!$this->tokenSigner->verifyRenderGrant($mid, $grant, $token, $ip, $this->config->siteKey)) {
            return ['error' => 'not_found'];
        }

        $contentReplaceOn = $this->contentReplaceOn();
        $entry = $this->htmlStore->retrieve($token);
        if ($entry !== null && !empty($entry['html'])) {
            return ['html' => $this->postProcessHtml($entry['html'], $mid)];
        }

        // Fallback content-replace OFF : on renvoie le HTML du site déjà stocké.
        if (!$contentReplaceOn) {
            $fresh = $this->htmlStore->hasFreshToken($this->config->siteKey, true);
            if ($fresh !== null && !empty($fresh['html'])) {
                return ['html' => $this->postProcessHtml($fresh['html'], $mid)];
            }
            $diskPath = '/tmp/shugoi-render-shared';
            if (is_dir($diskPath)) {
                $files = glob($diskPath . '/*');
                if (!empty($files)) {
                    $html = file_get_contents($files[0]);
                    if ($html !== false && $html !== '') {
                        return ['html' => $this->postProcessHtml($html, $mid)];
                    }
                }
            }
        }

        return ['error' => 'not_found'];
    }

    private function postProcessHtml(string $html, string $mid): string
    {
        if ($mid !== '') {
            $html = Notice::inject($html, $mid, $this->config->siteKey, $this->config->baseUrl);
        }
        $html = Notice::injectReferrerPolicy($html);
        return $html;
    }

    private function contentReplaceOn(): bool
    {
        try {
            $cfg = $this->configCache->get($this->config->internalUrl);
            $ecr = $cfg['enableContentReplacementCheck'] ?? $cfg['detectionFlags']['enableContentReplacementCheck'] ?? null;
            return $ecr === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function pow(): Pow
    {
        return $this->pow ?? new Pow($this->config);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function cookieValue(ServerRequestInterface $request, string $name): ?string
    {
        $cookie = $request->getHeaderLine('Cookie');
        if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=([^;]+)/', $cookie, $m)) {
            return $m[1];
        }
        return null;
    }

    private function methodNotAllowed(): ResponseInterface
    {
        return new Response(405, ['Content-Type' => 'application/json'], json_encode(['error' => 'method_not_allowed']));
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
