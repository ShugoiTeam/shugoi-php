#!/usr/bin/env php
<?php
declare(strict_types=1);

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Psr\Log\AbstractLogger;
use Shugoi\Config;
use Shugoi\WebSocket\PowGateway;
use function Amp\trapSignal;

require $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

try {
    $portText = getenv('SHUGOI_WS_PORT') ?: '4197';
    if (!ctype_digit($portText) || (int)$portText < 1024 || (int)$portText > 65535) {
        throw new RuntimeException('SHUGOI_WS_PORT must be a port between 1024 and 65535');
    }
    $config = new Config([
        'siteKey' => getenv('SHUGOI_SITE_KEY') ?: '',
        'secret' => getenv('SHUGOI_SECRET') ?: null,
        'signingSecret' => getenv('SHUGOI_SIGNING_SECRET') ?: null,
        'powDifficulty' => (int)(getenv('SHUGOI_POW_DIFFICULTY') ?: '14'),
        'powTtlMs' => 60_000,
    ]);
    if ($config->powDifficulty < 1 || $config->powDifficulty > 22) {
        throw new RuntimeException('SHUGOI_POW_DIFFICULTY must be between 1 and 22');
    }
    $logger = new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            if (in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
                fwrite(STDERR, '[shugoi-websocket] ' . $level . " server event\n");
            }
        }
    };
    $trustProxy = getenv('SHUGOI_TRUST_PROXY') === '1';
    $server = SocketHttpServer::createForDirectAccess($logger, enableCompression: false, connectionLimit: 128, connectionLimitPerIp: $trustProxy ? 128 : 10, concurrencyLimit: 128, allowedMethods: ['GET']);
    $server->expose('127.0.0.1:' . $portText);
    $gateway = new PowGateway($server, $logger, $config, getenv('SHUGOI_ORIGIN') ?: '', $trustProxy);
    $server->start($gateway, new DefaultErrorHandler());
    fwrite(STDOUT, 'Shugoi PoW WebSocket listening on 127.0.0.1:' . $portText . "\n");
    trapSignal([SIGINT, SIGTERM]);
    $server->stop();
} catch (Throwable $error) {
    fwrite(STDERR, 'Shugoi WebSocket startup failed: ' . $error->getMessage() . "\n");
    exit(1);
}
