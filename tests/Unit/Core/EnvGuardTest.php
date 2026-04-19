<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string,array{string}> */
    public static function nonProductionEnvProvider(): array
    {
        return [
            'development' => ['development'],
            'test'        => ['test'],
            'staging'     => ['staging'],
        ];
    }

    #[Test]
    #[DataProvider('nonProductionEnvProvider')]
    public function noOpForNonProductionEnvironments(string $env): void
    {
        unset($_ENV['AUDIT_HMAC_KEY'], $_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
        EnvGuard::assertProductionRequirements($env);
        $this->assertTrue(true);
    }

    /** @return array<string,array{string}> */
    public static function productionEnvVariantsProvider(): array
    {
        return [
            'lowercase'  => ['production'],
            'mixed-case' => ['Production'],
            'uppercase'  => ['PRODUCTION'],
            'padded'     => [' production '],
        ];
    }

    #[Test]
    #[DataProvider('productionEnvVariantsProvider')]
    public function treatsAllProductionCasingVariantsAsProduction(string $env): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = 'test-hmac-key';
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'kid1:' . base64_encode(str_repeat('a', 32));

        EnvGuard::assertProductionRequirements($env);
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
        $_ENV['AUDIT_HMAC_KEY']       = 'test-hmac-key';
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
    public function throwsWhenAuditKeyIsEmptyStringInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = '';
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'kid1:' . base64_encode(str_repeat('a', 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AUDIT_HMAC_KEY/');
        EnvGuard::assertProductionRequirements('production');
    }

    #[Test]
    public function throwsWhenAuditKeyIsWhitespaceOnlyInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = '   ';
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
    public function throwsWhenEncryptionKeyIsEmptyStringInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = 'test-hmac-key';
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = '';
        $_ENV['TOKEN_ENCRYPTION_KEY']  = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TOKEN_ENCRYPTION_KEYS/');
        EnvGuard::assertProductionRequirements('production');
    }

    #[Test]
    public function throwsWhenEncryptionKeyIsWhitespaceOnlyInProduction(): void
    {
        $_ENV['AUDIT_HMAC_KEY']        = 'test-hmac-key';
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = '   ';
        $_ENV['TOKEN_ENCRYPTION_KEY']  = '   ';

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
