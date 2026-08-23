<?php
declare(strict_types=1);

namespace Shugoi;

class CspBuilder
{
    private const DEFAULT_DIRECTIVES = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
        'connect-src' => ["'self'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'font-src' => ["'self'", 'data:'],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'frame-ancestors' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
    ];

    public function __construct(private readonly Config $config) {}

    public function build(): string
    {
        if (!$this->config->csp) return '';
        $directives = self::DEFAULT_DIRECTIVES;
        $apiOrigin = $this->config->apiOrigin();
        $shugoiOrigin = 'https://shugoi.com';

        foreach (['script-src', 'connect-src', 'style-src', 'font-src', 'img-src'] as $dir) {
            if ($apiOrigin && $apiOrigin !== $shugoiOrigin) {
                $directives[$dir][] = $apiOrigin;
            }
            if (!in_array($shugoiOrigin, $directives[$dir])) {
                $directives[$dir][] = $shugoiOrigin;
            }
        }
        // The inline skeleton carries an eval'd invisible payload (Obfuscator::invisibleEval),
        // so 'unsafe-eval' is required on pages where it is injected. The skeleton is only
        // injected inline when splitRender is enabled (Middleware); with splitRender=false no
        // inline eval'd script reaches the page, so we keep the stricter CSP without it.
        if (!$this->config->splitRender) {
            $directives['script-src'] = array_values(
                array_filter($directives['script-src'], fn($v) => $v !== "'unsafe-eval'")
            );
        }
        if ($this->config->extraDirectives) {
            foreach ($this->config->extraDirectives as $name => $values) {
                $directives[$name] ??= [];
                $directives[$name] = array_values(array_unique([...$directives[$name], ...$values]));
            }
        }
        return self::formatDirectives($directives);
    }

    public static function merge(?string $existing, string $added): string
    {
        $existingDirectives = $existing ? self::parseCsp($existing) : [];
        $addedDirectives = self::parseCsp($added);
        foreach ($addedDirectives as $name => $values) {
            if (!isset($existingDirectives[$name])) {
                $existingDirectives[$name] = $values;
            } else {
                $existingDirectives[$name] = array_values(array_unique([...$existingDirectives[$name], ...$values]));
            }
        }
        foreach ($existingDirectives as $name => $values) {
            if (in_array("'none'", $values, true) && count($values) > 1) {
                $existingDirectives[$name] = ["'none'"];
            }
        }
        return self::formatDirectives($existingDirectives);
    }

    private static function parseCsp(string $csp): array
    {
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $parts = preg_split('/\s+/', $part);
            $name = array_shift($parts);
            if ($name) $directives[$name] = $parts;
        }
        return $directives;
    }

    private static function formatDirectives(array $directives): string
    {
        $parts = [];
        foreach ($directives as $name => $values) {
            if (empty($values)) continue;
            $parts[] = $name . ' ' . implode(' ', $values);
        }
        return implode('; ', $parts);
    }
}
