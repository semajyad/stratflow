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
<script defer src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

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
        <?php $sync_type = 'sprints'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <a href="/app/user-stories?project_id=<?= (int) $project['id'] ?>" class="btn btn-secondary btn-sm">Back to User Stories</a>
    </div>
</div>

<!-- ===========================
     Page Description
     =========================== -->
<div class="page-description">
    Allocate user stories into time-boxed sprints. Drag stories from the backlog into sprint buckets, monitor capacity utilisation, and use AI for automatic allocation.
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
     Auto-Generate Sprints
     =========================== -->
<?php if (!empty($unallocated)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Auto-Generate Sprints</h3>
    </div>
    <div class="card-body">
        <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 1rem;">
            Create multiple sprints at once with a default capacity. You can then adjust individual sprint capacities before using Auto-Fill to allocate stories.
        </p>
        <form method="POST" action="/app/sprints/auto-generate"
              data-loading="Creating sprints...">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="sprint-gen-grid">
                <div class="sprint-gen-field">
                    <label>Number of sprints</label>
                    <input type="number" name="num_sprints" class="form-control" min="1" max="20" required value="5">
                </div>
                <div class="sprint-gen-field">
                    <label>First sprint starts</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div class="sprint-gen-field">
                    <label>Sprint length</label>
                    <select name="sprint_length" class="form-control" required>
                        <option value="7">1 week</option>
                        <option value="14" selected>2 weeks</option>
                        <option value="21">3 weeks</option>
                        <option value="28">4 weeks</option>
                    </select>
                </div>
                <div class="sprint-gen-field">
                    <label>Default capacity (pts)</label>
                    <input type="number" name="capacity" class="form-control" min="1" required placeholder="e.g. 50">
                </div>
                <div class="sprint-gen-field" style="align-self: end;">
                    <button type="submit" class="btn btn-primary">Generate Sprints</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


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
                <div class="sprint-story-item" data-story-id="<?= (int) $story['id'] ?>">
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
                <form method="POST" action="/app/sprints/auto-fill" class="inline-form"
                      data-loading="Filling sprints..."
                      data-overlay="Allocating stories to sprints by priority, packing each sprint as close to capacity as possible.">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Auto-fill sprints with backlog stories by priority?')">Auto-Fill Sprints</button>
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

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
