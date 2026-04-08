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
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign In</button>

            <div class="auth-footer-link">
                <a href="/forgot-password">Forgot your password?</a>
            </div>
        </form>

    </div>
</section>
