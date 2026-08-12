<?php
declare(strict_types=1);

namespace Shugoi;

class BlockPage
{
    private const SITE = 'https://shugoi.com';

    public static function shield(string $locale, string $title, string $message, string $badge, ?string $host = null, ?int $remainingSeconds = null): string
    {
        $countdown = '';
        if ($remainingSeconds !== null) {
            $countdown = self::countdownScript($remainingSeconds);
        }
        $htmlLang = $locale;
        $htmlTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $htmlBadge = htmlspecialchars($badge, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $htmlDesc = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Compte à rebours : on enveloppe les secondes dans le message, sinon on ajoute
        // le span directement (le module Node met à jour #cd via un script dédié).
        if ($remainingSeconds !== null) {
            $replaced = preg_replace('/(\d+)s/', '<span id="sg-countdown">$1s</span>', $htmlDesc, 1, $n);
            if ($n > 0) {
                $htmlDesc = $replaced;
            } else {
                $htmlDesc .= ' <span id="sg-countdown">' . $remainingSeconds . 's</span>';
            }
        }

        $footer = $host !== null && $host !== ''
            ? '<footer>· ' . htmlspecialchars($host, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' · Shugoi</footer>'
            : '';

        $fontFace = "@font-face{font-family:'Alex Brush';src:url(https://shugoi.com/alex-brush.woff2?v=2) format('woff2');font-display:swap}";
        $cssBody = "*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html,body{height:100%;background:#fcf9f5}body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:1.2rem}";
        $cssCard = "#c{max-width:460px;width:100%;background:#fff;border:4px solid #000;border-radius:28px 6px 32px 10px;box-shadow:12px 12px 0 #000;padding:3rem 2.4rem 2.8rem;text-align:center}";
        $cssLogo = "#c .l{width:80px;height:80px;pointer-events:none;transform:rotate(-2.5deg);margin:0 auto .6rem;display:block}";
        $cssBrand = "#c .b{display:block;margin:0 auto .2rem;pointer-events:none;max-width:100%;height:auto}";
        $cssBdg = "#c .bdg{display:inline-block;border:2px solid #000;border-radius:10px 2px 14px 4px;padding:.3rem .9rem;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#E87090;margin-bottom:1.4rem}";
        $cssH2 = "#c h2{font-family:'Alex Brush',Georgia,\"Times New Roman\",serif;font-size:2.2rem;color:#E87090;font-weight:400;margin:0 auto .6rem}";
        $cssDesc = "#c p.desc{font-size:.9rem;color:#555;line-height:1.8;max-width:380px;margin:0 auto}";

        return '<!DOCTYPE html><html lang="' . $htmlLang . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . $htmlTitle . ' · Shugoi</title><style>'
            . $fontFace . $cssBody . $cssCard . $cssLogo . $cssBrand . $cssBdg . $cssH2 . $cssDesc
            . '</style></head><body><div id=c>'
            . '<img src=https://shugoi.com/favicon.png alt class=l><img src=https://shugoi.com/brand.png alt class=b>'
            . '<div class=bdg>' . $htmlBadge . '</div>'
            . '<h2>' . $htmlTitle . '</h2>'
            . '<p class=desc>' . $htmlDesc . '</p>'
            . $footer
            . $countdown
            . '</div></body></html>';
    }

    private static function countdownScript(int $totalSeconds): string
    {
        return '<script>var s=' . $totalSeconds . ';var i=setInterval(function(){s--;var e=document.getElementById("sg-countdown");if(e){if(s<=0){e.innerHTML="0s";clearInterval(i);setTimeout(function(){location.reload()},500)}else{e.innerHTML=s+"s"}}},1000)</script>';
    }

    public static function blocked(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        return self::shield($locale, Locales::get($locale, 'blockedTitle'), Locales::get($locale, 'tamperBody'), Locales::get($locale, 'blockedBadge'), $ctx['host'] ?? null);
    }

    public static function rateLimit(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        $remaining = $ctx['remainingSeconds'] ?? 60;
        return self::shield($locale, Locales::get($locale, 'rateLimitTitle'), Locales::get($locale, 'rateLimitBody', self::formatRemaining($remaining)), Locales::get($locale, 'rateLimitBadge'), $ctx['host'] ?? null, $remaining);
    }

    public static function headless(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        return self::shield($locale, Locales::get($locale, 'blockedTitle'), Locales::get($locale, 'devtoolsBody'), Locales::get($locale, 'blockedBadge'), $ctx['host'] ?? null);
    }

    /** Format du temps restant — parité exacte avec core.ts (timeStr). */
    private static function formatRemaining(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        if ($mins > 0) {
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ($secs > 0 ? ' ' . $secs . ' s' : '');
        }
        return $secs . ' seconde' . ($secs > 1 ? 's' : '');
    }
}
