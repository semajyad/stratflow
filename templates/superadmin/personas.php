<?php
/**
 * Superadmin Default Personas Template
 *
 * Edit system-wide default persona panels and their member prompt descriptions.
 * Organisations can override these with their own customised versions.
 *
 * Variables: $user (array), $panels (array), $panel_members (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Default Persona Panels</h1>
    <p class="page-subtitle">Manage system-wide default personas. Organisations can customise their own.</p>
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
     Persona Editor Form
     =========================== -->
<form method="POST" action="/superadmin/personas">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <?php foreach ($panels as $panel): ?>
        <section class="card mt-6 persona-editor-panel">
            <div class="card-header">
                <h2 class="card-title"><?= htmlspecialchars($panel['name']) ?></h2>
                <span class="badge badge-secondary"><?= htmlspecialchars($panel['panel_type']) ?></span>
            </div>
            <div class="card-body">
                <?php
                    $members = $panel_members[(int) $panel['id']] ?? [];
                ?>
                <?php if (empty($members)): ?>
                    <p class="text-muted">No members in this panel.</p>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <div class="form-group mb-4">
                            <label class="form-label" for="member_<?= (int) $member['id'] ?>">
                                <?= htmlspecialchars($member['role_title']) ?>
                            </label>
                            <textarea
                                name="member_<?= (int) $member['id'] ?>"
                                id="member_<?= (int) $member['id'] ?>"
                                class="form-control"
                                rows="3"
                            ><?= htmlspecialchars($member['prompt_description'] ?? '') ?></textarea>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="mt-6" style="margin-bottom: 2rem;">
        <button type="submit" class="btn btn-primary">Save Personas</button>
        <a href="/superadmin" class="btn btn-secondary" style="margin-left: 0.5rem;">Back to Dashboard</a>
    </div>
</form>
