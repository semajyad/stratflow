<?php
/**
 * Billing Dashboard Template
 *
 * Subscription overview, seat usage, Stripe portal access, invoices.
 * Only visible to users with has_billing_access flag or superadmin.
 */
$sub = $subscription;
$sd  = $stripe_details;
$plan = $sub['plan_type'] ?? 'none';
$status = $sub['status'] ?? 'none';
$seatPct = $seat_limit > 0 ? min(100, ($active_users / $seat_limit) * 100) : 0;
$seatColor = $seatPct >= 90 ? 'var(--danger)' : ($seatPct >= 70 ? '#f0ad4e' : 'var(--primary)');
?>

<div class="page-header">
    <h1 class="page-title">Billing & Subscription</h1>
    <p class="page-subtitle"><a href="/app/admin">&larr; Back to Administration</a></p>
</div>

<!-- Overview Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 2rem; align-items: stretch;">
    <section class="card" style="display:flex; flex-direction:column;">
        <div class="card-body" style="text-align: center; padding: 1.25rem; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center;">
            <span class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;">Plan</span>
            <div style="font-size: 1.4rem; font-weight: 700; margin: 0.4rem 0;">
                <?= $sub ? htmlspecialchars(ucfirst($plan)) : 'No Plan' ?>
            </div>
            <?php if ($sub): ?>
                <span class="badge <?= $status === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                <?php if ($sd && $sd['cancel_at_period_end']): ?>
                    <br><small class="text-muted" style="color: var(--danger);">Cancels at period end</small>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card" style="display:flex; flex-direction:column;">
        <div class="card-body" style="text-align: center; padding: 1.25rem; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center;">
            <span class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;">Seats</span>
            <div style="font-size: 1.4rem; font-weight: 700; margin: 0.4rem 0;">
                <?= (int) $active_users ?> / <?= (int) $seat_limit ?>
            </div>
            <div style="background: var(--border); border-radius: 4px; height: 6px; margin-top: 0.4rem;">
                <div style="background: <?= $seatColor ?>; border-radius: 4px; height: 100%; width: <?= $seatPct ?>%; transition: width 0.3s;"></div>
            </div>
            <small class="text-muted"><?= (int) $total_users ?> total users</small>
        </div>
    </section>

    <section class="card" style="display:flex; flex-direction:column;">
        <div class="card-body" style="text-align: center; padding: 1.25rem; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center;">
            <span class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;">
                <?php if ($sd): ?>Cost<?php else: ?>Started<?php endif; ?>
            </span>
            <div style="font-size: 1.4rem; font-weight: 700; margin: 0.4rem 0;">
                <?php if ($sd): ?>
                    <?= htmlspecialchars(strtoupper($sd['currency'])) ?> <?= number_format($sd['unit_amount'] / 100, 2) ?>
                    <small style="font-weight: 400; font-size: 0.85rem;">/seat/<?= htmlspecialchars($sd['interval']) ?></small>
                <?php else: ?>
                    <?= $sub ? date('j M Y', strtotime($sub['started_at'])) : '—' ?>
                <?php endif; ?>
            </div>
            <?php if ($sd): ?>
                <small class="text-muted"><?= (int) ($sd['quantity'] ?? 1) ?> seats billed</small>
            <?php endif; ?>
        </div>
    </section>

    <section class="card" style="display:flex; flex-direction:column;">
        <div class="card-body" style="text-align: center; padding: 1.25rem; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center;">
            <span class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;">Period</span>
            <div style="font-size: 1.4rem; font-weight: 700; margin: 0.4rem 0;">
                <?php if ($sd): ?>
                    <?= date('j M', strtotime($sd['current_period_end'])) ?>
                <?php elseif ($sub && $sub['expires_at']): ?>
                    <?= date('j M Y', strtotime($sub['expires_at'])) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <small class="text-muted"><?= $sd ? 'Next renewal' : ($sub ? 'Expires' : '') ?></small>
        </div>
    </section>
</div>

<!-- Flash messages -->
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>

<!-- Plan Details -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 2rem;">
    <section class="card">
        <div class="card-header"><h2 class="card-title">Plan Details</h2></div>
        <div class="card-body">
            <table style="width: 100%;">
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 0.6rem 0; color: var(--text-muted); width: 40%;">Organisation</td>
                    <td style="padding: 0.6rem 0; font-weight: 600;"><?= htmlspecialchars($org['name'] ?? '') ?></td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 0.6rem 0; color: var(--text-muted);">Plan Type</td>
                    <td style="padding: 0.6rem 0;"><?= $sub ? ucfirst(htmlspecialchars($plan)) : 'No active plan' ?></td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 0.6rem 0; color: var(--text-muted);">User Seats</td>
                    <td style="padding: 0.6rem 0;"><?= (int) $active_users ?> active of <?= (int) $seat_limit ?> allowed</td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 0.6rem 0; color: var(--text-muted);">Features</td>
                    <td style="padding: 0.6rem 0;">
                        <?php if ($sub && $sub['has_evaluation_board']): ?>
                            <span class="badge badge-success">Sounding Board</span>
                        <?php endif; ?>
                        <span class="badge badge-info">Jira Integration</span>
                        <span class="badge badge-secondary">Standard</span>
                    </td>
                </tr>
                <?php if ($sd): ?>
                <tr>
                    <td style="padding: 0.6rem 0; color: var(--text-muted);">Billing Period</td>
                    <td style="padding: 0.6rem 0;"><?= htmlspecialchars($sd['current_period_start']) ?> to <?= htmlspecialchars($sd['current_period_end']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">Manage Subscription</h2></div>
        <div class="card-body">
            <?php if ($has_stripe): ?>
                <p style="font-size: 0.9rem; margin-bottom: 1.25rem; color: var(--text-secondary);">
                    Use the Stripe Customer Portal to manage your subscription, update payment methods, change seat quantities, or cancel.
                </p>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <form method="POST" action="/app/admin/billing/portal" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            Open Billing Portal
                        </button>
                    </form>
                    <a href="/app/admin/invoices" class="btn btn-secondary" style="width: 100%; text-align: center;">
                        View Invoices
                    </a>
                </div>
                <p class="text-muted" style="font-size: 0.8rem; margin-top: 1rem;">
                    In the portal you can:
                </p>
                <ul style="font-size: 0.8rem; color: var(--text-muted); margin: 0.25rem 0 0; padding-left: 1.25rem;">
                    <li>Add or remove user seats</li>
                    <li>Update payment method</li>
                    <li>Download invoices and receipts</li>
                    <li>Cancel subscription</li>
                </ul>
            <?php else: ?>
                <p style="font-size: 0.9rem; color: var(--text-secondary);">
                    No billing account is linked to this organisation.
                </p>
                <p class="text-muted" style="font-size: 0.85rem;">
                    Contact your account manager or <a href="mailto:support@stratflow.io">support@stratflow.io</a> to set up billing.
                </p>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ===========================
     Xero Invoicing
     =========================== -->
<section class="card mt-4">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">Xero Invoicing</h2>
            <small class="text-muted">Connect Xero to create and manage invoices for enterprise clients</small>
        </div>
        <div>
            <?php if ($xero_connected): ?>
                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 12px;">Connected</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 4px 12px;">Not Connected</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($xero_connected): ?>
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <span class="text-muted" style="font-size: 0.75rem; display: block; text-transform: uppercase; letter-spacing: 0.05em;">Connected Organisation</span>
                    <strong><?= htmlspecialchars($xero_tenant_name ?? 'Xero') ?></strong>
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center; padding-top: 1rem; border-top: 1px solid var(--border);">
                <a href="/app/admin/invoices" class="btn btn-primary">View &amp; Create Invoices</a>
                <form method="POST" action="/app/admin/invoices/sync" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-secondary">Sync from Xero</button>
                </form>
                <div style="margin-left: auto;">
                    <form method="POST" action="/app/admin/xero/disconnect" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Disconnect Xero? Cached invoices will be removed.')">
                            Disconnect
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size: 0.9rem; margin-bottom: 1.25rem; color: var(--text-secondary);">
                Connect your Xero account to generate and track invoices for enterprise clients directly from StratFlow.
                Enterprise clients often require Xero-compatible invoices rather than Stripe receipts.
            </p>
            <a href="/app/admin/xero/connect" class="btn btn-primary">Connect to Xero</a>
        <?php endif; ?>
    </div>
</section>
