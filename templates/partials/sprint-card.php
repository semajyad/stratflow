<?php
/**
 * Sprint Card Partial
 *
 * Renders a single sprint card with capacity bar, story list,
 * edit/delete controls, and a droppable story bucket.
 *
 * Variables: $sprint (array with stories), $project (array), $csrf_token (string)
 */
?>
<div class="sprint-card" data-sprint-id="<?= (int) $sprint['id'] ?>" data-capacity="<?= (int) ($sprint['team_capacity'] ?? 0) ?>">
    <div class="sprint-header">
        <div class="sprint-title-row">
            <h4><?= htmlspecialchars($sprint['name']) ?></h4>
            <?php if (!empty($sprint['team_name'])): ?>
                <span class="badge badge-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($sprint['team_name']) ?></span>
            <?php endif; ?>
            <span class="sprint-dates"><?= htmlspecialchars($sprint['start_date'] ?? 'TBD') ?> &mdash; <?= htmlspecialchars($sprint['end_date'] ?? 'TBD') ?></span>
        </div>
        <div class="capacity-bar">
            <?php
                $totalSize = (int) ($sprint['total_size'] ?? 0);
                $capacity  = max(1, (int) ($sprint['team_capacity'] ?? 1));
                $pct       = min(100, ($totalSize / $capacity) * 100);
                $overClass = $totalSize > $capacity ? ' over-capacity' : '';
            ?>
            <div class="capacity-fill<?= $overClass ?>" style="width: <?= $pct ?>%"></div>
            <span class="capacity-label"><?= $totalSize ?> / <?= $sprint['team_capacity'] ?? '?' ?> pts</span>
        </div>
        <div class="sprint-actions">
            <button type="button" class="btn btn-sm btn-secondary edit-sprint-btn"
                    onclick="toggleSprintEditForm(<?= (int) $sprint['id'] ?>)">Edit</button>
            <form method="POST" action="/app/sprints/<?= (int) $sprint['id'] ?>/delete" class="inline-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete sprint? Stories will return to backlog.')">Delete</button>
            </form>
        </div>
    </div>

    <!-- Edit form (hidden by default) -->
    <div class="sprint-edit-form hidden" id="sprint-edit-<?= (int) $sprint['id'] ?>">
        <form method="POST" action="/app/sprints/<?= (int) $sprint['id'] ?>">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="sprint-form-row" style="flex-wrap: wrap;">
                <?php if (!empty($teams ?? [])): ?>
                <select name="team_id" class="form-control form-control-sm" style="min-width: 130px;">
                    <option value="">No team</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= ((int) ($sprint['team_id'] ?? 0)) === (int) $t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="text" name="name" value="<?= htmlspecialchars($sprint['name']) ?>" placeholder="Sprint name" class="form-control form-control-sm" required>
                <input type="date" name="start_date" value="<?= htmlspecialchars($sprint['start_date'] ?? '') ?>" class="form-control form-control-sm">
                <input type="date" name="end_date" value="<?= htmlspecialchars($sprint['end_date'] ?? '') ?>" class="form-control form-control-sm">
                <input type="number" name="team_capacity" value="<?= $sprint['team_capacity'] ?? '' ?>" placeholder="Capacity" class="form-control form-control-sm" min="1" style="width: 80px;">
                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleSprintEditForm(<?= (int) $sprint['id'] ?>)">Cancel</button>
            </div>
        </form>
    </div>

    <div class="sprint-stories" id="sprint-<?= (int) $sprint['id'] ?>-stories" data-sprint-id="<?= (int) $sprint['id'] ?>">
        <?php foreach ($sprint['stories'] as $story): ?>
            <div class="sprint-story-item" data-story-id="<?= (int) $story['id'] ?>">
                <span class="story-title-text"><?= htmlspecialchars(mb_strimwidth($story['title'], 0, 80, '...')) ?></span>
                <span class="badge"><?= $story['size'] ?? '-' ?> pts</span>
                <?php if (!empty($story['parent_title'])): ?>
                    <span class="story-parent-tag"><?= htmlspecialchars(mb_strimwidth($story['parent_title'], 0, 30, '...')) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
