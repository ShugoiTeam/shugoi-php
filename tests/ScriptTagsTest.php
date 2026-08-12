<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\ScriptTags;
use Shugoi\Config;
use Shugoi\TokenSigner;
use Shugoi\GuardCache;
use Shugoi\ConfigCache;
use Shugoi\ApiClient;

class ScriptTagsTest extends TestCase
{
    private ScriptTags $scriptTags;

    protected function setUp(): void
    {
        $config = new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'internalUrl' => 'http://127.0.0.1:3098/v1',
        ]);

        $tokenSigner = new TokenSigner($config);

        $apiMock = $this->createMock(ApiClient::class);
        $apiMock->method('getConfig')->willReturn($config);
        $apiMock->method('fetchGuardDetect')->willReturn('console.log("detect");');
        $apiMock->method('fetchGuard')->willReturn('console.log("guard");');
        $apiMock->method('fetchWhitelist')->willReturn([
            'whitelistedMachines' => [],
            'detectionFlags' => [],
            'skipPaths' => [],
        ]);

        $guardCache = new GuardCache($apiMock);
        $configCache = new ConfigCache($apiMock);

        $this->scriptTags = new ScriptTags($config, $tokenSigner, $guardCache, $configCache);
    }

    public function test_generate_returns_expected_keys(): void
    {
        $result = $this->scriptTags->generate();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('guardDetect', $result);
        $this->assertArrayHasKey('guard', $result);
        $this->assertArrayHasKey('whitelistConfig', $result);
        $this->assertArrayHasKey('token', $result);
    }

    public function test_guardDetect_contains_script(): void
    {
        $result = $this->scriptTags->generate();

        $this->assertStringContainsString('<script>', $result['guardDetect']);
        $this->assertStringContainsString('window.__sg_siteKey=', $result['guardDetect']);
        $this->assertStringNotContainsString('eval(', $result['guardDetect']);
    }

    public function test_token_is_non_empty_string(): void
    {
        $result = $this->scriptTags->generate();

        $this->assertIsString($result['token']);
        $this->assertNotEmpty($result['token']);
    }

    public function test_guard_is_empty_string(): void
    {
        $result = $this->scriptTags->generate();

        $this->assertSame('', $result['guard']);
    }
}
