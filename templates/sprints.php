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
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Sprint Allocation
        <span class="page-title-count"><?= count($sprints) ?></span>
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">Allocate user stories into time-boxed sprints for the selected team. Drag stories from the backlog into sprint buckets, monitor capacity utilisation, and use AI for automatic allocation.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'sprints'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
    </div>
</div>

<!-- ===========================
     Team / Board Selector (Required)
     =========================== -->
<?php if (empty($teams ?? [])): ?>
<div class="card mb-6 sprint-callout--danger">
    <div class="card-body sprint-callout-body">
        <div>
            <strong>No teams configured</strong>
            <p class="text-muted sprint-callout-copy">
                Sprint allocation requires a team (Jira board). Create a team or import boards from Jira first.
            </p>
        </div>
        <a href="/app/admin/teams" class="btn btn-primary">Manage Teams</a>
    </div>
</div>
<?php else: ?>

<div id="sprints-page" data-project-id="<?= (int) $project['id'] ?>"></div>

<div class="card mb-6 sprint-callout--primary">
    <div class="card-body sprint-callout-body sprint-callout-body--compact">
        <label class="form-label sprint-team-label">Team (Board):</label>
        <select id="active-team-selector" class="form-control js-sprint-team-selector sprint-team-select">
            <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>"
                        data-capacity="<?= (int) ($t['capacity'] ?? 0) ?>"
                        data-jira-board-id="<?= (int) ($t['jira_board_id'] ?? 0) ?>"
                        <?= ((int) ($t['id'] ?? 0)) === (int) ($teams[0]['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                    <?= !empty($t['jira_board_id']) ? ' (Board #' . (int) $t['jira_board_id'] . ')' : '' ?>
                    <?php if (($t['capacity'] ?? 0) > 0): ?> — <?= (int) $t['capacity'] ?> pts/sprint<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
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
            <input type="hidden" name="team_id" id="sprint-team-id" value="<?= (int) ($teams[0]['id'] ?? 0) ?>">
            <div class="sprint-creation-form">
                <div>
                    <label class="form-label sprint-form-label">Sprint Name</label>
                    <input type="text" name="name" id="sprint-name-input" placeholder="e.g. Sprint 1" class="form-control" required>
                </div>
                <div>
                    <label class="form-label sprint-form-label">Start</label>
                    <input type="date" name="start_date" id="sprint-start-date" class="form-control">
                </div>
                <div>
                    <label class="form-label sprint-form-label">End</label>
                    <input type="date" name="end_date" id="sprint-end-date" class="form-control">
                </div>
                <div>
                    <label class="form-label sprint-form-label">Capacity (pts)</label>
                    <input type="number" name="team_capacity" placeholder="pts" class="form-control sprint-capacity-input" min="1">
                </div>
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
        <p class="sprint-helper-copy">
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
                    <label>First sprint starts
                        <?php if (!empty($jira_connected)): ?>
                            <span class="sprint-inline-meta"> — day after last Jira sprint</span>
                        <?php endif; ?>
                    </label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div class="sprint-gen-field">
                    <label>Sprint length
                        <?php if (!empty($jira_connected)): ?>
                            <span class="sprint-inline-meta"> — from Jira history</span>
                        <?php endif; ?>
                    </label>
                    <select name="sprint_length" class="form-control" required>
                        <option value="7">1 week</option>
                        <option value="14" selected>2 weeks</option>
                        <option value="21">3 weeks</option>
                        <option value="28">4 weeks</option>
                    </select>
                </div>
                <div class="sprint-gen-field">
                    <label>Default capacity (pts)
                        <?php if (!empty($jira_connected)): ?>
                            <span class="sprint-inline-meta"> — Jira avg velocity</span>
                        <?php endif; ?>
                    </label>
                    <input type="number" name="capacity" class="form-control" min="1" required placeholder="e.g. 50">
                </div>
                <div class="sprint-gen-field sprint-gen-field--button">
                    <button type="submit" class="btn btn-ai">Generate Sprints</button>
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
                <p class="text-muted text-center sprint-empty-state">All stories allocated</p>
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
                    <button type="submit" class="btn btn-ai btn-sm"
                            data-confirm="Auto-fill sprints with backlog stories by priority?">Auto-Fill Sprints</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($sprints)): ?>
            <p class="text-muted text-center sprint-empty-state sprint-empty-state--large">No sprints yet. Create one above.</p>
        <?php endif; ?>

        <?php foreach ($sprints as $sprint): ?>
            <?php include __DIR__ . '/partials/sprint-card.php'; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php endif; /* end teams check */ ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
