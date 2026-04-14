<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Shared helper for encrypting sensitive values at rest.
 *
 * Uses AES-256-GCM. Supports multi-key rotation via TOKEN_ENCRYPTION_KEYS:
 *
 *   TOKEN_ENCRYPTION_KEYS=kid1:base64key1,kid2:base64key2
 *
 * Encrypt always uses the *last* (newest) key. Decrypt picks the key matching
 * the `kid` field embedded in the envelope. This allows seamless rotation:
 * add a new kid, run bin/rotate_secrets.php to re-encrypt old rows, then
 * remove the old kid.
 *
 * Backwards-compatible with legacy TOKEN_ENCRYPTION_KEY (single key, no kid)
 * which is treated as kid "v1" during decryption only.
 */
final class SecretManager
{
    // v1 envelope: no kid field (legacy single-key format)
    private const MARKER_V1 = '__enc_v1';
    // v2 envelope: includes a kid field for key rotation
    private const MARKER_V2 = '__enc_v2';

    // ===========================
    // CONFIGURATION
    // ===========================

    public static function isConfigured(): bool
    {
        return self::currentKey() !== null;
    }

    /**
     * Parse TOKEN_ENCRYPTION_KEYS into an ordered map of kid → raw-key-bytes.
     * Falls back to TOKEN_ENCRYPTION_KEY as kid "legacy" for decryption.
     *
     * @return array<string, string>  kid → raw bytes (32 bytes each)
     */
    private static function keys(): array
    {
        $multi = (string) ($_ENV['TOKEN_ENCRYPTION_KEYS'] ?? '');

        if ($multi !== '') {
            $keys = [];
            foreach (explode(',', $multi) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                [$kid, $b64] = explode(':', $pair, 2) + [null, null];
                if ($kid !== null && $b64 !== null) {
                    $raw = base64_decode($b64, true);
                    if ($raw !== false && strlen($raw) === 32) {
                        $keys[$kid] = $raw;
                    }
                }
            }
            if (!empty($keys)) {
                return $keys;
            }
        }

        // Legacy single-key fallback
        $legacy = (string) ($_ENV['TOKEN_ENCRYPTION_KEY'] ?? '');
        if ($legacy !== '') {
            return ['legacy' => $legacy];
        }

        return [];
    }

    /**
     * Return [kid, raw-key] for the key to use when encrypting (always the last one).
     */
    private static function currentKey(): ?array
    {
        $keys = self::keys();
        if (empty($keys)) {
            return null;
        }
        $kid = array_key_last($keys);
        return [$kid, $keys[$kid]];
    }

    // ===========================
    // PUBLIC API
    // ===========================

    /**
     * Encrypt a string value into a v2 envelope.
     *
     * @return array{__enc_v2:true,kid:string,ciphertext:string,iv:string,tag:string}|string|null
     */
    public static function protectString(?string $plaintext): array|string|null
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        $current = self::currentKey();
        if ($current === null) {
            return $plaintext; // Encryption not configured — pass through
        }

        [$kid, $key] = $current;

        $iv  = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, 0, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt secret value.');
        }

        return [
            self::MARKER_V2 => true,
            'kid'           => $kid,
            'ciphertext'    => $ciphertext,
            'iv'            => base64_encode($iv),
            'tag'           => base64_encode($tag),
        ];
    }

    public static function unprotectString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Plaintext (unencrypted) string — pass through
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        // v2 envelope (with kid)
        if (isset($value[self::MARKER_V2], $value['kid'], $value['ciphertext'], $value['iv'], $value['tag'])) {
            return self::decryptEnvelope($value['kid'], $value);
        }

        // v1 envelope (legacy, no kid — key must exist under 'legacy')
        if (isset($value[self::MARKER_V1], $value['ciphertext'], $value['iv'], $value['tag'])) {
            return self::decryptEnvelope('legacy', $value);
        }

        return null;
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

    // ===========================
    // ROTATION HELPERS
    // ===========================

    /**
     * Return true if a value is a v1 envelope (needs re-encryption to v2).
     */
    public static function isLegacyEnvelope(mixed $value): bool
    {
        return is_array($value) && isset($value[self::MARKER_V1]);
    }

    /**
     * Re-encrypt a v1 or v2 envelope under the current (newest) key.
     * Returns the new envelope, or the original value if no change needed.
     */
    public static function rotate(mixed $value): mixed
    {
        $plaintext = self::unprotectString($value);
        if ($plaintext === null) {
            return $value;
        }

        $current = self::currentKey();
        if ($current === null) {
            return $value;
        }

        // Already on the current kid? No rotation needed.
        if (is_array($value) && isset($value[self::MARKER_V2]) && ($value['kid'] ?? '') === $current[0]) {
            return $value;
        }

        return self::protectString($plaintext);
    }

    // ===========================
    // INTERNAL HELPERS
    // ===========================

    private static function decryptEnvelope(string $kid, array $envelope): ?string
    {
        $keys = self::keys();
        if (!isset($keys[$kid])) {
            // Key not available (was removed) — cannot decrypt
            return null;
        }

        $key = $keys[$kid];

        $plaintext = openssl_decrypt(
            (string) $envelope['ciphertext'],
            'aes-256-gcm',
            $key,
            0,
            base64_decode((string) $envelope['iv'], true) ?: '',
            base64_decode((string) $envelope['tag'], true) ?: ''
        );

        return $plaintext !== false ? $plaintext : null;
    }

    private static function applyToPath(array &$payload, string $path, callable $transform): void
    {
        $segments = explode('.', $path);
        $node     = &$payload;

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
}
