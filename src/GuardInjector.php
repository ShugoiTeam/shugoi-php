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
