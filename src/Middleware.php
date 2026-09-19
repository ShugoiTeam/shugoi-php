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
        private readonly ?RenderService $renderService = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        if (str_ends_with($path, '/__shugoi/render')) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                return $this->methodNotAllowed();
            }
            return $this->handleRender($request);
        }
        if ($path === '/__sg_challenge' && !in_array($method, ['GET', 'HEAD'], true)) {
            return $this->methodNotAllowed();
        }
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
        if ($this->isMetadataCrawlerDocument($path, $ua)) {
            $originalBody = (string)$response->getBody();
            $metadataBody = MetadataOnly::extract($originalBody, (string)$request->getUri());
            if ($metadataBody === '') {
                $metadataBody = MetadataOnly::fallback((string)$request->getUri());
            }
            $stream = $response->getBody();
            $stream->rewind();
            $stream->write($metadataBody);
            $stream->truncate($stream->tell());
            $response = $response
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Cache-Control', 'public, max-age=300');
            if ($method === 'HEAD') {
                $stream->rewind();
                $stream->truncate(0);
            }
            return $response;
        }
        if ($this->config->csp) {
            $existing = $response->getHeaderLine('Content-Security-Policy');
            $response = $response->withHeader('Content-Security-Policy', $existing === '' ? $csp : CspBuilder::merge($existing, $csp));
        }
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

        $data = ($this->renderService ?? new RenderService($this->config, $this->htmlStore, $this->tokenSigner, $this->configCache))->render($token, $mid, $grant, $ip);

        $headers = ['Content-Type' => 'application/json'];
        if (isset($data['html'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, no-transform';
            $headers['Pragma'] = 'no-cache';
            $headers['Set-Cookie'] = $this->pow()->sgAuthorizedCookie();
        }
        return new Response(200, $headers, json_encode($data));
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

    private function isMetadataCrawlerDocument(string $path, string $ua): bool
    {
        if ($path === '' || str_starts_with($path, '/api/') || str_starts_with($path, '/__shugoi/') || str_starts_with($path, '/__sg_')) return false;
        if (preg_match('/\.(?:css|js|mjs|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|txt|xml)$/i', $path)) return false;
        return preg_match('/(?:Googlebot|Google-InspectionTool|bingbot|Discordbot|Twitterbot|facebookexternalhit)\b/i', $ua) === 1;
    }
}
