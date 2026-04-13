<?php
/**
 * Superadmin Dashboard Template
 *
 * System-wide overview: total organisations, users, and active subscriptions.
 * Quick links to management sub-pages.
 *
 * Variables: $user (array), $org_count (int), $user_count (int),
 *            $subscription_count (int), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Superadmin Dashboard</h1>
    <p class="page-subtitle">System-wide management for ThreePoints staff.</p>
</div>

<!-- ===========================
     Flash Messages
     =========================== -->
<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- ===========================
     Stat Cards
     =========================== -->
<div class="superadmin-stats">
    <a href="/superadmin/organisations" class="admin-card">
        <div class="admin-card-icon">&#127970;</div>
        <div class="admin-card-count"><?= (int) $org_count ?></div>
        <div class="admin-card-label">Total Organisations</div>
        <div class="admin-card-hint">View and manage all organisations</div>
    </a>

    <a href="/superadmin/users" class="admin-card">
        <div class="admin-card-icon">&#128101;</div>
        <div class="admin-card-count"><?= (int) $user_count ?></div>
        <div class="admin-card-label">Total Users</div>
        <div class="admin-card-hint">Active users across all organisations</div>
    </a>

    <a href="/superadmin/subscriptions" class="admin-card">
        <div class="admin-card-icon">&#128179;</div>
        <div class="admin-card-count"><?= (int) $subscription_count ?></div>
        <div class="admin-card-label">Active Subscriptions</div>
        <div class="admin-card-hint">Currently active subscription plans</div>
    </a>
</div>

<!-- ===========================
     Quick Links
     =========================== -->
<section class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Quick Links</h2>
    </div>
    <div class="card-body">
        <div class="quick-links">
            <a href="/superadmin/organisations" class="btn btn-primary">Manage Organisations</a>
            <a href="/superadmin/defaults" class="btn btn-primary">App Wide Defaults</a>
            <a href="/superadmin/personas" class="btn btn-primary">Manage Default Personas</a>
            <a href="/superadmin/audit-logs" class="btn btn-secondary">Audit Logs</a>
        </div>
    </div>
</section>
