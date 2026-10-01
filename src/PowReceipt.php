<?php
declare(strict_types=1);

namespace Shugoi;

/** Admission receipts are issued only after a live WebSocket proof succeeds. */
final class PowReceipt
{
    public function __construct(private readonly Config $config) {}

    public function issue(string $ip, string $ua): string
    {
        $payload = time() . '.' . bin2hex(random_bytes(16)) . '.' . hash('sha256', $ip . "\n" . $ua);
        return 'v1.' . $payload . '.' . $this->signature($payload);
    }

    public function consume(string $receipt, string $ip, string $ua): bool
    {
        if (!preg_match('/\Av1\.([0-9]{10})\.([0-9a-f]{32})\.([0-9a-f]{64})\.([0-9a-f]{64})\z/', $receipt, $parts)) return false;
        [, $timestamp, $nonce, $binding, $signature] = $parts;
        if (time() - (int)$timestamp > 60 || (int)$timestamp > time() + 5) return false;
        if (!hash_equals(hash('sha256', $ip . "\n" . $ua), $binding)) return false;
        try {
            if (!hash_equals($this->signature($timestamp . '.' . $nonce . '.' . $binding), $signature)) return false;
        } catch (\RuntimeException) {
            return false;
        }

        // Exclusive creation is atomic across PHP-FPM workers; an in-memory set is not.
        $directory = $this->config->powReceiptStorePath
            ?? sys_get_temp_dir() . '/shugoi-pow-' . hash('sha256', $this->config->siteKey);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return false;
        $path = $directory . '/' . hash('sha256', $receipt) . '.used';
        $handle = @fopen($path, 'x');
        if ($handle === false) return false;
        fclose($handle);
        @chmod($path, 0600);
        // Bounded opportunistic cleanup; a receipt is invalid before its claim is removed.
        $checked = 0;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (++$checked > 128) break;
            if ($entry->isFile() && preg_match('/\A[0-9a-f]{64}\.used\z/', $entry->getFilename())
                && $entry->getMTime() < time() - 120) @unlink($entry->getPathname());
        }
        return true;
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', 'pow-ws-receipt:' . $this->config->siteKey . ':' . str_replace('.', ':', $payload), $this->config->getSigningSecret());
    }
}
