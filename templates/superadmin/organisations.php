<?php
/**
 * Superadmin Organisations Template
 *
 * Table of all organisations with status, user count, subscription info, and actions.
 * Includes assign-superadmin section at the bottom.
 *
 * Variables: $user (array), $orgs (array), $org_subs (array), $all_users (array),
 *            $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Manage Organisations</h1>
    <p class="page-subtitle">View, suspend, enable, export, or delete organisations.</p>
</div>

<!-- ===========================
     Flash Messages
     =========================== -->
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
    <div class="card-header">
        <h2 class="card-title">Create Organisation</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="/superadmin/organisations/create">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="sprint-gen-grid">
                <div class="sprint-gen-field">
                    <label>Organisation Name</label>
                    <input type="text" name="org_name" class="form-control" required placeholder="e.g. Acme Corp">
                </div>
                <div class="sprint-gen-field">
                    <label>Plan Type</label>
                    <select name="plan_type" class="form-control">
                        <option value="product">Product</option>
                        <option value="consultancy">Consultancy</option>
                    </select>
                </div>
                <div class="sprint-gen-field" style="align-self: end;">
                    <button type="submit" class="btn btn-primary">Create Organisation</button>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- ===========================
     Organisations Table
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">All Organisations</h2>
    </div>
    <div class="card-body">
        <?php if (empty($orgs)): ?>
            <p class="text-muted">No organisations found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table org-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Users</th>
                            <th>Subscription</th>
                            <th>Jira</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orgs as $org): ?>
                            <?php
                                $orgId   = (int) $org['id'];
                                $isActive = (int) ($org['is_active'] ?? 1);
                                $sub     = $org_subs[$orgId] ?? null;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($org['name']) ?></td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) ($org['user_count'] ?? 0) ?></td>
                                <td>
                                    <?php if ($sub): ?>
                                        <span class="badge badge-primary"><?= htmlspecialchars(ucfirst($sub['plan_type'] ?? 'Unknown')) ?></span>
                                        <span class="badge <?= ($sub['status'] ?? '') === 'active' ? 'badge-success' : 'badge-muted' ?>">
                                            <?= htmlspecialchars(ucfirst($sub['status'] ?? 'Unknown')) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $jiraIntegration = \StratFlow\Models\Integration::findByOrgAndProvider($this->db ?? \StratFlow\Core\Database::getInstance(), $orgId, 'jira');
                                        $jiraEnabled = $jiraIntegration !== null;
                                    ?>
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>/jira" style="display:inline;">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <?php if ($jiraEnabled): ?>
                                            <input type="hidden" name="action" value="disable">
                                            <button type="submit" class="btn btn-sm btn-success" style="font-size:0.75rem;">Enabled</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="enable">
                                            <button type="submit" class="btn btn-sm btn-secondary" style="font-size:0.75rem;">Disabled</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td><?= htmlspecialchars($org['created_at'] ?? '') ?></td>
                                <td class="org-actions">
                                    <!-- Suspend/Enable toggle -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>" style="display:inline;">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <?php if ($isActive): ?>
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn btn-sm btn-warning">Suspend</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="enable">
                                            <button type="submit" class="btn btn-sm btn-success">Enable</button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Export -->
                                    <a href="/superadmin/organisations/<?= $orgId ?>/export" class="btn btn-sm btn-secondary">Export</a>

                                    <!-- Delete -->
                                    <form method="POST" action="/superadmin/organisations/<?= $orgId ?>" style="display:inline;"
                                          onsubmit="return confirm('Are you sure you want to delete this organisation? This cannot be undone.');">
                                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ===========================
     Assign Superadmin
     =========================== -->
<section class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Assign Superadmin Role</h2>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">Promote a user to superadmin. This grants full system-wide access.</p>
        <form method="POST" action="/superadmin/assign-superadmin" class="admin-inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <select name="user_id" class="form-control" required>
                        <option value="">Select a user...</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                                <?php if (!empty($u['org_name'])): ?> — <?= htmlspecialchars($u['org_name']) ?><?php endif; ?>
                                <?php if ($u['role'] === 'superadmin'): ?> [already superadmin]<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Assign Superadmin</button>
                </div>
            </div>
        </form>
    </div>
</section>
