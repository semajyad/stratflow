<?php
/**
 * Admin User Management Template
 *
 * Lists all organisation users with role badges and status. Provides
 * inline forms for creating new users and editing existing ones.
 *
 * Variables: $user (array), $users (array), $seat_limit (int),
 *            $user_count (int), $csrf_token (string)
 */
$assignable_roles = $assignable_roles ?? ['viewer', 'user', 'project_manager', 'org_admin'];
$roleLabels = [
    'viewer' => 'Viewer (read-only)',
    'user' => 'User (edit items)',
    'project_manager' => 'Project Manager',
    'org_admin' => 'Organisation Admin',
    'developer' => 'Developer',
    'superadmin' => 'Superadmin',
];
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">
            <a href="/app/admin">&larr; Back to Administration</a>
        </p>
    </div>
    <div class="seat-counter">
        <strong><?= (int) $user_count ?></strong> of <strong><?= (int) $seat_limit ?></strong> seats used
    </div>
</div>

<!-- ===========================
     User List
     =========================== -->
<section class="card">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title">Organisation Users</h2>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-user-section').classList.toggle('hidden')">
            + Add User
        </button>
    </div>

    <?php if (empty($users)): ?>
        <p class="empty-state">No users found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table user-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Flags</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php
                                    $roleBadge = match($u['role']) {
                                        'superadmin' => 'badge-warning',
                                        'org_admin' => 'badge-primary',
                                        'project_manager' => 'badge-info',
                                        'viewer' => 'badge-secondary',
                                        default => 'badge-secondary',
                                    };
                                    $roleLabel = match($u['role']) {
                                        'superadmin' => 'Superadmin',
                                        'org_admin' => 'Org Admin',
                                        'project_manager' => 'Project Manager',
                                        'developer' => 'Developer',
                                        'viewer' => 'Viewer',
                                        default => 'User',
                                    };
                                ?>
                                <span class="badge <?= $roleBadge ?>">
                                    <?= $roleLabel ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['is_project_admin'] ?? false): ?>
                                    <span class="badge badge-success" title="Can create and manage projects">Projects</span>
                                <?php endif; ?>
                                <?php if ($u['has_billing_access']): ?>
                                    <span class="badge badge-secondary" title="Billing access">Billing</span>
                                <?php endif; ?>
                                <?php if ($u['has_executive_access']): ?>
                                    <span class="badge badge-info" title="Executive dashboard access">Exec</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <button class="btn btn-sm btn-secondary"
                                            onclick="toggleEditUser(<?= (int) $u['id'] ?>)">Edit</button>
                                    <?php if ((int) $u['id'] !== (int) $user['id'] && $u['is_active']): ?>
                                        <form method="POST" action="/app/admin/users/<?= (int) $u['id'] ?>/delete" class="inline-form"
                                              onsubmit="return confirm('Deactivate this user?')">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <!-- Inline edit row -->
                        <tr id="edit-user-<?= (int) $u['id'] ?>" class="hidden">
                            <td colspan="6">
                                <form method="POST" action="/app/admin/users/<?= (int) $u['id'] ?>" class="admin-inline-form">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <div class="form-row gap-4">
                                        <div class="form-group">
                                            <label class="form-label">Name</label>
                                            <input type="text" name="full_name" class="form-input"
                                                   value="<?= htmlspecialchars($u['full_name']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-input"
                                                   value="<?= htmlspecialchars($u['email']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Role</label>
                                            <?php $editableRoles = array_values(array_unique(array_merge([$u['role']], $assignable_roles))); ?>
                                            <select name="role" class="form-input">
                                                <?php foreach ($editableRoles as $assignableRole): ?>
                                                <option value="<?= htmlspecialchars($assignableRole) ?>" <?= $u['role'] === $assignableRole ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($roleLabels[$assignableRole] ?? ucwords(str_replace('_', ' ', $assignableRole))) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">New Password <small>(optional)</small></label>
                                            <div class="password-wrapper">
                                                <input type="password" name="password" class="form-input" placeholder="Leave blank to keep" minlength="12">
                                                <button type="button" class="password-toggle" onclick="togglePassword(this)" aria-label="Toggle password visibility">
                                                    <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <svg class="eye-off-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Access Flags</label>
                                            <div style="display:flex; flex-direction:column; gap:6px; padding-top:4px;">
                                                <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                                                    <input type="hidden" name="is_project_admin" value="0">
                                                    <input type="checkbox" name="is_project_admin" value="1"
                                                           <?= ($u['is_project_admin'] ?? false) ? 'checked' : '' ?>>
                                                    Project admin (create &amp; manage projects)
                                                </label>
                                                <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                                                    <input type="hidden" name="has_billing_access" value="0">
                                                    <input type="checkbox" name="has_billing_access" value="1"
                                                           <?= $u['has_billing_access'] ? 'checked' : '' ?>>
                                                    Billing access
                                                </label>
                                                <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                                                    <input type="hidden" name="has_executive_access" value="0">
                                                    <input type="checkbox" name="has_executive_access" value="1"
                                                           <?= $u['has_executive_access'] ? 'checked' : '' ?>>
                                                    Executive dashboard
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group" style="align-self: flex-end;">
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            <button type="button" class="btn btn-secondary btn-sm"
                                                    onclick="toggleEditUser(<?= (int) $u['id'] ?>)">Cancel</button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ===========================
     Add User Form
     =========================== -->
<section id="add-user-section" class="card mt-4 hidden">
    <div class="card-header">
        <h2 class="card-title">Add New User</h2>
    </div>
    <form method="POST" action="/app/admin/users" class="card-body">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row gap-4">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-input">
                    <?php foreach ($assignable_roles as $assignableRole): ?>
                    <option value="<?= htmlspecialchars($assignableRole) ?>" <?= $assignableRole === 'user' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($roleLabels[$assignableRole] ?? ucwords(str_replace('_', ' ', $assignableRole))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Access Flags</label>
                <div style="display:flex; flex-direction:column; gap:6px; padding-top:4px;">
                    <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                        <input type="hidden" name="is_project_admin" value="0">
                        <input type="checkbox" name="is_project_admin" value="1">
                        Project admin (create &amp; manage projects)
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                        <input type="hidden" name="has_billing_access" value="0">
                        <input type="checkbox" name="has_billing_access" value="1">
                        Billing access
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer;">
                        <input type="hidden" name="has_executive_access" value="0">
                        <input type="checkbox" name="has_executive_access" value="1">
                        Executive dashboard
                    </label>
                </div>
            </div>
        </div>
        <p style="color: #64748b; font-size: 14px; margin-top: 8px;">A welcome email will be sent so the user can set their own password.</p>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </form>
</section>

<script>
function toggleEditUser(id) {
    const row = document.getElementById('edit-user-' + id);
    if (row) row.classList.toggle('hidden');
}
</script>
