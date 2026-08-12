<?php
declare(strict_types=1);

namespace Shugoi;

/**
 * Proof-of-work anti-curl + cookies HMAC — parité avec core.ts (module Node) :
 *   salt = HMAC(secret, ts + ':' + nonce)   (nonce 64 bits ALEATOIRE par challenge)
 *   proof = "ts:nonce:solution" où SHA256(salt:solution) a >= POW_DIFFICULTY bits à zéro.
 *   __sg_ok        : ts:ipBucket:uaFp:HMAC(secret, "sg_ok:ts:ipBucket:uaFp") — 30 jours,
 *                    lié au bucket IP + empreinte UA (non rejouable depuis une autre IP),
 *                    saute le pre-flight PoW.
 *   __sg_authorized: ts:HMAC(secret, "sg_authorized:ts") — 120 s, protège les assets /assets/*
 */
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

    /** Génère le challenge à injecter (window.__sg_pow). */
    public function challenge(): array
    {
        $ts = time();
        $nonce = $this->nonce();
        return ['ts' => $ts, 'nonce' => $nonce, 'salt' => $this->salt($ts, $nonce), 'difficulty' => $this->difficulty()];
    }

    /** Vérifie un proof "ts:nonce:solution" (fenêtre @ttlMs, comptage de bits CORRIGÉ). */
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

    /**
     * Nombre de bits à zéro en tête (comptage CORRIGÉ, audit 2026-08-03).
     * L'ancien comptage (décimal→binaire sans zéros de tête) sous-comptait les zéros
     * internes du premier nibble non-nul (`3` → '11' → 0 au lieu de 2). Ce comptage est
     * exact et DOIT rester synchrone avec core.ts (isPowValid), le challenge JS et le guard.
     */
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

    /** Valeur du cookie __sg_ok (HMAC serveur, 30 j) — lié au bucket IP + empreinte UA. */
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
        // Lier au bucket IP + UA courants : un cookie d'une autre IP/UA → invalide.
        if ($bucket !== $this->ipBucket($ip) || $fp !== $this->uaFp($ua)) return false;
        return hash_equals(hash_hmac('sha256', "sg_ok:{$tsStr}:{$bucket}:{$fp}", $this->secret()), $sig);
    }

    /** String Set-Cookie pour __sg_ok (posé par le middleware après une preuve valide). */
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

    /** Bucket d'IP (sans ':' pour rester parseable dans le cookie). IPv4 → /24, IPv6 → 4 hextets. */
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

    /** Empreinte UA (16 hex). */
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
