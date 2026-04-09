<?php
/**
 * Admin Invoices Template
 *
 * Shows Xero invoices (from local cache) when connected, with Stripe as
 * fallback/supplement. Includes create invoice form when Xero is active.
 *
 * Variables: $user (array), $xero_invoices (array), $xero_connected (bool),
 *            $xero_tenant_name (string|null), $stripe_invoices (array),
 *            $csrf_token (string), $flash_message (string|null), $flash_error (string|null)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header" style="display: flex; align-items: flex-start; justify-content: space-between;">
    <div>
        <h1 class="page-title">Invoices</h1>
        <p class="page-subtitle">
            <a href="/app/admin/billing">&larr; Back to Billing</a>
        </p>
    </div>
    <?php if ($xero_connected): ?>
        <div style="display: flex; gap: 0.5rem;">
            <form method="POST" action="/app/admin/invoices/sync" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" class="btn btn-sm btn-secondary">Sync from Xero</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================
     Flash Messages
     =========================== -->
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>

<?php if ($xero_connected): ?>

<!-- ===========================
     Create Xero Invoice
     =========================== -->
<section class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Create Invoice in Xero</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/admin/invoices/create">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr 120px 100px; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Contact / Client Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="contact_name" class="form-input" required placeholder="Acme Corporation">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-input" placeholder="StratFlow Subscription — Annual" value="StratFlow Subscription">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Amount <span style="color: var(--danger);">*</span></label>
                    <input type="number" name="amount" class="form-input" step="0.01" min="0.01" required placeholder="0.00">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-input">
                        <option value="NZD">NZD</option>
                        <option value="AUD">AUD</option>
                        <option value="USD">USD</option>
                        <option value="GBP">GBP</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; align-items: flex-end; gap: 1rem;">
                <div class="form-group" style="margin: 0; flex: 1;">
                    <label class="form-label">Reference <span class="text-muted" style="font-weight: 400;">(optional)</span></label>
                    <input type="text" name="reference" class="form-input" placeholder="SUB-001 or contract number">
                </div>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Create Invoice</button>
            </div>
            <p class="text-muted" style="font-size: 0.8rem; margin-top: 0.75rem;">
                Connected to <?= htmlspecialchars($xero_tenant_name ?? 'Xero') ?> &mdash; invoices are created as AUTHORISED with standard NZ GST applied.
            </p>
        </form>
    </div>
</section>

<!-- ===========================
     Xero Invoices (from cache)
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Xero Invoices</h2>
    </div>

    <?php if (empty($xero_invoices)): ?>
        <div class="card-body">
            <p class="text-muted">No invoices cached yet. Click <strong>Sync from Xero</strong> to pull existing invoices, or create a new one above.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Number</th>
                    <th>Client</th>
                    <th>Total</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th>Reference</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($xero_invoices as $inv): ?>
                    <?php
                        $status     = $inv['status'] ?? 'DRAFT';
                        $badgeClass = match ($status) {
                            'PAID'       => 'badge-success',
                            'AUTHORISED' => 'badge-primary',
                            'SUBMITTED'  => 'badge-info',
                            'VOIDED',
                            'DELETED'    => 'badge-secondary',
                            default      => 'badge-secondary',
                        };
                        $amountDue   = (float) ($inv['amount_due']  ?? 0);
                        $total       = (float) ($inv['total']       ?? 0);
                        $currency    = $inv['currency_code']         ?? 'NZD';
                        $invoiceDate = $inv['invoice_date'] ? date('j M Y', strtotime($inv['invoice_date'])) : '—';
                        $dueDate     = $inv['due_date']     ? date('j M Y', strtotime($inv['due_date']))     : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($invoiceDate) ?></td>
                        <td><?= htmlspecialchars($inv['invoice_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($inv['contact_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(number_format($total, 2) . ' ' . $currency) ?></td>
                        <td style="<?= $amountDue > 0 && $status === 'AUTHORISED' ? 'color: var(--danger); font-weight: 600;' : '' ?>">
                            <?= htmlspecialchars(number_format($amountDue, 2) . ' ' . $currency) ?>
                        </td>
                        <td><span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars(ucfirst(strtolower($status))) ?></span></td>
                        <td><?= htmlspecialchars($inv['reference'] ?? '—') ?></td>
                        <td>
                            <?php
                                $safeXeroUrl = !empty($inv['xero_url']) && preg_match('#^https://#i', $inv['xero_url'])
                                    ? $inv['xero_url'] : null;
                            ?>
                            <?php if ($safeXeroUrl): ?>
                                <a href="<?= htmlspecialchars($safeXeroUrl) ?>"
                                   class="btn btn-sm btn-secondary"
                                   target="_blank" rel="noopener">
                                    View in Xero
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php elseif (!empty($stripe_invoices)): ?>

<!-- ===========================
     Stripe Invoices (fallback)
     =========================== -->
<section class="card">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <h2 class="card-title">Billing History</h2>
        <small class="text-muted">
            <a href="/app/admin/billing">Connect Xero</a> for enterprise invoicing
        </small>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stripe_invoices as $invoice): ?>
                <tr>
                    <td><?= htmlspecialchars(date('d M Y', $invoice->created)) ?></td>
                    <td>
                        <?php
                            $amount   = $invoice->amount_paid ?? $invoice->amount_due ?? 0;
                            $currency = strtoupper($invoice->currency ?? 'usd');
                            echo htmlspecialchars(number_format($amount / 100, 2) . ' ' . $currency);
                        ?>
                    </td>
                    <td>
                        <?php
                            $status = $invoice->status ?? 'unknown';
                            $badgeClass = match ($status) {
                                'paid'   => 'badge-success',
                                'open'   => 'badge-warning',
                                'void'   => 'badge-secondary',
                                default  => 'badge-secondary',
                            };
                        ?>
                        <span class="badge <?= htmlspecialchars($badgeClass) ?>">
                            <?= htmlspecialchars(ucfirst($status)) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($invoice->description ?? ($invoice->lines->data[0]->description ?? '—')) ?></td>
                    <td>
                        <?php if (!empty($invoice->invoice_pdf)): ?>
                            <a href="/app/admin/invoices/<?= htmlspecialchars($invoice->id) ?>/download"
                               class="btn btn-sm btn-secondary"
                               target="_blank">
                                Download PDF
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php else: ?>

<!-- ===========================
     Empty State
     =========================== -->
<section class="card">
    <div class="card-body" style="text-align: center; padding: 3rem;">
        <p class="text-muted" style="margin-bottom: 1rem;">No invoices found for your account.</p>
        <a href="/app/admin/billing" class="btn btn-primary">Connect Xero for Invoicing</a>
    </div>
</section>

<?php endif; ?>
