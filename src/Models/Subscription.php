<?php
/**
 * Subscription Model
 *
 * Static data-access methods for the `subscriptions` table.
 * All methods accept a Database instance and use prepared statements.
 *
 * Columns: id, organisation_id, stripe_subscription_id, plan_type, status, created_at, updated_at
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class Subscription
{
    /**
     * Insert a new subscription row and return its new ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Associative array: organisation_id, stripe_subscription_id, plan_type, status
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO subscriptions (organisation_id, stripe_subscription_id, plan_type, status)
             VALUES (:organisation_id, :stripe_subscription_id, :plan_type, :status)",
            [
                ':organisation_id'         => $data['organisation_id'],
                ':stripe_subscription_id'  => $data['stripe_subscription_id'],
                ':plan_type'               => $data['plan_type'],
                ':status'                  => $data['status'] ?? 'active',
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Find the most recent subscription for an organisation.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation primary key
     * @return array|null     Row as associative array, or null if not found
     */
    public static function findByOrgId(Database $db, int $orgId): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM subscriptions WHERE organisation_id = :org_id ORDER BY id DESC LIMIT 1",
            [':org_id' => $orgId]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a subscription by its Stripe subscription ID.
     *
     * @param Database $db           Database instance
     * @param string   $stripeSubId  Stripe sub_xxx identifier
     * @return array|null            Row as associative array, or null if not found
     */
    public static function findByStripeId(Database $db, string $stripeSubId): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM subscriptions WHERE stripe_subscription_id = :stripe_sub_id LIMIT 1",
            [':stripe_sub_id' => $stripeSubId]
        );

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Update the status field of a subscription row.
     *
     * @param Database $db     Database instance
     * @param int      $id     Primary key of the row to update
     * @param string   $status New status value (e.g. 'active', 'cancelled', 'past_due')
     */
    public static function updateStatus(Database $db, int $id, string $status): void
    {
        $db->query(
            "UPDATE subscriptions SET status = :status WHERE id = :id",
            [
                ':status' => $status,
                ':id'     => $id,
            ]
        );
    }
}
