<?php
declare(strict_types=1);
namespace Shugoi\Tests\WebSocket;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\Pow;
use Shugoi\PowReceipt;
use Shugoi\WebSocket\PowSession;

final class PowSessionTest extends TestCase
{
    private function config(): Config
    {
        return new Config(['siteKey' => 'test-ws-site', 'secret' => 'test-ws-secret', 'powDifficulty' => 4]);
    }

    private function solve(array $challenge): string
    {
        $pow = new Pow($this->config());
        for ($i = 0; ; ++$i) {
            $solution = dechex($i);
            if ($pow->leadingZeroBits(hash('sha256', $challenge['salt'] . ':' . $solution)) >= $challenge['difficulty']) {
                return $challenge['ts'] . ':' . $challenge['nonce'] . ':' . $solution;
            }
        }
    }

    public function test_receipt_requires_solution_to_this_connections_challenge(): void
    {
        $session = new PowSession($this->config(), '192.0.2.1', 'Browser');
        $challenge = $session->receive(['cmd' => 'pow-start', 'siteKey' => 'test-ws-site']);
        $this->assertSame('pow-challenge', $challenge['type']);
        $result = $session->receive(['cmd' => 'pow-proof', 'proof' => $this->solve($challenge)]);
        $this->assertSame('pow-ok', $result['type']);
        $this->assertTrue((new PowReceipt($this->config()))->consume($result['receipt'], '192.0.2.1', 'Browser'));
        $this->assertSame('session_finished', $session->receive(['cmd' => 'pow-proof', 'proof' => $this->solve($challenge)])['error']);
    }

    public function test_solution_from_another_connection_is_rejected(): void
    {
        $session = new PowSession($this->config(), '192.0.2.1', 'Browser');
        $session->receive(['cmd' => 'pow-start', 'siteKey' => 'test-ws-site']);
        $other = new PowSession($this->config(), '192.0.2.1', 'Browser');
        $challenge = $other->receive(['cmd' => 'pow-start', 'siteKey' => 'test-ws-site']);
        $this->assertSame('invalid_proof', $session->receive(['cmd' => 'pow-proof', 'proof' => $this->solve($challenge)])['error']);
    }

    public function test_wrong_site_and_proof_before_start_are_rejected(): void
    {
        $session = new PowSession($this->config(), '', '');
        $this->assertSame('invalid_start', $session->receive(['cmd' => 'pow-start', 'siteKey' => 'other-site'])['error']);
        $session = new PowSession($this->config(), '', '');
        $this->assertSame('invalid_start', $session->receive(['cmd' => 'pow-proof', 'proof' => 'anything'])['error']);
    }
}
