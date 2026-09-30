<?php
declare(strict_types=1);

namespace Shugoi;

class TokenSigner
{
    public const GRANT_TTL_MS = 60_000;
    public const TOKEN_TTL_MS = 120_000;

    public function __construct(
        private readonly Config $config
    ) {}

    public function sign(int $timestamp, ?string $secretOverride = null): string
    {
        $secret = $secretOverride ?? $this->config->getSigningSecret();
        if ($secret === '') throw new \RuntimeException('No signing secret configured');
        $nonce = bin2hex(random_bytes(8));
        $payload = $this->config->siteKey . ':' . $timestamp . ':' . $nonce;
        $sig = hash_hmac('sha256', $payload, $secret);
        return $payload . ':' . $sig;
    }

    public function verify(string $token, ?string $secretOverride = null): ?array
    {
        try {
            $secret = $secretOverride ?? $this->config->getSigningSecret();
        } catch (\RuntimeException) {
            return null;
        }
        if ($secret === '' || strlen($token) > 300) return null;
        $parts = explode(':', $token);
        if (count($parts) !== 4) return null;
        [$siteKey, $timestamp, $nonce, $sig] = $parts;
        if ($siteKey !== $this->config->siteKey
            || !preg_match('/\A[1-9][0-9]{0,12}\z/D', $timestamp)
            || !preg_match('/\A[a-f0-9]{16}\z/D', $nonce)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $sig)) return null;
        $age = $this->nowMs() - (int)$timestamp;
        if ($age > self::TOKEN_TTL_MS || $age < -5000) return null;
        $payload = $siteKey . ':' . $timestamp . ':' . $nonce;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) return null;
        return [
            'siteKey' => $siteKey,
            'timestamp' => (int)$timestamp,
            'nonce' => $nonce,
            'sig' => $sig,
        ];
    }
    public function secret(): string
    {
        try {
            return $this->config->getSigningSecret();
        } catch (\RuntimeException) {
            return '';
        }
    }
    public function verifyRenderGrant(
        ?string $mid,
        ?string $grant,
        string $token = '',
        string $ip = '',
        ?string $expectedSiteKey = null
    ): bool {
        try {
            $secret = $this->config->getSigningSecret();
        } catch (\RuntimeException) {
            return false;
        }
        if ($secret === '') return false;
        if ($grant === null || $grant === '' || $mid === null || $mid === '') return false;
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $mid)) return false;
        if (!preg_match('/\A([1-9a-z][0-9a-z]{0,8}):([a-f0-9]{64})\z/D', $grant, $parts)) return false;
        if ($expectedSiteKey !== $this->config->siteKey || $this->verify($token) === null) return false;
        [, $ts, $sig] = $parts;
        $tsSec = (int)base_convert($ts, 36, 10);
        if ($tsSec <= 0) return false;
        $age = $this->nowMs() - $tsSec * 1000;
        if ($age > self::GRANT_TTL_MS || $age < -5000) return false;

        // The current protocol binds the tenant, machine and exact document.
        // Keep $ip in the public API for callers of earlier SDK versions.
        $payload = 'render-grant:' . implode(':', [$expectedSiteKey, $mid, $token, $ts]);
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $sig);
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
