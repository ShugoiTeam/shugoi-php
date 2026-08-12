<?php
declare(strict_types=1);

namespace Shugoi;

class GuardCache
{
    private const TTL = 300;
    private ?array $cached = null;
    private float $fetchedAt = 0;

    public function __construct(private readonly ApiClient $api) {}

    public function get(): array
    {
        $now = microtime(true);
        if ($this->cached !== null && ($now - $this->fetchedAt) < self::TTL) {
            return $this->cached;
        }
        $this->cached = [
            'detect' => $this->api->fetchGuardDetect(),
            'guard' => $this->api->fetchGuard(),
        ];
        $this->fetchedAt = $now;
        return $this->cached;
    }

    public function getConfig(): array
    {
        return $this->cached ?? ['detect' => '', 'guard' => ''];
    }

    public function clear(): void { $this->cached = null; $this->fetchedAt = 0; }
}
