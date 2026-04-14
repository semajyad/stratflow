<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;

/**
 * HLWorkItemFactory
 *
 * Creates HLWorkItem rows for use in tests.
 */
final class HLWorkItemFactory
{
    private static int $sequence = 0;

    public static function create(Database $db, int $projectId, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'project_id'      => $projectId,
            'priority_number' => self::$sequence,
            'title'           => 'Test Work Item ' . self::$sequence,
            'status'          => 'backlog',
        ], $overrides);

        return HLWorkItem::create($db, $data);
    }
}
