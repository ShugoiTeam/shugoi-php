<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Http\Request;
use Closure;
use Shugoi\Middleware as PsrMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

class ShugoiMiddleware
{
    public function __construct(private PsrMiddleware $middleware) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $psrFactory = new Psr17Factory();
        $uri = $psrFactory->createUri($request->fullUrl());
        $headers = $request->headers->all();
        $body = $psrFactory->createStream($request->getContent());
        $serverParams = $request->server->all();
        $psrRequest = new ServerRequest(
            $request->method(),
            $uri,
            $headers,
            $body,
            '1.1',
            $serverParams
        );
        $psrRequest = $psrRequest
            ->withQueryParams($request->query->all())
            ->withCookieParams($request->cookies->all())
            ->withParsedBody($request->request->all())
            ->withAttribute('shugoi.basePath', $request->getBaseUrl())
            ->withAttribute('shugoi.clientIp', $request->getClientIp());

        $handler = new class($next, $request) implements \Psr\Http\Server\RequestHandlerInterface {
            public ?\Symfony\Component\HttpFoundation\Response $response = null;

            public function __construct(private $next, private Request $laravelRequest) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                // Keep route bindings, session, authentication and uploaded files on
                // the actual Laravel request instead of constructing a new one.
                $response = $this->response = ($this->next)($this->laravelRequest);
                $psrFactory = new Psr17Factory();
                $content = $response->getContent();
                $psrResponse = new \Nyholm\Psr7\Response(
                    $response->getStatusCode(),
                    $response->headers->all(),
                    $psrFactory->createStream($content === false ? '' : $content)
                );
                if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                    $psrResponse = $psrResponse->withHeader('X-Shugoi-Unbuffered', '1');
                }
                return $psrResponse;
            }
        };

        $psrResponse = $this->middleware->process($psrRequest, $handler);
        if ($handler->response !== null && !($handler->response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) && !($handler->response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse)) {
            $response = $handler->response;
            $response->setContent((string)$psrResponse->getBody());
            $response->setStatusCode($psrResponse->getStatusCode());
            $response->headers->replace($psrResponse->getHeaders());
            return $response;
        }
        if ($handler->response !== null && !$request->isMethod('HEAD') && (string)$psrResponse->getBody() === '' && $psrResponse->getStatusCode() === $handler->response->getStatusCode()) {
            $handler->response->headers->replace($psrResponse->getHeaders());
            return $handler->response;
        }
        $laravelResponse = response(
            (string)$psrResponse->getBody(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
        return $laravelResponse;
    }
}
