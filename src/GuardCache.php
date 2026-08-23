<?php
declare(strict_types=1);

namespace Shugoi;

class GuardCache
{
    private const TTL = 300;
    private ?array $cached = null;
    private float $fetchedAt = 0;
    private bool $refreshing = false;

    public function __construct(private readonly ApiClient $api) {}

    public function get(): array
    {
        $now = microtime(true);
        if ($this->cached !== null) {
            if (($now - $this->fetchedAt) >= self::TTL && !$this->refreshing) {
                $this->refreshAsync();
            }
            return $this->cached;
        }
        $this->refresh();
        return $this->cached ?? ['detect' => '', 'guard' => ''];
    }

    public function getConfig(): array
    {
        return $this->cached ?? ['detect' => '', 'guard' => ''];
    }

    public function clear(): void { $this->cached = null; $this->fetchedAt = 0; }

    private function refresh(): void
    {
        $this->refreshing = true;
        try {
            $detect = $this->api->fetchGuardDetect();
            $guard = $this->api->fetchGuard();
            if ($detect !== '' || $guard !== '') {
                $this->cached = ['detect' => $detect, 'guard' => $guard];
                $this->fetchedAt = microtime(true);
            }
        } finally {
            $this->refreshing = false;
        }
    }

    private function refreshAsync(): void
    {
        if ($this->refreshing) return;
        $this->refreshing = true;
        register_shutdown_function(function () {
            try {
                $this->refresh();
            } catch (\Throwable) {
            } finally {
                $this->refreshing = false;
            }
        });
    }
}
