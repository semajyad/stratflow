<?php
/**
 * Admin Dashboard Template
 *
 * Overview of organisation: user count vs seat limit, team count, subscription status.
 * Navigation links to Users, Teams, and Settings sub-pages.
 *
 * Variables: $user (array), $user_count (int), $seat_limit (int),
 *            $team_count (int), $subscription (array|null), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Administration</h1>
    <p class="page-subtitle">Manage your organisation's users, teams, and workflow settings.</p>
</div>

<!-- ===========================
     Dashboard Cards
     =========================== -->
<div class="admin-cards">
    <a href="/app/admin/users" class="admin-card">
        <div class="admin-card-icon">&#128101;</div>
        <div class="admin-card-count"><?= (int) $user_count ?> / <?= (int) $seat_limit ?></div>
        <div class="admin-card-label">Users</div>
        <div class="admin-card-hint">Manage user accounts and roles</div>
    </a>

    <a href="/app/admin/teams" class="admin-card">
        <div class="admin-card-icon">&#128101;</div>
        <div class="admin-card-count"><?= (int) $team_count ?></div>
        <div class="admin-card-label">Teams</div>
        <div class="admin-card-hint">Create teams and assign members</div>
    </a>

    <a href="/app/admin/settings" class="admin-card">
        <div class="admin-card-icon">&#9881;</div>
        <div class="admin-card-count">&mdash;</div>
        <div class="admin-card-label">Settings</div>
        <div class="admin-card-hint">Workflow personas, defaults, tripwires</div>
    </a>
</div>

<!-- ===========================
     Subscription Status
     =========================== -->
<section class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Subscription</h2>
    </div>
    <div class="card-body">
        <?php if ($subscription): ?>
            <table class="table">
                <tr>
                    <th>Plan</th>
                    <td><?= htmlspecialchars(ucfirst($subscription['plan_type'] ?? 'Unknown')) ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <span class="badge <?= ($subscription['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning' ?>">
                            <?= htmlspecialchars(ucfirst($subscription['status'] ?? 'Unknown')) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Seat Limit</th>
                    <td><?= (int) $seat_limit ?> users</td>
                </tr>
            </table>
        <?php else: ?>
            <p class="text-muted">No active subscription found.</p>
        <?php endif; ?>
    </div>
</section>
