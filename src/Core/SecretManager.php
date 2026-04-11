<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Shared helper for encrypting sensitive values at rest.
 *
 * Uses AES-256-GCM when TOKEN_ENCRYPTION_KEY is configured. Values remain
 * backwards-compatible with legacy plaintext rows by only decrypting payloads
 * that match the expected encrypted envelope shape.
 */
final class SecretManager
{
    private const ENCRYPTED_MARKER = '__enc_v1';

    public static function isConfigured(): bool
    {
        return self::key() !== '';
    }

    /**
     * Encrypt a string value into an envelope array.
     *
     * @return array{__enc_v1:true,ciphertext:string,iv:string,tag:string}|string
     */
    public static function protectString(?string $plaintext): array|string|null
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        $key = self::key();
        if ($key === '') {
            return $plaintext;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, 0, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt secret value.');
        }

        return [
            self::ENCRYPTED_MARKER => true,
            'ciphertext'           => $ciphertext,
            'iv'                   => base64_encode($iv),
            'tag'                  => base64_encode($tag),
        ];
    }

    public static function unprotectString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_array($value) || !isset($value[self::ENCRYPTED_MARKER], $value['ciphertext'], $value['iv'], $value['tag'])) {
            return is_string($value) ? $value : null;
        }

        $key = self::key();
        if ($key === '') {
            return is_string($value['ciphertext']) ? $value['ciphertext'] : null;
        }

        $plaintext = openssl_decrypt(
            (string) $value['ciphertext'],
            'aes-256-gcm',
            $key,
            0,
            base64_decode((string) $value['iv'], true) ?: '',
            base64_decode((string) $value['tag'], true) ?: ''
        );

        return $plaintext !== false ? $plaintext : null;
    }

    public static function protectJson(array $payload, array $sensitivePaths): string
    {
        foreach ($sensitivePaths as $path) {
            self::applyToPath($payload, $path, static function (mixed $value): mixed {
                return self::protectString(is_string($value) ? $value : null);
            });
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public static function unprotectJson(?string $json, array $sensitivePaths): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return $json;
        }

        foreach ($sensitivePaths as $path) {
            self::applyToPath($payload, $path, static function (mixed $value): mixed {
                $decrypted = self::unprotectString($value);
                return $decrypted ?? $value;
            });
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private static function applyToPath(array &$payload, string $path, callable $transform): void
    {
        $segments = explode('.', $path);
        $node = &$payload;

        foreach ($segments as $index => $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return;
            }

            if ($index === count($segments) - 1) {
                $node[$segment] = $transform($node[$segment]);
                return;
            }

            $node = &$node[$segment];
        }
    }

    private static function key(): string
    {
        return (string) ($_ENV['TOKEN_ENCRYPTION_KEY'] ?? '');
    }
}
