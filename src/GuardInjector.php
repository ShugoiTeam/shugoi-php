<?php
declare(strict_types=1);
namespace Shugoi;

class GuardInjector
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenSigner $tokenSigner,
        private readonly HtmlStore $htmlStore,
        private readonly GuardCache $guardCache,
        private readonly ConfigCache $configCache,
        private readonly ?SkeletonGenerator $skeletonGenerator = null,
    ) {}

    public function injectSkipNavigationGuard(string $html, array $skipPaths): string
    {
        if (!preg_match('/<(?:!doctype\s+html|html\b)/i', $html) || str_contains($html, 'data-shugoi-skip-navigation')) return $html;
        $paths = json_encode(array_values($skipPaths), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = '<script data-shugoi-skip-navigation>(function(s){function skip(p){return s.indexOf(p)>=0}function leave(u){try{var n=new URL(u,location.href);if(n.origin===location.origin&&!skip(n.pathname)){location.assign(n.href);return true}}catch(e){}return false}var p=history.pushState,r=history.replaceState;history.pushState=function(){if(arguments.length>2&&leave(arguments[2]))return;return p.apply(this,arguments)};history.replaceState=function(){if(arguments.length>2&&leave(arguments[2]))return;return r.apply(this,arguments)};window.addEventListener("popstate",function(){if(!skip(location.pathname))location.reload()});document.addEventListener("click",function(e){if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;var n=e.target;while(n&&n.nodeType===1&&n.tagName!=="A")n=n.parentNode;if(!n||n.tagName!=="A"||n.target&&n.target!=="_self"||n.hasAttribute("download"))return;if(leave(n.href))e.preventDefault()},true)})('.$paths.');</script>';
        return preg_replace('/<\/body\s*>/i', $script . '</body>', $html, 1) ?? ($html . $script);
    }

    public function inject(string $html, string $path, string $ua, string $ip, string $host, ?string $acceptLanguage = null, string $renderUrl = ''): string
    {
        $guards = $this->guardCache->get();
        $whitelistConfig = $this->configCache->get($this->config->internalUrl);
        $whitelistConfig['powDifficulty'] = $this->config->powDifficulty;

        $baseUrl = $this->config->baseUrl;
        $locale = LocaleResolver::resolve($this->config->locale, $acceptLanguage);

        $ts = (int)(microtime(true) * 1000);
        $token = $this->tokenSigner->sign($ts);

        $configScript = '';
        if (!$this->config->restrictedAccess) {
            $configScript = '<script>window.__sg_disableRestrictedAccess=true</script>';
        }

        $injectedHtml = $html;
        if ($configScript !== '') {
            $injectedHtml = str_replace('</head>', $configScript . "\n</head>", $injectedHtml);
        }

        $this->htmlStore->store($token, $injectedHtml, 120_000, 1, true);

        $skeletonGen = $this->skeletonGenerator ?? new SkeletonGenerator($this->tokenSigner);
        $renderUrl = $renderUrl ?: $baseUrl . '/__shugoi/render';
        $skeleton = $skeletonGen->generate($token, $guards, $whitelistConfig, $this->config->restrictedAccess, $locale, $baseUrl, $renderUrl, $this->config->siteKey);

        return $skeleton;
    }
}
