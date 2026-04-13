<?php
/**
 * Project Member Picker Partial
 *
 * Type-to-search input with chip-style selected users.
 * JS lives in the shared app.js home/dashboard handlers.
 * Included inside #new-member-picker and #edit-member-picker divs.
 */
?>
<div class="member-picker-wrap gen-style-793a57">
    <!-- Selected user chips -->
    <div class="member-chips gen-style-53a668"></div>
    <p class="text-muted gen-style-f49cbe">
        Add users, then choose whether each person is a viewer, editor, or project admin for this project.
    </p>
    <!-- Search input + dropdown -->
    <div class="gen-style-4cb8ce">
        <input type="text" class="form-input member-search-input gen-style-50915c"
               placeholder="Search users to add..."
               autocomplete="off">
        <div class="member-search-results gen-style-ca0e4e"></div>
    </div>
    <?php if (empty($org_users)): ?>
        <p class="text-muted gen-style-45ff67">No users in your organisation yet.</p>
    <?php endif; ?>
</div>
