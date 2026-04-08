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
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Minimum 8 characters"
                        required
                        minlength="8"
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm Password</label>
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        class="form-control"
                        placeholder="Re-enter your password"
                        required
                        minlength="8"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">Set Password</button>
            </form>

            <div class="auth-footer-link">
                <a href="/login">&larr; Back to Login</a>
            </div>

        <?php endif; ?>

    </div>
</section>
