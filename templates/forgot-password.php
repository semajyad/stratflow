<?php
/**
 * Forgot Password Template
 *
 * Simple form asking for an email address to receive a password reset link.
 * Uses the public layout (no authentication required).
 *
 * Variables: $csrf_token (string), $success (bool|null), $error (string|null)
 */
?>

<section class="auth-section">
    <div class="auth-card">

        <div class="auth-card-header">
            <h1 class="auth-logo">StratFlow</h1>
            <p class="auth-subtitle">Reset your password</p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="flash-message flash-message--success" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;">
                If an account exists with that email, a password reset link has been sent. Please check your inbox.
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="flash-message flash-message--error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/forgot-password" class="auth-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="form-group">
                <label for="email">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    placeholder="you@example.com"
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>

        <div class="auth-card-footer">
            <a href="/login">&larr; Back to Login</a>
        </div>

    </div>
</section>
