<?php
declare(strict_types=1);

namespace Shugoi\WebSocket;

use Shugoi\Config;
use Shugoi\Pow;
use Shugoi\PowReceipt;

/** One challenge and one proof per WebSocket connection. */
final class PowSession
{
    private ?array $challenge = null;
    private bool $finished = false;

    public function __construct(
        private readonly Config $config,
        private readonly string $ip,
        private readonly string $userAgent,
    ) {}

    public function receive(array $message): array
    {
        if ($this->finished) return ['type' => 'pow-error', 'error' => 'session_finished'];
        if ($this->challenge === null) {
            if (($message['cmd'] ?? null) !== 'pow-start' || ($message['siteKey'] ?? null) !== $this->config->siteKey) {
                $this->finished = true;
                return ['type' => 'pow-error', 'error' => 'invalid_start'];
            }
            $this->challenge = (new Pow($this->config))->challenge();
            return ['type' => 'pow-challenge'] + $this->challenge;
        }

        $this->finished = true;
        $proof = $message['proof'] ?? null;
        if (($message['cmd'] ?? null) !== 'pow-proof' || !is_string($proof)
            || !preg_match('/\A([0-9]{10}):([0-9a-f]{16}):([0-9a-f]{1,16})\z/', $proof, $parts)
            || $parts[1] !== (string)$this->challenge['ts']
            || $parts[2] !== $this->challenge['nonce']
            || !(new Pow($this->config))->isValid($proof)) {
            return ['type' => 'pow-error', 'error' => 'invalid_proof'];
        }
        return ['type' => 'pow-ok', 'receipt' => (new PowReceipt($this->config))->issue($this->ip, $this->userAgent)];
    }
}
