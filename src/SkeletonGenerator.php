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
        string $siteKey = '',
        bool $renderWebSocket = false
    ): string {
        $flags = $config['detectionFlags'] ?? [];
        $msgs = Locales::getAll($locale);

        $fragments = [];
        $fragments[] = 'window.__sg_siteKey=' . json_encode($siteKey, JSON_UNESCAPED_UNICODE);
        $fragments[] = 'window.__sg_baseUrl=' . json_encode($baseUrl, JSON_UNESCAPED_UNICODE);
        if ($renderWebSocket) {
            $fragments[] = 'window.__sg_renderBaseUrl=(location.origin||location.protocol+"//"+location.host)+"/api/v1"';
            $fragments[] = 'window.__sg_wsBaseUrl=window.__sg_renderBaseUrl';
            $fragments[] = 'window.__sg_baseUrl=window.__sg_renderBaseUrl';
            $fragments[] = 'try{var _sgWsBase=new URL(window.__sg_wsBaseUrl,location.href);var _sgPre=new WebSocket((_sgWsBase.protocol==="https:"?"wss://":"ws://")+_sgWsBase.host+_sgWsBase.pathname.replace(/\\/$/,"")+"/ws-wlc");window.__sg_wlcPreSocket=_sgPre}catch(_e){}';
        }
        $fragments[] = 'window.__sg_config=' . json_encode($flags);
        $fragments[] = 'window.__sg_token=' . json_encode($token);
        $fragments[] = 'window.__sg_renderUrl=' . json_encode($renderUrl);
        $fragments[] = 'window.__sg_transportMessages=' . json_encode([
            'title' => $msgs['serviceUnavailableTitle'],
            'body' => $msgs['renderFailedBody'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $fragments[] = BrowserTransport::script();
        $fragments[] = 'window.__sg_diagEnabled=' . ($this->isProduction() ? 'false' : 'true');
        $fragments[] = "try{if((location.search||'').indexOf('sg_proof=')>=0){var _qs=location.search.replace(/[?&]sg_proof=[^&]*/,'');var _cu=location.pathname+(_qs?_qs:'')+location.hash;history.replaceState(null,'',_cu)}}catch(e){}";
        $powTs = time();
        $powSecret = $this->tokenSigner->secret();
        $powNonce = bin2hex(random_bytes(8));
        $powSalt = $powSecret !== '' ? hash_hmac('sha256', $powTs . ':' . $powNonce, $powSecret) : '';
        $powDiff = (int)($config['powDifficulty'] ?? 14);
        $fragments[] = 'window.__sg_pow=' . json_encode(['ts' => $powTs, 'nonce' => $powNonce, 'salt' => $powSalt, 'difficulty' => $powDiff]);
        $nowMs = (int)(microtime(true) * 1000);
        $fragments[] = 'window.__sg_ntp=' . $nowMs;
        $fragments[] = 'window.__sg_serverTime=' . $nowMs;
        $fragments[] = 'window.__sg_clockts=' . $nowMs;
        if (!$restrictedAccess) {
            $fragments[] = 'window.__sg_disableRestrictedAccess=true';
        }
        if (!empty($guards['detect'])) {
            $fragments[] = '__shugoi_remote_guard__()';
        }

        $fragments[] = $this->showBlockFragment($msgs, (string)($config['supportEmail'] ?? 'support@shugoi.com'));
        $fragments[] = 'var t=' . json_encode($token);
        $fragments[] = 'window.__sg_token=' . json_encode($token);
        $fragments[] = 'var k=' . json_encode($siteKey);
        $fragments[] = 'var b=' . json_encode($baseUrl);
        $fragments[] = 'var r=' . json_encode($renderUrl);
        $fragments[] = $this->rdFragment($msgs, $renderWebSocket);
        $fragments[] = "_gw(function(){rd(r+\"?token=\"+t,0);setTimeout(_sgCl,1500)})";
        $fragments[] = $this->cleanupFragment();

        $combined = implode(';', $fragments);
        $combined = preg_replace('#</(script|style)#i', '<\\\\/$1', $combined);

        if ($this->obfuscator) {
            $combined = $this->obfuscator->obfuscate($combined, $siteKey);
            // ⚠️ Couche "invisible eval" (U+E0000, Obfuscator::invisibleEval)
            // TEMPORAIREMENT RETIRÉE : elle crash WebKit/Safari (bootcode affiché
            // en <pre> → page morte), confirmé 2× côté SDK Node. Le code est
            // conservé pour réactivation future — voir AGENTS.md.
            // $combined = $this->obfuscator->invisibleEval($combined, $siteKey . '_e0');
        }

        // The API guard already contains its own build/rotation. Rewriting its
        // function bodies breaks workers created from Function#toString: they
        // cannot access this document's string decoder in their separate realm.
        // Escape document tags too: outer HTML asset injectors must not rewrite
        // an error-page string inside the JavaScript payload.
        if (!empty($guards['detect'])) {
            $guard = preg_replace('~</([a-z][a-z0-9-]*)~i', '<\\\\/$1', $guards['detect']);
            $combined = str_replace('__shugoi_remote_guard__()', 'try{' . $guard . '}catch(e){window.__sg_blocked=true}', $combined);
        }

        return '<script>' . $combined . '</script>';
    }

    private function showBlockFragment(array $msgs, string $supportEmail): string
    {
        $toJs = static fn(string $value): string => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $support = htmlspecialchars($supportEmail !== '' ? $supportEmail : 'support@shugoi.com', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $restrictedBody = sprintf($msgs['restrictedBody'], '<strong>' . $support . '</strong>');
        return 'window.__sg_showBlock=function(msg,title,badge){'
            . 'if((msg==="Accès restreint"||msg==="Restricted Access")&&/n\\\'est pas autorisé|not authorized/i.test(String(title||""))){var _sgMid=(window.__sg_detectMid||window.__sg_mid||"");msg=' . $toJs($restrictedBody) . '+( _sgMid?" <code style=\\"font-size:.7rem\\">"+_sgMid+"</code>":"");title=' . $toJs($msgs['restrictedTitle']) . '}'
            . 'var _ua=navigator.userAgent||"",_light="#fff",_dark="#202124";'
            . 'if(/Edg\\//.test(_ua)){_light="#f6f6f6";_dark="#2d2d2d"}'
            . 'else if(/Firefox\\//.test(_ua)){_light="#f9f9fb";_dark="#2b2a33"}'
            . 'else if(/AppleWebKit/.test(_ua)&&!/Chrome|Chromium|Edg\\/|OPR\\//.test(_ua)){_light="#f6f6f6";_dark="rgb(30,30,30)"}'
            . 'else if(/OPR\\//.test(_ua)){_light="#eef3f7";_dark="#101214"}'
            . 'var css=\'@font-face{font-family:"Reggae One";src:url(https://shugoi.com/reggae-one.woff2) format("woff2");font-display:swap}*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html,body{height:100%;background:\'+_light+\'}body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:1.2rem}#c{max-width:460px;width:100%;background:#fffdfa;border:1px solid rgba(43,33,29,.16);border-radius:16px 5px 16px 5px;box-shadow:0 10px 30px rgba(43,33,29,.08);padding:3rem 2.4rem 2.8rem;text-align:center}#c .l{width:80px;height:80px;pointer-events:none;filter:drop-shadow(2px 4px 8px rgba(231,112,144,.55));margin:0 auto .6rem;display:block}#c .b{display:block;margin:0 auto .2rem;pointer-events:none;max-width:100%;height:auto}#c .bdg{display:inline-block;background:#fdf0f4;border:1px solid rgba(194,84,111,.35);border-radius:10px 4px 10px 4px;padding:.3rem .9rem;font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.16em;color:#a83d5a;margin-bottom:1.4rem}#c h2{font-family:"Reggae One",Georgia,serif;font-size:2.2rem;color:#a83d5a;font-weight:400;margin:0 auto .6rem}#c p.desc{font-size:.9rem;color:#7a6a62;line-height:1.8;max-width:380px;margin:0 auto}#c p.ft{font-size:.55rem;color:#a83d5a;margin-top:1.8rem}@media(prefers-color-scheme:dark){html,body{background:\'+_dark+\'}#c{background:#241a30;border-color:rgba(241,232,245,.14);box-shadow:0 10px 30px rgba(0,0,0,.4)}#c .bdg{background:rgba(233,137,159,.16);border-color:rgba(233,137,159,.5);color:#e9899f}#c h2,#c p.ft{color:#e9899f}#c p.desc{color:#a795b4}}</style>\';'
            . 'var h="<head><meta charset=UTF-8><meta name=viewport content=width=device-width,initial-scale=1><meta name=color-scheme content=\"light dark\"><style>"+css+"</style></head><body><div id=c><img src=https://shugoi.com/favicon-block.png class=l><img src=https://shugoi.com/brand-block.png class=b><div class=bdg>"+(badge||' . $toJs($msgs['blockedBadge']) . ')+"</div><h2>"+(title||' . $toJs($msgs['blockedTitle']) . ')+"</h2><p class=desc>"+(msg||"")+"</p><p class=ft>"+location.hostname+" · Shugoi</p></div></body>";document.documentElement.innerHTML=h}';
    }

    private function rdFragment(array $msgs, bool $renderWebSocket = false): string
    {
        $devtools = self::jsStr($msgs['devtoolsBody']);
        $tamperTitle = self::jsStr($msgs['tamperTitle']);
        if ($renderWebSocket) {
            return 'var _gw=function(cb){if(window.__sg_guardsReady||window.__sg_blocked)cb();else setTimeout(function(){_gw(cb)},100)};'
                . 'function rd(p,n){if(window.__sg_blocked)return;if(!document.body)return setTimeout(function(){rd(p,n)},50);'
                . 'if(n>6){window.__sg_showBlock&&window.__sg_showBlock("' . self::jsStr($msgs['renderFailedBody']) . '","' . self::jsStr($msgs['serviceUnavailableTitle']) . '");return}'
                . 'var _g=window.__sg_grant||"",_m=window.__sg_detectMid||window.__sg_mid||"";if(!_g||!_m)return setTimeout(function(){rd(p,n+1)},100);'
                . 'var _done=false,_retrying=false,_w=null,_to=setTimeout(function(){try{if(_w)_w.close()}catch(_e){}again()},6500);function again(){if(_done||_retrying)return;_retrying=true;clearTimeout(_to);setTimeout(function(){rd(p,n+1)},300)}'
                . 'try{var _u=new URL("/__shugoi/render/ws",location.href);_u.protocol=location.protocol==="https:"?"wss:":"ws:";_w=new WebSocket(_u.href);'
                . '_w.onopen=function(){try{_w.send(JSON.stringify({token:t,mid:_m,grant:_g}))}catch(_e){_w.close()}};'
                . '_w.onmessage=function(e){if(_done||window.__sg_blocked)return;_done=true;clearTimeout(_to);var h=String(e.data);if(!/^\\s*<!doctype|^\\s*<html/i.test(h)){window.__sg_showBlock&&window.__sg_showBlock("' . self::jsStr($msgs['renderFailedBody']) . '","' . self::jsStr($msgs['serviceUnavailableTitle']) . '");return}window.__sg_renderDone=true;try{delete window.__sg_disableRestrictedAccess}catch(_e){}document.open("text/html");document.write(h);document.close();window.scrollTo(0,0)};'
                . '_w.onerror=function(){try{_w.close()}catch(_e){again()}};_w.onclose=function(){again()}}catch(_e){again()}}';
        }
        return 'var _gw=function(cb){if(window.__sg_guardsReady||window.__sg_blocked)cb();else setTimeout(function(){_gw(cb)},100)};'
            . 'function rd(p,n){if(window.__sg_blocked||window.__sg_renderStarted||window.__sg_renderDone)return;if(!document.body)return setTimeout(function(){rd(p,n)},50);'
            . 'if(n>6){if((window.__sg_config||{}).enableContentReplacementCheck===true)window.__sg_showBlock&&window.__sg_showBlock("' . $devtools . '","' . $tamperTitle . '");return}'
            . 'var _g=(window.__sg_grant||"");if(_g){p=p+("&grant="+encodeURIComponent(_g))}'
            . 'var _m=(window.__sg_detectMid||window.__sg_mid||"");if(_m){p=p+("&mid="+encodeURIComponent(_m))}'
            . 'fetch(p).then(function(x){return x.json()}).then(function(d){if(window.__sg_blocked)return;'
            . 'if(!document.body)return setTimeout(function(){rd(p,n+1)},50);'
            . 'if(d.html){document.open("text/html");document.write(d.html);document.close();window.scrollTo(0,0)}'
            . 'if(d.blocked){window.__sg_showBlock&&window.__sg_showBlock(d.message,d.title)}'
            . 'if(d.error){if((window.__sg_config||{}).enableContentReplacementCheck===true)window.__sg_showBlock&&window.__sg_showBlock("' . $devtools . '","' . $tamperTitle . '")}'
            . 'else if(!d.html&&!d.blocked){setTimeout(function(){rd(p,n+1)},300)}})'
            . '.catch(function(){window.__sg_showBlock&&window.__sg_showBlock("' . self::jsStr($msgs['renderFailedBody']) . '","' . self::jsStr($msgs['serviceUnavailableTitle']) . '")})}';
    }

    private function cleanupFragment(): string
    {
        return 'function _sgCl(){if(window.__sg_wsMux||typeof window.__sg_wlcRequest==="function")return;try{for(var _i in window){if(_i.indexOf("__sg")===0){window[_i]=null;delete window[_i]}}'
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
