<?php
/**
 * Admin Invoices Template
 *
 * Lists all Stripe invoices for the organisation with date, amount,
 * status, description, and a link to download the PDF.
 *
 * Variables: $user (array), $invoices (array of Stripe Invoice objects),
 *            $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">Invoices</h1>
        <p class="page-subtitle">
            <a href="/app/admin">&larr; Back to Administration</a>
        </p>
    </div>
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

<!-- ===========================
     Invoice Table
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Billing History</h2>
    </div>

    <?php if (empty($invoices)): ?>
        <p class="empty-state">No invoices found for your account.</p>
    <?php else: ?>
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
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(date('d M Y', $invoice->created)) ?>
                        </td>
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
                        <td>
                            <?= htmlspecialchars($invoice->description ?? ($invoice->lines->data[0]->description ?? '—')) ?>
                        </td>
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
    <?php endif; ?>
</section>
