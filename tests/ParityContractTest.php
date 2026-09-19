<?php
declare(strict_types=1);

namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\SkeletonGenerator;
use Shugoi\TokenSigner;

final class ParityContractTest extends TestCase
{
    private function skeleton(string $locale = 'fr'): string
    {
        $config = new Config(['siteKey' => 'sg_sk_test_parity', 'secret' => 'test-secret']);
        return (new SkeletonGenerator(new TokenSigner($config)))->generate(
            token: 'sg_sk_test_parity:1722000000000:nonce12345678:sig',
            guards: ['detect' => '', 'guard' => ''],
            config: ['supportEmail' => 'support@example.test', 'detectionFlags' => []],
            restrictedAccess: false,
            locale: $locale,
            baseUrl: 'https://shugoi.com/api/v1',
        );
    }

    public function testGeneratedContractKeepsShowBlockArgumentOrder(): void
    {
        $script = $this->skeleton();

        $this->assertStringContainsString('function(msg,title,badge)', $script);
        $this->assertStringContainsString('d.message,d.title', $script);
    }

    public function testRestrictedFallbackContainsSupportAndMachineIdInstruction(): void
    {
        $script = $this->skeleton();

        $this->assertStringContainsString('support@example.test', $script);
        $this->assertStringContainsString('machineId', $script);
        $this->assertStringContainsString('Accès restreint', $script);
    }

    public function testSuccessfulRenderCleansTemporaryGlobalsWithoutReloadLoop(): void
    {
        $script = $this->skeleton();

        $this->assertStringContainsString('function _sgCl()', $script);
        $this->assertStringContainsString('_i.indexOf("__sg")===0', $script);
        $this->assertStringNotContainsString('location.reload()', $script);
        $this->assertStringNotContainsString('stableStrDevice', $script);
    }

    public function testEnglishFallbackUsesEnglishLabels(): void
    {
        $script = $this->skeleton('en');

        $this->assertStringContainsString('Restricted Access', $script);
        $this->assertStringContainsString('Service temporarily unavailable', $script);
    }
}
