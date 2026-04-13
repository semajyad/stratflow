<?php
/**
 * Superadmin Subscription Management Template
 *
 * Lists all subscriptions in the system with their status, plan, and billing details.
 *
 * Variables: $user (array), $all_subscriptions (array)
 */
?>

<div class="page-header">
    <h1 class="page-title">Active Subscriptions</h1>
    <p class="page-subtitle">
        <a href="/superadmin">&larr; Back to Superadmin Dashboard</a>
    </p>
</div>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">All Subscriptions (<?= count($all_subscriptions) ?>)</h2>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Organisation</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Seats</th>
                        <th>Billing Cycle</th>
                        <th>Next Invoice</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_subscriptions as $s): ?>
                    <tr>
                        <td class="font-600"><?= htmlspecialchars($s['org_name'] ?? 'Unknown Org') ?></td>
                        <td>
                            <span class="badge badge-primary">
                                <?= ucfirst(htmlspecialchars($s['plan_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?= ($s['status'] === 'active') ? 'success' : 'secondary' ?>">
                                <?= htmlspecialchars($s['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $s['user_seat_limit'] ?></td>
                        <td>
                            <?php if (($s['billing_period_months'] ?? 1) == 12): ?>
                                Annual
                            <?php else: ?>
                                Monthly
                            <?php endif; ?>
                            <br>
                            <small class="text-muted">
                                <?= htmlspecialchars(strtoupper($s['billing_method'] ?? 'stripe')) ?>
                            </small>
                        </td>
                        <td>
                            <?php if (!empty($s['next_invoice_date'])): ?>
                                <?= date('M j, Y', strtotime($s['next_invoice_date'])) ?>
                            <?php else: ?>
                                <span class="text-muted text-sm">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted text-sm">
                            <?= !empty($s['started_at']) ? date('M j, Y', strtotime($s['started_at'])) : date('M j, Y', strtotime($s['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
