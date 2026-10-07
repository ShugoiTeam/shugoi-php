<?php
declare(strict_types=1);

namespace Shugoi;

class Core
{
    private const CHALLENGE_LIMIT = 60;
    private const CHALLENGE_WINDOW_MS = 60_000;
    private const CHALLENGE_MAX_BLOCK_MS = 15 * 60 * 1000;
    private const VALIDATION_WARN_INTERVAL = 3600;

    private bool $validated = false;
    private bool $validationFailed = false;
    private float $lastValidationWarn = 0;
    private array $challengeLimits = [];

    public static function getBlockPage(): string
    {
        return "+---------------------------------------------+\n"
            . "|           BLOCKED BY SHUGOI                 |\n"
            . "+---------------------------------------------+\n"
            . "|  Bots, scrapers and headless clients        |\n"
            . "|  are blocked by Shugoi protection.          |\n"
            . "|                                             |\n"
            . "|  Use a standard browser to access           |\n"
            . "|  this site.                                 |\n"
            . "|                                             |\n"
            . "|  - web: https://shugoi.com -                |\n"
            . "+---------------------------------------------+\n";
    }

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $api,
        private readonly Pow $pow,
        private readonly ?ConfigCache $configCache = null,
        private readonly ?BotVerifier $botVerifier = null,
    ) {}

    public function ensureValidated(): void
    {
        if ($this->validated) return;
        if ($this->config->secret === null) {
            $this->validated = true;
            return;
        }
        try {
            $result = $this->api->validateKey();
            $this->validationFailed = !($result['valid'] ?? false);
            $this->validated = true;
        } catch (\Throwable $e) {
            $this->validationFailed = true;
            $this->validated = true;
        }
        if ($this->validationFailed) $this->warnIfValidationFailed(true);
    }
    public function isAllowlisted(string $path): bool
    {
        foreach ($this->config->allowlist as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
        }
        return false;
    }

    public function isWhitelistedBot(string $ua): bool
    {
        foreach ($this->config->botWhitelist as $pattern) {
            if (preg_match($pattern, $ua)) return true;
        }
        return false;
    }
    public function evaluate(array $ctx): ?array
    {
        $this->ensureValidated();

        if ($this->config->failOpenOnUnavailable && $this->configCache !== null && !$this->configCache->isAvailable($this->config->internalUrl)) {
            return null;
        }

        $path = $ctx['path'] ?? '/';
        $ua = $ctx['ua'] ?? '';
        $ip = $ctx['ip'] ?? '';
        if (preg_match('~/assets/[^?#]+\.(js|css)(\?|$)~', $path)) {
            $authOk = !empty($ctx['sgAuthorized']) && $this->pow->isSgAuthorizedValid((string)$ctx['sgAuthorized']);
            if (!$authOk) {
                return [
                    'block' => true,
                    'status' => 403,
                    'contentType' => 'text/plain; charset=utf-8',
                    'body' => self::getBlockPage(),
                ];
            }
        }

        if ($this->isAllowlisted($path)) return null;
        $config = $this->configCache?->get($this->config->internalUrl) ?? ['detectionFlags' => []];
        $flags = $config['detectionFlags'] ?? [];
        if (($flags['enableHeadlessCheck'] ?? true) !== false && !$this->isTrustedBot($ua, $ip)) {
            $headless = $ua === '';
            foreach ($this->config->headlessPatterns as $pattern) {
                if (preg_match($pattern, $ua)) $headless = true;
            }
            if ($headless) {
                $this->api->sendEvent('headless');
                return ['block' => true, 'status' => $this->config->blockStatus,
                    'contentType' => 'text/plain; charset=utf-8', 'body' => self::getBlockPage(),
                    'headers' => ['Cache-Control' => 'no-store']];
            }
        }
        if ($this->pow->secret() === '') {
            return ['block' => true, 'status' => 503, 'contentType' => 'text/plain; charset=utf-8',
                'body' => 'Shugoi signing secret is not configured.', 'headers' => ['Cache-Control' => 'no-store']];
        }
        if ($path === '/__sg_challenge/ws') {
            return ['block' => true, 'status' => 426, 'contentType' => 'text/plain; charset=utf-8',
                'body' => 'WebSocket gateway required.', 'headers' => ['Cache-Control' => 'no-store']];
        }
        $receipt = $ctx['sgReceipt'] ?? '';
        if (($ctx['method'] ?? 'GET') === 'GET' && is_string($receipt) && $receipt !== ''
            && (new PowReceipt($this->config))->consume($receipt, $ip, $ua)) {
            $secure = ($ctx['secure'] ?? false) ? '; Secure' : '';
            return ['block' => true, 'status' => 303, 'contentType' => 'text/plain; charset=utf-8', 'body' => '',
                'headers' => [
                    'Location' => $this->safeChallengePath($ctx['requestTarget'] ?? $path),
                    'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
                    'Set-Cookie' => '__sg_ok=' . $this->pow->sgOkValue($ip, $ua)
                        . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . intdiv($this->config->powOkTtlMs, 1000) . $secure,
                ]];
        }
        $validCookie = !empty($ctx['sgOk']) && $this->pow->isSgOkValid((string)$ctx['sgOk'], $ip, $ua);
        if (!$validCookie) {
            return $this->challengePage($ctx);
        }
        if ($flags['enableRateLimit'] ?? false) {
            $rl = $this->api->checkRateLimit($ip, [
                'ip' => $ip,
                'userAgent' => $ua,
                'middleware' => true,
            ]);
            if (!($rl['allowed'] ?? true)) {
                $resetAt = $rl['resetAt'] ?? (time() + 60);
                if ($resetAt > 100000000000) $resetAt = (int)ceil($resetAt / 1000);
                $remaining = max(0, $resetAt - time());
                $timeStr = $this->formatRemaining($remaining);
                $locale = LocaleResolver::resolve($this->config->locale, $ctx['acceptLanguage'] ?? null);
                $body = null;
                if (is_callable($this->config->blockPage)) {
                    $body = ($this->config->blockPage)([
                        'reason' => 'rate_limit',
                        'title' => Locales::get($locale, 'rateLimitTitle'),
                        'message' => Locales::get($locale, 'rateLimitBody', $timeStr),
                        'badge' => Locales::get($locale, 'rateLimitBadge'),
                        'host' => $ctx['host'] ?? '',
                        'ua' => $ua,
                        'remainingSeconds' => $remaining,
                        'locale' => $locale,
                    ]);
                }
                if ($body === null) {
                    $body = BlockPage::rateLimit([
                        'locale' => $locale,
                        'remainingSeconds' => $remaining,
                        'host' => $ctx['host'] ?? null,
                        'ua' => $ua,
                    ]);
                }
                return [
                    'block' => true,
                    'status' => 429,
                    'contentType' => 'text/html; charset=utf-8',
                    'body' => $body,
                ];
            }
        }
        return null;
    }

    public function isTrustedBot(string $ua, string $ip): bool
    {
        if (!$this->isWhitelistedBot($ua)) return false;
        if ($this->botVerifier === null) return !$this->config->verifyBots;
        $verified = $this->botVerifier->verify($ua, $ip);
        return $verified === null ? false : $verified;
    }
    private function challengePage(array $ctx): array
    {
        $ip = $ctx['ip'] ?? '';
        if (!$this->allowChallenge($ip)) {
            $locale = LocaleResolver::resolve($this->config->locale, $ctx['acceptLanguage'] ?? null);
            return [
                'block' => true,
                'status' => 429,
                'contentType' => 'text/html; charset=utf-8',
                'body' => BlockPage::rateLimit([
                    'locale' => $locale,
                    'remainingSeconds' => 60,
                    'host' => $ctx['host'] ?? null,
                    'ua' => (string)($ctx['ua'] ?? ''),
                ]),
            ];
        }
        if (($ctx['method'] ?? 'GET') !== 'GET' && ($ctx['method'] ?? 'GET') !== 'HEAD') {
            return ['block' => true, 'status' => 403, 'contentType' => 'text/plain; charset=utf-8',
                'body' => 'Complete browser verification before submitting this request.',
                'headers' => ['Cache-Control' => 'no-store']];
        }
        $options = json_encode(['siteKey' => $this->config->siteKey, 'url' => $this->config->powWebSocketUrl],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $script = file_get_contents(__DIR__ . '/../resources/pow.js');
        if ($script === false) throw new \RuntimeException('Missing WebSocket PoW resource');
        $body = '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="referrer" content="no-referrer"><title>Browser verification</title></head><body>'
            . '<p id="sg-status" role="status">Verifying your browser...</p><noscript>JavaScript is required.</noscript>'
            . '<script>window.__sg_powOptions=' . $options . ';' . $script . '</script></body></html>';
        return ['block' => true, 'status' => 200, 'contentType' => 'text/html; charset=utf-8', 'body' => $body,
            'headers' => ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']];
    }
    private function safeChallengePath(string $path): string
    {
        if ($path === '') return '/';
        if ($path[0] !== '/' || ($path[1] ?? '') === '/' || str_contains($path, '\\')) return '/';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($path[$i]);
            if ($c < 0x20 || $c === 0x7f) return '/';
        }
        return $path;
    }
    private function allowChallenge(string $ip): bool
    {
        if ($ip === '' || $ip === 'unknown') return true;
        $now = (int)(microtime(true) * 1000);
        if (!isset($this->challengeLimits[$ip])) {
            $this->challengeLimits[$ip] = ['count' => 1, 'windowStart' => $now, 'blockedUntil' => 0];
            return true;
        }
        $e = &$this->challengeLimits[$ip];
        if ($now - $e['windowStart'] >= self::CHALLENGE_WINDOW_MS) {
            $e = ['count' => 1, 'windowStart' => $now, 'blockedUntil' => 0];
            return true;
        }
        $e['count']++;
        if ($e['blockedUntil'] > $now) return false;
        if ($e['count'] > self::CHALLENGE_LIMIT) {
            $backoff = min(60_000 * (2 ** min($e['count'] - self::CHALLENGE_LIMIT, 10)), self::CHALLENGE_MAX_BLOCK_MS);
            $e['blockedUntil'] = $now + $backoff;
            $e['count'] = 0;
            return false;
        }
        return true;
    }
    private function warnIfValidationFailed(bool $force = false): void
    {
        $now = time();
        if (!$force && $now - $this->lastValidationWarn < self::VALIDATION_WARN_INTERVAL) return;
        $this->lastValidationWarn = $now;
        $this->api->sendEvent('validation_failed');
        error_log('[shugoi] La validation de la clé a échoué pour le siteKey ' . $this->config->siteKey . '.');
        error_log('[shugoi] La protection reste active, mais cette installation n\'est pas authentifiée.');
        error_log('[shugoi] Vérifiez `siteKey` et `secret` : https://shugoi.com/docs#validation');
    }
    private function formatRemaining(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        if ($mins > 0) {
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ($secs > 0 ? ' ' . $secs . ' s' : '');
        }
        return $secs . ' seconde' . ($secs > 1 ? 's' : '');
    }
}
