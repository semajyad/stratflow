<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\Integration;

/**
 * IntegrationFactory
 *
 * Creates Integration rows for use in tests.
 * Defaults to a disconnected Jira integration with placeholder tokens.
 */
final class IntegrationFactory
{
    private static int $sequence = 0;

    public static function create(Database $db, int $orgId, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'org_id'       => $orgId,
            'provider'     => 'jira',
            'display_name' => 'Test Jira ' . self::$sequence,
            'status'       => 'disconnected',
        ], $overrides);

        return Integration::create($db, $data);
    }
}
