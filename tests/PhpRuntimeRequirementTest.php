<?php
namespace Shugoi\Tests;

use Composer\Semver\Semver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpRuntimeRequirementTest extends TestCase
{
    #[DataProvider('runtimeVersions')]
    public function testComposerRequiresPatchedPhp(string $version, bool $allowed): void
    {
        $files = [dirname(__DIR__) . '/composer.json'];
        $demo = dirname(__DIR__) . '/demo/websocket-test-site/composer.json';
        if (is_file($demo)) {
            $files[] = $demo;
        }

        foreach ($files as $file) {
            $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(
                $allowed,
                Semver::satisfies($version, $manifest['require']['php']),
                basename(dirname($file)) . ': PHP ' . $version,
            );
        }
    }

    public static function runtimeVersions(): iterable
    {
        // A single >=8.2.35 constraint would wrongly accept vulnerable 8.3/8.4/8.5.
        foreach (['8.1.35', '8.2.0', '8.2.34', '8.3.0', '8.3.35', '8.4.0',
                  '8.4.26', '8.5.0', '8.5.11', '8.2.35RC1', '8.3.36RC1',
                  '8.4.27RC1', '8.5.12RC1'] as $version) {
            yield 'reject ' . $version => [$version, false];
        }

        foreach (['8.2.35', '8.2.36', '8.3.36', '8.3.37', '8.4.27', '8.4.28',
                  '8.5.12', '8.5.13', '8.6.0'] as $version) {
            yield 'accept ' . $version => [$version, true];
        }

        yield 'unsupported major' => ['9.0.0', false];
    }
}
