<?php
/**
 * Billing Dashboard Template
 *
 * Subscription overview, seat usage, Stripe portal access, invoices.
 * Only visible to users with has_billing_access flag or superadmin.
 */
$sub    = $subscription;
$sd     = $stripe_details;
$plan   = $sub['plan_type'] ?? 'none';
$status = $sub['status'] ?? 'none';
$seatPct   = $seat_limit > 0 ? min(100, ($active_users / $seat_limit) * 100) : 0;
$seatColor = $seatPct >= 90 ? 'var(--danger)' : ($seatPct >= 70 ? '#f0ad4e' : 'var(--primary)');

// Invoice billing helpers
$isInvoice       = $is_invoice_billing ?? true;
$nextInvDate     = $sub['next_invoice_date'] ?? null;
$pricePerSeat    = (int) ($sub['price_per_seat_cents'] ?? 0);   // cents
$billingPeriodMo = (int) ($sub['billing_period_months'] ?? 1);
$periodLabels    = [1 => 'Monthly', 3 => 'Quarterly', 6 => '6-Monthly', 12 => 'Annual'];
$periodLabel     = $periodLabels[$billingPeriodMo] ?? 'Monthly';
$totalCostCents  = $pricePerSeat * $seat_limit;   // price × seats per period
$billingContact  = $billing_contact ?? [];
?>

<div class="page-header">
    <h1 class="page-title">Billing & Subscription</h1>
    <p class="page-subtitle"><a href="/app/admin">&larr; Back to Administration</a></p>
</div>

<!-- Overview Cards — fixed structure: label top, value middle, sub bottom -->
<!-- The global .card + .card rule adds margin-top: 1.5rem which breaks grid alignment;
     the .billing-overview class overrides it to zero for cards inside this grid. -->
<style>.billing-overview .card + .card { margin-top: 0; }</style>
<div class="billing-overview" style="display:grid; grid-template-columns:repeat(4,1fr); gap:1.25rem; margin-bottom:2rem; align-items:stretch;">
    <?php
    // Helper: render a stat card with pinned label/value/sub positions.
    // Each card has the same three-zone layout regardless of content,
    // so all labels, values, and subtitles align across the row.
    //   $label  — uppercase tiny label
    //   $value  — main bold value (HTML allowed)
    //   $sub    — sub-line HTML (optional)
    ?>

    <!-- Plan -->
    <section class="card">
        <div class="billing-stat">
            <span class="billing-stat-label">Plan</span>
            <div class="billing-stat-value">
                <?= $sub ? htmlspecialchars(ucfirst($plan)) : 'No Plan' ?>
            </div>
            <div class="billing-stat-sub">
                <?php if ($sub): ?>
                    <span class="badge <?= $status === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                    <?php if ($sd && $sd['cancel_at_period_end']): ?>
                        <span style="color:var(--danger); font-size:0.75rem; display:block; margin-top:2px;">Cancels at period end</span>
                    <?php endif; ?>
                <?php else: ?>
                    &nbsp;
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Seats -->
    <section class="card">
        <div class="billing-stat">
            <span class="billing-stat-label">Seats</span>
            <div class="billing-stat-value"><?= (int) $active_users ?> / <?= (int) $seat_limit ?></div>
            <div class="billing-stat-sub">
                <div style="width:100%; background:var(--border); border-radius:4px; height:5px; margin-bottom:4px; overflow:hidden;">
                    <div style="background:<?= $seatColor ?>; height:100%; width:<?= $seatPct ?>%; border-radius:4px; transition:width 0.3s;"></div>
                </div>
                <?= (int) $total_users ?> total user<?= $total_users !== 1 ? 's' : '' ?>
            </div>
        </div>
    </section>

    <!-- Cost / Started -->
    <section class="card">
        <div class="billing-stat">
            <span class="billing-stat-label"><?= $sd ? 'Cost' : ($isInvoice && $pricePerSeat > 0 ? 'Cost / Seat' : 'Started') ?></span>
            <div class="billing-stat-value">
                <?php if ($sd): ?>
                    <?= htmlspecialchars(strtoupper($sd['currency'])) ?>&nbsp;<?= number_format($sd['unit_amount'] / 100, 2) ?>
                    <span style="font-weight:400; font-size:0.85rem;">/seat/<?= htmlspecialchars($sd['interval']) ?></span>
                <?php elseif ($isInvoice && $pricePerSeat > 0): ?>
                    $<?= number_format($pricePerSeat / 100, 2) ?>
                    <span style="font-weight:400; font-size:0.85rem;">/seat</span>
                <?php else: ?>
                    <?= $sub ? date('j M Y', strtotime($sub['started_at'])) : '—' ?>
                <?php endif; ?>
            </div>
            <div class="billing-stat-sub">
                <?php if ($sd): ?>
                    <?= (int) ($sd['quantity'] ?? 1) ?> seat<?= ($sd['quantity'] ?? 1) !== 1 ? 's' : '' ?> billed
                <?php elseif ($isInvoice && $pricePerSeat > 0 && $seat_limit > 0): ?>
                    $<?= number_format($totalCostCents / 100, 2) ?> total / <?= strtolower($periodLabel) ?>
                <?php else: ?>
                    &nbsp;
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Period -->
    <section class="card">
        <div class="billing-stat">
            <span class="billing-stat-label">Period</span>
            <div class="billing-stat-value">
                <?php if ($sd): ?>
                    <?= date('j M Y', strtotime($sd['current_period_end'])) ?>
                <?php elseif ($isInvoice && $nextInvDate): ?>
                    <?= date('j M Y', strtotime($nextInvDate)) ?>
                <?php elseif ($sub && $sub['expires_at']): ?>
                    <?= date('j M Y', strtotime($sub['expires_at'])) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="billing-stat-sub">
                <?php if ($sd): ?>
                    Next renewal
                <?php elseif ($isInvoice && $nextInvDate): ?>
                    Next invoice due
                <?php elseif ($isInvoice): ?>
                    <?= htmlspecialchars($periodLabel) ?>
                <?php elseif ($sub && $sub['expires_at']): ?>
                    Expires
                <?php else: ?>
                    &nbsp;
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<style>
.billing-stat {
    padding: 1.25rem 1.25rem 1rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}
.billing-stat-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #94a3b8;
    font-weight: 600;
    display: block;
    margin-bottom: 0.5rem;
}
.billing-stat-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.1;
    margin-bottom: 0.5rem;
}
.billing-stat-sub {
    font-size: 0.78rem;
    color: #64748b;
    min-height: 1.2em;
    width: 100%;
    text-align: center;
}
</style>

<!-- Flash messages -->
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>

<!-- Plan Details -->
<style>.billing-detail .card + .card { margin-top: 0; }</style>
<div class="billing-detail" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 2rem; align-items: start;">
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
            <?php elseif ($isInvoice): ?>
                <?php if ($nextInvDate): ?>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem;">
                    <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:.06em; color:#1d4ed8; font-weight:700; margin-bottom:4px;">Next Invoice</div>
                    <div style="font-size:1.25rem; font-weight:800; color:#1e40af;"><?= date('j M Y', strtotime($nextInvDate)) ?></div>
                    <?php if ($totalCostCents > 0): ?>
                    <div style="font-size:0.85rem; color:#2563eb; margin-top:4px;">
                        $<?= number_format($totalCostCents / 100, 2) ?> &middot; <?= htmlspecialchars($periodLabel) ?> billing
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                    Invoiced billing &mdash; invoices are sent directly to your billing contact.
                </p>
                <?php endif; ?>
                <p class="text-muted" style="font-size: 0.8rem;">
                    To update billing details or raise a query, contact <a href="mailto:support@stratflow.io">support@stratflow.io</a>.
                </p>
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
     Purchase Seats
     =========================== -->
<?php if ($sub): ?>
<section class="card mt-4">
    <div class="card-header"><h2 class="card-title">Purchase Seats</h2></div>
    <div class="card-body">
        <?php if ($isInvoice): ?>
            <p style="font-size:0.875rem; color:var(--text-muted); margin-bottom:1.25rem;">
                Add seats to your subscription. The additional cost will be reflected in your next invoice.
                <?php if ($pricePerSeat > 0): ?>
                    Current rate: <strong>$<?= number_format($pricePerSeat / 100, 2) ?>/seat/<?= strtolower($periodLabel) ?></strong>.
                <?php endif; ?>
            </p>
            <form method="POST" action="/app/admin/billing/seats/invoice" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div style="display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap;">
                    <div>
                        <label style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:0.25rem;">Seats to Add</label>
                        <input type="number" name="seats_to_add" id="invoice-seats-input"
                               min="1" max="1000" value="1" class="form-control" style="width:110px;"
                               oninput="updateInvoiceSeatPreview()">
                    </div>
                    <?php if ($pricePerSeat > 0): ?>
                    <div id="invoice-seat-preview" style="font-size:0.875rem; color:#2563eb; padding-bottom:0.5rem;">
                        <span id="invoice-preview-text">+$<?= number_format($pricePerSeat / 100, 2) ?>/<?= strtolower($periodLabel) ?></span>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Add seats to your subscription? This will be billed on your next invoice.')">
                        Add Seats
                    </button>
                </div>
            </form>
            <?php if ($pricePerSeat > 0): ?>
            <script>
            (function() {
                var pricePerSeat    = <?= $pricePerSeat ?>;
                var periodLabel     = <?= json_encode(strtolower($periodLabel)) ?>;
                window.updateInvoiceSeatPreview = function() {
                    var n    = parseInt(document.getElementById('invoice-seats-input').value, 10) || 1;
                    var cost = (pricePerSeat * n / 100).toFixed(2);
                    document.getElementById('invoice-preview-text').textContent =
                        '+$' + cost + '/' + periodLabel + ' (' + n + ' seat' + (n !== 1 ? 's' : '') + ')';
                };
            })();
            </script>
            <?php endif; ?>

        <?php else: ?>
            <p style="font-size:0.875rem; color:var(--text-muted); margin-bottom:1.25rem;">
                Choose how many seats you'd like and you'll be taken to our secure Stripe checkout to complete the purchase.
            </p>
            <?php if ($has_stripe_price_config ?? false): ?>
            <form method="POST" action="/app/admin/billing/seats/stripe" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div style="display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap;">
                    <div>
                        <label style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:0.25rem;">Number of Seats</label>
                        <input type="number" name="seat_quantity" min="1" max="1000"
                               value="<?= (int) $seat_limit ?>"
                               class="form-control" style="width:110px;">
                    </div>
                    <button type="submit" class="btn btn-primary">Go to Stripe Checkout</button>
                </div>
            </form>
            <?php else: ?>
            <p style="font-size:0.875rem; color:var(--danger);">
                Stripe pricing is not configured for this plan. Contact <a href="mailto:support@stratflow.io">support@stratflow.io</a>.
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Stripe Subscriptions (Stripe billing only)
     =========================== -->
<?php if (!$isInvoice && !empty($stripe_subscriptions)): ?>
<section class="card mt-4">
    <div class="card-header"><h2 class="card-title">Active Subscriptions</h2></div>
    <div class="card-body" style="padding:0;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="padding:0.6rem 1.25rem; text-align:left; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Product</th>
                    <th style="padding:0.6rem 1.25rem; text-align:center; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Seats</th>
                    <th style="padding:0.6rem 1.25rem; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Price/Seat</th>
                    <th style="padding:0.6rem 1.25rem; text-align:left; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Billing Period</th>
                    <th style="padding:0.6rem 1.25rem; text-align:center; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stripe_subscriptions as $ss): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:0.75rem 1.25rem; font-size:0.875rem; font-weight:600;"><?= htmlspecialchars($ss['product_name']) ?></td>
                    <td style="padding:0.75rem 1.25rem; text-align:center; font-size:0.875rem;"><?= (int) $ss['quantity'] ?></td>
                    <td style="padding:0.75rem 1.25rem; text-align:right; font-size:0.875rem;">
                        <?= htmlspecialchars($ss['currency']) ?>&nbsp;<?= number_format($ss['unit_amount'] / 100, 2) ?>/<?= htmlspecialchars($ss['interval']) ?>
                    </td>
                    <td style="padding:0.75rem 1.25rem; font-size:0.875rem; color:var(--text-muted);">
                        <?= htmlspecialchars($ss['current_period_start']) ?> &rarr; <?= htmlspecialchars($ss['current_period_end']) ?>
                        <?php if ($ss['cancel_at_period_end']): ?>
                            <span style="color:var(--danger); font-size:0.75rem; margin-left:0.4rem;">(cancels at period end)</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem 1.25rem; text-align:center;">
                        <?php
                        $ssBadge = match($ss['status']) {
                            'active'   => 'badge-success',
                            'trialing' => 'badge-info',
                            'past_due' => 'badge-warning',
                            default    => 'badge-secondary',
                        };
                        ?>
                        <span class="badge <?= $ssBadge ?>"><?= htmlspecialchars(ucfirst($ss['status'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Cost Breakdown (invoice billing only)
     =========================== -->
<?php if ($isInvoice && $pricePerSeat > 0): ?>
<section class="card mt-4">
    <div class="card-header"><h2 class="card-title">Cost Breakdown</h2></div>
    <div class="card-body" style="padding:0;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="padding:0.6rem 1.25rem; text-align:left; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Item</th>
                    <th style="padding:0.6rem 1.25rem; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Unit Price</th>
                    <th style="padding:0.6rem 1.25rem; text-align:center; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Qty</th>
                    <th style="padding:0.6rem 1.25rem; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600;">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:0.75rem 1.25rem;">
                        <div style="font-weight:600; font-size:0.9rem;">StratFlow <?= htmlspecialchars(ucfirst($plan)) ?></div>
                        <div style="font-size:0.78rem; color:var(--text-muted);"><?= htmlspecialchars($periodLabel) ?> subscription &mdash; <?= $active_users ?> of <?= $seat_limit ?> seats in use</div>
                    </td>
                    <td style="padding:0.75rem 1.25rem; text-align:right; font-size:0.9rem;">$<?= number_format($pricePerSeat / 100, 2) ?>/seat</td>
                    <td style="padding:0.75rem 1.25rem; text-align:center; font-size:0.9rem;"><?= $seat_limit ?></td>
                    <td style="padding:0.75rem 1.25rem; text-align:right; font-size:0.9rem; font-weight:700;">$<?= number_format($totalCostCents / 100, 2) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="background:#f9fafb;">
                    <td colspan="3" style="padding:0.75rem 1.25rem; text-align:right; font-size:0.85rem; color:var(--text-muted); font-weight:600;">Total per <?= strtolower($periodLabel) ?></td>
                    <td style="padding:0.75rem 1.25rem; text-align:right; font-size:1.1rem; font-weight:800; color:#1e293b;">$<?= number_format($totalCostCents / 100, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Billing Contact
     =========================== -->
<section class="card mt-4">
    <div class="card-header"><h2 class="card-title">Billing Contact</h2></div>
    <div class="card-body">
        <p style="font-size:0.875rem; color:var(--text-muted); margin-bottom:1rem;">
            Invoices are sent to this contact. Keep it up to date to ensure you receive your invoices.
        </p>
        <form method="POST" action="/app/admin/billing/contact" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:0.25rem;">Contact Name</label>
                    <input type="text" name="billing_contact_name" class="form-control"
                           value="<?= htmlspecialchars($billingContact['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Jane Smith" style="min-width:220px;">
                </div>
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:0.25rem;">Invoice Email</label>
                    <input type="email" name="billing_contact_email" class="form-control"
                           value="<?= htmlspecialchars($billingContact['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="accounts@example.com" style="min-width:260px;">
                </div>
                <button type="submit" class="btn btn-primary">Save Contact</button>
            </div>
        </form>
    </div>
</section>

<!-- ===========================
     Invoices
     =========================== -->
<section class="card mt-4">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin: 0;">Invoices</h2>
            <small class="text-muted">Your subscription invoices<?= $xero_connected ? ' — push any to <strong>' . htmlspecialchars($xero_tenant_name ?? 'Xero') . '</strong>' : '' ?></small>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <?php if ($xero_connected): ?>
                <span class="badge badge-success" style="font-size: 0.82rem; padding: 4px 10px;">Xero Connected</span>
                <form method="POST" action="/app/admin/xero/disconnect" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Disconnect Xero? Cached invoices will be removed.')">
                        Disconnect
                    </button>
                </form>
            <?php else: ?>
                <a href="/app/admin/xero/connect" class="btn btn-sm btn-secondary">Connect Xero</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($stripe_invoices)): ?>
            <p style="padding: 1.25rem; color: var(--text-muted); font-size: 0.9rem;">No invoices found.</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border);">
                        <th style="padding: 0.6rem 1.25rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 600;">Date</th>
                        <th style="padding: 0.6rem 1.25rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 600;">Description</th>
                        <th style="padding: 0.6rem 1.25rem; text-align: right; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 600;">Amount</th>
                        <th style="padding: 0.6rem 1.25rem; text-align: center; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); font-weight: 600;">Status</th>
                        <th style="padding: 0.6rem 1.25rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stripe_invoices as $inv): ?>
                        <?php
                            $isPaid     = ($inv->status ?? '') === 'paid';
                            $isPushed   = in_array($inv->id, $pushed_to_xero ?? [], true);
                            $amount     = number_format(($inv->amount_paid ?? $inv->amount_due ?? 0) / 100, 2);
                            $currency   = strtoupper($inv->currency ?? 'NZD');
                            $date       = date('j M Y', $inv->created ?? time());
                            $desc       = $inv->description ?? ($inv->lines->data[0]->description ?? 'StratFlow Subscription');
                        ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.75rem 1.25rem; font-size: 0.875rem; color: var(--text-muted);"><?= htmlspecialchars($date) ?></td>
                            <td style="padding: 0.75rem 1.25rem; font-size: 0.875rem;"><?= htmlspecialchars($desc) ?></td>
                            <td style="padding: 0.75rem 1.25rem; font-size: 0.875rem; text-align: right; font-weight: 600;"><?= $currency ?> <?= $amount ?></td>
                            <td style="padding: 0.75rem 1.25rem; text-align: center;">
                                <span class="badge <?= $isPaid ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars(ucfirst($inv->status ?? 'open')) ?></span>
                            </td>
                            <td style="padding: 0.75rem 1.25rem; text-align: right; white-space: nowrap;">
                                <?php if (!empty($inv->invoice_pdf)): ?>
                                    <a href="<?= htmlspecialchars($inv->invoice_pdf) ?>" target="_blank"
                                       style="font-size: 0.8rem; color: var(--text-muted); margin-right: 0.75rem;">PDF</a>
                                <?php endif; ?>
                                <?php if ($xero_connected): ?>
                                    <?php if ($isPushed): ?>
                                        <span style="font-size: 0.8rem; color: #22c55e; font-weight: 600;">✓ In Xero</span>
                                    <?php else: ?>
                                        <form method="POST" action="/app/admin/invoices/<?= htmlspecialchars($inv->id) ?>/push-to-xero" class="inline-form" style="display: inline;">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary">Push to Xero</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
