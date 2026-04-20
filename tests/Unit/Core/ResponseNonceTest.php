<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Response;

#[CoversClass(Response::class)]
class ResponseNonceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static nonce between tests via reflection
        $prop = new \ReflectionProperty(Response::class, 'nonce');
        $prop->setValue(null, '');
    }

    public function testGetNonceReturnsNonEmptyString(): void
    {
        $nonce = Response::getNonce();
        $this->assertNotEmpty($nonce);
        $this->assertIsString($nonce);
    }

    public function testGetNonceIsValidBase64(): void
    {
        $nonce = Response::getNonce();
        $decoded = base64_decode($nonce, true);
        $this->assertNotFalse($decoded, 'Nonce must be valid base64');
        $this->assertSame(16, strlen($decoded), 'Nonce must be 16 bytes of entropy');
    }

    public function testGetNonceReturnsSameValueWithinRequest(): void
    {
        $first  = Response::getNonce();
        $second = Response::getNonce();
        $this->assertSame($first, $second, 'Nonce must be stable within a single request');
    }

    public function testNonceChangesAfterReset(): void
    {
        $first = Response::getNonce();

        $prop = new \ReflectionProperty(Response::class, 'nonce');
        $prop->setValue(null, '');

        $second = Response::getNonce();
        $this->assertNotSame($first, $second, 'A fresh nonce must differ after reset');
    }
}
