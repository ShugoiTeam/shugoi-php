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
        if ($this->tokenSigner->verify($token) === null) return ['error' => 'not_found'];
        if (!$this->tokenSigner->verifyRenderGrant($mid, $grant, $token, $ip, $this->config->siteKey)) return ['error' => 'not_found'];
        $entry = $this->htmlStore->consume($token);
        if ($entry !== null && $entry['html'] !== '') return ['html' => $this->postProcess($entry['html'], $mid)];
        return ['error' => 'not_found'];
    }

    private function postProcess(string $html, string $mid): string
    {
        if ($mid !== '') $html = Notice::inject($html, $mid, $this->config->siteKey, $this->config->baseUrl);
        return Notice::injectReferrerPolicy($html);
    }
}
