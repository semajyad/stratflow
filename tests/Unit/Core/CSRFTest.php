<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\CSRF;
use StratFlow\Core\Session;

/**
 * CSRFTest
 *
 * Tests token generation (non-empty, correct length) and validation
 * (correct token passes, wrong token fails, empty token fails).
 * Uses an in-memory fake Session to avoid real session overhead.
 */
class CSRFTest extends TestCase
{
    // ===========================
    // FAKE SESSION
    // ===========================

    /**
     * Build a lightweight in-memory Session substitute that doesn't
     * call session_start() so tests are CLI-safe.
     */
    private function makeSession(): Session
    {
        // Session::__construct calls session_start() only if no session is active.
        // We suppress that by using a partial mock that overrides the constructor side-effects
        // and delegates get/set to a real in-memory store.

        $store = [];

        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set'])
            ->getMock();

        $session->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$store): void {
                $store[$key] = $value;
            });

        $session->method('get')
            ->willReturnCallback(function (string $key, mixed $default = null) use (&$store): mixed {
                return $store[$key] ?? $default;
            });

        return $session;
    }

    // ===========================
    // TOKEN GENERATION
    // ===========================

    #[Test]
    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $csrf  = new CSRF($this->makeSession());
        $token = $csrf->generateToken();

        $this->assertNotEmpty($token);
    }

    #[Test]
    public function testGenerateTokenIs64HexChars(): void
    {
        // bin2hex(random_bytes(32)) produces 64 hex characters
        $csrf  = new CSRF($this->makeSession());
        $token = $csrf->generateToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function testGenerateTokenReturnsDifferentValuesEachCall(): void
    {
        $csrf   = new CSRF($this->makeSession());
        $token1 = $csrf->generateToken();
        $token2 = $csrf->generateToken();

        // Statistically impossible for two random 32-byte tokens to collide
        $this->assertNotSame($token1, $token2);
    }

    // ===========================
    // TOKEN VALIDATION
    // ===========================

    #[Test]
    public function testValidateTokenReturnsTrueForCorrectToken(): void
    {
        $csrf  = new CSRF($this->makeSession());
        $token = $csrf->generateToken();

        $this->assertTrue($csrf->validateToken($token));
    }

    #[Test]
    public function testValidateTokenReturnsFalseForWrongToken(): void
    {
        $csrf = new CSRF($this->makeSession());
        $csrf->generateToken();

        $this->assertFalse($csrf->validateToken('not_the_right_token'));
    }

    #[Test]
    public function testValidateTokenReturnsFalseForEmptyToken(): void
    {
        $csrf = new CSRF($this->makeSession());
        $csrf->generateToken();

        $this->assertFalse($csrf->validateToken(''));
    }

    #[Test]
    public function testValidateTokenReturnsFalseWhenNoTokenGenerated(): void
    {
        $csrf = new CSRF($this->makeSession());

        $this->assertFalse($csrf->validateToken('anything'));
    }

    // ===========================
    // GET TOKEN
    // ===========================

    #[Test]
    public function testGetTokenGeneratesTokenIfNoneExists(): void
    {
        $csrf  = new CSRF($this->makeSession());
        $token = $csrf->getToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
    }

    #[Test]
    public function testGetTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $csrf   = new CSRF($this->makeSession());
        $first  = $csrf->getToken();
        $second = $csrf->getToken();

        $this->assertSame($first, $second);
    }
}
