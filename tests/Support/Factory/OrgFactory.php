<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\Organisation;

/**
 * OrgFactory
 *
 * Creates Organisation rows for use in tests.
 * Call create() to insert and return the new ID.
 */
final class OrgFactory
{
    private static int $sequence = 0;

    public static function create(Database $db, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'name'               => 'Test Org ' . self::$sequence,
            'stripe_customer_id' => 'cus_test_' . self::$sequence,
            'is_active'          => 1,
        ], $overrides);

        return Organisation::create($db, $data);
    }
}
