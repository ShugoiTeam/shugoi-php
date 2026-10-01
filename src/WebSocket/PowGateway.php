<?php
declare(strict_types=1);

namespace Shugoi\WebSocket;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\TimeoutCancellation;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LoggerInterface;
use Shugoi\Config;

final class PowGateway implements RequestHandler, WebsocketClientHandler
{
    private readonly Websocket $websocket;
    /** @var array<string,array{until:int,count:int}> */
    private array $attempts = [];
    private int $lastCleanup = 0;

    public function __construct(
        HttpServer $server,
        LoggerInterface $logger,
        private readonly Config $config,
        private readonly string $origin,
        private readonly bool $trustLoopbackProxy = false,
    ) {
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
            throw new \InvalidArgumentException('SHUGOI_ORIGIN must be an HTTP(S) origin without a path');
        }
        if ($config->siteKey === '' || $config->getSigningSecret() === '') {
            throw new \InvalidArgumentException('A site key and signing secret are required');
        }
        $this->websocket = new Websocket(
            $server,
            $logger,
            new AllowOriginAcceptor([$origin]),
            $this,
            clientFactory: new Rfc6455ClientFactory(
                rateLimit: new ConstantRateLimit(8192, 10),
                parserFactory: new Rfc6455ParserFactory(textOnly: true, messageSizeLimit: 4096, frameSizeLimit: 4096),
                closePeriod: 1,
            ),
        );
    }

    public function handleRequest(Request $request): Response
    {
        if ($request->getUri()->getPath() !== '/__sg_challenge/ws') return $this->error(404, 'not_found');
        if ($request->getMethod() !== 'GET') return $this->error(405, 'method_not_allowed');
        if ($request->getHeader('origin') !== $this->origin) return $this->error(403, 'invalid_origin');
        if (strlen($request->getHeader('user-agent') ?? '') > 1024) return $this->error(400, 'invalid_user_agent');
        $ip = $this->clientIp($request);
        if ($ip === null) return $this->error(400, 'invalid_client_address');
        if (!$this->allowAttempt($ip)) return $this->error(429, 'rate_limited');
        return $this->websocket->handleRequest($request);
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $ip = $this->clientIp($request);
        if ($ip === null) {
            $client->close(1008, 'Invalid client address');
            return;
        }
        $session = new PowSession($this->config, $ip, $request->getHeader('user-agent') ?? '');
        $timeout = new TimeoutCancellation(min(60, max(1, $this->config->powTtlMs / 1000)));
        try {
            for ($count = 0; $count < 2; $count++) {
                $message = $client->receive($timeout);
                if ($message === null) return;
                if (!$message->isText()) {
                    $client->close(1003, 'Text messages required');
                    return;
                }
                $payload = json_decode($message->buffer($timeout, 4096), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || array_is_list($payload)) {
                    $client->close(1008, 'Invalid message');
                    return;
                }
                $result = $session->receive($payload);
                $client->sendText(json_encode($result, JSON_THROW_ON_ERROR));
                if ($result['type'] !== 'pow-challenge') {
                    $client->close($result['type'] === 'pow-ok' ? 1000 : 1008);
                    return;
                }
            }
        } catch (\Throwable) {
            // Never write proof material, secrets or untrusted payloads to logs.
            if (!$client->isClosed()) $client->close(1008, 'Challenge unavailable or expired');
        } finally {
            if (!$client->isClosed()) $client->close(1000);
        }
    }

    public function clientIp(Request $request): ?string
    {
        $remote = $request->getClient()->getRemoteAddress();
        if (!$remote instanceof InternetAddress) return null;
        $ip = $remote->getAddress();
        if ($this->trustLoopbackProxy && in_array($ip, ['127.0.0.1', '::1'], true)) {
            $ip = $request->getHeader('x-real-ip') ?? '';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function allowAttempt(string $ip): bool
    {
        $now = time();
        if ($now - $this->lastCleanup >= 10) {
            foreach ($this->attempts as $key => $attempt) {
                if ($attempt['until'] <= $now) unset($this->attempts[$key]);
            }
            $this->lastCleanup = $now;
        }
        if (!isset($this->attempts[$ip]) || $this->attempts[$ip]['until'] <= $now) {
            if (count($this->attempts) >= 4096) return false;
            $this->attempts[$ip] = ['until' => $now + 60, 'count' => 0];
        }
        return ++$this->attempts[$ip]['count'] <= 30;
    }

    private function error(int $status, string $error): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], json_encode(['error' => $error]));
    }
}
