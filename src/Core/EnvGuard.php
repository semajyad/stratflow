<?php

declare(strict_types=1);

namespace StratFlow\Core;

/**
 * Boot-time assertions for production-required environment variables.
 *
 * Call EnvGuard::assertProductionRequirements() early in bootstrap before
 * any services are instantiated. Missing security keys fail hard in
 * production rather than degrading silently to unkeyed audit chains or
 * plaintext secret storage.
 */
final class EnvGuard
{
    /**
     * Throw when required production secrets are absent.
     *
     * AUDIT_HMAC_KEY         — without this, the audit chain falls back to
     *                          plain SHA-256, which cannot detect deliberate
     *                          tampering (only accidental corruption).
     *
     * TOKEN_ENCRYPTION_KEYS  — without at least one encryption key,
     *   (or TOKEN_ENCRYPTION_KEY)  SecretManager::protectString() passes values
     *                          through to the database in plaintext.
     *
     * In non-production environments the check is a no-op so local dev and
     * CI work without secrets configured.
     *
     * @throws \RuntimeException listing every missing variable.
     */
    public static function assertProductionRequirements(string $appEnv): void
    {
        if (strtolower(trim($appEnv)) !== 'production') {
            return;
        }

        $missing = [];

        if (trim((string) ($_ENV['AUDIT_HMAC_KEY'] ?? '')) === '') {
            $missing[] = 'AUDIT_HMAC_KEY';
        }

        $hasEncKey = trim((string) ($_ENV['TOKEN_ENCRYPTION_KEYS'] ?? '')) !== ''
                  || trim((string) ($_ENV['TOKEN_ENCRYPTION_KEY']  ?? '')) !== '';
        if (!$hasEncKey) {
            $missing[] = 'TOKEN_ENCRYPTION_KEYS';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Missing required production environment variable(s): '
                . implode(', ', $missing)
                . '. Application cannot start safely.'
            );
        }
    }
}
