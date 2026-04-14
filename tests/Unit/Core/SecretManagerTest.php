<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\SecretManager;

/**
 * SecretManagerTest
 *
 * Tests AES-256-GCM envelope encryption/decryption, multi-key rotation,
 * JSON path protection, and the v1/v2 envelope distinction.
 */
class SecretManagerTest extends TestCase
{
    // ===========================
    // SETUP
    // ===========================

    protected function setUp(): void
    {
        parent::setUp();
        // Clean env before each test
        unset($_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['TOKEN_ENCRYPTION_KEYS'], $_ENV['TOKEN_ENCRYPTION_KEY']);
        parent::tearDown();
    }

    private function setKey(string $kid = 'k1'): string
    {
        $raw = random_bytes(32);
        $b64 = base64_encode($raw);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = "{$kid}:{$b64}";
        return $raw;
    }

    // ===========================
    // isConfigured
    // ===========================

    public function testIsConfiguredReturnsFalseWithNoKey(): void
    {
        $this->assertFalse(SecretManager::isConfigured());
    }

    public function testIsConfiguredReturnsTrueWithMultiKey(): void
    {
        $this->setKey();
        $this->assertTrue(SecretManager::isConfigured());
    }

    public function testIsConfiguredReturnsTrueWithLegacyKey(): void
    {
        $_ENV['TOKEN_ENCRYPTION_KEY'] = str_repeat('x', 32);
        $this->assertTrue(SecretManager::isConfigured());
    }

    // ===========================
    // protectString / unprotectString
    // ===========================

    public function testProtectStringReturnsNullForNull(): void
    {
        $this->setKey();
        $this->assertNull(SecretManager::protectString(null));
    }

    public function testProtectStringReturnsEmptyStringForEmptyString(): void
    {
        $this->setKey();
        $this->assertSame('', SecretManager::protectString(''));
    }

    public function testProtectStringPassesThroughWhenNoKeyConfigured(): void
    {
        $result = SecretManager::protectString('my-secret');
        $this->assertSame('my-secret', $result);
    }

    public function testProtectStringProducesV2Envelope(): void
    {
        $this->setKey('k1');
        $envelope = SecretManager::protectString('my-secret');

        $this->assertIsArray($envelope);
        $this->assertTrue($envelope['__enc_v2']);
        $this->assertSame('k1', $envelope['kid']);
        $this->assertArrayHasKey('ciphertext', $envelope);
        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('tag', $envelope);
    }

    public function testRoundTripEncryptionDecryption(): void
    {
        $this->setKey();
        $plaintext = 'super-secret-token-value';
        $envelope  = SecretManager::protectString($plaintext);
        $recovered = SecretManager::unprotectString($envelope);

        $this->assertSame($plaintext, $recovered);
    }

    public function testUnprotectStringReturnsPlaintextStringAsIs(): void
    {
        // A plain string (not an envelope) is returned unchanged
        $this->assertSame('plaintext', SecretManager::unprotectString('plaintext'));
    }

    public function testUnprotectStringReturnsNullForNull(): void
    {
        $this->assertNull(SecretManager::unprotectString(null));
    }

    public function testUnprotectStringReturnsNullForEmptyString(): void
    {
        $this->assertNull(SecretManager::unprotectString(''));
    }

    public function testUnprotectStringReturnsNullForUnknownKid(): void
    {
        $this->setKey('k1');
        // Build envelope referencing a kid not in the key ring
        $envelope = [
            '__enc_v2'   => true,
            'kid'        => 'unknown-kid',
            'ciphertext' => 'abc',
            'iv'         => base64_encode('123456789012'),
            'tag'        => base64_encode('1234567890123456'),
        ];
        $this->assertNull(SecretManager::unprotectString($envelope));
    }

    public function testEachEncryptionProducesUniqueIv(): void
    {
        $this->setKey();
        $a = SecretManager::protectString('same-value');
        $b = SecretManager::protectString('same-value');
        // IVs must differ (random 12 bytes each time)
        $this->assertNotSame($a['iv'], $b['iv']);
    }

    // ===========================
    // Multi-key rotation
    // ===========================

    public function testEncryptAlwaysUsesLastKey(): void
    {
        $raw1 = base64_encode(random_bytes(32));
        $raw2 = base64_encode(random_bytes(32));
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = "k1:{$raw1},k2:{$raw2}";

        $envelope = SecretManager::protectString('value');
        $this->assertSame('k2', $envelope['kid']);
    }

    public function testDecryptWithOlderKeyStillWorks(): void
    {
        // Encrypt with k1 only
        $raw1 = random_bytes(32);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'k1:' . base64_encode($raw1);
        $envelope = SecretManager::protectString('old-value');

        // Now add k2 as the current key — k1 must still decrypt
        $raw2 = random_bytes(32);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'k1:' . base64_encode($raw1) . ',k2:' . base64_encode($raw2);
        $this->assertSame('old-value', SecretManager::unprotectString($envelope));
    }

    public function testRotateReEncryptsUnderCurrentKey(): void
    {
        $raw1 = random_bytes(32);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'k1:' . base64_encode($raw1);
        $old = SecretManager::protectString('rotate-me');
        $this->assertSame('k1', $old['kid']);

        // Promote k2 to current
        $raw2 = random_bytes(32);
        $_ENV['TOKEN_ENCRYPTION_KEYS'] = 'k1:' . base64_encode($raw1) . ',k2:' . base64_encode($raw2);
        $rotated = SecretManager::rotate($old);

        $this->assertIsArray($rotated);
        $this->assertSame('k2', $rotated['kid']);
        $this->assertSame('rotate-me', SecretManager::unprotectString($rotated));
    }

    public function testRotateDoesNotChangeIfAlreadyCurrentKey(): void
    {
        $this->setKey('k1');
        $original = SecretManager::protectString('no-change');
        $rotated  = SecretManager::rotate($original);
        // Same kid — rotate() returns same value (no re-encryption)
        $this->assertSame('k1', $rotated['kid']);
    }

    // ===========================
    // isLegacyEnvelope
    // ===========================

    public function testIsLegacyEnvelopeReturnsTrueForV1(): void
    {
        $v1 = ['__enc_v1' => true, 'ciphertext' => 'x', 'iv' => 'y', 'tag' => 'z'];
        $this->assertTrue(SecretManager::isLegacyEnvelope($v1));
    }

    public function testIsLegacyEnvelopeReturnsFalseForV2(): void
    {
        $this->setKey();
        $v2 = SecretManager::protectString('test');
        $this->assertFalse(SecretManager::isLegacyEnvelope($v2));
    }

    public function testIsLegacyEnvelopeReturnsFalseForPlainString(): void
    {
        $this->assertFalse(SecretManager::isLegacyEnvelope('plaintext'));
    }

    // ===========================
    // protectJson / unprotectJson
    // ===========================

    public function testProtectJsonEncryptsNestedPath(): void
    {
        $this->setKey();
        $payload = ['ai' => ['api_key' => 'sk-abc123']];
        $json    = SecretManager::protectJson($payload, ['ai.api_key']);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded['ai']['api_key']);
        $this->assertTrue($decoded['ai']['api_key']['__enc_v2']);
    }

    public function testUnprotectJsonDecryptsNestedPath(): void
    {
        $this->setKey();
        $payload   = ['ai' => ['api_key' => 'sk-secret']];
        $protected = SecretManager::protectJson($payload, ['ai.api_key']);
        $restored  = SecretManager::unprotectJson($protected, ['ai.api_key']);
        $decoded   = json_decode($restored, true);

        $this->assertSame('sk-secret', $decoded['ai']['api_key']);
    }

    public function testUnprotectJsonReturnsNullInputAsNull(): void
    {
        $this->assertNull(SecretManager::unprotectJson(null, ['path']));
    }

    public function testProtectJsonLeavesNonSensitivePathsAlone(): void
    {
        $this->setKey();
        $payload = ['name' => 'Acme', 'ai' => ['api_key' => 'sk-x']];
        $json    = SecretManager::protectJson($payload, ['ai.api_key']);
        $decoded = json_decode($json, true);

        $this->assertSame('Acme', $decoded['name']);
    }
}
