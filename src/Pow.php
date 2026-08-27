<?php
declare(strict_types=1);

namespace Shugoi;
class Pow
{
    public function __construct(private readonly Config $config) {}

    public function difficulty(): int
    {
        return $this->config->powDifficulty;
    }

    public function ttlMs(): int
    {
        return $this->config->powTtlMs;
    }

    public function secret(): string
    {
        try {
            return $this->config->getSigningSecret();
        } catch (\RuntimeException) {
            return '';
        }
    }
    public function challenge(): array
    {
        $ts = time();
        $nonce = $this->nonce();
        return ['ts' => $ts, 'nonce' => $nonce, 'salt' => $this->salt($ts, $nonce), 'difficulty' => $this->difficulty()];
    }
    public function isValid(string $proof): bool
    {
        if ($proof === '' || $this->secret() === '') return false;
        $parts = explode(':', $proof);
        if (count($parts) !== 3) return false;
        [$tsStr, $nonce, $solution] = $parts;
        if ($tsStr === '' || $nonce === '' || $solution === '') return false;
        if (!preg_match('/^[0-9a-f]{16}$/', $nonce)) return false;
        $ts = (int)$tsStr;
        if ($ts <= 0) return false;
        if (abs(($this->nowMs() - $ts * 1000)) > $this->ttlMs()) return false;

        $digest = hash('sha256', $this->salt($tsStr, $nonce) . ':' . $solution);
        return $this->leadingZeroBits($digest) >= $this->difficulty();
    }
    public function leadingZeroBits(string $hex): int
    {
        $leading = 0;
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $nib = hexdec($hex[$i]);
            if ($nib === 0) {
                $leading += 4;
                continue;
            }
            $leading += ($nib & 8) ? 0 : (($nib & 4) ? 1 : (($nib & 2) ? 2 : 3));
            break;
        }
        return $leading;
    }
    public function sgOkValue(string $ip = '', string $ua = ''): string
    {
        $ts = time();
        $bucket = $this->ipBucket($ip);
        $fp = $this->uaFp($ua);
        return $ts . ':' . $bucket . ':' . $fp . ':' . hash_hmac('sha256', "sg_ok:{$ts}:{$bucket}:{$fp}", $this->secret());
    }

    public function isSgOkValid(string $cookieVal, string $ip = '', string $ua = ''): bool
    {
        if ($this->secret() === '' || $cookieVal === '') return false;
        $parts = explode(':', $cookieVal);
        if (count($parts) !== 4) return false;
        [$tsStr, $bucket, $fp, $sig] = $parts;
        if ($tsStr === '' || $bucket === '' || $fp === '' || $sig === '') return false;
        $ts = (int)$tsStr;
        if ($ts <= 0) return false;
        if ($this->nowMs() - $ts * 1000 > $this->config->powOkTtlMs) return false;
        if ($ts * 1000 > $this->nowMs() + 60_000) return false;
        if ($bucket !== $this->ipBucket($ip) || $fp !== $this->uaFp($ua)) return false;
        return hash_equals(hash_hmac('sha256', "sg_ok:{$tsStr}:{$bucket}:{$fp}", $this->secret()), $sig);
    }
    public function sgOkCookie(string $proof, string $ip = '', string $ua = ''): ?string
    {
        if (!$this->isValid($proof)) return null;
        $secure = $this->isProduction() ? '; Secure' : '';
        return '__sg_ok=' . $this->sgOkValue($ip, $ua)
            . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . intdiv($this->config->powOkTtlMs, 1000) . $secure;
    }

    public function sgAuthorizedValue(): string
    {
        $ts = time();
        return $ts . ':' . hash_hmac('sha256', 'sg_authorized:' . $ts, $this->secret());
    }

    public function isSgAuthorizedValid(string $cookieVal): bool
    {
        if ($this->secret() === '' || $cookieVal === '') return false;
        $sep = strpos($cookieVal, ':');
        if ($sep <= 0) return false;
        $tsStr = substr($cookieVal, 0, $sep);
        $sig = substr($cookieVal, $sep + 1);
        $ts = (int)$tsStr;
        if ($ts <= 0) return false;
        if ($this->nowMs() - $ts * 1000 > 120_000) return false;
        if ($ts * 1000 > $this->nowMs() + 60_000) return false;
        return hash_equals(hash_hmac('sha256', 'sg_authorized:' . $tsStr, $this->secret()), $sig);
    }

    public function sgAuthorizedCookie(): string
    {
        $secure = $this->isProduction() ? '; Secure' : '';
        return '__sg_authorized=' . $this->sgAuthorizedValue()
            . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=120' . $secure;
    }

    private function nonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function salt(string $ts, string $nonce = ''): string
    {
        return hash_hmac('sha256', $ts . ':' . $nonce, $this->secret());
    }
    private function ipBucket(string $ip): string
    {
        if ($ip === '' || $ip === 'unknown') return '0';
        if (str_contains($ip, '.')) {
            if (preg_match('/^(\d+\.\d+\.\d+)(?:\.\d+)?$/', $ip, $m)) return $m[1];
            return '0';
        }
        if (!str_contains($ip, ':')) return '0';
        $segs = array_values(array_filter(explode(':', $ip), fn($s) => $s !== ''));
        $bucket = implode('.', array_slice($segs, 0, 4));
        return $bucket === '' ? '0' : $bucket;
    }
    private function uaFp(string $ua): string
    {
        return substr(hash('sha256', $ua), 0, 16);
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }

    private function isProduction(): bool
    {
        $env = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? null) ?: ($_SERVER['NODE_ENV'] ?? null) ?: getenv('NODE_ENV');
        return $env === 'production';
    }
}
