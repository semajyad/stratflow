<?php
/**
 * Sprints Template
 *
 * Split-view layout: unallocated story backlog on the left,
 * sprint buckets on the right. Supports drag-and-drop allocation
 * via SortableJS and AI auto-allocation.
 *
 * Variables: $project (array), $sprints (array), $unallocated (array),
 *            $csrf_token (string)
 */
?>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center mb-6">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Sprint Allocation</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0; font-size: 0.875rem;">
            <?= count($sprints) ?> sprint<?= count($sprints) !== 1 ? 's' : '' ?>,
            <?= count($unallocated) ?> unallocated stor<?= count($unallocated) === 1 ? 'y' : 'ies' ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <a href="/app/user-stories?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary btn-sm">Back to User Stories</a>
    </div>
</div>

<!-- ===========================
     Sprint Creation Form
     =========================== -->
<div class="card mb-6">
    <div class="card-header">
        <h3>Create Sprint</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/sprints/store">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="sprint-creation-form">
                <input type="text" name="name" placeholder="Sprint name (e.g. Sprint 1)" class="form-control" required>
                <input type="date" name="start_date" class="form-control">
                <input type="date" name="end_date" class="form-control">
                <input type="number" name="team_capacity" placeholder="Capacity (pts)" class="form-control" min="1" style="width: 140px;">
                <button type="submit" class="btn btn-primary">Create Sprint</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================
     Split View: Backlog + Sprints
     =========================== -->
<div class="sprint-layout">

    <!-- Left Panel: Backlog -->
    <div class="backlog-panel">
        <div class="panel-header">
            <h3>Backlog</h3>
            <span class="badge badge-muted"><?= count($unallocated) ?></span>
        </div>
        <div class="backlog-stories" id="backlog-stories">
            <?php if (empty($unallocated)): ?>
                <p class="text-muted text-center" style="padding: 1rem; font-size: 0.875rem;">All stories allocated</p>
            <?php endif; ?>
            <?php foreach ($unallocated as $story): ?>
                <div class="sprint-story-item" data-story-id="<?= $story['id'] ?>">
                    <span class="story-title-text"><?= htmlspecialchars(mb_strimwidth($story['title'], 0, 60, '...')) ?></span>
                    <span class="badge"><?= $story['size'] ?? '-' ?> pts</span>
                    <?php if (!empty($story['parent_title'])): ?>
                        <span class="story-parent-tag"><?= htmlspecialchars(mb_strimwidth($story['parent_title'], 0, 30, '...')) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right Panel: Sprint Buckets -->
    <div class="sprints-panel">
        <div class="panel-header">
            <h3>Sprints</h3>
            <?php if (!empty($sprints) && !empty($unallocated)): ?>
                <form method="POST" action="/app/sprints/ai-allocate" class="inline-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Auto-allocate unallocated stories into sprints using AI?')">AI Auto-Allocate</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($sprints)): ?>
            <p class="text-muted text-center" style="padding: 2rem; font-size: 0.875rem;">No sprints yet. Create one above.</p>
        <?php endif; ?>

        <?php foreach ($sprints as $sprint): ?>
            <?php include __DIR__ . '/partials/sprint-card.php'; ?>
        <?php endforeach; ?>
    </div>
</div>
