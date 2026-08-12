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
        if (str_starts_with($request->path(), '__shugoi/')) {
            return $next($request);
        }
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

        $handler = new class($next) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private $next) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $method = $request->getMethod();
                $uri = (string)$request->getUri();
                $headers = $request->getHeaders();
                $body = (string)$request->getBody();
                $laravelRequest = Request::create($uri, $method, [], [], [], [], $body);
                foreach ($headers as $name => $values) {
                    $laravelRequest->headers->set($name, $values);
                }
                $response = ($this->next)($laravelRequest);
                $psrFactory = new Psr17Factory();
                $psrResponse = new \Nyholm\Psr7\Response(
                    $response->getStatusCode(),
                    $response->headers->all(),
                    $psrFactory->createStream($response->getContent())
                );
                return $psrResponse;
            }
        };

        $psrResponse = $this->middleware->process($psrRequest, $handler);
        $laravelResponse = response(
            (string)$psrResponse->getBody(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
        return $laravelResponse;
    }
}
