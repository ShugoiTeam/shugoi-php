<?php
declare(strict_types=1);

namespace Shugoi;

use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;

class ApiClient
{
    private ClientInterface $http;
    private TokenSigner $tokenSigner;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new GuzzleClient(['timeout' => 5]);
        $this->tokenSigner = new TokenSigner($config);
    }

    public function fetchWhitelist(?string $baseUrl = null): array
    {
        $cb = bin2hex(random_bytes(4));
        try {
            $sig = hash_hmac('sha256', $cb, $this->config->getSigningSecret());
        } catch (\RuntimeException) {
            $sig = '';
        }
        $url = ($baseUrl ?? $this->config->baseUrl) . '/whitelist?key=' . urlencode($this->config->siteKey) . '&cb=' . $cb . ($sig !== '' ? '&sig=' . $sig : '');
        $response = $this->http->request('GET', $url);
        $body = json_decode((string)$response->getBody(), true);
        if (!is_array($body)) throw new ShugoiError('unexpected_api_response', 'Whitelist API returned non-JSON');
        return $body;
    }

    public function fetchGuardDetect(?string $baseUrl = null): string
    {
        $url = ($baseUrl ?? $this->config->internalUrl) . '/guard-detect?key=' . urlencode($this->config->siteKey) . '&raw=1&cb=' . $this->guardCb();
        $response = $this->http->request('GET', $url);
        return (string)$response->getBody();
    }

    public function fetchGuard(?string $baseUrl = null): string
    {
        $url = ($baseUrl ?? $this->config->internalUrl) . '/guard?key=' . urlencode($this->config->siteKey) . '&raw=1&cb=' . $this->guardCb();
        $response = $this->http->request('GET', $url);
        return (string)$response->getBody();
    }
    private function guardCb(): string
    {
        $cb = bin2hex(random_bytes(4));
        try {
            $sig = hash_hmac('sha256', $cb, $this->config->getSigningSecret());
        } catch (\RuntimeException) {
            $sig = '';
        }
        return $cb . ($sig !== '' ? '&sig=' . $sig : '');
    }

    public function checkRateLimit(string $ip, array $metadata = []): array
    {
        $url = $this->config->baseUrl . '/rate-limit-check';
        $response = $this->http->request('POST', $url, [
            'json' => ['siteKey' => $this->config->siteKey, 'scope' => 'edge_ip', 'ip' => $ip, 'metadata' => $metadata],
        ]);
        $body = json_decode((string)$response->getBody(), true);
        return is_array($body) ? $body : ['allowed' => true];
    }

    public function validateKey(): array
    {
        $url = $this->config->baseUrl . '/validate-key';
        $response = $this->http->request('POST', $url, [
            'json' => ['siteKey' => $this->config->siteKey, 'secret' => $this->config->secret],
        ]);
        $body = json_decode((string)$response->getBody(), true);
        return is_array($body) ? $body : ['valid' => false];
    }

    public function sendEvent(string $type, array $data = []): void
    {
        $url = $this->config->baseUrl . '/event';
        try {
            $this->http->request('POST', $url, [
                'json' => array_merge(['siteKey' => $this->config->siteKey, 'reason' => $type], $data),
            ]);
        } catch (\Throwable $e) {
            if ($this->config->debug) error_log("Shugoi sendEvent failed: " . $e->getMessage());
        }
    }

    public function checkLicense(array $params): array
    {
        $url = $this->config->baseUrl . '/check';
        $response = $this->http->request('POST', $url, [
            'json' => array_merge(['siteKey' => $this->config->siteKey], $params),
            'timeout' => $params['timeout'] ?? 5,
        ]);
        $body = json_decode((string)$response->getBody(), true);
        return is_array($body) ? $body : ['error' => 'invalid_response'];
    }

    public function getConfig(): Config { return $this->config; }
}
