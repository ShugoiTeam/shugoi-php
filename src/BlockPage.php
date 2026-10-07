<?php
declare(strict_types=1);

namespace Shugoi;

class BlockPage
{
    private const SITE = 'https://shugoi.com';

    public static function shield(string $locale, string $title, string $message, string $badge, ?string $host = null, ?int $remainingSeconds = null, string $userAgent = ''): string
    {
        $countdown = '';
        if ($remainingSeconds !== null) {
            $countdown = self::countdownScript($remainingSeconds);
        }
        $htmlLang = $locale;
        $htmlTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $htmlBadge = htmlspecialchars($badge, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $htmlDesc = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

        [$lightBackground, $darkBackground] = self::nativePagePalette($userAgent);
        $fontFace = "@font-face{font-family:'Reggae One';src:url(https://shugoi.com/reggae-one.woff2) format('woff2');font-display:swap}";
        $cssBody = "*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html,body{height:100%;background:" . $lightBackground . "}body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:1.2rem}";
        $cssCard = "#c{max-width:460px;width:100%;background:#fffdfa;border:1px solid rgba(43,33,29,.16);border-radius:16px 5px 16px 5px;box-shadow:0 10px 30px rgba(43,33,29,.08);padding:3rem 2.4rem 2.8rem;text-align:center}";
        $cssLogo = "#c .l{width:80px;height:80px;pointer-events:none;filter:drop-shadow(2px 4px 8px rgba(231,112,144,.55));margin:0 auto .6rem;display:block}";
        $cssBrand = "#c .b{display:block;margin:0 auto .2rem;pointer-events:none;max-width:100%;height:auto}";
        $cssBdg = "#c .bdg{display:inline-block;background:#fdf0f4;border:1px solid rgba(194,84,111,.35);border-radius:10px 4px 10px 4px;padding:.3rem .9rem;font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.16em;color:#a83d5a;margin-bottom:1.4rem}";
        $cssH2 = "#c h2{font-family:'Reggae One',Georgia,\"Times New Roman\",serif;font-size:2.2rem;color:#a83d5a;font-weight:400;margin:0 auto .6rem}";
        $cssDesc = "#c p.desc{font-size:.9rem;color:#7a6a62;line-height:1.8;max-width:380px;margin:0 auto}#c footer{font-size:.55rem;color:#a83d5a;margin-top:1.8rem}";
        $cssDark = "@media(prefers-color-scheme:dark){html,body{background:" . $darkBackground . "}#c{background:#241a30;border-color:rgba(241,232,245,.14);box-shadow:0 10px 30px rgba(0,0,0,.4)}#c .bdg{background:rgba(233,137,159,.16);border-color:rgba(233,137,159,.5);color:#e9899f}#c h2,#c footer{color:#e9899f}#c p.desc{color:#a795b4}}";

        return '<!DOCTYPE html><html lang="' . $htmlLang . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>'
            . $htmlTitle . ' · Shugoi</title><style>'
            . $fontFace . $cssBody . $cssCard . $cssLogo . $cssBrand . $cssBdg . $cssH2 . $cssDesc . $cssDark
            . '</style></head><body><div id=c>'
            . '<img src=https://shugoi.com/favicon-block.png alt class=l><img src=https://shugoi.com/brand-block.png alt=Shugoi class=b>'
            . '<div class=bdg>' . $htmlBadge . '</div>'
            . '<h2>' . $htmlTitle . '</h2>'
            . '<p class=desc>' . $htmlDesc . '</p>'
            . $footer
            . $countdown
            . '</div></body></html>';
    }

    private static function countdownScript(int $totalSeconds): string
    {
        return '<script>var s=' . $totalSeconds . ';var i=setInterval(function(){s--;var e=document.getElementById("sg-countdown");if(e){if(s<=0){e.innerHTML="0s";clearInterval(i)}else{e.innerHTML=s+"s"}}},1000)</script>';
    }

    public static function blocked(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        return self::shield($locale, Locales::get($locale, 'blockedTitle'), Locales::get($locale, 'tamperBody'), Locales::get($locale, 'blockedBadge'), $ctx['host'] ?? null, null, (string)($ctx['ua'] ?? ''));
    }

    public static function rateLimit(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        $remaining = $ctx['remainingSeconds'] ?? 60;
        return self::shield($locale, Locales::get($locale, 'rateLimitTitle'), Locales::get($locale, 'rateLimitBody', self::formatRemaining($remaining)), Locales::get($locale, 'rateLimitBadge'), $ctx['host'] ?? null, $remaining, (string)($ctx['ua'] ?? ''));
    }

    public static function headless(array $ctx): string
    {
        $locale = $ctx['locale'] ?? 'en';
        return self::shield($locale, Locales::get($locale, 'blockedTitle'), Locales::get($locale, 'devtoolsBody'), Locales::get($locale, 'blockedBadge'), $ctx['host'] ?? null, null, (string)($ctx['ua'] ?? ''));
    }

    /** @return array{0:string,1:string} */
    private static function nativePagePalette(string $userAgent): array
    {
        if (preg_match('/Edg\//', $userAgent)) return ['#f6f6f6', '#2d2d2d'];
        if (preg_match('/Firefox\//', $userAgent)) return ['#f9f9fb', '#2b2a33'];
        if (preg_match('/AppleWebKit/', $userAgent) && !preg_match('/Chrome|Chromium|Edg\/|OPR\//', $userAgent)) return ['#f6f6f6', 'rgb(30,30,30)'];
        if (preg_match('/OPR\//', $userAgent)) return ['#eef3f7', '#101214'];
        return ['#fff', '#202124'];
    }
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
