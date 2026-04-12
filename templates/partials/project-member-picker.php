<?php
/**
 * Project Member Picker Partial
 *
 * Type-to-search input with chip-style selected users.
 * JS lives in the shared app.js home/dashboard handlers.
 * Included inside #new-member-picker and #edit-member-picker divs.
 */
?>
<div class="member-picker-wrap" style="margin-top:0.25rem;">
    <!-- Selected user chips -->
    <div class="member-chips" style="display:flex; flex-wrap:wrap; gap:0.35rem; margin-bottom:0.5rem; min-height:0;"></div>
    <p class="text-muted" style="font-size:0.75rem; margin:0 0 0.5rem;">
        Add users, then choose whether each person is a viewer, editor, or project admin for this project.
    </p>
    <!-- Search input + dropdown -->
    <div style="position:relative;">
        <input type="text" class="form-input member-search-input"
               placeholder="Search users to add..."
               autocomplete="off"
               style="font-size:0.875rem;">
        <div class="member-search-results"
             style="display:none; position:absolute; top:calc(100% + 2px); left:0; right:0; z-index:200;
                    background:#fff; border:1px solid var(--border); border-radius:6px;
                    box-shadow:0 4px 16px rgba(0,0,0,0.12); max-height:200px; overflow-y:auto;"></div>
    </div>
    <?php if (empty($org_users)): ?>
        <p class="text-muted" style="font-size:0.8rem; margin-top:0.4rem;">No users in your organisation yet.</p>
    <?php endif; ?>
</div>
