<?php

/**
 * TOTP Service (RFC 6238 / RFC 4226)
 *
 * Implements Time-based One-Time Passwords without external dependencies.
 * Uses HMAC-SHA1 as specified in RFC 4226, with a 30-second window and
 * 6-digit codes. Accepts ±1 time step (90 seconds) to tolerate clock skew.
 *
 * Usage:
 *   $secret  = TotpService::generateSecret();           // for setup
 *   $uri     = TotpService::uri($secret, $email);       // for QR code / manual entry
 *   $valid   = TotpService::verify($secret, $code);     // on login
 *   $codes   = TotpService::generateRecoveryCodes(8);   // one-time recovery codes
 */

declare(strict_types=1);

namespace StratFlow\Services;

class TotpService
{
    private const DIGITS  = 6;
    private const PERIOD  = 30;
    private const WINDOW  = 1;   // ±1 step = ±30 s tolerance
    private const ISSUER  = 'StratFlow';

    // ===========================
    // SECRET GENERATION
    // ===========================

    /**
     * Generate a cryptographically random Base32 secret (160 bits = 32 chars).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Build an otpauth:// URI for use in authenticator apps.
     */
    public static function uri(string $secret, string $accountLabel): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode(self::ISSUER),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode(self::ISSUER),
            self::DIGITS,
            self::PERIOD
        );
    }

    // ===========================
    // VERIFICATION
    // ===========================

    /**
     * Verify a 6-digit TOTP code against a Base32 secret.
     * Accepts ±WINDOW time steps to tolerate clock skew.
     *
     * @param string $secret  Base32-encoded TOTP secret
     * @param string $code    6-digit code submitted by the user
     * @param int    $at      Unix timestamp to verify against (default: now)
     */
    public static function verify(string $secret, string $code, int $at = 0): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $at     = $at ?: time();
        $rawKey = self::base32Decode($secret);

        for ($step = -self::WINDOW; $step <= self::WINDOW; $step++) {
            $counter  = (int) floor($at / self::PERIOD) + $step;
            $expected = self::hotp($rawKey, $counter);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    // ===========================
    // RECOVERY CODES
    // ===========================

    /**
     * Generate N random plaintext recovery codes (e.g. "ABCD-EFGH-1234-5678").
     *
     * The caller is responsible for hashing and storing them;
     * the plaintext codes must be shown to the user exactly once.
     *
     * @return string[]
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw    = strtoupper(bin2hex(random_bytes(8)));
            $codes[] = implode('-', str_split($raw, 4));
        }
        return $codes;
    }

    /**
     * Hash a recovery code for storage (HMAC-SHA256 keyed by APP_KEY env var).
     */
    public static function hashRecoveryCode(string $plaintext): string
    {
        $key = (string) ($_ENV['APP_KEY'] ?? 'stratflow-recovery-default');
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $plaintext)), $key);
    }

    /**
     * Verify a submitted recovery code against the list of stored hashes.
     * Returns the index of the matched code, or -1 if no match.
     */
    public static function matchRecoveryCode(string $submitted, array $storedHashes): int
    {
        $hash = self::hashRecoveryCode($submitted);
        foreach ($storedHashes as $idx => $stored) {
            if (hash_equals($stored, $hash)) {
                return (int) $idx;
            }
        }
        return -1;
    }

    // ===========================
    // INTERNAL: HOTP + BASE32
    // ===========================

    private static function hotp(string $rawKey, int $counter): string
    {
        // Pack counter as unsigned 64-bit big-endian
        $msg  = pack('N*', 0) . pack('N*', $counter);
        $hmac = hash_hmac('sha1', $msg, $rawKey, true);

        // Dynamic truncation
        $offset = ord($hmac[19]) & 0x0f;
        $code   = (
            ((ord($hmac[$offset])     & 0x7f) << 24) |
            ((ord($hmac[$offset + 1]) & 0xff) << 16) |
            ((ord($hmac[$offset + 2]) & 0xff) << 8)  |
             (ord($hmac[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output   = '';
        $bitsBuf  = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($bytes); $i++) {
            $bitsBuf  = ($bitsBuf << 8) | ord($bytes[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output   .= $alphabet[($bitsBuf >> $bitsLeft) & 0x1f];
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($bitsBuf << (5 - $bitsLeft)) & 0x1f];
        }

        return $output;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded  = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output   = '';
        $bitsBuf  = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($encoded); $i++) {
            $val = strpos($alphabet, $encoded[$i]);
            if ($val === false) {
                continue;
            }
            $bitsBuf  = ($bitsBuf << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($bitsBuf >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
