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

<p class="story-rules-copy">
    Configure splitting patterns and mandatory conditions injected into AI story generation prompts.
</p>

<?php
$patterns   = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'splitting_pattern'));
$conditions = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'mandatory_condition'));
?>

<div class="story-rules-grid">

    <!-- Splitting Patterns column -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Splitting Patterns</h2>
        </div>
        <div class="card-body">
            <?php if (empty($patterns)): ?>
                <p class="story-rules-empty">No patterns defined.</p>
            <?php else: ?>
            <ul class="story-rules-list">
                <?php foreach ($patterns as $rule): ?>
                <li class="story-rules-item">
                    <span>
                        <?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((int) $rule['is_default']): ?>
                            <span class="story-rules-default">default</span>
                        <?php endif; ?>
                    </span>
                    <?php if (!(int) $rule['is_default']): ?>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" data-confirm="Remove this splitting pattern?" class="story-rules-remove">&#10005; remove</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom pattern -->
            <form method="POST" action="/app/admin/story-quality-rules" class="story-rules-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="splitting_pattern">
                <input type="text" name="label" required maxlength="255"
                       class="story-rules-input"
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
                <p class="story-rules-empty">No mandatory conditions defined. Add one to require the AI to include it in every generated story.</p>
            <?php else: ?>
            <ul class="story-rules-list">
                <?php foreach ($conditions as $rule): ?>
                <li class="story-rules-item">
                    <span class="story-rules-label"><?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" data-confirm="Remove this condition?" class="story-rules-remove">&#10005; remove</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom condition -->
            <form method="POST" action="/app/admin/story-quality-rules" class="story-rules-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="mandatory_condition">
                <input type="text" name="label" required maxlength="255"
                       class="story-rules-input"
                       placeholder="e.g. Every story must reference an API contract">
                <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
            </form>
        </div>
    </section>

</div>
