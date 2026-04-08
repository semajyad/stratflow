<?php
/**
 * TeamMember Model
 *
 * Static data-access methods for the `team_members` junction table.
 * Links users to teams. All methods accept a Database instance.
 *
 * Columns: team_id, user_id, created_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class TeamMember
{
    /**
     * Add a user to a team. Uses INSERT IGNORE to silently skip duplicates.
     *
     * @param Database $db     Database instance
     * @param int      $teamId Team primary key
     * @param int      $userId User primary key
     */
    public static function addMember(Database $db, int $teamId, int $userId): void
    {
        $db->query(
            "INSERT IGNORE INTO team_members (team_id, user_id) VALUES (:team_id, :user_id)",
            [
                ':team_id' => $teamId,
                ':user_id' => $userId,
            ]
        );
    }

    /**
     * Remove a user from a team.
     *
     * @param Database $db     Database instance
     * @param int      $teamId Team primary key
     * @param int      $userId User primary key
     */
    public static function removeMember(Database $db, int $teamId, int $userId): void
    {
        $db->query(
            "DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id",
            [
                ':team_id' => $teamId,
                ':user_id' => $userId,
            ]
        );
    }

    /**
     * Find all members of a team with their user data.
     *
     * @param Database $db     Database instance
     * @param int      $teamId Team primary key
     * @return array           Array of user rows (joined from users table)
     */
    public static function findByTeamId(Database $db, int $teamId): array
    {
        $stmt = $db->query(
            "SELECT u.id, u.full_name, u.email, u.role, u.is_active
             FROM team_members tm
             JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = :team_id
             ORDER BY u.full_name ASC",
            [':team_id' => $teamId]
        );

        return $stmt->fetchAll();
    }

    /**
     * Find all teams a user belongs to.
     *
     * @param Database $db     Database instance
     * @param int      $userId User primary key
     * @return array           Array of team rows
     */
    public static function findTeamsForUser(Database $db, int $userId): array
    {
        $stmt = $db->query(
            "SELECT t.*
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = :user_id
             ORDER BY t.name ASC",
            [':user_id' => $userId]
        );

        return $stmt->fetchAll();
    }
}
