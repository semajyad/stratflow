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

$roleHelpText = 'Viewer: read-only access across assigned areas. '
    . 'User: can create and edit roadmap content. '
    . 'Project Manager: manages delivery planning and team workflows. '
    . 'Organisation Admin: manages users, settings, and organisation administration. '
    . 'Developer: intended for API, PAT, and developer workflow access where enabled. '
    . 'Superadmin: full system-wide access.';

$accessFlagsHelpText = 'Project admin: can create, update, and manage projects. '
    . 'Billing access: can open billing pages, invoices, and subscription controls. '
    . 'Executive dashboard: can view executive reporting and roll-up dashboards.';
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
<section class="card card--allow-overflow">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title">Organisation Users</h2>
        <button class="btn btn-primary btn-sm js-toggle-target" data-target-id="add-user-section">
            + Add User
        </button>
    </div>

    <?php if (empty($users)): ?>
        <p class="empty-state">No users found.</p>
    <?php else: ?>
        <div class="table-responsive table-responsive--popovers">
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
                                <div class="text-muted gen-style-daea08">
                                    Account type: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $u['account_type'] ?? $u['role']))) ?>
                                </div>
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
                                <?php if (!empty($u['access_summary'])): ?>
                                    <div class="text-muted gen-style-0596d1">
                                        <?= htmlspecialchars(implode(' | ', $u['access_summary'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <button class="btn btn-sm btn-secondary js-toggle-target"
                                            type="button"
                                            data-target-id="edit-user-<?= (int) $u['id'] ?>">Edit</button>
                                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                                        <?php if ($u['is_active']): ?>
                                            <form method="POST" action="/app/admin/users/<?= (int) $u['id'] ?>/delete" class="inline-form"
                                                  data-confirm="Deactivate this user?">
                                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Deactivate</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="/app/admin/users/<?= (int) $u['id'] ?>/reactivate" class="inline-form"
                                                  data-confirm="Reactivate this user?">
                                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Reactivate</button>
                                            </form>
                                        <?php endif; ?>
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
                                            <label class="form-label">
                                                Role
                                                <span class="page-info" tabindex="0" role="button" aria-label="Role help">
                                                    <span class="page-info-btn" aria-hidden="true">i</span>
                                                    <span class="page-info-popover" role="tooltip"><?= htmlspecialchars($roleHelpText) ?></span>
                                                </span>
                                            </label>
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
                                                <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                                                    <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <svg class="eye-off-icon gen-style-cb4589" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">
                                                Access Flags
                                                <span class="page-info" tabindex="0" role="button" aria-label="Access flag help">
                                                    <span class="page-info-btn" aria-hidden="true">i</span>
                                                    <span class="page-info-popover" role="tooltip"><?= htmlspecialchars($accessFlagsHelpText) ?></span>
                                                </span>
                                            </label>
                                            <div class="gen-style-889e64">
                                                <label class="gen-style-6be3bc">
                                                    <input type="hidden" name="is_project_admin" value="0">
                                                    <input type="checkbox" name="is_project_admin" value="1"
                                                           <?= ($u['is_project_admin'] ?? false) ? 'checked' : '' ?>>
                                                    Project admin (create &amp; manage projects)
                                                </label>
                                                <label class="gen-style-6be3bc">
                                                    <input type="hidden" name="has_billing_access" value="0">
                                                    <input type="checkbox" name="has_billing_access" value="1"
                                                           <?= $u['has_billing_access'] ? 'checked' : '' ?>>
                                                    Billing access
                                                </label>
                                                <label class="gen-style-6be3bc">
                                                    <input type="hidden" name="has_executive_access" value="0">
                                                    <input type="checkbox" name="has_executive_access" value="1"
                                                           <?= $u['has_executive_access'] ? 'checked' : '' ?>>
                                                    Executive dashboard
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Jira Identity</label>
                                            <?php if ($jira_connected ?? false): ?>
                                                <div class="jira-user-picker" data-picker-id="edit-<?= (int) $u['id'] ?>">
                                                    <input type="text"
                                                           class="form-input jira-search-input"
                                                           placeholder="Search Jira users..."
                                                           autocomplete="off"
                                                           value="<?= htmlspecialchars($u['jira_display_name'] ?? '') ?>">
                                                    <ul class="jira-suggestions hidden"></ul>
                                                    <input type="hidden" name="jira_account_id"   value="<?= htmlspecialchars($u['jira_account_id'] ?? '') ?>">
                                                    <input type="hidden" name="jira_display_name" value="<?= htmlspecialchars($u['jira_display_name'] ?? '') ?>">
                                                </div>
                                            <?php else: ?>
                                                <p class="text-muted form-note">Connect Jira first to link a Jira account.</p>
                                                <input type="hidden" name="jira_account_id"   value="<?= htmlspecialchars($u['jira_account_id'] ?? '') ?>">
                                                <input type="hidden" name="jira_display_name" value="<?= htmlspecialchars($u['jira_display_name'] ?? '') ?>">
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group gen-style-703ccd">
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            <button type="button" class="btn btn-secondary btn-sm js-toggle-target"
                                                    data-target-id="edit-user-<?= (int) $u['id'] ?>">Cancel</button>
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
<section id="add-user-section" class="card card--allow-overflow mt-4 hidden">
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
                <label class="form-label">
                    Role
                    <span class="page-info" tabindex="0" role="button" aria-label="Role help">
                        <span class="page-info-btn" aria-hidden="true">i</span>
                        <span class="page-info-popover" role="tooltip"><?= htmlspecialchars($roleHelpText) ?></span>
                    </span>
                </label>
                <select name="role" class="form-input">
                    <?php foreach ($assignable_roles as $assignableRole): ?>
                    <option value="<?= htmlspecialchars($assignableRole) ?>" <?= $assignableRole === 'user' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($roleLabels[$assignableRole] ?? ucwords(str_replace('_', ' ', $assignableRole))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">
                    Access Flags
                    <span class="page-info" tabindex="0" role="button" aria-label="Access flag help">
                        <span class="page-info-btn" aria-hidden="true">i</span>
                        <span class="page-info-popover" role="tooltip"><?= htmlspecialchars($accessFlagsHelpText) ?></span>
                    </span>
                </label>
                <div class="gen-style-889e64">
                    <label class="gen-style-6be3bc">
                        <input type="hidden" name="is_project_admin" value="0">
                        <input type="checkbox" name="is_project_admin" value="1">
                        Project admin (create &amp; manage projects)
                    </label>
                    <label class="gen-style-6be3bc">
                        <input type="hidden" name="has_billing_access" value="0">
                        <input type="checkbox" name="has_billing_access" value="1">
                        Billing access
                    </label>
                    <label class="gen-style-6be3bc">
                        <input type="hidden" name="has_executive_access" value="0">
                        <input type="checkbox" name="has_executive_access" value="1">
                        Executive dashboard
                    </label>
                </div>
            </div>
        </div>
        <?php if ($jira_connected ?? false): ?>
        <div class="form-row gap-4 mt-4">
            <div class="form-group">
                <label class="form-label">Jira Identity <small>(optional)</small></label>
                <div class="jira-user-picker" data-picker-id="create">
                    <input type="text"
                           class="form-input jira-search-input"
                           placeholder="Search Jira users..."
                           autocomplete="off">
                    <ul class="jira-suggestions hidden"></ul>
                    <input type="hidden" name="jira_account_id"   value="">
                    <input type="hidden" name="jira_display_name" value="">
                </div>
            </div>
        </div>
        <?php endif; ?>
        <p class="gen-style-8c82cd">A welcome email will be sent so the user can set their own password.</p>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </form>
</section>

<style nonce="<?= \StratFlow\Core\Response::getNonce() ?>">
.jira-user-picker { position: relative; }
.jira-suggestions {
    position: absolute; z-index: 200; background: var(--bg-card, #fff);
    border: 1px solid var(--border-color, #ddd); border-radius: 6px;
    width: 100%; max-height: 220px; overflow-y: auto; margin: 0; padding: 0;
    list-style: none; box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.jira-suggestions li { padding: 8px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; }
.jira-suggestions li:hover, .jira-suggestions li.active { background: var(--bg-hover, #f0f4ff); }
.jira-suggestions li img { width: 20px; height: 20px; border-radius: 50%; }
.jira-suggestions li .js-name { font-size: .9em; font-weight: 500; }
.jira-suggestions li .js-email { font-size: .8em; color: var(--text-muted, #888); margin-left: 4px; }
.hidden { display: none !important; }
</style>

<script nonce="<?= \StratFlow\Core\Response::getNonce() ?>">
(function () {
    const SEARCH_URL = '/app/admin/integrations/jira/users';
    let debounceTimer;

    function initPicker(container) {
        const input   = container.querySelector('.jira-search-input');
        const list    = container.querySelector('.jira-suggestions');
        const idField = container.querySelector('input[name="jira_account_id"]');
        const dnField = container.querySelector('input[name="jira_display_name"]');

        // Show all assignable users on focus (empty query → endpoint sends 'a' fallback)
        input.addEventListener('focus', function () {
            if (list.children.length === 0) {
                fetchUsers(this.value.trim());
            } else {
                list.classList.remove('hidden');
            }
        });

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            idField.value = '';
            dnField.value = '';
            const q = this.value.trim();
            debounceTimer = setTimeout(() => fetchUsers(q), 220);
        });

        input.addEventListener('keydown', function (e) {
            const items = [...list.querySelectorAll('li')];
            const active = list.querySelector('li.active');
            const idx = items.indexOf(active);
            if (e.key === 'ArrowDown') { e.preventDefault(); items[Math.min(idx + 1, items.length - 1)]?.classList.add('active'); active?.classList.remove('active'); }
            if (e.key === 'ArrowUp')   { e.preventDefault(); items[Math.max(idx - 1, 0)]?.classList.add('active'); active?.classList.remove('active'); }
            if (e.key === 'Enter' && active) { e.preventDefault(); selectUser(active.dataset); }
            if (e.key === 'Escape') { list.innerHTML = ''; list.classList.add('hidden'); }
        });

        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) { list.innerHTML = ''; list.classList.add('hidden'); }
        });

        function fetchUsers(q) {
            fetch(SEARCH_URL + '?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    list.innerHTML = '';
                    if (!data.users || data.users.length === 0) { list.classList.add('hidden'); return; }
                    data.users.forEach(u => {
                        const li = document.createElement('li');
                        li.dataset.accountId = u.accountId;
                        li.dataset.displayName = u.displayName;
                        li.innerHTML = (u.avatar ? `<img src="${u.avatar}" alt="">` : '')
                            + `<span class="js-name">${escHtml(u.displayName)}</span>`
                            + (u.email ? `<span class="js-email">${escHtml(u.email)}</span>` : '');
                        li.addEventListener('click', () => selectUser(li.dataset));
                        list.appendChild(li);
                    });
                    list.classList.remove('hidden');
                })
                .catch(() => {});
        }

        function selectUser(d) {
            input.value  = d.displayName;
            idField.value = d.accountId;
            dnField.value = d.displayName;
            list.innerHTML = '';
            list.classList.add('hidden');
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.querySelectorAll('.jira-user-picker').forEach(initPicker);
})();
</script>

