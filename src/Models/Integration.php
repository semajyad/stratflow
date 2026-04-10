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

class Integration
{
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
        $key = $_ENV['TOKEN_ENCRYPTION_KEY'] ?? '';
        if ($key === '') {
            return ['ciphertext' => $plaintext, 'iv' => null, 'tag' => null];
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, 0, $iv, $tag);
        return [
            'ciphertext' => $ciphertext,
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
        ];
    }

    /**
     * Decrypt a token value. Falls back to plaintext if no encryption key.
     */
    public static function decryptToken(string $ciphertext, ?string $iv, ?string $tag): string
    {
        $key = $_ENV['TOKEN_ENCRYPTION_KEY'] ?? '';
        if ($key === '' || $iv === null || $tag === null) {
            return $ciphertext; // Not encrypted, return as-is
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, 0, base64_decode($iv), base64_decode($tag));
        return $plaintext !== false ? $plaintext : $ciphertext;
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
                ':org_id'           => $data['org_id'],
                ':provider'         => $data['provider'],
                ':display_name'     => $data['display_name'] ?? '',
                ':cloud_id'         => $data['cloud_id'] ?? null,
                ':access_token'     => $data['access_token'] ?? null,
                ':refresh_token'    => $data['refresh_token'] ?? null,
                ':token_expires_at' => $data['token_expires_at'] ?? null,
                ':site_url'         => $data['site_url'] ?? null,
                ':config_json'      => $data['config_json'] ?? null,
                ':status'           => $data['status'] ?? 'disconnected',
                ':installation_id'  => $data['installation_id'] ?? null,
                ':account_login'    => $data['account_login'] ?? null,
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

        return $row !== false ? $row : null;
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

        return $stmt->fetchAll();
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

        return $stmt->fetchAll();
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

        return $row !== false ? $row : null;
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
        $db->query(
            "UPDATE integrations
             SET access_token = :access_token,
                 refresh_token = :refresh_token,
                 token_expires_at = :expires_at
             WHERE id = :id",
            [
                ':access_token'  => $accessToken,
                ':refresh_token' => $refreshToken,
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
}
