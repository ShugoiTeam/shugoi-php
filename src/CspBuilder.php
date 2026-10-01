<?php
declare(strict_types=1);

namespace Shugoi;

class CspBuilder
{
    private const DEFAULT_DIRECTIVES = [
        'default-src' => ["'self'"],
        // Pas d''unsafe-eval' : la couche "invisible eval" (U+E0000) est
        // temporairement retirée (crash WebKit/Safari, voir SkeletonGenerator).
        'script-src' => ["'self'", "'unsafe-inline'"],
        'connect-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
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
        foreach (array_unique(array_filter([$apiOrigin, $shugoiOrigin])) as $origin) {
            if (str_starts_with($origin, 'https://')) {
                $directives['connect-src'][] = 'wss://' . substr($origin, 8);
            } elseif (str_starts_with($origin, 'http://')) {
                $directives['connect-src'][] = 'ws://' . substr($origin, 7);
            }
        }
        $powEndpoint = parse_url($this->config->powWebSocketUrl);
        if (is_array($powEndpoint) && isset($powEndpoint['scheme'], $powEndpoint['host'])
            && in_array($powEndpoint['scheme'], ['http', 'https', 'ws', 'wss'], true)) {
            $scheme = in_array($powEndpoint['scheme'], ['https', 'wss'], true) ? 'wss' : 'ws';
            $directives['connect-src'][] = $scheme . '://' . $powEndpoint['host']
                . (isset($powEndpoint['port']) ? ':' . $powEndpoint['port'] : '');
        }
        // La couche invisible-eval étant temporairement retirée (crash WebKit,
        // voir SkeletonGenerator), plus besoin d''unsafe-eval' — le CSP reste
        // strict même avec splitRender activé.
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
