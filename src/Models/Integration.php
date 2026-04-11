<?php
/**
 * Integration Model
 *
 * Static data-access methods for the `integrations` table.
 * Stores OAuth credentials and configuration for external tool
 * integrations (Jira Cloud, Azure DevOps). Multi-tenancy scoped
 * by org_id at the controller level.
 *
 * Columns: id, org_id, provider, display_name, cloud_id, access_token,
 *          refresh_token, token_expires_at, site_url, config_json,
 *          status, last_sync_at, error_message, error_count,
 *          created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;
use StratFlow\Core\SecretManager;

class Integration
{
    private const CONFIG_SECRET_PATHS = [
        'access_token',
        'refresh_token',
        'webhook_secret',
    ];

    // ===========================
    // UPDATABLE COLUMNS
    // ===========================

    /** @var string[] Columns allowed in dynamic update calls */
    private const UPDATABLE_COLUMNS = [
        'display_name', 'cloud_id', 'access_token', 'refresh_token',
        'token_expires_at', 'site_url', 'config_json', 'status',
        'last_sync_at', 'error_message', 'error_count',
        'token_iv', 'token_tag',
        'installation_id', 'account_login',
    ];

    /**
     * Encrypt a token value using AES-256-GCM.
     * Returns null if no encryption key is configured.
     */
    public static function encryptToken(string $plaintext): array
    {
        $protected = SecretManager::protectString($plaintext);
        if (!is_array($protected)) {
            return ['ciphertext' => $plaintext, 'iv' => null, 'tag' => null];
        }

        return [
            'ciphertext' => json_encode($protected, JSON_UNESCAPED_SLASHES),
            'iv'         => null,
            'tag'        => null,
        ];
    }

    /**
     * Decrypt a token value. Falls back to plaintext if no encryption key.
     */
    public static function decryptToken(string $ciphertext, ?string $iv, ?string $tag): string
    {
        $decoded = json_decode($ciphertext, true);
        if (is_array($decoded)) {
            $plaintext = SecretManager::unprotectString($decoded);
            return $plaintext ?? $ciphertext;
        }

        $plaintext = SecretManager::unprotectString([
            '__enc_v1'   => true,
            'ciphertext' => $ciphertext,
            'iv'         => $iv,
            'tag'        => $tag,
        ]);
        return $plaintext ?? $ciphertext;
    }

    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new integration and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: org_id, provider, display_name, cloud_id,
     *                       access_token, refresh_token, token_expires_at,
     *                       site_url, config_json, status
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $protected = self::prepareForStorage($data);
        $db->query(
            "INSERT INTO integrations
                (org_id, provider, display_name, cloud_id, access_token,
                 refresh_token, token_expires_at, site_url, config_json, status,
                 installation_id, account_login)
             VALUES
                (:org_id, :provider, :display_name, :cloud_id, :access_token,
                 :refresh_token, :token_expires_at, :site_url, :config_json, :status,
                 :installation_id, :account_login)",
            [
                ':org_id'           => $protected['org_id'],
                ':provider'         => $protected['provider'],
                ':display_name'     => $protected['display_name'] ?? '',
                ':cloud_id'         => $protected['cloud_id'] ?? null,
                ':access_token'     => $protected['access_token'] ?? null,
                ':refresh_token'    => $protected['refresh_token'] ?? null,
                ':token_expires_at' => $protected['token_expires_at'] ?? null,
                ':site_url'         => $protected['site_url'] ?? null,
                ':config_json'      => $protected['config_json'] ?? null,
                ':status'           => $protected['status'] ?? 'disconnected',
                ':installation_id'  => $protected['installation_id'] ?? null,
                ':account_login'    => $protected['account_login'] ?? null,
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find an integration by organisation and provider.
     *
     * @param Database $db       Database instance
     * @param int      $orgId    Organisation ID
     * @param string   $provider Provider name (e.g. 'jira')
     * @return array|null        Row as associative array, or null if not found
     */
    public static function findByOrgAndProvider(Database $db, int $orgId, string $provider): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM integrations
             WHERE org_id = :org_id AND provider = :provider
             LIMIT 1",
            [':org_id' => $orgId, ':provider' => $provider]
        );
        $row = $stmt->fetch();
        return $row !== false ? self::hydrateRow($db, $row) : null;
    }

    /**
     * Return all integrations for an organisation.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     * @return array          Array of integration rows
     */
    public static function findByOrg(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT * FROM integrations WHERE org_id = :org_id ORDER BY provider ASC",
            [':org_id' => $orgId]
        );

        return array_map(
            static fn(array $row): array => self::hydrateRow($db, $row),
            $stmt->fetchAll() ?: []
        );
    }

    /**
     * Find a single active GitHub integration by its installation_id.
     *
     * The (provider, installation_id) pair is globally unique — each GitHub App
     * installation belongs to exactly one stratflow org. Returns the row so the
     * caller can extract org_id for tenancy scoping.
     *
     * @param Database $db             Database instance
     * @param int      $installationId GitHub App installation ID from webhook payload
     * @return array|null              Row as associative array, or null if not found
     */
    public static function findActiveByInstallationId(Database $db, int $installationId): ?array
    {
        $stmt = $db->query(
            "SELECT id, org_id, account_login, installation_id FROM integrations
             WHERE provider = 'github'
               AND installation_id = :installation_id
               AND status = 'active'
             LIMIT 1",
            [':installation_id' => $installationId]
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Return all active GitHub App integrations for an organisation.
     *
     * Used by the admin integrations page to list all connected GitHub accounts.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     * @return array          Array of integration rows (newest first)
     */
    public static function findActiveGithubByOrg(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT i.*, COUNT(ir.id) AS repo_count
             FROM integrations i
             LEFT JOIN integration_repos ir ON ir.integration_id = i.id
             WHERE i.org_id = :org_id
               AND i.provider = 'github'
               AND i.status = 'active'
             GROUP BY i.id
             ORDER BY i.id DESC",
            [':org_id' => $orgId]
        );

        return array_map(
            static fn(array $row): array => self::hydrateRow($db, $row),
            $stmt->fetchAll() ?: []
        );
    }

    /**
     * Find a single integration by its primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Integration primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM integrations WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();
        return $row !== false ? self::hydrateRow($db, $row) : null;
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Update arbitrary columns on an integration by ID.
     *
     * @param Database $db   Database instance
     * @param int      $id   Integration primary key
     * @param array    $data Columns to update as key => value pairs
     */
    public static function update(Database $db, int $id, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }

        $data = self::prepareForStorage($data);

        $setClauses = implode(
            ', ',
            array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data))
        );

        $bound = [];
        foreach ($data as $col => $val) {
            $bound[":{$col}"] = $val;
        }
        $bound[':id'] = $id;

        $db->query("UPDATE integrations SET {$setClauses} WHERE id = :id", $bound);
    }

    /**
     * Update OAuth tokens for an integration.
     *
     * @param Database $db           Database instance
     * @param int      $id           Integration primary key
     * @param string   $accessToken  New access token
     * @param string   $refreshToken New refresh token
     * @param string   $expiresAt    Token expiry datetime string
     */
    public static function updateTokens(Database $db, int $id, string $accessToken, string $refreshToken, string $expiresAt): void
    {
        $protectedAccess = self::encryptToken($accessToken);
        $protectedRefresh = self::encryptToken($refreshToken);
        $db->query(
            "UPDATE integrations
             SET access_token = :access_token,
                  refresh_token = :refresh_token,
                  token_iv = :token_iv,
                  token_tag = :token_tag,
                  token_expires_at = :expires_at
              WHERE id = :id",
             [
                ':access_token'  => $protectedAccess['ciphertext'],
                ':refresh_token' => $protectedRefresh['ciphertext'],
                ':token_iv'      => $protectedAccess['iv'],
                ':token_tag'     => $protectedAccess['tag'],
                ':expires_at'    => $expiresAt,
                ':id'            => $id,
            ]
        );
    }

    /**
     * Record an error against an integration.
     *
     * Increments error_count and sets error_message. If error_count
     * reaches 5 or more, status is automatically set to 'error'.
     *
     * @param Database $db      Database instance
     * @param int      $id      Integration primary key
     * @param string   $message Error message to record
     */
    public static function recordError(Database $db, int $id, string $message): void
    {
        $db->query(
            "UPDATE integrations
             SET error_count = error_count + 1,
                 error_message = :message,
                 status = CASE WHEN error_count + 1 >= 5 THEN 'error' ELSE status END
             WHERE id = :id",
            [':message' => $message, ':id' => $id]
        );
    }

    /**
     * Clear error state on an integration.
     *
     * Resets error_count to 0 and error_message to null.
     *
     * @param Database $db Database instance
     * @param int      $id Integration primary key
     */
    public static function clearError(Database $db, int $id): void
    {
        $db->query(
            "UPDATE integrations
             SET error_count = 0, error_message = NULL
             WHERE id = :id",
            [':id' => $id]
        );
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete an integration by ID.
     *
     * @param Database $db Database instance
     * @param int      $id Integration primary key
     */
    public static function delete(Database $db, int $id): void
    {
        $db->query("DELETE FROM integrations WHERE id = :id", [':id' => $id]);
    }

    private static function prepareForStorage(array $data): array
    {
        if (array_key_exists('access_token', $data)) {
            $token = $data['access_token'];
            if (is_string($token) && $token !== '') {
                $protected = self::encryptToken($token);
                $data['access_token'] = $protected['ciphertext'];
                $data['token_iv'] = $protected['iv'];
                $data['token_tag'] = $protected['tag'];
            } else {
                $data['token_iv'] = null;
                $data['token_tag'] = null;
            }
        }

        if (array_key_exists('refresh_token', $data)) {
            $token = $data['refresh_token'];
            if (is_string($token) && $token !== '') {
                $protected = self::encryptToken($token);
                $data['refresh_token'] = $protected['ciphertext'];
            }
        }

        if (array_key_exists('config_json', $data) && is_string($data['config_json']) && $data['config_json'] !== '') {
            $decoded = json_decode($data['config_json'], true);
            if (is_array($decoded)) {
                $data['config_json'] = SecretManager::protectJson($decoded, self::CONFIG_SECRET_PATHS);
            }
        }

        return $data;
    }

    private static function hydrateRow(Database $db, array $row): array
    {
        $needsBackfill = false;

        if (SecretManager::isConfigured()) {
            if (!empty($row['access_token']) && json_decode((string) $row['access_token'], true) === null && empty($row['token_iv']) && empty($row['token_tag'])) {
                $needsBackfill = true;
            }

            if (!empty($row['refresh_token']) && json_decode((string) $row['refresh_token'], true) === null && empty($row['token_iv']) && empty($row['token_tag'])) {
                $needsBackfill = true;
            }

            if (!empty($row['config_json']) && is_string($row['config_json']) && self::jsonNeedsProtection($row['config_json'], self::CONFIG_SECRET_PATHS)) {
                $needsBackfill = true;
            }
        }

        if (!empty($row['access_token'])) {
            $row['access_token'] = self::decryptToken(
                (string) $row['access_token'],
                $row['token_iv'] ?? null,
                $row['token_tag'] ?? null
            );
        }

        if (!empty($row['refresh_token'])) {
            $row['refresh_token'] = self::decryptToken(
                (string) $row['refresh_token'],
                $row['token_iv'] ?? null,
                $row['token_tag'] ?? null
            );
        }

        if (!empty($row['config_json']) && is_string($row['config_json'])) {
            $row['config_json'] = SecretManager::unprotectJson($row['config_json'], self::CONFIG_SECRET_PATHS);
        }

        if ($needsBackfill && !empty($row['id'])) {
            $data = [];
            if (!empty($row['access_token'])) {
                $data['access_token'] = $row['access_token'];
            }
            if (!empty($row['refresh_token'])) {
                $data['refresh_token'] = $row['refresh_token'];
            }
            if (!empty($row['config_json'])) {
                $data['config_json'] = $row['config_json'];
            }
            if ($data !== []) {
                self::update($db, (int) $row['id'], $data);
            }
        }

        return $row;
    }

    private static function jsonNeedsProtection(string $json, array $paths): bool
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return false;
        }

        foreach ($paths as $path) {
            $value = self::valueAtPath($payload, $path);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function valueAtPath(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $node = $payload;

        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
