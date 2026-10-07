<?php
declare(strict_types=1);

namespace Shugoi\WebSocket;

use Shugoi\Config;
use Shugoi\ApiClient;
use Shugoi\RenderService;

/** Optional same-origin render socket. It does not replace PHP-FPM or ApiClient HTTP calls. */
final class OpenSwooleRenderServer
{
    public function __construct(
        private readonly Config $config,
        private readonly RenderService $renderService,
        private readonly ApiClient $apiClient,
    ) {}

    public function start(): never
    {
        if (!extension_loaded('openswoole') || !class_exists('OpenSwoole\\WebSocket\\Server')) {
            throw new \RuntimeException('The optional OpenSwoole extension is required for Shugoi WebSocket transport');
        }
        if ($this->config->browserTransport !== 'websocket' || $this->config->renderStore !== 'disk') {
            throw new \RuntimeException('WebSocket sidecar requires browserTransport=websocket and renderStore=disk');
        }
        if (!$this->renderService->isCrossProcessSafe()) {
            throw new \RuntimeException('WebSocket sidecar requires a cross-process render store');
        }
        $origin = $this->normalizedOrigin($this->config->publicOrigin ?? '');
        if ($origin === null) throw new \RuntimeException('SHUGOI_PUBLIC_ORIGIN must be an absolute HTTP(S) origin');

        $server = new \OpenSwoole\WebSocket\Server($this->config->websocketBind, $this->config->websocketPort);
        $server->set([
            'worker_num' => 2,
            'max_request' => 500,
            'package_max_length' => 32768,
            'open_websocket_protocol' => true,
            'heartbeat_idle_time' => 75,
            'heartbeat_check_interval' => 15,
        ]);

        // Implement the standard RFC 6455 handshake so Origin and endpoint are
        // rejected before a WebSocket is accepted.
        $connections = [];
        $server->on('Handshake', function ($request, $response) use ($origin, &$connections): bool {
            $path = parse_url((string)($request->server['request_uri'] ?? '/'), PHP_URL_PATH) ?: '/';
            $headers = array_change_key_case($request->header ?? [], CASE_LOWER);
            $clientOrigin = $this->normalizedOrigin((string)($headers['origin'] ?? ''));
            $key = (string)($headers['sec-websocket-key'] ?? '');
            $validKey = base64_decode($key, true);
            $isUpgrade = strtolower((string)($headers['upgrade'] ?? '')) === 'websocket'
                && stripos((string)($headers['connection'] ?? ''), 'upgrade') !== false
                && (string)($headers['sec-websocket-version'] ?? '') === '13'
                && $validKey !== false && strlen($validKey) === 16;
            if (!in_array($path, ['/__shugoi/render/ws', '/api/v1/ws-wlc'], true) || $clientOrigin !== $origin || !$isUpgrade) {
                $response->status(403);
                $response->end('Forbidden');
                return false;
            }
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $response->status(101);
            $response->header('Upgrade', 'websocket');
            $response->header('Connection', 'Upgrade');
            $response->header('Sec-WebSocket-Accept', $accept);
            $response->end();
            $fd = (int)($request->fd ?? 0);
            $connections[$fd] = [
                'path' => $path,
                'headers' => $this->forwardHeaders($headers),
                'clockPings' => 0,
                'drift' => null,
                'mode' => null,
                'timer' => null,
            ];
            return true;
        });

        $renderService = $this->renderService;
        $apiClient = $this->apiClient;
        $siteKey = $this->config->siteKey;
        $server->on('Message', static function ($server, $frame) use ($renderService, $apiClient, $siteKey, &$connections): void {
            if ((int)($frame->opcode ?? 0) !== 1 || strlen((string)($frame->data ?? '')) > 24_576) {
                $server->disconnect((int)$frame->fd, 1003, 'invalid-frame');
                return;
            }
            $message = json_decode((string)$frame->data, true);
            if (!is_array($message) || array_is_list($message)) {
                $server->disconnect((int)$frame->fd, 1008, 'invalid-render-request');
                return;
            }
            $fd = (int)$frame->fd;
            $context = $connections[$fd] ?? null;
            if (!is_array($context)) {
                $server->disconnect($fd, 1008, 'missing-connection-context');
                return;
            }

            if (($context['path'] ?? '') === '/api/v1/ws-wlc') {
                if (isset($message['cmd'])) {
                    if (($message['cmd'] ?? '') === 'clock-ping' && count($message) === 2 && isset($message['tSend']) && is_numeric($message['tSend']) && ++$connections[$fd]['clockPings'] <= 8) {
                        $received = microtime(true) * 1000;
                        $sent = microtime(true) * 1000;
                        $connections[$fd]['mode'] = 'clock';
                        $connections[$fd]['drift'] = (($received + $sent) / 2) - (float)$message['tSend'];
                        $server->push($fd, json_encode(['cmd' => 'clock-pong', 'tSend' => (float)$message['tSend'], 'tRecv' => $received, 'tOut' => $sent]));
                        return;
                    }
                    if (($message['cmd'] ?? '') === 'clock-stream' && count($message) === 2 && isset($message['ms']) && is_numeric($message['ms']) && ($connections[$fd]['mode'] ?? null) === 'clock') {
                        $interval = max(50, min(1000, (int)($message['ms'] ?? 250)));
                        if (!empty($connections[$fd]['timer'])) $server->clearTimer($connections[$fd]['timer']);
                        $connections[$fd]['timer'] = $server->tick($interval, static function () use ($server, $fd, $interval): void {
                            if ($server->isEstablished($fd)) $server->push($fd, json_encode(['cmd' => 'clock-tick', 't' => microtime(true) * 1000, 'ms' => $interval]));
                        });
                        return;
                    }
                    if (($message['cmd'] ?? '') === 'clock-stop' && count($message) === 1 && ($connections[$fd]['mode'] ?? null) === 'clock') {
                        if (!empty($connections[$fd]['timer'])) $server->clearTimer($connections[$fd]['timer']);
                        $connections[$fd]['timer'] = null;
                        return;
                    }
                    if (($message['cmd'] ?? '') === 'event' && count($message) === 4
                        && is_string($message['reason'] ?? null) && strlen($message['reason']) <= 128
                        && is_string($message['siteKey'] ?? null) && hash_equals($siteKey, $message['siteKey'])
                        && is_string($message['machineId'] ?? null) && strlen($message['machineId']) <= 256) {
                        $apiClient->sendEvent($message['reason'], ['machineId' => $message['machineId']]);
                        $server->disconnect($fd, 1000, 'event-delivered');
                        return;
                    }
                    $server->disconnect($fd, 1008, 'invalid-wlc-message');
                    return;
                }
                if (count($message) !== 1 || !is_string($message['q'] ?? null) || ($connections[$fd]['mode'] ?? null) === 'clock') {
                    $server->disconnect($fd, 1008, 'invalid-wlc-request');
                    return;
                }
                $query = $message['q'];
                if (strlen($query) < 2 || strlen($query) > 24_576 || $query[0] !== '?') {
                    $server->disconnect($fd, 1008, 'invalid-wlc-query');
                    return;
                }
                if ($connections[$fd]['drift'] !== null) {
                    parse_str(substr($query, 1), $queryParams);
                    if (!isset($queryParams['drift']) || abs((float)$queryParams['drift'] - $connections[$fd]['drift']) > 1500) {
                        $server->disconnect($fd, 1008, 'clock-drift-mismatch');
                        return;
                    }
                }
                $connections[$fd]['mode'] = 'wlc';
                try {
                    $body = $apiClient->relayWlc($query, $connections[$fd]['headers']);
                    if ($server->isEstablished($fd)) {
                        $server->push($fd, $body);
                        $server->after(50, static function () use ($server, $fd): void {
                            if ($server->isEstablished($fd)) $server->disconnect($fd, 1000, 'wlc-delivered');
                        });
                    }
                } catch (\Throwable) {
                    if ($server->isEstablished($fd)) $server->disconnect($fd, 1011, 'wlc-unavailable');
                }
                return;
            }

            if (count($message) !== 3
                || array_diff(array_keys($message), ['token', 'mid', 'grant']) !== []
                || !is_string($message['token'] ?? null)
                || !is_string($message['mid'] ?? null)
                || !is_string($message['grant'] ?? null)) {
                $server->disconnect($fd, 1008, 'invalid-render-request');
                return;
            }
            $client = $server->getClientInfo($fd) ?: [];
            $result = $renderService->render(
                $message['token'],
                $message['mid'],
                $message['grant'],
                (string)($client['remote_ip'] ?? '')
            );
            if (!isset($result['html']) || !is_string($result['html']) || strlen($result['html']) > 1_048_576) {
                $server->disconnect($fd, 1008, 'render-rejected');
                return;
            }
            $server->push($fd, $result['html'], 1, true);
            $server->after(50, static function () use ($server, $fd): void {
                if ($server->isEstablished($fd)) $server->disconnect($fd, 1000, 'render-delivered');
            });
        });

        $server->on('Close', static function ($server, int $fd) use (&$connections): void {
            if (!empty($connections[$fd]['timer'])) $server->clearTimer($connections[$fd]['timer']);
            unset($connections[$fd]);
        });

        $server->on('Request', static function ($request, $response): void {
            $path = parse_url((string)($request->server['request_uri'] ?? '/'), PHP_URL_PATH) ?: '/';
            if ($path === '/healthz') {
                $response->header('Content-Type', 'text/plain; charset=utf-8');
                $response->end('ok');
                return;
            }
            $response->status(404);
            $response->end('Not Found');
        });

        fwrite(STDOUT, sprintf("Shugoi WebSocket sidecar listening on %s:%d\n", $this->config->websocketBind, $this->config->websocketPort));
        $server->start();
        throw new \RuntimeException('OpenSwoole server stopped unexpectedly');
    }

    private function forwardHeaders(array $headers): array
    {
        $out = ['accept' => 'application/json', 'x-shugoi-ws-relay' => '1'];
        foreach (['origin', 'user-agent', 'sec-ch-ua', 'sec-ch-ua-platform', 'sec-ch-ua-mobile', 'x-real-ip', 'x-forwarded-for', 'x-forwarded-proto', 'cookie'] as $name) {
            $value = $headers[$name] ?? null;
            if (is_string($value) && strlen($value) <= 4096 && !preg_match('/[\r\n]/', $value)) $out[$name] = $value;
        }
        return $out;
    }

    private function normalizedOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) && $parts['path'] !== ''
            || isset($parts['query']) || isset($parts['fragment'])) return null;
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65535))) return null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) $port = null;
        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }
}
