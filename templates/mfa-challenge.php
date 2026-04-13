<?php
/**
 * MFA Challenge Template
 *
 * Shown after a successful password login when the user has TOTP MFA enabled.
 * Accepts a 6-digit authenticator code or an 8-character recovery code.
 */
?>
<section class="auth-section">
    <div class="auth-card">

        <div class="auth-card-header">
            <div class="auth-logo">
                <img src="/assets/images/StratFlow_logo.webp" alt="StratFlow">
            </div>
            <h2 class="auth-heading">Two-factor authentication</h2>
            <p class="auth-subheading">Enter the 6-digit code from your authenticator app, or a recovery code.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login/mfa" autocomplete="off" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="code">Authentication code</label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    class="form-control"
                    placeholder="000000"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="20"
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block">Verify</button>

            <div class="auth-footer-link">
                <a href="/login">Back to sign in</a>
            </div>
        </form>

    </div>
</section>
