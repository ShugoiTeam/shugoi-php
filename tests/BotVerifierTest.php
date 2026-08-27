<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\BotVerifier;

class BotVerifierTest extends TestCase
{
    public function test_unknown_ua_returns_null(): void
    {
        $verifier = new BotVerifier();
        $this->assertNull($verifier->verify('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', '1.2.3.4'));
    }

    public function test_googlebot_no_ip_returns_false(): void
    {
        $verifier = new BotVerifier();
        $this->assertFalse($verifier->verify('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ''));
    }

    public function test_cache_hit_returns_cached_result(): void
    {
        $verifier = new BotVerifier();
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $ip = '66.249.66.1';

        $ref = new \ReflectionClass(BotVerifier::class);
        $cacheProp = $ref->getProperty('cache');
        $cacheKey = md5($ua . ':' . $ip);
        $cacheProp->setValue(null, [$cacheKey => ['result' => true, 'time' => time()]]);

        $this->assertTrue($verifier->verify($ua, $ip));
    }

    public function test_verify_bingbot_performs_ptr_check(): void
    {
        $verifier = $this->getMockBuilder(BotVerifier::class)
            ->onlyMethods(['verifyPtr'])
            ->getMock();

        $verifier->expects($this->once())
            ->method('verifyPtr')
            ->with('123.123.123.123', ['.search.msn.com'])
            ->willReturn(true);

        $this->assertTrue($verifier->verify('Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)', '123.123.123.123'));
    }

    public function test_verify_ptr_returns_false_when_dns_fails(): void
    {
        $verifier = $this->getMockBuilder(BotVerifier::class)
            ->onlyMethods(['verifyPtr'])
            ->getMock();

        $verifier->expects($this->once())
            ->method('verifyPtr')
            ->willReturn(false);

        $this->assertFalse($verifier->verify('Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', '5.255.253.100'));
    }

    public function test_cache_expires_after_ttl(): void
    {
        $verifier = new BotVerifier();
        $ua = 'Mozilla/5.0 (compatible; DuckDuckBot-Https/1.1; https://duckduckgo.com/duckduckbot)';
        $ip = '20.191.45.212';

        $ref = new \ReflectionClass(BotVerifier::class);
        $cacheProp = $ref->getProperty('cache');
        $cacheKey = md5($ua . ':' . $ip);
        $cacheProp->setValue(null, [$cacheKey => ['result' => true, 'time' => time() - 7200]]);

        $verifier2 = $this->getMockBuilder(BotVerifier::class)
            ->onlyMethods(['verifyPtr'])
            ->getMock();

        $verifier2->expects($this->once())
            ->method('verifyPtr')
            ->willReturn(false);

        $this->assertFalse($verifier2->verify($ua, $ip));
    }
}
