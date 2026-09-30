<?php
declare(strict_types=1);

namespace Shugoi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;

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
        $skip = [];
        if ($this->config->autoInject && $this->config->siteKey !== '') {
            try {
                $cfg = $this->configCache->get($this->config->internalUrl);
                $skip = $cfg['skipPaths'] ?? [];
            } catch (\Throwable) {
            }
        }
        if (is_array($skip) && in_array($path, $skip, true)) {
            $response = $handler->handle($request)->withoutHeader('X-Shugoi-Unbuffered');
            if ($method === 'HEAD') {
                return $response->withBody(Stream::create(''));
            }
            $contentType = $response->getHeaderLine('Content-Type');
            $body = (string)$response->getBody();
            if (($contentType === '' || str_contains($contentType, 'text/html')) && preg_match('/<html\b/i', $body)) {
                $response = $this->replaceBody($response, $this->injector->injectSkipNavigationGuard($body, $skip));
            }
            return $response;
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
            'sgReceipt' => $method !== 'HEAD' && isset($query['sg_receipt']) && is_string($query['sg_receipt']) ? $query['sg_receipt'] : null,
            'method' => $method,
            'secure' => $request->getUri()->getScheme() === 'https',
            'requestTarget' => $this->cleanRequestTarget($request),
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
            return new Response($block['status'], $headers, $method === 'HEAD' ? '' : $block['body']);
        }

        $response = $handler->handle($request);
        $unbuffered = $response->getHeaderLine('X-Shugoi-Unbuffered') === '1';
        $response = $response->withoutHeader('X-Shugoi-Unbuffered');
        if ($method === 'HEAD') {
            return $response->withBody(Stream::create(''));
        }
        if ($this->isMetadataCrawlerDocument($path, $ua)) {
            $originalBody = (string)$response->getBody();
            $metadataBody = MetadataOnly::extract($originalBody, (string)$request->getUri());
            if ($metadataBody === '') {
                $metadataBody = MetadataOnly::fallback((string)$request->getUri());
            }
            $response = $this->replaceBody($response, $metadataBody)
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Cache-Control', 'public, max-age=300');
            return $response;
        }
        if ($this->config->csp) {
            $existing = $response->getHeaderLine('Content-Security-Policy');
            $response = $response->withHeader('Content-Security-Policy', $existing === '' ? $csp : CspBuilder::merge($existing, $csp));
        }
        $body = (string)$response->getBody();
        if (
            $this->config->autoInject && $this->config->splitRender
            && !$this->core->isTrustedBot($ua, $ip) && !$this->core->isAllowlisted($path)
        ) {
            $contentType = $response->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'text/html') || $contentType === '') {
                if ($unbuffered) {
                    return new Response(503, [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Cache-Control' => 'no-store',
                    ], 'Shugoi split rendering requires a buffered HTML response.');
                }
                if ($body !== '' && preg_match('/<html\b/i', $body)) {
                    try {
                        $basePath = $request->getAttribute('shugoi.basePath', '');
                        $basePath = is_string($basePath) ? '/' . trim($basePath, '/') : '';
                        $renderUrl = $this->publicRenderUrl ?: rtrim($basePath, '/') . '/__shugoi/render';
                        $body = $this->injector->inject($body, $path, $ua, $ip, $host ?? '', $acceptLanguage, $renderUrl);
                        $response = $this->replaceBody($response, $body)
                            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, no-transform')
                            ->withHeader('Pragma', 'no-cache');
                    } catch (\Throwable $e) {
                        if ($this->config->debug) {
                            error_log("Shugoi inject failed: " . $e->getMessage());
                        }
                        return new Response(503, [
                            'Content-Type' => 'text/plain; charset=UTF-8',
                            'Cache-Control' => 'no-store',
                            'Retry-After' => '30',
                        ], 'Shugoi protection is temporarily unavailable. Please try again.');
                    }
                }
            }
        }

        return $response;
    }

    private function handleRender(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, no-transform',
            'Pragma' => 'no-cache',
        ];
        if (strtoupper($request->getMethod()) === 'HEAD') {
            return new Response(200, $headers);
        }
        $token = is_string($params['token'] ?? null) ? $params['token'] : '';
        $mid = is_string($params['mid'] ?? null) ? $params['mid'] : '';
        $grant = is_string($params['grant'] ?? null) ? $params['grant'] : '';
        $ip = $this->clientIp($request);

        $data = ($this->renderService ?? new RenderService($this->config, $this->htmlStore, $this->tokenSigner, $this->configCache))->render($token, $mid, $grant, $ip);

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
        $resolved = $request->getAttribute('shugoi.clientIp');
        if (is_string($resolved) && filter_var($resolved, FILTER_VALIDATE_IP)) return $resolved;
        return (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function cleanRequestTarget(ServerRequestInterface $request): string
    {
        $parts = array_filter(explode('&', $request->getUri()->getQuery()), static function (string $part): bool {
            $name = urldecode(explode('=', $part, 2)[0]);
            return $name !== 'sg_receipt' && $name !== 'sg_proof';
        });
        $query = implode('&', $parts);
        return ($request->getUri()->getPath() ?: '/') . ($query === '' ? '' : '?' . $query);
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
        return new Response(405, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Allow' => 'GET, HEAD'], json_encode(['error' => 'method_not_allowed']));
    }

    private function replaceBody(ResponseInterface $response, string $body): ResponseInterface
    {
        return $response->withBody(Stream::create($body))
            ->withoutHeader('Content-Length')
            ->withoutHeader('ETag')
            ->withoutHeader('Last-Modified');
    }

    private function isMetadataCrawlerDocument(string $path, string $ua): bool
    {
        if ($path === '' || str_starts_with($path, '/api/') || str_starts_with($path, '/__shugoi/') || str_starts_with($path, '/__sg_')) return false;
        if (preg_match('/\.(?:css|js|mjs|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|txt|xml)$/i', $path)) return false;
        return preg_match('/(?:Googlebot|Google-InspectionTool|bingbot|Discordbot|Twitterbot|facebookexternalhit)\b/i', $ua) === 1;
    }
}
