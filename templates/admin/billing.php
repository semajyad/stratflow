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
$seatTone  = $seatPct >= 90 ? 'danger' : ($seatPct >= 70 ? 'warning' : 'primary');

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
<div class="billing-overview">
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
                        <span class="billing-stat-subnote-danger">Cancels at period end</span>
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
                <div class="billing-seat-meter">
                    <progress class="billing-seat-meter__progress billing-seat-meter__progress--<?= htmlspecialchars($seatTone, ENT_QUOTES, 'UTF-8') ?>" max="100" value="<?= (float) $seatPct ?>"></progress>
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
                    <span class="billing-stat-value-subtle">/seat/<?= htmlspecialchars($sd['interval']) ?></span>
                <?php elseif ($isInvoice && $pricePerSeat > 0): ?>
                    $<?= number_format($pricePerSeat / 100, 2) ?>
                    <span class="billing-stat-value-subtle">/seat</span>
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

<!-- Flash messages -->
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>

<!-- Plan Details -->
<div class="billing-detail">
    <section class="card">
        <div class="card-header"><h2 class="card-title">Plan Details</h2></div>
        <div class="card-body">
            <table class="billing-table">
                <tr class="billing-table__row">
                    <td class="billing-table__cell--y-only billing-table__cell--muted billing-table__org-col">Organisation</td>
                    <td class="billing-table__cell--y-only billing-table__cell--strong"><?= htmlspecialchars($org['name'] ?? '') ?></td>
                </tr>
                <tr class="billing-table__row">
                    <td class="billing-table__cell--y-only billing-table__cell--muted">Plan Type</td>
                    <td class="billing-table__cell--y-only"><?= $sub ? ucfirst(htmlspecialchars($plan)) : 'No active plan' ?></td>
                </tr>
                <tr class="billing-table__row">
                    <td class="billing-table__cell--y-only billing-table__cell--muted">User Seats</td>
                    <td class="billing-table__cell--y-only"><?= (int) $active_users ?> active of <?= (int) $seat_limit ?> allowed</td>
                </tr>
                <tr class="billing-table__row">
                    <td class="billing-table__cell--y-only billing-table__cell--muted">Features</td>
                    <td class="billing-table__cell--y-only">
                        <?php if ($sub && $sub['has_evaluation_board']): ?>
                            <span class="badge badge-success">Sounding Board</span>
                        <?php endif; ?>
                        <span class="badge badge-info">Jira Integration</span>
                        <span class="badge badge-secondary">Standard</span>
                    </td>
                </tr>
                <?php if ($sd): ?>
                <tr>
                    <td class="billing-table__cell--y-only billing-table__cell--muted">Billing Period</td>
                    <td class="billing-table__cell--y-only"><?= htmlspecialchars($sd['current_period_start']) ?> to <?= htmlspecialchars($sd['current_period_end']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">Manage Subscription</h2></div>
        <div class="card-body">
            <?php if ($has_stripe): ?>
                <p class="billing-manage-copy">
                    Use the Stripe Customer Portal to manage your subscription, update payment methods, change seat quantities, or cancel.
                </p>
                <div class="billing-actions-stack">
                    <a href="/app/admin/billing/portal" class="btn btn-primary billing-action-link">
                        Open Billing Portal
                    </a>
                    <a href="/app/admin/invoices" class="btn btn-secondary billing-action-link">
                        View Invoices
                    </a>
                </div>
            <?php elseif ($isInvoice): ?>
                <?php if ($nextInvDate): ?>
                <div class="billing-next-invoice">
                    <div class="billing-next-invoice__label">Next Invoice</div>
                    <div class="billing-next-invoice__date"><?= date('j M Y', strtotime($nextInvDate)) ?></div>
                    <?php if ($totalCostCents > 0): ?>
                    <div class="billing-next-invoice__meta">
                        $<?= number_format($totalCostCents / 100, 2) ?> &middot; <?= htmlspecialchars($periodLabel) ?> billing
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="billing-manage-copy billing-manage-copy--tight">
                    Invoiced billing &mdash; invoices are sent directly to your billing contact.
                </p>
                <?php endif; ?>
                <p class="text-muted billing-help-copy--small">
                    To update billing details or raise a query, contact <a href="mailto:support@stratflow.io">support@stratflow.io</a>.
                </p>
            <?php else: ?>
                <p class="billing-manage-copy">
                    No billing account is linked to this organisation.
                </p>
                <p class="text-muted billing-help-copy">
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
            <p class="billing-purchase-copy">
                Add seats to your subscription. The additional cost will be reflected in your next invoice.
                <?php if ($pricePerSeat > 0): ?>
                    Current rate: <strong>$<?= number_format($pricePerSeat / 100, 2) ?>/seat/<?= strtolower($periodLabel) ?></strong>.
                <?php endif; ?>
            </p>
            <form method="POST" action="/app/admin/billing/seats/invoice" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="billing-inline-row">
                    <div>
                        <label class="billing-field-label">Seats to Add</label>
                        <input type="number" name="seats_to_add" id="invoice-seats-input"
                               min="1" max="1000" value="1" class="form-control billing-seat-input"
                               class="js-invoice-seats-input"
                               data-period-label="<?= htmlspecialchars(strtolower($periodLabel), ENT_QUOTES, 'UTF-8') ?>"
                               data-price-per-seat="<?= (int) $pricePerSeat ?>">
                    </div>
                    <?php if ($pricePerSeat > 0): ?>
                    <div id="invoice-seat-preview" class="billing-seat-preview">
                        <span id="invoice-preview-text">+$<?= number_format($pricePerSeat / 100, 2) ?>/<?= strtolower($periodLabel) ?></span>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary"
                            data-confirm="Add seats to your subscription? This will be billed on your next invoice.">
                        Add Seats
                    </button>
                </div>
            </form>

        <?php else: ?>
            <p class="billing-purchase-copy">
                Choose how many seats you'd like and you'll be taken to our secure Stripe checkout to complete the purchase.
            </p>
            <?php if ($has_stripe_price_config ?? false): ?>
            <form method="POST" action="/app/admin/billing/seats/stripe" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="billing-inline-row">
                    <div>
                        <label class="billing-field-label">Number of Seats</label>
                        <input type="number" name="seat_quantity" min="1" max="1000"
                               value="<?= (int) $seat_limit ?>"
                               class="form-control billing-seat-input">
                    </div>
                    <button type="submit" class="btn btn-primary">Go to Stripe Checkout</button>
                </div>
            </form>
            <?php else: ?>
            <p class="billing-alert-danger">
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
    <div class="card-body billing-card-body--flush">
        <table class="billing-table">
            <thead>
                <tr class="billing-table__row--header">
                    <th class="billing-table__cell--compact billing-table__heading">Product</th>
                    <th class="billing-table__cell--compact billing-table__cell--center billing-table__heading">Seats</th>
                    <th class="billing-table__cell--compact billing-table__cell--right billing-table__heading">Price/Seat</th>
                    <th class="billing-table__cell--compact billing-table__heading">Billing Period</th>
                    <th class="billing-table__cell--compact billing-table__cell--center billing-table__heading">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stripe_subscriptions as $ss): ?>
                <tr class="billing-table__row">
                    <td class="billing-table__cell billing-table__cell--strong"><?= htmlspecialchars($ss['product_name']) ?></td>
                    <td class="billing-table__cell billing-table__cell--center"><?= (int) $ss['quantity'] ?></td>
                    <td class="billing-table__cell billing-table__cell--right">
                        <?= htmlspecialchars($ss['currency']) ?>&nbsp;<?= number_format($ss['unit_amount'] / 100, 2) ?>/<?= htmlspecialchars($ss['interval']) ?>
                    </td>
                    <td class="billing-table__cell billing-table__cell--muted">
                        <?= htmlspecialchars($ss['current_period_start']) ?> &rarr; <?= htmlspecialchars($ss['current_period_end']) ?>
                        <?php if ($ss['cancel_at_period_end']): ?>
                            <span class="billing-status-note-danger">(cancels at period end)</span>
                        <?php endif; ?>
                    </td>
                    <td class="billing-table__cell billing-table__cell--center">
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
    <div class="card-body billing-card-body--flush">
        <table class="billing-table">
            <thead>
                <tr class="billing-table__row--header">
                    <th class="billing-table__cell--compact billing-table__heading">Item</th>
                    <th class="billing-table__cell--compact billing-table__cell--right billing-table__heading">Unit Price</th>
                    <th class="billing-table__cell--compact billing-table__cell--center billing-table__heading">Qty</th>
                    <th class="billing-table__cell--compact billing-table__cell--right billing-table__heading">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr class="billing-table__row">
                    <td class="billing-table__cell">
                        <div class="billing-line-item-title">StratFlow <?= htmlspecialchars(ucfirst($plan)) ?></div>
                        <div class="billing-line-item-subtitle"><?= htmlspecialchars($periodLabel) ?> subscription &mdash; <?= $active_users ?> of <?= $seat_limit ?> seats in use</div>
                    </td>
                    <td class="billing-table__cell billing-table__cell--right">$<?= number_format($pricePerSeat / 100, 2) ?>/seat</td>
                    <td class="billing-table__cell billing-table__cell--center"><?= $seat_limit ?></td>
                    <td class="billing-table__cell billing-table__cell--right billing-table__cell--strong">$<?= number_format($totalCostCents / 100, 2) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="billing-total-row">
                    <td colspan="3" class="billing-table__cell billing-table__cell--right billing-total-label">Total per <?= strtolower($periodLabel) ?></td>
                    <td class="billing-table__cell billing-table__cell--right billing-total-value">$<?= number_format($totalCostCents / 100, 2) ?></td>
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
        <p class="billing-contact-copy">
            Invoices are sent to this contact. Keep it up to date to ensure you receive your invoices.
        </p>
        <form method="POST" action="/app/admin/billing/contact" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="billing-inline-row">
                <div>
                    <label class="billing-field-label">Contact Name</label>
                    <input type="text" name="billing_contact_name" class="form-control billing-contact-input--name"
                           value="<?= htmlspecialchars($billingContact['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Jane Smith">
                </div>
                <div>
                    <label class="billing-field-label">Invoice Email</label>
                    <input type="email" name="billing_contact_email" class="form-control billing-contact-input--email"
                           value="<?= htmlspecialchars($billingContact['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="accounts@example.com">
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
    <div class="card-header billing-card-header--split">
        <div>
            <h2 class="card-title billing-card-title-reset">Invoices</h2>
            <small class="text-muted">Your subscription invoices<?= $xero_connected ? ' — push any to <strong>' . htmlspecialchars($xero_tenant_name ?? 'Xero') . '</strong>' : '' ?></small>
        </div>
        <div class="billing-header-actions">
            <?php if ($xero_connected): ?>
                <span class="badge badge-success billing-xero-badge">Xero Connected</span>
                <form method="POST" action="/app/admin/xero/disconnect" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            data-confirm="Disconnect Xero? Cached invoices will be removed.">
                        Disconnect
                    </button>
                </form>
            <?php else: ?>
                <a href="/app/admin/xero/connect" class="btn btn-sm btn-secondary">Connect Xero</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body billing-card-body--flush">
        <?php if (empty($stripe_invoices)): ?>
            <p class="billing-empty-state">No invoices found.</p>
        <?php else: ?>
            <table class="billing-table">
                <thead>
                    <tr class="billing-table__row--header">
                        <th class="billing-table__cell--compact billing-table__heading">Date</th>
                        <th class="billing-table__cell--compact billing-table__heading">Description</th>
                        <th class="billing-table__cell--compact billing-table__cell--right billing-table__heading">Amount</th>
                        <th class="billing-table__cell--compact billing-table__cell--center billing-table__heading">Status</th>
                        <th class="billing-table__cell--compact billing-table__heading billing-table__heading--blank"></th>
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
                        <tr class="billing-table__row">
                            <td class="billing-table__cell billing-table__cell--muted"><?= htmlspecialchars($date) ?></td>
                            <td class="billing-table__cell"><?= htmlspecialchars($desc) ?></td>
                            <td class="billing-table__cell billing-table__cell--right billing-table__cell--strong"><?= $currency ?> <?= $amount ?></td>
                            <td class="billing-table__cell billing-table__cell--center">
                                <span class="badge <?= $isPaid ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars(ucfirst($inv->status ?? 'open')) ?></span>
                            </td>
                            <td class="billing-table__cell billing-actions-cell">
                                <?php if (!empty($inv->invoice_pdf)): ?>
                                    <a href="<?= htmlspecialchars($inv->invoice_pdf) ?>" target="_blank"
                                       class="billing-pdf-link">PDF</a>
                                <?php endif; ?>
                                <?php if ($xero_connected): ?>
                                    <?php if ($isPushed): ?>
                                        <span class="billing-status-note-success">✓ In Xero</span>
                                    <?php else: ?>
                                        <form method="POST" action="/app/admin/invoices/<?= htmlspecialchars($inv->id) ?>/push-to-xero" class="inline-form">
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
