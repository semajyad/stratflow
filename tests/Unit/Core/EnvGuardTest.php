<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\EnvGuard;

class EnvGuardTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'AUDIT_HMAC_KEY'        => $_ENV['AUDIT_HMAC_KEY']        ?? false,
            'TOKEN_ENCRYPTION_KEYS' => $_ENV['TOKEN_ENCRYPTION_KEYS'] ?? false,
            'TOKEN_ENCRYPTION_KEY'  => $_ENV['TOKEN_ENCRYPTION_KEY']  ?? false,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $val) {
            if ($val === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $val;
            }
        }
    }

    #[Test]
    public function noOpInDevelopment(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
        EnvGuard::assertProductionRequirements('development');
        $this->assertTrue(true);
    }

    #[Test]
    public function noOpInTest(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
        EnvGuard::assertProductionRequirements('test');
        $this->assertTrue(true);
    }

    #[Test]
    public function noOpInStagingWithMissingKeys(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
        EnvGuard::assertProductionRequirements('staging');
        $this->assertTrue(true);
    }

    #[Test]
    public function passesInProductionWhenAllKeysPresent(): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = 'test-hmac-key';
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'kid1:' . base64_encode(str_repeat('a', 32));

        EnvGuard::assertProductionRequirements('production');
        $this->assertTrue(true);
    }

    #[Test]
    public function acceptsLegacyEncryptionKeyInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY']      = 'test-hmac-key';
        $_ENV['TOKEN_ENCRYPTION_KEY'] = str_repeat('b', 32);
        unset($_ENV['TOKEN_ENCRYPTION_KEYS']);

        EnvGuard::assertProductionRequirements('production');
        $this->assertTrue(true);
    }

    #[Test]
    public function throwsWhenAuditKeyMissingInProduction(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY']);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'kid1:' . base64_encode(str_repeat('a', 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AUDIT_HMAC_KEY/');
        EnvGuard::assertProductionRequirements('production');
    }

    #[Test]
    public function throwsWhenEncryptionKeyMissingInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY'] = 'test-hmac-key';
        unset($_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TOKEN_ENCRYPTION_KEYS/');
        EnvGuard::assertProductionRequirements('production');
    }

    #[Test]
    public function throwsWhenBothKeysMissingInProduction(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AUDIT_HMAC_KEY/');
        EnvGuard::assertProductionRequirements('production');
    }

    #[Test]
    public function exceptionMessageListsAllMissingKeys(): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);

        try {
            EnvGuard::assertProductionRequirements('production');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AUDIT_HMAC_KEY', $e->getMessage());
            $this->assertStringContainsString('TOKEN_ENCRYPTION_KEYS', $e->getMessage());
        }
    }
}
