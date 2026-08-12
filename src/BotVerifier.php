<?php
declare(strict_types=1);
namespace Shugoi;

class BotVerifier
{
    private const CACHE_TTL = 3600;
    private const MAX_CACHE = 5000;
    private static array $cache = [];

    private const BOT_DOMAINS = [
        ['pattern' => '/Googlebot|Google-InspectionTool|Storebot-Google/i', 'suffixes' => ['.googlebot.com', '.google.com']],
        ['pattern' => '/Bingbot|adidxbot|BingPreview/i', 'suffixes' => ['.search.msn.com']],
        ['pattern' => '/Slurp/i', 'suffixes' => ['.crawl.yahoo.net']],
        ['pattern' => '/DuckDuckBot/i', 'suffixes' => ['.duckduckgo.com']],
        ['pattern' => '/YandexBot/i', 'suffixes' => ['.yandex.ru', '.yandex.net', '.yandex.com']],
        ['pattern' => '/Applebot/i', 'suffixes' => ['.applebot.apple.com']],
    ];

    public function verify(string $ua, string $ip): ?bool
    {
        $match = null;
        foreach (self::BOT_DOMAINS as $bot) {
            if (preg_match($bot['pattern'], $ua)) { $match = $bot; break; }
        }
        if ($match === null) return null;
        if (empty($ip) || $ip === 'unknown') return false;

        $cacheKey = md5($ua . ':' . $ip);
        if (isset(self::$cache[$cacheKey])) {
            $entry = self::$cache[$cacheKey];
            if (time() - $entry['time'] < self::CACHE_TTL) return $entry['result'];
        }
        if (count(self::$cache) >= self::MAX_CACHE) array_shift(self::$cache);

        $result = $this->verifyPtr($ip, $match['suffixes']);
        self::$cache[$cacheKey] = ['result' => $result, 'time' => time()];
        return $result;
    }

    // Protégé (et non privé) : testable par les mocks PHPUnit (onlyMethods).
    protected function verifyPtr(string $ip, array $expectedSuffixes): bool
    {
        $ptr = gethostbyaddr($ip);
        if ($ptr === false || $ptr === $ip) return false;
        $ptrLower = strtolower($ptr);
        $matched = false;
        foreach ($expectedSuffixes as $suffix) {
            if (str_ends_with($ptrLower, strtolower($suffix))) { $matched = true; break; }
        }
        if (!$matched) return false;
        $records = dns_get_record($ptr, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) return false;
        foreach ($records as $rec) {
            $resolvedIp = $rec['ip'] ?? $rec['ipv6'] ?? null;
            if ($resolvedIp === $ip) return true;
        }
        return false;
    }
}
