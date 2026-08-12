<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Http\Request;
use Shugoi\HtmlStore;
use Shugoi\Config;
use Shugoi\TokenSigner;
use Shugoi\Pow;
use Shugoi\ConfigCache;
use Shugoi\Notice;

class ShugoiController
{
    public function __construct(
        private HtmlStore $htmlStore,
        private Config $config,
        private TokenSigner $tokenSigner,
        private Pow $pow,
        private ConfigCache $configCache,
    ) {}

    public function render(Request $request)
    {
        $token = (string)$request->query('token', '');
        $mid = (string)$request->query('mid', '');
        $grant = (string)$request->query('grant', '');
        $ip = $request->header('X-Forwarded-For', '') ?: (string)($request->server('REMOTE_ADDR', ''));
        $ip = trim(explode(',', $ip)[0]);

        $data = $this->renderData($token, $mid, $grant, $ip);

        $headers = [];
        if (isset($data['html'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, no-transform';
            $headers['Pragma'] = 'no-cache';
            $headers['Set-Cookie'] = $this->pow->sgAuthorizedCookie();
        }
        return response()->json($data, 200, $headers);
    }

    private function renderData(string $token, string $mid, string $grant, string $ip): array
    {
        $len = strlen($token);
        if ($len < 16 || $len > 300) return ['error' => 'not_found'];

        $tokSiteKey = explode(':', $token)[0];
        if ($tokSiteKey !== $this->config->siteKey) return ['error' => 'not_found'];

        $tokTs = (int)(explode(':', $token)[1] ?? '0');
        if ($tokTs > 0 && (int)(microtime(true) * 1000) - $tokTs > TokenSigner::TOKEN_TTL_MS) return ['error' => 'not_found'];

        if (!$this->tokenSigner->verifyRenderGrant($mid, $grant, $token, $ip, $this->config->siteKey)) {
            return ['error' => 'not_found'];
        }

        $contentReplaceOn = false;
        try {
            $cfg = $this->configCache->get($this->config->internalUrl);
            $contentReplaceOn = ($cfg['enableContentReplacementCheck'] ?? $cfg['detectionFlags']['enableContentReplacementCheck'] ?? null) === true;
        } catch (\Throwable) {
        }

        $entry = $this->htmlStore->retrieve($token);
        if ($entry !== null && !empty($entry['html'])) {
            return ['html' => $this->postProcessHtml($entry['html'], $mid)];
        }

        if (!$contentReplaceOn) {
            $fresh = $this->htmlStore->hasFreshToken($this->config->siteKey, true);
            if ($fresh !== null && !empty($fresh['html'])) {
                return ['html' => $this->postProcessHtml($fresh['html'], $mid)];
            }
        }

        return ['error' => 'not_found'];
    }

    private function postProcessHtml(string $html, string $mid): string
    {
        if ($mid !== '') {
            $html = Notice::inject($html, $mid, $this->config->siteKey, $this->config->baseUrl);
        }
        return Notice::injectReferrerPolicy($html);
    }
}
