<?php
declare(strict_types=1);

namespace Shugoi;

class HtmlStore
{
    private const MAX_TOKENS = 5000;
    private const MAX_MEMORY_BYTES = 64 * 1024 * 1024;
    private const MAX_DISK_BYTES = 90 * 1024 * 1024;

    private array $store = [];
    private int $memoryUsed = 0;
    private string $diskPath = '';

    public function __construct(bool|string $diskPath = false)
    {
        if ($diskPath === false) return;
        // A stable directory is necessary across independent PHP-FPM requests.
        // Applications should supply their own private, shared cache directory.
        $this->diskPath = is_string($diskPath) ? rtrim($diskPath, '/\\') : sys_get_temp_dir()
            . '/shugoi-render-php-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'local');
        if ($this->diskPath === '' || is_link($this->diskPath)
            || (!is_dir($this->diskPath) && !@mkdir($this->diskPath, 0700, true) && !is_dir($this->diskPath))
            || !is_writable($this->diskPath)) {
            throw new \RuntimeException('Cannot initialize Shugoi render storage');
        }
    }

    public function store(
        string $token,
        string $html,
        int $ttlMs = 120_000,
        int $maxReads = -1,
        bool $contentReplace = false
    ): void {
        $htmlLen = strlen($html);
        if ($htmlLen > self::MAX_MEMORY_BYTES) throw new \LengthException('Shugoi render document is too large');
        $this->forget($token);
        $now = microtime(true);
        foreach ($this->store as $key => $entry) {
            if ($now >= $entry['expiresAt']) $this->forget($key);
        }
        while (count($this->store) >= self::MAX_TOKENS || $this->memoryUsed + $htmlLen > self::MAX_MEMORY_BYTES) {
            $firstKey = array_key_first($this->store);
            if ($firstKey === null) break;
            $this->forget($firstKey);
        }
        $entry = [
            'html' => $html,
            'expiresAt' => $now + ($ttlMs / 1000),
            'maxReads' => $maxReads,
            'readCount' => 0,
            'contentReplace' => $contentReplace,
        ];
        if ($this->diskPath !== '') {
            $this->pruneDisk();
            $this->atomicWrite($this->path($token), $this->encode($token, $entry), $entry['expiresAt']);
        }
        $this->store[$token] = $entry;
        $this->memoryUsed += $htmlLen;
    }

    public function retrieve(string $token): ?array
    {
        if ($this->diskPath !== '') return $this->readDisk($token, false);
        $entry = $this->store[$token] ?? null;
        if (!$entry) return null;
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

    /** Claim the exact document once, including across independent workers. */
    public function consume(string $token): ?array
    {
        if ($this->diskPath !== '') return $this->readDisk($token, true);
        $entry = $this->store[$token] ?? null;
        $this->forget($token);
        if ($entry === null || microtime(true) >= $entry['expiresAt']
            || ($entry['maxReads'] > 0 && $entry['readCount'] >= $entry['maxReads'])) return null;
        return ['html' => $entry['html'], 'found' => true];
    }

    public function hasFreshToken(string $siteKey, bool $contentReplace = false): ?array
    {
        $now = microtime(true);
        foreach ($this->store as $token => $entry) {
            if ($now >= $entry['expiresAt']) continue;
            if ($entry['contentReplace'] !== $contentReplace) continue;
            if ($this->diskPath !== '' && !is_file($this->path($token))) continue;
            if (str_starts_with($token, $siteKey . ':')) {
                return ['html' => $entry['html'], 'token' => $token];
            }
        }
        return null;
    }

    public function remove(string $token): void
    {
        $this->forget($token);
        if ($this->diskPath !== '') @unlink($this->path($token));
    }

    private function forget(string $token): void
    {
        if (isset($this->store[$token])) {
            $this->memoryUsed -= strlen($this->store[$token]['html']);
            unset($this->store[$token]);
        }
    }

    private function path(string $token): string
    {
        return $this->diskPath . '/' . hash('sha256', $token) . '.json';
    }

    private function encode(string $token, array $entry): string
    {
        // Base64 preserves arbitrary response bytes without PHP deserialization.
        $entry['htmlBase64'] = base64_encode($entry['html']);
        unset($entry['html']);
        return json_encode(['version' => 1, 'tokenHash' => hash('sha256', $token)] + $entry, JSON_THROW_ON_ERROR);
    }

    private function decode(string $token, string $content): ?array
    {
        $entry = json_decode($content, true, 16);
        if (!is_array($entry) || ($entry['version'] ?? null) !== 1
            || !is_string($entry['tokenHash'] ?? null) || !hash_equals(hash('sha256', $token), $entry['tokenHash'])
            || (!is_float($entry['expiresAt'] ?? null) && !is_int($entry['expiresAt'] ?? null))
            || !is_finite((float)$entry['expiresAt']) || microtime(true) >= $entry['expiresAt']
            || !is_int($entry['maxReads'] ?? null) || !is_int($entry['readCount'] ?? null) || $entry['readCount'] < 0
            || !is_bool($entry['contentReplace'] ?? null) || !is_string($entry['htmlBase64'] ?? null)) return null;
        $html = base64_decode($entry['htmlBase64'], true);
        if ($html === false || strlen($html) > self::MAX_MEMORY_BYTES) return null;
        $entry['html'] = $html;
        unset($entry['version'], $entry['tokenHash'], $entry['htmlBase64']);
        return $entry;
    }

    private function readDisk(string $token, bool $consume): ?array
    {
        // Disk is authoritative: another worker may already have consumed it.
        $this->forget($token);
        $path = $this->path($token);
        $claim = $path . '.claimed.' . bin2hex(random_bytes(16));
        if (!@rename($path, $claim)) return null;
        try {
            if (is_link($claim)) return null;
            $size = @filesize($claim);
            if ($size === false || $size > self::MAX_DISK_BYTES) return null;
            $content = @file_get_contents($claim);
            $entry = $content === false ? null : $this->decode($token, $content);
            if ($entry === null) return null;
            ++$entry['readCount'];
            if ($entry['maxReads'] > 0 && $entry['readCount'] > $entry['maxReads']) return null;
            if (!$consume && !$entry['contentReplace']
                && ($entry['maxReads'] <= 0 || $entry['readCount'] < $entry['maxReads'])) {
                $this->atomicWrite($path, $this->encode($token, $entry), $entry['expiresAt']);
            }
            return ['html' => $entry['html'], 'found' => true];
        } finally {
            @unlink($claim);
        }
    }

    private function atomicWrite(string $path, string $content, float $expiresAt): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(16));
        $handle = @fopen($tmp, 'x+b');
        if ($handle === false) throw new \RuntimeException('Cannot write Shugoi render storage');
        try {
            @chmod($tmp, 0600);
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write Shugoi render storage');
            }
            fclose($handle);
            $handle = null;
            @touch($tmp, (int)ceil($expiresAt));
            if (!@rename($tmp, $path)) throw new \RuntimeException('Cannot publish Shugoi render storage');
        } finally {
            if (is_resource($handle)) fclose($handle);
            @unlink($tmp);
        }
    }

    private function pruneDisk(): void
    {
        $checked = 0;
        foreach (new \DirectoryIterator($this->diskPath) as $file) {
            if ($file->isDot()) continue;
            if (++$checked > 64) break;
            if ($file->isLink() || !preg_match('/\A[a-f0-9]{64}\.json(?:\.claimed\.[a-f0-9]{32})?\z/D', $file->getFilename())) continue;
            if ($file->getMTime() <= time()) @unlink($file->getPathname());
        }
    }
}
