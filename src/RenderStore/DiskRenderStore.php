<?php
declare(strict_types=1);

namespace Shugoi\RenderStore;

/** Shared, atomic, one-shot-capable render storage for FPM and a WS sidecar. */
final class DiskRenderStore implements RenderStoreInterface
{
    public function __construct(private readonly string $directory)
    {
        if ($directory === '') throw new \InvalidArgumentException('Render store directory is required');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Shugoi render store directory');
        }
        if (!is_writable($directory)) throw new \RuntimeException('Shugoi render store directory is not writable');
        @chmod($directory, 0700);
    }

    public function store(string $token, string $html, int $ttlMs = 120_000, int $maxReads = -1, bool $contentReplace = false): void
    {
        $entry = [
            'token' => $token,
            'html' => $html,
            'expiresAt' => microtime(true) + max(1, $ttlMs) / 1000,
            'maxReads' => $maxReads,
            'readCount' => 0,
            'contentReplace' => $contentReplace,
        ];
        $this->writeEntry($this->entryPath($token), $entry);
    }

    public function retrieve(string $token): ?array
    {
        $path = $this->entryPath($token);
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) return null;
        try {
            if (!flock($lock, LOCK_EX)) return null;
            $entry = $this->readEntry($path, $token);
            if ($entry === null) return null;
            if ((float)$entry['expiresAt'] <= microtime(true)) {
                @unlink($path);
                return null;
            }
            $entry['readCount'] = (int)$entry['readCount'] + 1;
            if ((int)$entry['maxReads'] > 0 && $entry['readCount'] > $entry['maxReads']) {
                @unlink($path);
                return null;
            }
            $result = ['html' => (string)$entry['html'], 'found' => true, 'contentReplace' => (bool)$entry['contentReplace']];
            if (!empty($entry['contentReplace'])) {
                @unlink($path);
            } else {
                $this->writeEntry($path, $entry);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function hasFreshToken(string $siteKey, bool $contentReplace = false): ?array
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $entry = $raw === false ? null : json_decode($raw, true);
            if (!is_array($entry) || (float)($entry['expiresAt'] ?? 0) <= microtime(true)) {
                @unlink($path);
                continue;
            }
            $token = $entry['token'] ?? '';
            if (!is_string($token) || !str_starts_with($token, $siteKey . ':')) continue;
            if ((bool)($entry['contentReplace'] ?? false) !== $contentReplace) continue;
            if (!isset($entry['html']) || !is_string($entry['html']) || $entry['html'] === '') continue;
            return ['html' => $entry['html'], 'token' => $token];
        }
        return null;
    }

    public function remove(string $token): void
    {
        @unlink($this->entryPath($token));
    }

    public function isCrossProcessSafe(): bool
    {
        return true;
    }

    private function entryPath(string $token): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.json';
    }

    private function readEntry(string $path, string $token): ?array
    {
        $raw = @file_get_contents($path);
        $entry = $raw === false ? null : json_decode($raw, true);
        if (!is_array($entry) || !hash_equals($token, (string)($entry['token'] ?? ''))) return null;
        return $entry;
    }

    private function writeEntry(string $path, array $entry): void
    {
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new \RuntimeException('Unable to encode Shugoi render entry');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write Shugoi render entry');
        }
        @chmod($path, 0600);
    }
}
