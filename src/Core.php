<?php

namespace Shugoi;

class Core
{
    private const POW_TTL_MS = 60_000;
    private const CHALLENGE_LIMIT = 60;
    private const CHALLENGE_WINDOW_MS = 60_000;
    private const CHALLENGE_MAX_BLOCK_MS = 15 * 60 * 1000;
    private const VALIDATION_WARN_INTERVAL = 3600;

    private bool $validated = false;
    private bool $validationFailed = false;
    private float $lastValidationWarn = 0;

    /** @var array<string,int> preuves PoW single-use (clé = ip:proof → timestamp) */
    private array $usedProofs = [];
    /** @var array<string,array{count:int,windowStart:int,blockedUntil:int}> */
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

    /** Allowlist : match exact ou préfixe + '/' (parité Node — pas de prefix-match large). */
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

    /**
     * @param array{path: string, ua: string, ip: string, host?: string, acceptLanguage?: string, secFetchDest?: string, secFetchMode?: string, sgProof?: ?string, sgOk?: ?string, sgAuthorized?: ?string, forwardedPrefix?: ?string} $ctx
     * @return array|null {block: true, status: int, contentType: string, body: string, headers?: array} or null to allow
     */
    public function evaluate(array $ctx): ?array
    {
        $this->ensureValidated();

        if ($this->config->failOpenOnUnavailable && $this->configCache !== null && !$this->configCache->isAvailable($this->config->internalUrl)) {
            return null;
        }

        $path = $ctx['path'] ?? '/';
        $ua = $ctx['ua'] ?? '';
        $ip = $ctx['ip'] ?? '';

        // Protection des assets à contenu (/assets/*.js, *.css) — parité core.ts. Le bundle
        // SPA est téléchargeable publiquement sans ce verrou : on exige le cookie
        // __sg_authorized posé par handleRender après un render réussi (grant valide).
        // NB : vérifié AVANT l'allowlist (les assets sont allowlistés pour le split-render).
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

        // Route du challenge JS (le navigateur arrive ici après le 307).
        if ($path === '/__sg_challenge') {
            return $this->challengePage($ctx);
        }

        // Pre-flight PoW challenge (anti-curl/view-source). S'applique aux navigateurs
        // (UA Mozilla) sans preuve valide ni cookie __sg_ok (HMAC serveur, 30 j).
        if (!str_contains($path, '/__shugoi/') && !str_starts_with($path, '/api/')) {
            if ($this->pow->secret() !== '') {
                $proof = (string)($ctx['sgProof'] ?? '');
                $validProof = $proof !== '' && $this->pow->isValid($proof);
                $validCookie = !empty($ctx['sgOk']) && $this->pow->isSgOkValid((string)$ctx['sgOk'], $ip, $ua);
                // Round 16 (R2) : la preuve est SINGLE-USE (par IP) — un rejeu → 307.
                $proofFresh = $validProof ? $this->consumeProof($proof) : false;
                if (!$validCookie && !$proofFresh) {
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
                            ]),
                        ];
                    }
                    return $this->powChallenge307($ctx);
                }
            }
        }

        $config = $this->configCache?->get($this->config->internalUrl) ?? ['detectionFlags' => []];
        $flags = $config['detectionFlags'] ?? [];

        // Rate limit check — activé uniquement si le flag est explicitement vrai.
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
                        'remainingSeconds' => $remaining,
                        'locale' => $locale,
                    ]);
                }
                if ($body === null) {
                    $body = BlockPage::rateLimit([
                        'locale' => $locale,
                        'remainingSeconds' => $remaining,
                        'host' => $ctx['host'] ?? null,
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

        // Blocage headless (UA). Actif par défaut, y compris sans configuration chargée.
        $enableHeadless = $flags['enableHeadlessCheck'] ?? true;
        if ($enableHeadless !== false && $ua !== '' && !$this->isTrustedBot($ua, $ip)) {
            foreach ($this->config->headlessPatterns as $pattern) {
                if (preg_match($pattern, $ua)) {
                    $this->api->sendEvent('headless');
                    return [
                        'block' => true,
                        'status' => $this->config->blockStatus,
                        'contentType' => 'text/plain; charset=utf-8',
                        'body' => self::getBlockPage(),
                    ];
                }
            }
        }

        // Fake browser (Sec-Fetch/Accept-Language absents) : NE BLOQUE PLUS en 403 brut
        // (audit Tor, parité core.ts) — le guard CLIENT gère la détection (Tor → card dédiée).
        return null;
    }

    public function isTrustedBot(string $ua, string $ip): bool
    {
        if (!$this->isWhitelistedBot($ua)) return false;
        if ($this->botVerifier === null) return true;
        $verified = $this->botVerifier->verify($ua, $ip);
        return $verified === null ? false : $verified;
    }

    /** Page challenge (tableau en commentaire + JS PoW inline, comptage de bits corrigé). */
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
                ]),
            ];
        }
        $js = '(function(){'
            . 'var P=new URLSearchParams(location.search);'
            . "var salt=P.get('salt')||'', ts=P.get('ts')||'', nonce=P.get('nonce')||'', diff=parseInt(P.get('diff')||'" . $this->config->powDifficulty . "',10), path=P.get('path')||'/';"
            . 'if(path.charAt(0)!==\'/\'||path.charAt(1)===\'/\'||path.indexOf(\'\\\\\')>=0)path=\'/\';'
            . 'var enc=new TextEncoder();'
            . 'function bits(d){var l=0;for(var i=0;i<d.length;i++){var b=parseInt(d[i],16);if(b===0){l+=4;continue}l+=(b&8)?0:(b&4)?1:(b&2)?2:3;break}return l}'
            . 'var n=0;'
            . 'function step(){'
            . "crypto.subtle.digest('SHA-256',enc.encode(salt+':'+n.toString(16))).then(function(buf){"
            . "var h=Array.from(new Uint8Array(buf)).map(function(v){return v.toString(16).padStart(2,'0')}).join('');"
            . "if(bits(h)>=diff){var base=path;var q=(base.indexOf('?')>=0?'&':'?')+'sg_proof='+ts+':'+nonce+':'+n.toString(16);location.replace(base+q)}"
            . 'else{n++;if(n<300000)step()}'
            . '}).catch(function(){location.reload()});'
            . '}'
            . 'step();'
            . '})();';
        $body = "<!--\n" . self::getBlockPage() . "-->\n<script>" . $js . '</script>';
        return ['block' => true, 'status' => 200, 'contentType' => 'text/html; charset=utf-8', 'body' => $body];
    }

    /** 307 vers le challenge : body = tableau ASCII seul (curl le voit tel quel). */
    private function powChallenge307(array $ctx): array
    {
        $ts = time();
        $nonce = bin2hex(random_bytes(8));
        $salt = hash_hmac('sha256', $ts . ':' . $nonce, $this->pow->secret());
        $prefix = (string)($ctx['forwardedPrefix'] ?? '');
        if ($prefix === '/' || $prefix === '') $prefix = '';
        $prefix = rtrim($prefix, '/');
        $path = $this->safeChallengePath($ctx['path'] ?? '/');
        $chalUrl = $prefix . '/__sg_challenge?ts=' . $ts . '&salt=' . $salt
            . '&nonce=' . $nonce . '&diff=' . $this->config->powDifficulty . '&path=' . rawurlencode($prefix . $path);
        return [
            'block' => true,
            'status' => 307,
            'contentType' => 'text/plain; charset=utf-8',
            'body' => self::getBlockPage(),
            'headers' => ['Location' => $chalUrl],
        ];
    }

    /** Sanitisation open redirect (audit #5) : n'accepte qu'un chemin relatif '/...'. */
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

    /** Anti-scraping : borne par IP l'émission de challenges (quota + backoff exponentiel). */
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

    /** Preuve PoW single-use GLOBAL (round 17) — clé = preuve seule (nonce aléatoire unique) → rejeu depuis n'importe quel IP → 307. Entrées purgées après POW_TTL_MS. */
    private function consumeProof(string $proof): bool
    {
        if (isset($this->usedProofs[$proof])) return false;
        $this->usedProofs[$proof] = time();
        if (count($this->usedProofs) > 5000) {
            $now = time();
            $this->usedProofs = array_filter($this->usedProofs, fn($t) => $now - $t <= (self::POW_TTL_MS / 1000));
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

    /** Format du temps restant — parité exacte avec core.ts (timeStr). */
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
