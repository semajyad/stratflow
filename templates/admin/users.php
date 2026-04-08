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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'org_admin' ? 'badge-primary' : ($u['role'] === 'superadmin' ? 'badge-warning' : 'badge-secondary') ?>">
                                    <?= htmlspecialchars($u['role']) ?>
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
                            <td colspan="5">
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
                                            <select name="role" class="form-input">
                                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                <option value="org_admin" <?= $u['role'] === 'org_admin' ? 'selected' : '' ?>>Admin</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">New Password <small>(optional)</small></label>
                                            <input type="password" name="password" class="form-input"
                                                   placeholder="Leave blank to keep" minlength="8">
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
                    <option value="user">User</option>
                    <option value="org_admin">Admin</option>
                </select>
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
