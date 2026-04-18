<?php
/**
 * Superadmin Organisations Template
 *
 * Clean org table with inline edit panels. Columns: name, plan, seats, billing, status, created.
 * Edit panel: rename, plan type, seat limit, billing method toggle, suspend/enable.
 *
 * Variables: $user (array), $orgs (array), $org_subs (array), $all_users (array), $csrf_token (string)
 */
?>

<div class="page-header">
    <h1 class="page-title">Manage Organisations</h1>
    <p class="page-subtitle">
        <a href="/superadmin">&larr; Back to Superadmin Dashboard</a>
    </p>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- ===========================
     Create Organisation
     =========================== -->
<section class="card mb-4">
    <div class="card-header"><h2 class="card-title">Create Organisation</h2></div>
    <div class="card-body">
        <form method="POST" action="/superadmin/organisations/create">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="org-form-row">
                <div>
                    <label class="form-label org-form-label">Organisation Name</label>
                    <input type="text" name="org_name" class="form-control org-input-name" required placeholder="e.g. Acme Corp">
                </div>
                <div>
                    <label class="form-label org-form-label">Plan Type</label>
                    <select name="plan_type" class="form-control">
                        <option value="product">Product</option>
                        <option value="consultancy">Consultancy</option>
                    </select>
                </div>
                <div>
                    <label class="form-label org-form-label">Billing</label>
                    <select name="billing_method" class="form-control">
                        <option value="invoiced">Invoiced</option>
                        <option value="stripe">Stripe</option>
                    </select>
                </div>
                <div>
                    <label class="form-label org-form-label">Seats</label>
                    <input type="number" name="seat_limit" value="5" min="1" max="10000" class="form-control org-input-seats">
                </div>
                <div>
                    <label class="form-label org-form-label">Period</label>
                    <select name="billing_period_months" class="form-control">
                        <option value="1">Monthly</option>
                        <option value="3">Quarterly</option>
                        <option value="6">6-Monthly</option>
                        <option value="12">Annual</option>
                    </select>
                </div>
                <div>
                    <label class="form-label org-form-label">$/Seat</label>
                    <input type="number" name="price_per_seat" value="0.00" min="0" step="0.01" class="form-control org-input-price">
                </div>
                <div>
                    <label class="form-label org-form-label">Next Invoice</label>
                    <input type="date" name="next_invoice_date" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary">Create Organisation</button>
            </div>
        </form>
    </div>
</section>

<!-- ===========================
     Organisations Table
     =========================== -->
<section class="card">
    <div class="card-header integration-card-header">
        <h2 class="card-title">All Organisations</h2>
        <span class="text-muted org-card-header-meta"><?= count($orgs) ?> organisation<?= count($orgs) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body org-card-body--flush">
        <?php if (empty($orgs)): ?>
            <p class="text-muted org-empty-state">No organisations found.</p>
        <?php else: ?>
            <table class="org-table">
                <thead>
                    <tr class="org-table-head-row">
                        <th class="org-table-head org-table-head--name">Name</th>
                        <th class="org-table-head org-table-head--standard">Plan</th>
                        <th class="org-table-head org-table-head--standard org-table-head--center">Users / Seats</th>
                        <th class="org-table-head org-table-head--standard">Billing</th>
                        <th class="org-table-head org-table-head--standard">Features</th>
                        <th class="org-table-head org-table-head--standard">Status</th>
                        <th class="org-table-head org-table-head--standard">Created</th>
                        <th class="org-table-head org-table-head--actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orgs as $org): ?>
                        <?php
                            $orgId    = (int) $org['id'];
                            $isActive = (int) ($org['is_active'] ?? 1);
                            $sub      = $org_subs[$orgId] ?? null;
                            $planType = $sub['plan_type'] ?? 'none';
                            $seats    = (int) ($sub['user_seat_limit'] ?? 5);
                            $users    = (int) ($org['user_count'] ?? 0);
                            $subId    = $sub['stripe_subscription_id'] ?? '';
                            $isInvoiced = (empty($subId) || str_starts_with($subId, 'manual_'));
                            $billingLabel = $isInvoiced ? 'Invoiced' : 'Stripe';
                            $createdAt = date('j M Y', strtotime($org['created_at'] ?? 'now'));
                            $hasEvalBoard = (bool) ($sub['has_evaluation_board'] ?? false);
                        ?>
                        <!-- Main row -->
                        <tr class="org-table-row" id="org-row-<?= $orgId ?>">
                            <td class="org-table-cell org-table-cell--name org-name-cell"><?= htmlspecialchars($org['name']) ?></td>
                            <td class="org-table-cell org-table-cell--standard">
                                <span class="badge badge-primary org-plan-badge"><?= htmlspecialchars(ucfirst($planType)) ?></span>
                            </td>
                            <td class="org-table-cell org-table-cell--standard org-table-cell--center">
                                <span class="org-seat-summary"><?= $users ?> / <?= $seats ?></span>
                                <?php
                                    $pct = $seats > 0 ? min(100, ($users / $seats) * 100) : 0;
                                    $barTone = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'primary');
                                ?>
                                <progress class="org-seat-meter org-seat-meter--<?= htmlspecialchars($barTone) ?>" max="100" value="<?= (int) $pct ?>"><?= (int) $pct ?>%</progress>
                            </td>
                            <td class="org-table-cell org-table-cell--standard org-billing-copy"><?= htmlspecialchars($billingLabel) ?></td>
                            <td class="org-table-cell org-table-cell--standard">
                                <?php if ($hasEvalBoard): ?>
                                    <span class="badge badge-success">Eval Board</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="org-table-cell org-table-cell--standard">
                                <?php if ($isActive): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="org-table-cell org-table-cell--standard org-created-copy"><?= htmlspecialchars($createdAt) ?></td>
                            <td class="org-table-cell org-table-cell--actions">
                                <button type="button" class="btn btn-sm btn-secondary js-toggle-target"
                                        data-target-id="org-edit-<?= $orgId ?>">Edit</button>
                                <a href="/superadmin/organisations/<?= $orgId ?>/export"
                                   class="btn btn-sm btn-secondary">Export</a>
                                <form method="POST" action="/superadmin/organisations/<?= $orgId ?>" class="org-inline-form">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            data-confirm="Delete <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>? This cannot be undone.">Delete</button>
                                </form>
                            </td>
                        </tr>

                        <!-- Inline edit row (hidden by default) -->
                        <tr id="org-edit-<?= $orgId ?>" class="hidden org-edit-row">
                            <td colspan="8" class="org-edit-cell">
                                <div class="org-form-row">
                                    <!-- Edit form -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>"
                                          class="org-edit-form">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <div>
                                            <label class="org-edit-label">Organisation Name</label>
                                            <input type="text" name="org_name" value="<?= htmlspecialchars($org['name']) ?>"
                                                   class="form-control form-control-sm org-input-name--edit" required>
                                        </div>
                                        <div>
                                            <label class="org-edit-label">Plan Type</label>
                                            <select name="plan_type" class="form-control form-control-sm">
                                                <option value="product"     <?= $planType === 'product'     ? 'selected' : '' ?>>Product</option>
                                                <option value="consultancy" <?= $planType === 'consultancy' ? 'selected' : '' ?>>Consultancy</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="org-edit-label">Seats</label>
                                            <input type="number" name="seat_limit" value="<?= $seats ?>" min="1" max="10000"
                                                   class="form-control form-control-sm org-input-seats">
                                        </div>
                                        <div>
                                            <label class="org-edit-label">Billing</label>
                                            <select name="billing_method" class="form-control form-control-sm">
                                                <option value="invoiced" <?= $isInvoiced  ? 'selected' : '' ?>>Invoiced</option>
                                                <option value="stripe"   <?= !$isInvoiced ? 'selected' : '' ?>>Stripe</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="org-edit-label">Period</label>
                                            <select name="billing_period_months" class="form-control form-control-sm">
                                                <option value="1"  <?= (int) ($sub['billing_period_months'] ?? 1) === 1  ? 'selected' : '' ?>>Monthly</option>
                                                <option value="3"  <?= (int) ($sub['billing_period_months'] ?? 1) === 3  ? 'selected' : '' ?>>Quarterly</option>
                                                <option value="6"  <?= (int) ($sub['billing_period_months'] ?? 1) === 6  ? 'selected' : '' ?>>6-Monthly</option>
                                                <option value="12" <?= (int) ($sub['billing_period_months'] ?? 1) === 12 ? 'selected' : '' ?>>Annual</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="org-edit-label">$/Seat</label>
                                            <input type="number" name="price_per_seat" step="0.01" min="0"
                                                   value="<?= number_format((int) ($sub['price_per_seat_cents'] ?? 0) / 100, 2) ?>"
                                                   class="form-control form-control-sm org-input-price" placeholder="0.00">
                                        </div>
                                        <div>
                                            <label class="org-edit-label">Next Invoice</label>
                                            <input type="date" name="next_invoice_date"
                                                   value="<?= htmlspecialchars($sub['next_invoice_date'] ?? '') ?>"
                                                   class="form-control form-control-sm">
                                        </div>
                                        <div class="org-edit-actions">
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                            <button type="button" class="btn btn-sm btn-secondary js-toggle-target"
                                                    data-target-id="org-edit-<?= $orgId ?>">Cancel</button>
                                        </div>
                                    </form>

                                    <!-- Suspend / Enable form — separate, never nested -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>"
                                          class="org-edit-spacer">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <?php if ($isActive): ?>
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn btn-sm btn-warning"
                                                    data-confirm="Suspend <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>?">
                                                Suspend
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="enable">
                                            <button type="submit" class="btn btn-sm btn-success">Enable</button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Evaluation Board toggle — separate form -->
                                    <?php if ($sub): ?>
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>/evaluation-board"
                                          class="org-edit-spacer">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <?php if ($hasEvalBoard): ?>
                                            <input type="hidden" name="action" value="disable">
                                            <button type="submit" class="btn btn-sm btn-secondary">Disable Eval Board</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="enable">
                                            <button type="submit" class="btn btn-sm btn-success">Enable Eval Board</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<!-- ===========================
     Assign Superadmin
     =========================== -->
<section class="card mt-4">
    <div class="card-header"><h2 class="card-title">Assign Superadmin Role</h2></div>
    <div class="card-body">
        <p class="text-muted org-superadmin-copy">Promote a user to superadmin. Grants full system-wide access.</p>
        <form method="POST" action="/superadmin/assign-superadmin" class="org-superadmin-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="org-superadmin-user">
                <select name="user_id" class="form-control" required>
                    <option value="">Select a user...</option>
                    <?php foreach ($all_users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>">
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                            <?php if (!empty($u['org_name'])): ?> — <?= htmlspecialchars($u['org_name']) ?><?php endif; ?>
                            <?php if ($u['role'] === 'superadmin'): ?> [superadmin]<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Assign Superadmin</button>
        </form>
    </div>
</section>
