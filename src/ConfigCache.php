<?php
declare(strict_types=1);

namespace Shugoi;

class ConfigCache
{
    private const FRESH_TTL = 30;
    private const STALE_TTL = 600;
    private const MAX_TENANTS = 500;

    private array $store = [];
    private int $tenantCount = 0;

    public function __construct(private readonly ApiClient $api) {}

    public function get(?string $baseUrl = null): array
    {
        $key = $baseUrl ?? 'default';
        $now = microtime(true);
        $entry = $this->store[$key] ?? null;
        if ($entry !== null && ($now - $entry['fetchedAt']) < self::FRESH_TTL) {
            return $entry['data'];
        }
        try {
            $fresh = $this->api->fetchWhitelist($baseUrl);
            if ($entry === null) {
                if ($this->tenantCount >= self::MAX_TENANTS) return $fresh;
                $this->tenantCount++;
            }
            $this->store[$key] = ['data' => $fresh, 'fetchedAt' => $now];
            return $fresh;
        } catch (\Throwable) {
            if ($entry !== null && ($now - $entry['fetchedAt']) < self::STALE_TTL) {
                return $entry['data'];
            }
            return ['whitelistedMachines' => [], 'detectionFlags' => [], 'skipPaths' => []];
        }
    }

    public function clear(): void { $this->store = []; $this->tenantCount = 0; }
}
