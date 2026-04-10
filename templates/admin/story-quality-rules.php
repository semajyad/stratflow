<?php
/**
 * Story Quality Rules Settings Page
 *
 * Two-column layout: Splitting Patterns | Mandatory Conditions.
 * Default rows are read-only; custom rows show a delete button.
 *
 * Variables: $user, $rules, $flash_message, $flash_error, $csrf_token
 */
?>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title">Story Quality Rules</h1>
    <p class="page-subtitle"><a href="/app/admin">&larr; Back to Administration</a></p>
</div>

<p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1.5rem;">
    Configure splitting patterns and mandatory conditions injected into AI story generation prompts.
</p>

<?php
$patterns   = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'splitting_pattern'));
$conditions = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'mandatory_condition'));
?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1.5rem;">

    <!-- Splitting Patterns column -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Splitting Patterns</h2>
        </div>
        <div class="card-body">
            <?php if (empty($patterns)): ?>
                <p style="color:#9ca3af; font-size:0.875rem;">No patterns defined.</p>
            <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0 0 1rem;">
                <?php foreach ($patterns as $rule): ?>
                <li style="display:flex; align-items:center; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid var(--border);">
                    <span>
                        <?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((int) $rule['is_default']): ?>
                            <span style="font-size:0.7rem; color:#6366f1; border:1px solid #c7d2fe; border-radius:999px; padding:1px 7px; margin-left:6px;">default</span>
                        <?php endif; ?>
                    </span>
                    <?php if (!(int) $rule['is_default']): ?>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete"
                          onsubmit="return confirm('Remove this splitting pattern?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.8rem; padding:0;">&#10005; remove</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom pattern -->
            <form method="POST" action="/app/admin/story-quality-rules" style="display:flex; gap:0.5rem; margin-top:0.75rem;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="splitting_pattern">
                <input type="text" name="label" required maxlength="255"
                       style="flex:1; border:1px solid var(--border); border-radius:4px; padding:0.4rem 0.6rem; font-size:0.875rem;"
                       placeholder="e.g. Data Complexity">
                <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
            </form>
        </div>
    </section>

    <!-- Mandatory Conditions column -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Mandatory Conditions</h2>
        </div>
        <div class="card-body">
            <?php if (empty($conditions)): ?>
                <p style="color:#9ca3af; font-size:0.875rem;">No mandatory conditions defined. Add one to require the AI to include it in every generated story.</p>
            <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0 0 1rem;">
                <?php foreach ($conditions as $rule): ?>
                <li style="display:flex; align-items:center; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid var(--border);">
                    <span style="font-size:0.875rem;"><?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete"
                          onsubmit="return confirm('Remove this condition?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.8rem; padding:0;">&#10005; remove</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom condition -->
            <form method="POST" action="/app/admin/story-quality-rules" style="display:flex; gap:0.5rem; margin-top:0.75rem;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="mandatory_condition">
                <input type="text" name="label" required maxlength="255"
                       style="flex:1; border:1px solid var(--border); border-radius:4px; padding:0.4rem 0.6rem; font-size:0.875rem;"
                       placeholder="e.g. Every story must reference an API contract">
                <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
            </form>
        </div>
    </section>

</div>
