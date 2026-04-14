<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\User;

/**
 * UserFactory
 *
 * Creates User rows for use in tests.
 * Defaults to a plain 'user' role with a known password hash.
 */
final class UserFactory
{
    private static int $sequence = 0;

    /** Bcrypt hash of 'TestPassword123!' — pre-computed for speed. */
    private const DEFAULT_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public static function create(Database $db, int $orgId, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'org_id'        => $orgId,
            'full_name'     => 'Test User ' . self::$sequence,
            'email'         => 'test.user.' . self::$sequence . '@test.invalid',
            'password_hash' => self::DEFAULT_HASH,
            'role'          => 'user',
        ], $overrides);

        return User::create($db, $data);
    }
}
