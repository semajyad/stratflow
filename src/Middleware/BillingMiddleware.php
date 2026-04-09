<?php
declare(strict_types=1);

namespace StratFlow\Middleware;

use StratFlow\Core\Auth;
use StratFlow\Core\Response;

/**
 * Billing Middleware
 *
 * Restricts billing pages to users with has_billing_access flag,
 * org_admin, or superadmin. Billing access is independent of role —
 * any role can be granted billing access via the flag.
 */
class BillingMiddleware
{
    public function handle(Auth $auth, Response $response): bool
    {
        $user = $auth->user();
        if (!$user) {
            $response->redirect('/login');
            return false;
        }

        $hasBilling    = (bool) ($user['has_billing_access'] ?? false);
        $isSuperadmin  = $user['role'] === 'superadmin';
        $isOrgAdmin    = $user['role'] === 'org_admin';

        // Org admins get billing access by default UNLESS another user
        // in the org has explicit billing access (dedicated billing person)
        if ($isOrgAdmin && !$hasBilling) {
            try {
                $db = \StratFlow\Core\Database::getInstance();
                $stmt = $db->query(
                    "SELECT COUNT(*) AS cnt FROM users WHERE org_id = :oid AND has_billing_access = 1 AND id != :uid",
                    [':oid' => $user['org_id'], ':uid' => $user['id']]
                );
                $row = $stmt->fetch();
                // If no one else has billing flag, org_admin gets access
                if ((int) ($row['cnt'] ?? 0) === 0) {
                    $hasBilling = true;
                }
            } catch (\Throwable $e) {
                // If check fails, allow org_admin access as safe default
                $hasBilling = true;
            }
        }

        if (!$hasBilling && !$isSuperadmin) {
            $response->redirect('/app/home');
            return false;
        }
        return true;
    }
}
