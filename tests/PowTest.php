<?php
namespace Shugoi\Tests;

use PHPUnit\Framework\TestCase;
use Shugoi\Config;
use Shugoi\Pow;

class PowTest extends TestCase
{
    private function makePow(int $difficulty = 10): Pow
    {
        return new Pow(new Config([
            'siteKey' => 'sg_sk_test_abc',
            'secret' => 'test_secret',
            'powDifficulty' => $difficulty,
        ]));
    }

    private function solvePow(Pow $pow, int $difficulty): string
    {
        $ts = time();
        $nonce = bin2hex(random_bytes(8));
        $salt = hash_hmac('sha256', $ts . ':' . $nonce, $pow->secret());
        $n = 0;
        while (true) {
            $digest = hash('sha256', $salt . ':' . dechex($n));
            if ($pow->leadingZeroBits($digest) >= $difficulty) {
                return $ts . ':' . $nonce . ':' . dechex($n);
            }
            $n++;
        }
    }

    public function test_leading_zero_bits_corrected_counting(): void
    {
        $pow = $this->makePow();
        $this->assertEquals(2, $pow->leadingZeroBits('3' . str_repeat('0', 63)));
        $this->assertEquals(1, $pow->leadingZeroBits('7' . str_repeat('0', 63)));
        $this->assertEquals(0, $pow->leadingZeroBits('8' . str_repeat('0', 63)));
        $this->assertEquals(4, $pow->leadingZeroBits('0f' . str_repeat('0', 62)));
    }

    public function test_valid_proof_accepted(): void
    {
        $pow = $this->makePow(10);
        $proof = $this->solvePow($pow, 10);
        $this->assertTrue($pow->isValid($proof));
    }

    public function test_invalid_proof_rejected(): void
    {
        $pow = $this->makePow(10);
        $this->assertFalse($pow->isValid('123:deadbeef'));
        $this->assertFalse($pow->isValid('notaproof'));
        $this->assertFalse($pow->isValid(''));
    }

    public function test_expired_proof_rejected(): void
    {
        $pow = $this->makePow(4);
        $ts = time() - 3600; // vieille de 1h (> TTL 60 s)
        $salt = hash_hmac('sha256', (string)$ts, $pow->secret());
        $digest = hash('sha256', $salt . ':0');
        $n = 0;
        while ($pow->leadingZeroBits($digest) < 4) {
            $n++;
            $digest = hash('sha256', $salt . ':' . dechex($n));
        }
        $this->assertFalse($pow->isValid($ts . ':' . dechex($n)));
    }

    public function test_sg_ok_cookie_and_validation(): void
    {
        $pow = $this->makePow(10);
        $proof = $this->solvePow($pow, 10);
        $cookie = $pow->sgOkCookie($proof);
        $this->assertNotNull($cookie);
        $this->assertStringContainsString('__sg_ok=', $cookie);
        $this->assertStringContainsString('Max-Age=2592000', $cookie);
        $value = $pow->sgOkValue();
        $this->assertTrue($pow->isSgOkValid($value));
        $this->assertFalse($pow->isSgOkValid('1:invalid'));
        $this->assertFalse($pow->isSgOkValid(''));
    }

    public function test_sg_ok_cookie_null_with_invalid_proof(): void
    {
        $pow = $this->makePow(10);
        $this->assertNull($pow->sgOkCookie('bogus'));
    }

    public function test_sg_authorized_cookie_and_validation(): void
    {
        $pow = $this->makePow(10);
        $value = $pow->sgAuthorizedValue();
        $this->assertTrue($pow->isSgAuthorizedValid($value));
        $this->assertFalse($pow->isSgAuthorizedValid('tampered'));
        $cookie = $pow->sgAuthorizedCookie();
        $this->assertStringContainsString('__sg_authorized=', $cookie);
        $this->assertStringContainsString('Max-Age=120', $cookie);
    }
}
