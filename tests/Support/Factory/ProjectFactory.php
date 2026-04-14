<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\Project;

/**
 * ProjectFactory
 *
 * Creates Project rows for use in tests.
 */
final class ProjectFactory
{
    private static int $sequence = 0;

    public static function create(Database $db, int $orgId, int $createdBy, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'org_id'     => $orgId,
            'name'       => 'Test Project ' . self::$sequence,
            'status'     => 'active',
            'created_by' => $createdBy,
        ], $overrides);

        return Project::create($db, $data);
    }
}
