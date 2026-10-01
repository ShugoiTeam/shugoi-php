<?php
declare(strict_types=1);

namespace Shugoi;

final class MetadataOnly
{
    public const MAX_BYTES = 1024 * 1024;

    public static function extract(string $html, string $requestUrl = ''): string
    {
        if ($html === '' || strlen($html) > self::MAX_BYTES) return '';
        if (!preg_match('~<head(?:\s[^>]*)?>([\s\S]*?)</head\s*>~i', $html, $headMatch)) return '';
        $head = $headMatch[1];
        $entries = [];

        if (preg_match('~<title(?:\s[^>]*)?>([\s\S]*?)</title\s*>~i', $head, $match)) {
            $entries[] = '<title>' . self::escape(self::decode($match[1])) . '</title>';
        }

        if (preg_match_all('~<meta\b([^>]*)>~i', $head, $matches)) {
            foreach ($matches[1] as $attrs) {
                $name = self::attribute($attrs, 'name');
                $property = self::attribute($attrs, 'property');
                $content = self::attribute($attrs, 'content');
                $key = $property ?? $name;
                if ($key === null || $content === null || !preg_match('/^(description|og:[a-z0-9:_-]+|twitter:[a-z0-9:_-]+)$/i', $key)) continue;
                $attrName = $property !== null ? 'property' : 'name';
                $entries[] = '<meta ' . $attrName . '="' . self::escape(self::decode($key)) . '" content="' . self::escape(self::decode($content)) . '">';
            }
        }

        if (preg_match_all('~<link\b([^>]*)>~i', $head, $matches)) {
            foreach ($matches[1] as $attrs) {
                $rel = self::attribute($attrs, 'rel');
                $href = self::attribute($attrs, 'href');
                if ($rel !== null && $href !== null && in_array('canonical', preg_split('/\s+/', strtolower($rel)), true)) {
                    $entries[] = '<link rel="canonical" href="' . self::escape(self::decode($href) ?: $requestUrl) . '">';
                }
            }
        }

        if ($entries === []) return '';
        return '<!doctype html><html><head>' . implode('', $entries) . '</head><body></body></html>';
    }

    public static function fallback(string $canonical, string $title = 'Protected application'): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'
            . self::escape($title) . '</title><link rel="canonical" href="'
            . self::escape($canonical) . '"></head><body></body></html>';
    }

    private static function attribute(string $source, string $key): ?string
    {
        $pattern = '~(?:^|\s)' . preg_quote($key, '~') . '\s*=\s*(["\'])([\s\S]*?)\1~i';
        return preg_match($pattern, $source, $match) ? $match[2] : null;
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
