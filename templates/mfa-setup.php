<?php
/**
 * MFA Setup Template
 *
 * Shows current MFA status and allows enable/disable.
 * When enabling, displays the TOTP secret key and otpauth:// URI.
 */
?>
<div class="page-header">
    <h1>Two-factor authentication</h1>
</div>

<?php
$error   = $_GET['error']   ?? null;
$success = $_GET['success'] ?? null;
?>

<?php if ($error === 'invalid_code'): ?>
    <div class="alert alert-danger">Invalid authentication code. Please try again.</div>
<?php elseif ($error === 'wrong_password'): ?>
    <div class="alert alert-danger">Incorrect password. MFA was not disabled.</div>
<?php elseif ($success === 'disabled'): ?>
    <div class="alert alert-success">Two-factor authentication has been disabled.</div>
<?php endif; ?>

<?php if ($mfa_enabled): ?>
    <div class="card">
        <div class="card-body">
            <p><strong>Status:</strong> <span class="badge badge-success">Enabled</span></p>
            <p>Recovery codes remaining: <strong><?= (int) $recovery_remaining ?></strong></p>
            <p>To disable two-factor authentication, confirm your current password below.</p>

            <form method="POST" action="/app/account/mfa/disable">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="password">Current password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn--danger">Disable two-factor authentication</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <p><strong>Status:</strong> <span class="badge badge-secondary">Not enabled</span></p>
            <p>Protect your account with a time-based one-time password (TOTP) authenticator app such as Google Authenticator, Authy, or 1Password.</p>

            <h3>Setup instructions</h3>
            <ol>
                <li>Open your authenticator app and add a new account.</li>
                <li>Enter the key manually or scan the URI below.</li>
                <li>Enter the 6-digit code shown by the app to confirm setup.</li>
            </ol>

            <div class="form-group">
                <label>Your secret key</label>
                <code class="mfa-secret"><?= htmlspecialchars($totp_secret, ENT_QUOTES, 'UTF-8') ?></code>
            </div>

            <div class="form-group">
                <label>Authenticator URI (for advanced users)</label>
                <input type="text" class="form-control" readonly
                       value="<?= htmlspecialchars($totp_uri, ENT_QUOTES, 'UTF-8') ?>"
                       onclick="this.select()">
            </div>

            <form method="POST" action="/app/account/mfa/enable">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="code">Verification code from your app</label>
                    <input
                        type="text"
                        id="code"
                        name="code"
                        class="form-control"
                        placeholder="000000"
                        inputmode="numeric"
                        maxlength="6"
                        required
                        autocomplete="off"
                    >
                </div>
                <button type="submit" class="btn btn--primary">Enable two-factor authentication</button>
            </form>
        </div>
    </div>
<?php endif; ?>
