<?php
declare(strict_types=1);
namespace Shugoi;

class Config
{
    public const DEFAULT_ALLOWLIST = ['/api', '/legal'];
    public const DEFAULT_BASE_URL = 'https://api.shugoi.com/api/v1';
    public const DEFAULT_BLOCK_STATUS = 403;

    public const DEFAULT_HEADLESS_PATTERNS = [
        '/^curl/i',
        '/^wget/i',
        '/^python/i',
        '/^Go-http-client/i',
        '/^Java\//i',
        '/HTTPie/i',
        '/^node-fetch/i',
        '/axios/i',
        '/^okhttp/i',
        '/^scrapy/i',
        '/PowerShell/i',
        '/WinHttp/i',
    ];

    public const DEFAULT_BOT_WHITELIST = [
        '/Googlebot/i',
        '/Bingbot/i',
        '/Slurp/i',
        '/DuckDuckBot/i',
        '/YandexBot/i',
        '/Applebot/i',
        '/facebookexternalhit/i',
        '/Twitterbot/i',
        '/LinkedInBot/i',
        '/Discordbot/i',
        '/Slackbot/i',
        '/WhatsApp/i',
        '/TelegramBot/i',
    ];

    public readonly string $siteKey;
    public readonly ?string $secret;
    public readonly ?string $signingSecret;
    public readonly array $allowlist;
    public readonly array $headlessPatterns;
    public readonly array $botWhitelist;
    public readonly string $baseUrl;
    public readonly string $internalUrl;
    public readonly bool $debug;
    public readonly bool $autoInject;
    public readonly bool $restrictedAccess;
    public readonly ?array $extraDirectives;
    public readonly bool $csp;
    public readonly int $blockStatus;
    public readonly ?string $locale;
    public readonly mixed $blockPage;
    public readonly bool $splitRender;
    public readonly bool $multiProcess;
    public readonly ?string $renderStorePath;
    public readonly string $powWebSocketUrl;
    public readonly ?string $powReceiptStorePath;
    public readonly bool $failOpenOnUnavailable;
    public readonly bool $verifyBots;
    public readonly int $powDifficulty;
    public readonly int $powTtlMs;
    public readonly int $powOkTtlMs;
    public readonly string $browserTransport;
    public readonly string $renderStore;
    public readonly string $websocketBind;
    public readonly int $websocketPort;
    public readonly ?string $publicOrigin;

    public function __construct(array $options = [])
    {
        if (!isset($options['siteKey'])) {
            throw new \InvalidArgumentException('siteKey is required');
        }
        $this->siteKey = $options['siteKey'];
        $this->secret = $options['secret'] ?? null;
        $this->signingSecret = $options['signingSecret'] ?? null;
        $this->allowlist = $options['allowlist'] ?? self::DEFAULT_ALLOWLIST;
        $this->headlessPatterns = $options['headlessPatterns'] ?? self::DEFAULT_HEADLESS_PATTERNS;
        $this->botWhitelist = $options['botWhitelist'] ?? self::DEFAULT_BOT_WHITELIST;
        $this->baseUrl = $options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $this->internalUrl = $options['internalUrl'] ?? $this->baseUrl;
        $this->debug = $options['debug'] ?? false;
        $this->autoInject = $options['autoInject'] ?? true;
        $this->restrictedAccess = $options['restrictedAccess'] ?? false;
        $this->extraDirectives = $options['extraDirectives'] ?? null;
        $this->csp = $options['csp'] ?? true;
        $this->blockStatus = $options['blockStatus'] ?? self::DEFAULT_BLOCK_STATUS;
        $this->locale = $options['locale'] ?? null;
        $this->blockPage = $options['blockPage'] ?? null;
        $this->splitRender = $options['splitRender'] ?? true;
        $this->multiProcess = $options['multiProcess'] ?? false;
        $this->renderStorePath = $options['renderStorePath'] ?? null;
        $this->powWebSocketUrl = $options['powWebSocketUrl'] ?? '/__sg_challenge/ws';
        $this->powReceiptStorePath = $options['powReceiptStorePath'] ?? null;
        $this->failOpenOnUnavailable = $options['failOpenOnUnavailable'] ?? false;
        $this->verifyBots = $options['verifyBots'] ?? true;
        $this->powDifficulty = $options['powDifficulty'] ?? 14;
        $this->powTtlMs = $options['powTtlMs'] ?? 60_000;
        $this->powOkTtlMs = $options['powOkTtlMs'] ?? 30 * 24 * 3600 * 1000;
        $this->browserTransport = $options['browserTransport'] ?? 'http';
        $this->renderStore = $options['renderStore'] ?? 'memory';
        $this->websocketBind = $options['websocketBind'] ?? '127.0.0.1';
        $this->websocketPort = (int)($options['websocketPort'] ?? 8787);
        $this->publicOrigin = $options['publicOrigin'] ?? null;
        if (!in_array($this->browserTransport, ['http', 'websocket'], true)) {
            throw new \InvalidArgumentException('browserTransport must be http or websocket');
        }
        if ($this->browserTransport === 'websocket' && ($this->renderStore !== 'disk' || $this->renderStorePath === null || $this->renderStorePath === '' || $this->publicOrigin === null || $this->publicOrigin === '')) {
            throw new \InvalidArgumentException('WebSocket transport requires a disk render store and publicOrigin');
        }
    }

    public function getSigningSecret(): string
    {
        // Match Node's `signingSecret || secret`: an empty env value must not
        // shadow a configured signing secret.
        $secret = ($this->signingSecret !== null && $this->signingSecret !== '')
            ? $this->signingSecret
            : $this->secret;

        if ($secret === null || $secret === '') {
            throw new \RuntimeException('No signing secret configured');
        }

        return $secret;
    }

    public function apiOrigin(): ?string
    {
        $parts = parse_url($this->baseUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
