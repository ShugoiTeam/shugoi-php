<?php
declare(strict_types=1);

namespace Shugoi\Laravel;

final class LivewireNavigation
{
    private const SCRIPT = <<<'JS'
document.addEventListener('livewire:navigate', function (event) {
    if (event.defaultPrevented || !event.detail || !event.detail.url) return;
    var destination;
    try { destination = new URL(event.detail.url, location.href); } catch (_) { return; }
    if (destination.origin !== location.origin) return;
    event.preventDefault();
    if (event.detail.history) location.replace(destination.href);
    else location.assign(destination.href);
});
JS;

    public static function inject(string $html): string
    {
        // A split-render skeleton requires a fresh document/guard lifecycle.
        // Keep Livewire component requests, but prevent its SPA document swap.
        if (str_contains($html, 'data-shugoi-livewire-navigation')) return $html;
        $script = '<script data-shugoi-livewire-navigation>' . self::SCRIPT . '</script>';
        $position = stripos($html, '</head>');
        if ($position === false) $position = stripos($html, '</body>');
        if ($position === false) return $html;
        return substr_replace($html, $script, $position, 0);
    }
}
