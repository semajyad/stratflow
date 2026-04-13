<?php
/**
 * Superadmin User Management Template
 *
 * Lists all users in the system with их organisation, role, and status.
 *
 * Variables: $user (array), $all_users (array)
 */
?>

<div class="page-header">
    <h1 class="page-title">System Users</h1>
    <p class="page-subtitle">
        <a href="/superadmin">&larr; Back to Superadmin Dashboard</a>
    </p>
</div>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">All Users (<?= count($all_users) ?>)</h2>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Organisation</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $u): ?>
                    <tr>
                        <td class="font-600"><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <span class="badge badge-secondary">
                                <?= htmlspecialchars($u['org_name'] ?? 'System') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?= ($u['role'] ?? '') === 'superadmin' ? 'primary' : 'info' ?>">
                                <?= htmlspecialchars($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($u['is_active'])): ?>
                                <span class="status-dot status-dot--active"></span> Active
                            <?php else: ?>
                                <span class="status-dot status-dot--inactive"></span> Inactive
                            <?php endif; ?>
                        </td>
                        <td class="text-muted text-sm"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
