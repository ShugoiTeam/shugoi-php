<?php
declare(strict_types=1);
namespace Shugoi\Tests\WebSocket;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shugoi\Config;
use Shugoi\WebSocket\PowGateway;

final class PowGatewayTest extends TestCase
{
    private function gateway(bool $trustProxy = false, string $origin = 'https://example.test'): PowGateway
    {
        return new PowGateway($this->createMock(HttpServer::class), new NullLogger(), new Config(['siteKey' => 'test', 'secret' => 'test-secret']), $origin, $trustProxy);
    }

    private function request(string $remote = '127.0.0.1', array $headers = []): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress($remote, 12345));
        return new Request($client, 'GET', new Uri('http://127.0.0.1:4197/__sg_challenge/ws'), $headers + [
            'Origin' => 'https://example.test',
            'Upgrade' => 'websocket', 'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version' => '13',
        ]);
    }

    public function test_rejects_wrong_origin_and_path(): void
    {
        $gateway = $this->gateway();
        $this->assertSame(403, $gateway->handleRequest($this->request(headers: ['Origin' => 'https://other.test']))->getStatus());
        $request = $this->request();
        $request->setUri(new Uri('http://127.0.0.1:4197/other'));
        $this->assertSame(404, $gateway->handleRequest($request)->getStatus());
        $this->assertSame(101, $gateway->handleRequest($this->request())->getStatus());
    }

    public function test_untrusted_ip_headers_do_not_affect_binding(): void
    {
        $this->assertSame('192.0.2.1', $this->gateway()->clientIp($this->request('192.0.2.1', ['X-Real-IP' => '198.51.100.1'])));
        $this->assertSame('192.0.2.1', $this->gateway(true)->clientIp($this->request('192.0.2.1', ['X-Real-IP' => '198.51.100.1'])));
        $this->assertSame('198.51.100.1', $this->gateway(true)->clientIp($this->request('127.0.0.1', ['X-Real-IP' => '198.51.100.1'])));
        $this->assertNull($this->gateway(true)->clientIp($this->request('127.0.0.1', ['X-Real-IP' => '198.51.100.1,192.0.2.1'])));
    }

    public function test_bounds_connection_attempts_per_client(): void
    {
        $gateway = $this->gateway();
        for ($i = 0; $i < 30; ++$i) $this->assertSame(101, $gateway->handleRequest($this->request())->getStatus());
        $response = $gateway->handleRequest($this->request());
        $this->assertSame(429, $response->getStatus());
        $this->assertSame('no-store', $response->getHeader('cache-control'));
    }

    public function test_origin_must_not_contain_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gateway(origin: 'https://user@example.test');
    }
}
