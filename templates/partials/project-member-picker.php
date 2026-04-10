<?php
/**
 * Project Member Picker Partial
 *
 * Renders a searchable checkbox list of org users for project access control.
 * Included inside both #new-member-picker and #edit-member-picker divs.
 *
 * Expects $org_users (array of {id, full_name, email}) and $orgUsersJson in scope.
 */
?>
<div style="margin-bottom: 0.5rem;">
    <input type="text" class="form-input member-search-input"
           placeholder="Search users..."
           style="font-size:0.875rem; padding:0.35rem 0.6rem;"
           oninput="filterMemberList(this)">
</div>
<div class="member-checkbox-list"
     style="max-height:180px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:0.5rem;">
    <?php if (empty($org_users)): ?>
        <p class="text-muted" style="font-size:0.8rem; margin:0; padding:0.25rem 0.5rem;">No users found.</p>
    <?php else: ?>
        <?php foreach ($org_users as $u): ?>
            <label style="display:flex; align-items:center; gap:0.5rem; padding:0.25rem 0.5rem; cursor:pointer; border-radius:4px;"
                   class="member-item"
                   data-label="<?= htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['email']), ENT_QUOTES, 'UTF-8') ?>">
                <input type="checkbox" name="member_ids[]" value="<?= (int) $u['id'] ?>"
                       style="accent-color: var(--primary); width:15px; height:15px; cursor:pointer;">
                <span style="font-size:0.875rem;">
                    <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-muted" style="font-size:0.8rem;">(<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>)</span>
                </span>
            </label>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
