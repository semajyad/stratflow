<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support\Factory;

use StratFlow\Core\Database;
use StratFlow\Models\UserStory;

/**
 * UserStoryFactory
 *
 * Creates UserStory rows for use in tests.
 */
final class UserStoryFactory
{
    private static int $sequence = 0;

    public static function create(Database $db, int $projectId, array $overrides = []): int
    {
        self::$sequence++;
        $data = array_merge([
            'project_id'      => $projectId,
            'priority_number' => self::$sequence,
            'title'           => 'Test Story ' . self::$sequence,
            'status'          => 'backlog',
        ], $overrides);

        return UserStory::create($db, $data);
    }
}
