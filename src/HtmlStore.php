<?php
declare(strict_types=1);

namespace Shugoi;

class HtmlStore
{
    private const MAX_TOKENS = 5000;
    private const MAX_MEMORY_BYTES = 64 * 1024 * 1024;

    private array $store = [];
    private int $memoryUsed = 0;
    private string $diskPath = '';
    private bool $ownsDiskPath = false;

    public function __construct(bool|string $diskPath = false)
    {
        if (is_string($diskPath)) {
            $this->diskPath = $diskPath;
            $this->ownsDiskPath = false;
            if (!is_dir($this->diskPath)) {
                mkdir($this->diskPath, 0700, true);
            }
        } elseif ($diskPath === true) {
            $this->diskPath = sys_get_temp_dir() . '/shugoi-render-' . bin2hex(random_bytes(4));
            $this->ownsDiskPath = true;
            if (!is_dir($this->diskPath)) {
                mkdir($this->diskPath, 0700, true);
            }
        }
    }

    public function store(
        string $token,
        string $html,
        int $ttlMs = 120_000,
        int $maxReads = -1,
        bool $contentReplace = false
    ): void {
        foreach ($this->store as $key => $entry) {
            if (microtime(true) >= $entry['expiresAt']) {
                $this->memoryUsed -= strlen($entry['html']);
                unset($this->store[$key]);
            }
        }
        while (count($this->store) >= self::MAX_TOKENS) {
            reset($this->store);
            $firstKey = key($this->store);
            if ($firstKey === null) break;
            $this->memoryUsed -= strlen($this->store[$firstKey]['html']);
            unset($this->store[$firstKey]);
        }
        $htmlLen = strlen($html);
        while ($this->memoryUsed + $htmlLen > self::MAX_MEMORY_BYTES && count($this->store) > 0) {
            reset($this->store);
            $firstKey = key($this->store);
            if ($firstKey === null) break;
            $this->memoryUsed -= strlen($this->store[$firstKey]['html']);
            unset($this->store[$firstKey]);
        }
        if ($this->diskPath) {
            $suffix = substr($token, -8);
            $this->atomicWrite("{$this->diskPath}/{$suffix}", $html);
        }
        $this->store[$token] = [
            'html' => $html,
            'expiresAt' => microtime(true) + ($ttlMs / 1000),
            'maxReads' => $maxReads,
            'readCount' => 0,
            'contentReplace' => $contentReplace,
        ];
        $this->memoryUsed += $htmlLen;
    }

    public function retrieve(string $token): ?array
    {
        $entry = $this->store[$token] ?? null;
        if (!$entry) {
            if ($this->diskPath) {
                $suffix = substr($token, -8);
                $path = "{$this->diskPath}/{$suffix}";
                if (file_exists($path)) {
                    $html = file_get_contents($path);
                    @unlink($path);
                    if ($html !== false && $html !== '') {
                        return ['html' => $html, 'found' => true];
                    }
                }
            }
            return null;
        }
        if (microtime(true) >= $entry['expiresAt']) {
            $this->remove($token);
            return null;
        }
        $entry['readCount']++;
        $this->store[$token]['readCount'] = $entry['readCount'];
        if ($entry['maxReads'] > 0 && $entry['readCount'] > $entry['maxReads']) {
            $this->remove($token);
            return null;
        }
        if ($entry['contentReplace']) {
            $html = $entry['html'];
            $this->remove($token);
            return ['html' => $html, 'found' => true];
        }
        return ['html' => $entry['html'], 'found' => true];
    }

    public function hasFreshToken(string $siteKey, bool $contentReplace = false): ?array
    {
        $now = microtime(true);
        foreach ($this->store as $token => $entry) {
            if ($now >= $entry['expiresAt']) continue;
            if ($entry['contentReplace'] !== $contentReplace) continue;
            if (str_starts_with($token, $siteKey . ':')) {
                return ['html' => $entry['html'], 'token' => $token];
            }
        }
        return null;
    }

    public function remove(string $token): void
    {
        if (isset($this->store[$token])) {
            $this->memoryUsed -= strlen($this->store[$token]['html']);
            unset($this->store[$token]);
        }
        if ($this->diskPath) {
            $suffix = substr($token, -8);
            $path = "{$this->diskPath}/{$suffix}";
            if (file_exists($path)) @unlink($path);
        }
    }

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content, LOCK_EX);
        rename($tmp, $path);
    }

    public function __destruct()
    {
        if ($this->ownsDiskPath && $this->diskPath && is_dir($this->diskPath)) {
            array_map('unlink', glob($this->diskPath . '/*') ?: []);
            @rmdir($this->diskPath);
        }
    }
}
