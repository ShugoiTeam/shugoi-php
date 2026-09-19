<?php
declare(strict_types=1);

namespace Shugoi;

final class RenderService
{
    public function __construct(
        private readonly Config $config,
        private readonly HtmlStore $htmlStore,
        private readonly TokenSigner $tokenSigner,
        private readonly ConfigCache $configCache,
    ) {}

    public function render(string $token, string $mid, string $grant, string $ip): array
    {
        if (strlen($token) < 16 || strlen($token) > 300) return ['error' => 'not_found'];
        if (explode(':', $token)[0] !== $this->config->siteKey) return ['error' => 'not_found'];
        $tokTs = (int)(explode(':', $token)[1] ?? '0');
        if ($tokTs > 0 && (int)(microtime(true) * 1000) - $tokTs > TokenSigner::TOKEN_TTL_MS) return ['error' => 'not_found'];
        if (!$this->tokenSigner->verifyRenderGrant($mid, $grant, $token, $ip, $this->config->siteKey)) return ['error' => 'not_found'];

        $contentReplaceOn = false;
        try {
            $cfg = $this->configCache->get($this->config->internalUrl);
            $contentReplaceOn = ($cfg['enableContentReplacementCheck'] ?? $cfg['detectionFlags']['enableContentReplacementCheck'] ?? null) === true;
        } catch (\Throwable) {}

        $entry = $this->htmlStore->retrieve($token);
        if ($entry !== null && !empty($entry['html'])) return ['html' => $this->postProcess($entry['html'], $mid)];
        if (!$contentReplaceOn) {
            $fresh = $this->htmlStore->hasFreshToken($this->config->siteKey, true);
            if ($fresh !== null && !empty($fresh['html'])) return ['html' => $this->postProcess($fresh['html'], $mid)];
            $diskPath = '/tmp/shugoi-render-shared';
            if (is_dir($diskPath)) foreach (glob($diskPath . '/*') ?: [] as $file) {
                $html = @file_get_contents($file);
                if ($html !== false && $html !== '') return ['html' => $this->postProcess($html, $mid)];
            }
        }
        return ['error' => 'not_found'];
    }

    private function postProcess(string $html, string $mid): string
    {
        if ($mid !== '') $html = Notice::inject($html, $mid, $this->config->siteKey, $this->config->baseUrl);
        return Notice::injectReferrerPolicy($html);
    }
}
