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
        <?php
            $seatPct = $seat_limit > 0 ? min(100, ($user_count / $seat_limit) * 100) : 0;
            $seatColor = $seatPct >= 90 ? 'var(--danger)' : ($seatPct >= 70 ? '#f0ad4e' : 'var(--primary)');
        ?>
        <div style="margin-top:0.75rem; background:var(--border); border-radius:3px; height:5px;">
            <div style="background:<?= $seatColor ?>; height:100%; border-radius:3px; width:<?= $seatPct ?>%; transition:width 300ms ease;"></div>
        </div>
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

<!-- Recent Activity -->
<?php if (!empty($recent_activity)): ?>
<section class="card mt-6">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title" style="margin:0;">Recent Activity</h2>
        <a href="/app/admin/audit-logs" class="btn btn-sm btn-secondary">View All Logs</a>
    </div>
    <div class="card-body" style="padding:0;">
        <?php foreach ($recent_activity as $log):
            $details = json_decode($log['details_json'] ?? '{}', true) ?: [];
            $eventLabel = ucwords(str_replace('_', ' ', strtolower($log['event_type'])));
            $isWarning = str_contains($log['event_type'], 'FAILURE') || str_contains($log['event_type'], 'DELETED');
        ?>
            <div style="display:flex; align-items:center; gap:1rem; padding:0.75rem 1.25rem; border-bottom:1px solid var(--border); font-size:0.875rem;">
                <span class="badge <?= $isWarning ? 'badge-warning' : 'badge-secondary' ?>" style="font-size:0.7rem; flex-shrink:0;">
                    <?= htmlspecialchars($eventLabel) ?>
                </span>
                <span style="flex:1; color:var(--text);">
                    <strong><?= htmlspecialchars($log['full_name'] ?? 'System') ?></strong>
                    <?php if (!empty($details)): ?>
                        <?php
                            $parts = [];
                            foreach ($details as $k => $v) {
                                if (is_scalar($v) && $k !== 'provider') {
                                    $parts[] = $k . ': ' . $v;
                                }
                            }
                            if (!empty($parts)) {
                                echo '<span class="text-muted"> — ' . htmlspecialchars(implode(', ', array_slice($parts, 0, 2))) . '</span>';
                            }
                        ?>
                    <?php endif; ?>
                </span>
                <span class="text-muted" style="flex-shrink:0; font-size:0.75rem;">
                    <?= date('j M g:ia', strtotime($log['created_at'])) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
