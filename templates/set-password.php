<?php
/**
 * Set/Reset Password Template
 *
 * Form for setting a new password using a valid token from an email link.
 * Used for both initial password set (welcome) and password reset flows.
 *
 * Variables: $csrf_token (string), $token (string), $error (string|null),
 *            $token_valid (bool)
 */
?>

<section class="auth-section">
    <div class="auth-card">

        <div class="auth-card-header">
            <div class="auth-logo">
                <img src="/assets/images/StratFlow_logo.webp" alt="StratFlow">
            </div>
            <h2 class="auth-heading">Set your password</h2>
        </div>

        <?php if (!($token_valid ?? true)): ?>
            <div class="flash-message flash-message--error">
                This link has expired or is invalid. Please request a new one.
            </div>
            <div class="auth-footer-link">
                <a href="/forgot-password">Request a new reset link</a>
                &nbsp;&middot;&nbsp;
                <a href="/login">Back to Login</a>
            </div>
        <?php else: ?>

            <?php if (!empty($error)): ?>
                <div class="flash-message flash-message--error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/set-password/<?= htmlspecialchars($token) ?>" class="auth-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 12 characters" required minlength="12" autofocus>
                        <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-off-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" placeholder="Re-enter your password" required minlength="12">
                        <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-off-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Set Password</button>
            </form>

            <div class="auth-footer-link">
                <a href="/login">&larr; Back to Login</a>
            </div>

        <?php endif; ?>

    </div>
</section>
