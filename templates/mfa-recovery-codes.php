<?php
/**
 * MFA Recovery Codes Template
 *
 * Shown ONCE after MFA is first enabled. User must save these codes.
 * Each code can be used exactly once in place of a TOTP code.
 */
?>
<div class="page-header">
    <h1>Save your recovery codes</h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="alert alert-warning">
            <strong>Save these codes now.</strong> They will not be shown again. Each code can be used once if you lose access to your authenticator app.
        </div>

        <div class="mfa-recovery-codes-list">
            <?php foreach ($recovery_codes as $code): ?>
                <code class="recovery-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></code>
            <?php endforeach; ?>
        </div>

        <p class="mfa-recovery-hint">Store these somewhere safe — a password manager, encrypted note, or printed and locked away.</p>

        <a href="/app/account/mfa" class="btn btn--primary">I have saved my recovery codes</a>
    </div>
</div>
