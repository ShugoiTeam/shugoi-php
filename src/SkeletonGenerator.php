<?php
declare(strict_types=1);
namespace Shugoi;
class SkeletonGenerator
{
    public function __construct(
        private readonly TokenSigner $tokenSigner,
        private readonly ?Obfuscator $obfuscator = null,
    ) {}

    public function generate(
        string $token,
        array $guards,
        array $config,
        bool $restrictedAccess,
        string $locale,
        string $baseUrl,
        string $renderUrl = './__shugoi/render',
        string $siteKey = ''
    ): string {
        $flags = $config['detectionFlags'] ?? [];
        $msgs = Locales::getAll($locale);

        $fragments = [];
        $fragments[] = 'window.__sg_siteKey=' . json_encode($siteKey, JSON_UNESCAPED_UNICODE);
        $fragments[] = 'window.__sg_baseUrl=' . json_encode($baseUrl, JSON_UNESCAPED_UNICODE);
        $fragments[] = 'window.__sg_config=' . json_encode($flags);
        $fragments[] = 'window.__sg_diagEnabled=' . ($this->isProduction() ? 'false' : 'true');
        $fragments[] = "try{if((location.search||'').indexOf('sg_proof=')>=0){var _qs=location.search.replace(/[?&]sg_proof=[^&]*/,'');var _cu=location.pathname+(_qs?_qs:'')+location.hash;history.replaceState(null,'',_cu)}}catch(e){}";
        $powTs = time();
        $powSecret = $this->tokenSigner->secret();
        $powSalt = $powSecret !== '' ? hash_hmac('sha256', (string)$powTs, $powSecret) : '';
        $powDiff = (int)($config['powDifficulty'] ?? 14);
        $fragments[] = 'window.__sg_pow=' . json_encode(['ts' => $powTs, 'salt' => $powSalt, 'difficulty' => $powDiff]);
        $nowMs = (int)(microtime(true) * 1000);
        $fragments[] = 'window.__sg_ntp=' . $nowMs;
        $fragments[] = 'window.__sg_serverTime=' . $nowMs;
        $fragments[] = 'window.__sg_clockts=' . $nowMs;
        if (!$restrictedAccess) {
            $fragments[] = 'window.__sg_disableRestrictedAccess=true';
        }
        if (!empty($guards['detect'])) {
            $fragments[] = 'try{' . $guards['detect'] . '}catch(e){window.__sg_blocked=true}';
        }

        $fragments[] = $this->showBlockFragment($msgs);
        $fragments[] = 'var t=' . json_encode($token);
        $fragments[] = 'window.__sg_token=' . json_encode($token);
        $fragments[] = 'var k=' . json_encode($siteKey);
        $fragments[] = 'var b=' . json_encode($baseUrl);
        $fragments[] = 'var r=' . json_encode($renderUrl);
        $fragments[] = $this->rdFragment($msgs);
        $fragments[] = "_gw(function(){rd(r+\"?token=\"+t,0);setTimeout(_sgCl,1500)})";
        $fragments[] = $this->cleanupFragment();

        $combined = implode(';', $fragments);
        $combined = preg_replace('#</(script|style)#i', '<\\\\/$1', $combined);

        if ($this->obfuscator) {
            $combined = $this->obfuscator->obfuscate($combined, $siteKey);
            $combined = $this->obfuscator->invisibleEval($combined, $siteKey . '_e0');
        }

        return '<script>' . $combined . '</script>';
    }

    private function showBlockFragment(array $msgs): string
    {
        $fbBadge = self::jsStr($msgs['blockedBadge']);
        $fbTitle = self::jsStr($msgs['blockedTitle']);
        return 'window.__sg_showBlock=function(msg,title,badge){var h='
            . '"<head><meta charset=UTF-8><meta name=viewport content=width=device-width,initial-scale=1><style>'
            . "@font-face{font-family:\\x27Alex Brush\\x27;src:url(https://shugoi.com/alex-brush.woff2?v=2) format(\\x27woff2\\x27);font-display:swap}"
            . '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html,body{height:100%;background:#fcf9f5}'
            . "body{font-family:system-ui,-apple-system,\\x27Segoe UI\\x27,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:1.2rem}"
            . '#c{max-width:460px;width:100%;background:#fff;border:4px solid #000;border-radius:28px 6px 32px 10px;box-shadow:12px 12px 0 #000;padding:3rem 2.4rem 2.8rem;text-align:center}'
            . '#c .l{width:80px;height:80px;pointer-events:none;transform:rotate(-2.5deg);margin:0 auto .6rem;display:block}'
            . '#c .b{display:block;margin:0 auto .2rem;pointer-events:none;max-width:100%;height:auto}'
            . '#c .bdg{display:inline-block;border:2px solid #000;border-radius:10px 2px 14px 4px;padding:.3rem .9rem;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#E87090;margin-bottom:1.4rem}'
            . "h2{font-family:\\x27Alex Brush\\x27,Georgia,\\x27Times New Roman\\x27,serif;font-size:2.2rem;color:#E87090;font-weight:400;margin:0 auto .6rem}"
            . '#c p.desc{font-size:.9rem;color:#555;line-height:1.8;max-width:380px;margin:0 auto}'
            . '#c p.ft{font-size:.55rem;color:#E87090;margin-top:1.8rem}'
            . '@media (prefers-color-scheme:dark){html,body{background:#16101c}'
            . '#c{background:#241a30;border-color:rgba(241,232,245,.14);box-shadow:0 10px 30px rgba(0,0,0,.4)}'
            . '#c .bdg{background:rgba(233,137,159,.16);border-color:rgba(233,137,159,.5);color:#e9899f}'
            . '#c h2{color:#e9899f}'
            . '#c p.desc{color:#a795b4}'
            . '#c p.ft{color:#e9899f}}'
            . '</style></head><body><div id=c><img src=https://shugoi.com/favicon-block.png class=l>'
            . '<img src=https://shugoi.com/brand-block.png class=b><div class=bdg>"+(badge||"' . $fbBadge . '")+"</div>'
            . '<h2>"+(title||"' . $fbTitle . '")+"</h2><p class=desc>"+(msg||"")+"</p>'
            . '<p class=ft>"+location.hostname+" \\u00b7 Shugoi</p></div></body>";document.documentElement.innerHTML=h}';
    }

    private function rdFragment(array $msgs): string
    {
        $devtools = self::jsStr($msgs['devtoolsBody']);
        $tamperTitle = self::jsStr($msgs['tamperTitle']);
        return 'var _gw=function(cb){if(window.__sg_guardsReady||window.__sg_blocked)cb();else setTimeout(function(){_gw(cb)},100)};'
            . 'function rd(p,n){if(window.__sg_blocked)return;if(!document.body)return setTimeout(function(){rd(p,n)},50);'
            . 'if(n>6){if((window.__sg_config||{}).enableContentReplacementCheck===true)window.__sg_showBlock&&window.__sg_showBlock("' . $devtools . '","' . $tamperTitle . '");return}'
            . 'var _g=(window.__sg_grant||"");if(_g){p=p+("&grant="+encodeURIComponent(_g))}'
            . 'var _m=(window.__sg_detectMid||window.__sg_mid||"");if(_m){p=p+("&mid="+encodeURIComponent(_m))}'
            . 'fetch(p).then(function(x){return x.json()}).then(function(d){if(window.__sg_blocked)return;'
            . 'if(!document.body)return setTimeout(function(){rd(p,n+1)},50);'
            . 'if(d.html){document.open("text/html");document.write(d.html);document.close();window.scrollTo(0,0)}'
            . 'if(d.blocked){window.__sg_showBlock&&window.__sg_showBlock(d.message,d.title)}'
            . 'if(d.error){if((window.__sg_config||{}).enableContentReplacementCheck===true)window.__sg_showBlock&&window.__sg_showBlock("' . $devtools . '","' . $tamperTitle . '")}'
            . 'else if(!d.html&&!d.blocked){setTimeout(function(){rd(p,n+1)},300)}})'
            . '.catch(function(){setTimeout(function(){rd(p,n+1)},300)})}';
    }

    private function cleanupFragment(): string
    {
        return 'function _sgCl(){try{for(var _i in window){if(_i.indexOf("__sg")===0){window[_i]=null;delete window[_i]}}'
            . 'window._sgLogCP=function(){};window.midHex=function(){};window.rd=function(){};window._gw=function(){};'
            . 'window.applyDecision=function(){};window._D=function(){};window.z=function(f){return f()}}catch(_e){}}';
    }
    private static function jsStr(string $s): string
    {
        return str_replace('<', '\\x3c', substr(json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1));
    }

    private function isProduction(): bool
    {
        $env = $_SERVER['APP_ENV'] ?? $_SERVER['NODE_ENV'] ?? null;
        return $env === 'production';
    }
}
