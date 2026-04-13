<?php
/**
 * DSAR Data Export Template
 *
 * Confirmation page before downloading the user's data archive.
 */
?>
<div class="page-header">
    <h1>Download your data</h1>
</div>

<div class="card">
    <div class="card-body">
        <p>This will generate a ZIP archive containing all personal data held for your account, including:</p>

        <ul>
            <li>Profile information (name, email, role)</li>
            <li>Projects in your organisation</li>
            <li>User stories assigned to you</li>
            <li>Work items in your organisation</li>
            <li>Your last 1,000 audit log entries</li>
        </ul>

        <p>The download may take a few seconds to prepare. Sensitive data (passwords, MFA secrets) is excluded.</p>

        <form method="POST" action="/app/account/export-data">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn--primary">Download my data</button>
        </form>
    </div>
</div>
