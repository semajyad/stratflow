<?php
/**
 * Billing Dashboard Template
 *
 * Shows subscription status, seat usage, plan details, and links
 * to invoices and plan management. Restricted to users with billing access.
 *
 * Variables: $user, $org, $subscription, $seat_limit, $active_users, $total_users
 */
$sub = $subscription;
$plan = $sub['plan_type'] ?? 'none';
$status = $sub['status'] ?? 'none';
?>

<div class="page-header">
    <h1 class="page-title">Billing & Subscription</h1>
    <p class="page-subtitle"><a href="/app/admin">&larr; Back to Administration</a></p>
</div>

<!-- Subscription Overview -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <section class="card">
        <div class="card-body" style="text-align: center; padding: 1.5rem;">
            <span class="text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Plan</span>
            <strong style="font-size: 1.5rem; display: block; margin: 0.5rem 0;">
                <?= $sub ? htmlspecialchars(ucfirst($plan)) : 'No Plan' ?>
            </strong>
            <?php if ($sub): ?>
                <span class="badge <?= $status === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($status) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-body" style="text-align: center; padding: 1.5rem;">
            <span class="text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Seats Used</span>
            <strong style="font-size: 1.5rem; display: block; margin: 0.5rem 0;">
                <?= (int) $active_users ?> / <?= (int) $seat_limit ?>
            </strong>
            <?php
                $pct = $seat_limit > 0 ? ($active_users / $seat_limit) * 100 : 0;
                $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? '#f0ad4e' : 'var(--primary)');
            ?>
            <div style="background: var(--border); border-radius: 4px; height: 8px; margin-top: 0.5rem;">
                <div style="background: <?= $color ?>; border-radius: 4px; height: 100%; width: <?= min(100, $pct) ?>%;"></div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-body" style="text-align: center; padding: 1.5rem;">
            <span class="text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Started</span>
            <strong style="font-size: 1.5rem; display: block; margin: 0.5rem 0;">
                <?= $sub ? date('j M Y', strtotime($sub['started_at'])) : '—' ?>
            </strong>
            <?php if ($sub && $sub['expires_at']): ?>
                <span class="text-muted" style="font-size: 0.85rem;">Renews <?= date('j M Y', strtotime($sub['expires_at'])) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-body" style="text-align: center; padding: 1.5rem;">
            <span class="text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; display: block;">Features</span>
            <div style="margin: 0.5rem 0; font-size: 0.9rem;">
                <?php if ($sub && $sub['has_evaluation_board']): ?>
                    <span class="badge badge-success">Sounding Board</span>
                <?php else: ?>
                    <span class="badge badge-secondary">Standard</span>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<!-- Plan Details -->
<section class="card mb-6">
    <div class="card-header"><h2 class="card-title">Plan Details</h2></div>
    <div class="card-body">
        <table style="width: 100%; max-width: 500px;">
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 0.75rem 0; color: var(--text-muted);">Organisation</td>
                <td style="padding: 0.75rem 0; font-weight: 600;"><?= htmlspecialchars($org['name'] ?? '') ?></td>
            </tr>
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 0.75rem 0; color: var(--text-muted);">Plan Type</td>
                <td style="padding: 0.75rem 0;"><?= $sub ? ucfirst(htmlspecialchars($plan)) : 'No active plan' ?></td>
            </tr>
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 0.75rem 0; color: var(--text-muted);">User Seats</td>
                <td style="padding: 0.75rem 0;"><?= (int) $active_users ?> active of <?= (int) $seat_limit ?> allowed (<?= (int) $total_users ?> total)</td>
            </tr>
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 0.75rem 0; color: var(--text-muted);">Stripe Customer</td>
                <td style="padding: 0.75rem 0; font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars($org['stripe_customer_id'] ?? 'Not configured') ?></td>
            </tr>
            <?php if ($sub): ?>
            <tr>
                <td style="padding: 0.75rem 0; color: var(--text-muted);">Subscription ID</td>
                <td style="padding: 0.75rem 0; font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars($sub['stripe_subscription_id'] ?? '') ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<!-- Actions -->
<section class="card">
    <div class="card-header"><h2 class="card-title">Billing Actions</h2></div>
    <div class="card-body">
        <div class="flex items-center gap-3" style="flex-wrap: wrap;">
            <a href="/app/admin/invoices" class="btn btn-secondary">View Invoices</a>
            <?php if (!empty($org['stripe_customer_id'])): ?>
                <span class="text-muted" style="font-size: 0.85rem;">
                    To update your plan, add seats, or change payment method, contact <a href="mailto:support@stratflow.io">support@stratflow.io</a> or your account manager.
                </span>
            <?php else: ?>
                <span class="text-muted" style="font-size: 0.85rem;">
                    No billing account linked. Contact support to set up your subscription.
                </span>
            <?php endif; ?>
        </div>
    </div>
</section>
