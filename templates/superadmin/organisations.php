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
    <p class="page-subtitle">Create, configure and manage client organisations.</p>
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
            <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Organisation Name</label>
                    <input type="text" name="org_name" class="form-control" required placeholder="e.g. Acme Corp" style="min-width:220px;">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Plan Type</label>
                    <select name="plan_type" class="form-control">
                        <option value="product">Product</option>
                        <option value="consultancy">Consultancy</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Billing</label>
                    <select name="billing_method" class="form-control">
                        <option value="invoiced">Invoiced</option>
                        <option value="stripe">Stripe</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Seats</label>
                    <input type="number" name="seat_limit" value="5" min="1" max="10000" class="form-control" style="width:80px;">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Period</label>
                    <select name="billing_period_months" class="form-control">
                        <option value="1">Monthly</option>
                        <option value="3">Quarterly</option>
                        <option value="6">6-Monthly</option>
                        <option value="12">Annual</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">$/Seat</label>
                    <input type="number" name="price_per_seat" value="0.00" min="0" step="0.01" class="form-control" style="width:90px;">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted);">Next Invoice</label>
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
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
        <h2 class="card-title">All Organisations</h2>
        <span class="text-muted" style="font-size:0.85rem;"><?= count($orgs) ?> organisation<?= count($orgs) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($orgs)): ?>
            <p class="text-muted" style="padding:1.25rem;">No organisations found.</p>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border);">
                        <th style="padding:0.6rem 1.25rem; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Name</th>
                        <th style="padding:0.6rem 1rem; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Plan</th>
                        <th style="padding:0.6rem 1rem; text-align:center; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Users / Seats</th>
                        <th style="padding:0.6rem 1rem; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Billing</th>
                        <th style="padding:0.6rem 1rem; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Status</th>
                        <th style="padding:0.6rem 1rem; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); font-weight:600;">Created</th>
                        <th style="padding:0.6rem 1.25rem;"></th>
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
                        ?>
                        <!-- Main row -->
                        <tr style="border-bottom:1px solid var(--border);" id="org-row-<?= $orgId ?>">
                            <td style="padding:0.85rem 1.25rem; font-weight:600;"><?= htmlspecialchars($org['name']) ?></td>
                            <td style="padding:0.85rem 1rem;">
                                <span class="badge badge-primary" style="font-size:0.75rem;"><?= htmlspecialchars(ucfirst($planType)) ?></span>
                            </td>
                            <td style="padding:0.85rem 1rem; text-align:center;">
                                <span style="font-size:0.875rem;"><?= $users ?> / <?= $seats ?></span>
                                <?php
                                    $pct = $seats > 0 ? min(100, ($users / $seats) * 100) : 0;
                                    $barColor = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? '#f0ad4e' : 'var(--primary)');
                                ?>
                                <div style="width:60px; height:4px; background:var(--border); border-radius:2px; margin:3px auto 0;">
                                    <div style="width:<?= $pct ?>%; height:100%; background:<?= $barColor ?>; border-radius:2px;"></div>
                                </div>
                            </td>
                            <td style="padding:0.85rem 1rem; font-size:0.85rem; color:var(--text-muted);"><?= htmlspecialchars($billingLabel) ?></td>
                            <td style="padding:0.85rem 1rem;">
                                <?php if ($isActive): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.85rem 1rem; font-size:0.8rem; color:var(--text-muted);"><?= htmlspecialchars($createdAt) ?></td>
                            <td style="padding:0.85rem 1.25rem; text-align:right; white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary js-toggle-target"
                                        data-target-id="org-edit-<?= $orgId ?>">Edit</button>
                                <a href="/superadmin/organisations/<?= $orgId ?>/export"
                                   class="btn btn-sm btn-secondary">Export</a>
                                <form method="POST" action="/superadmin/organisations/<?= $orgId ?>" style="display:inline;">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            data-confirm="Delete <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>? This cannot be undone.">Delete</button>
                                </form>
                            </td>
                        </tr>

                        <!-- Inline edit row (hidden by default) -->
                        <tr id="org-edit-<?= $orgId ?>" class="hidden" style="background:#f8fafc;">
                            <td colspan="7" style="padding:1.25rem 1.5rem; border-bottom:2px solid var(--primary);">
                                <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                                    <!-- Edit form -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>"
                                          style="display:contents;">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Organisation Name</label>
                                            <input type="text" name="org_name" value="<?= htmlspecialchars($org['name']) ?>"
                                                   class="form-control form-control-sm" style="min-width:200px;" required>
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Plan Type</label>
                                            <select name="plan_type" class="form-control form-control-sm">
                                                <option value="product"     <?= $planType === 'product'     ? 'selected' : '' ?>>Product</option>
                                                <option value="consultancy" <?= $planType === 'consultancy' ? 'selected' : '' ?>>Consultancy</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Seats</label>
                                            <input type="number" name="seat_limit" value="<?= $seats ?>" min="1" max="10000"
                                                   class="form-control form-control-sm" style="width:80px;">
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Billing</label>
                                            <select name="billing_method" class="form-control form-control-sm">
                                                <option value="invoiced" <?= $isInvoiced  ? 'selected' : '' ?>>Invoiced</option>
                                                <option value="stripe"   <?= !$isInvoiced ? 'selected' : '' ?>>Stripe</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Period</label>
                                            <select name="billing_period_months" class="form-control form-control-sm">
                                                <option value="1"  <?= (int) ($sub['billing_period_months'] ?? 1) === 1  ? 'selected' : '' ?>>Monthly</option>
                                                <option value="3"  <?= (int) ($sub['billing_period_months'] ?? 1) === 3  ? 'selected' : '' ?>>Quarterly</option>
                                                <option value="6"  <?= (int) ($sub['billing_period_months'] ?? 1) === 6  ? 'selected' : '' ?>>6-Monthly</option>
                                                <option value="12" <?= (int) ($sub['billing_period_months'] ?? 1) === 12 ? 'selected' : '' ?>>Annual</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">$/Seat</label>
                                            <input type="number" name="price_per_seat" step="0.01" min="0"
                                                   value="<?= number_format((int) ($sub['price_per_seat_cents'] ?? 0) / 100, 2) ?>"
                                                   class="form-control form-control-sm" style="width:90px;" placeholder="0.00">
                                        </div>
                                        <div>
                                            <label style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); display:block; margin-bottom:0.25rem;">Next Invoice</label>
                                            <input type="date" name="next_invoice_date"
                                                   value="<?= htmlspecialchars($sub['next_invoice_date'] ?? '') ?>"
                                                   class="form-control form-control-sm">
                                        </div>
                                        <div style="display:flex; gap:0.5rem;">
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                            <button type="button" class="btn btn-sm btn-secondary js-toggle-target"
                                                    data-target-id="org-edit-<?= $orgId ?>">Cancel</button>
                                        </div>
                                    </form>

                                    <!-- Suspend / Enable form — separate, never nested -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>"
                                          style="margin-left:auto;">
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
        <p class="text-muted" style="font-size:0.875rem; margin-bottom:1rem;">Promote a user to superadmin. Grants full system-wide access.</p>
        <form method="POST" action="/superadmin/assign-superadmin" style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="flex:1; min-width:280px;">
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
