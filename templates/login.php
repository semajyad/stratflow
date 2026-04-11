<?php
/**
 * Login Template
 *
 * Centered login card with email/password fields, CSRF protection,
 * and an error message area for failed authentication attempts.
 */
?>

<section class="auth-section">
    <div class="auth-card">

        <div class="auth-card-header">
            <div class="auth-logo">
                <img src="/assets/images/StratFlow_logo.webp" alt="StratFlow">
            </div>
            <h2 class="auth-heading">Sign in to your account</h2>
        </div>

        <?php if (!empty($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login" class="auth-form">
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

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                    <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                        <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign In</button>

            <div class="auth-footer-link">
                <a href="/forgot-password">Forgot your password?</a>
            </div>
        </form>

    </div>
</section>
