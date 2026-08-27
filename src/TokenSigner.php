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
        $nonce = bin2hex(random_bytes(8));
        $payload = $this->config->siteKey . ':' . $timestamp . ':' . $nonce;
        $sig = hash_hmac('sha256', $payload, $secret);
        return $payload . ':' . $sig;
    }

    public function verify(string $token, ?string $secretOverride = null): ?array
    {
        $secret = $secretOverride ?? $this->config->getSigningSecret();
        $parts = explode(':', $token);
        if (count($parts) !== 4) return null;
        [$siteKey, $timestamp, $nonce, $sig] = $parts;
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
            return true; // fail-safe : pas de secret configuré → pas de vérification
        }
        if ($secret === '') return true;
        if ($grant === null || $grant === '' || $mid === null || $mid === '') return false;
        if (!preg_match('/^[a-f0-9]{64}$/', $mid)) return false;

        $sep = strpos($grant, ':');
        if ($sep < 0) return false;
        $ts = substr($grant, 0, $sep);
        $sig = substr($grant, $sep + 1);
        $tsSec = (int)base_convert($ts, 36, 10);
        if ($tsSec <= 0) return false;
        if (($this->nowMs() - $tsSec * 1000) > self::GRANT_TTL_MS) return false;
        if ($expectedSiteKey === null || $expectedSiteKey === '') return false;

        $payload = 'render-grant:' . implode(':', [$expectedSiteKey, $mid, $token, $ip, $ts]);
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $sig);
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
