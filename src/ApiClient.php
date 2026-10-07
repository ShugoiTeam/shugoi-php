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
        // The API accepts a signed Unix timestamp in milliseconds (60-second TTL).
        $cb = (string)(int)floor(microtime(true) * 1000);
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

    /** Relay the browser's signed WLC query through the same-origin WS sidecar. */
    public function relayWlc(string $query, array $headers = []): string
    {
        if (strlen($query) < 2 || strlen($query) > 24_576 || $query[0] !== '?' || preg_match('/[\r\n]/', $query)) {
            throw new ShugoiError('invalid_wlc_request', 'Invalid WLC query');
        }
        parse_str(substr($query, 1), $params);
        if (!isset($params['mid'], $params['raw'], $params['key'])) {
            throw new ShugoiError('invalid_wlc_request', 'WLC query is missing required fields');
        }

        $allowed = ['origin', 'user-agent', 'sec-ch-ua', 'sec-ch-ua-platform', 'sec-ch-ua-mobile', 'x-real-ip', 'x-forwarded-for', 'x-forwarded-proto', 'cookie'];
        $forward = ['accept' => 'application/json', 'x-shugoi-ws-relay' => '1'];
        foreach ($allowed as $name) {
            $value = $headers[$name] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 4096 && !preg_match('/[\r\n]/', $value)) {
                $forward[$name] = $value;
            }
        }
        $response = $this->http->request('GET', rtrim($this->config->baseUrl, '/') . '/wlc' . $query, [
            'headers' => $forward,
            'timeout' => 8,
            'http_errors' => false,
        ]);
        $body = (string)$response->getBody();
        if (strlen($body) > 16_384) throw new ShugoiError('wlc_response_too_large', 'WLC response exceeded the limit');
        return $body;
    }
    private function guardCb(): string
    {
        // The API accepts a signed Unix timestamp in milliseconds (60-second TTL).
        $cb = (string)(int)floor(microtime(true) * 1000);
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
            'json' => ['siteKey' => $this->config->siteKey, 'secret' => $this->config->getSigningSecret()],
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
