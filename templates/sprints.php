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
<div class="card mb-6" style="border-left: 4px solid var(--danger);">
    <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem;">
        <div>
            <strong>No teams configured</strong>
            <p class="text-muted" style="margin: 0.25rem 0 0; font-size: 0.875rem;">
                Sprint allocation requires a team (Jira board). Create a team or import boards from Jira first.
            </p>
        </div>
        <a href="/app/admin/teams" class="btn btn-primary">Manage Teams</a>
    </div>
</div>
<?php else: ?>

<div class="card mb-6" style="border-left: 4px solid var(--primary);">
    <div class="card-body" style="display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem;">
        <label class="form-label" style="margin: 0; white-space: nowrap; font-weight: 600;">Team (Board):</label>
        <select id="active-team-selector" class="form-control" style="max-width: 300px;"
                onchange="filterSprintsByTeam(this.value)">
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
            <div class="sprint-creation-form" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end;">
                <div>
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Sprint Name</label>
                    <input type="text" name="name" id="sprint-name-input" placeholder="e.g. Sprint 1" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Start</label>
                    <input type="date" name="start_date" id="sprint-start-date" class="form-control" onchange="autoSetSprintEndDate()">
                </div>
                <div>
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">End</label>
                    <input type="date" name="end_date" id="sprint-end-date" class="form-control">
                </div>
                <div>
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Capacity (pts)</label>
                    <input type="number" name="team_capacity" placeholder="pts" class="form-control" min="1" style="width: 100px;">
                </div>
                <button type="submit" class="btn btn-primary">Create Sprint</button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    var PROJECT_ID    = <?= (int) $project['id'] ?>;
    var JIRA_ENABLED  = <?= json_encode(!empty($jira_connected)) ?>;
    var sprintLengthDays = 14; // updated by loadJiraDefaults

    function tomorrow() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        return d.toISOString().slice(0, 10);
    }
    function addDays(isoDate, days) {
        var d = new Date(isoDate);
        d.setDate(d.getDate() + days);
        return d.toISOString().slice(0, 10);
    }

    // Populate all sprint forms with fetched/fallback defaults
    function applyDefaults(data) {
        sprintLengthDays = data.sprint_length_days || 14;

        var start = data.suggested_start || tomorrow();

        // Create Sprint — name
        var nameEl = document.getElementById('sprint-name-input');
        if (nameEl && !nameEl.value && data.next_sprint_number) {
            nameEl.value       = 'Sprint ' + data.next_sprint_number;
            nameEl.placeholder = 'Sprint ' + data.next_sprint_number;
        }

        // Create Sprint — start date
        var startEl = document.getElementById('sprint-start-date');
        if (startEl && !startEl.value) {
            startEl.value = start;
        }

        // Create Sprint — end date (sprint length - 1 so start+end are inclusive)
        var endEl = document.getElementById('sprint-end-date');
        if (endEl && !endEl.value && startEl && startEl.value) {
            endEl.value = addDays(startEl.value, sprintLengthDays - 1);
        }

        // Create Sprint — capacity
        var capEl = document.querySelector('input[name="team_capacity"]');
        if (capEl && !capEl.value && data.suggested_capacity) {
            capEl.value       = data.suggested_capacity;
            capEl.placeholder = data.suggested_capacity + ' pts';
        }

        // Auto-Generate — start date
        var genStart = document.querySelector('form[action*="auto-generate"] input[name="start_date"]');
        if (genStart && !genStart.value) {
            genStart.value = start;
            if (data.suggested_start) {
                genStart.title = 'Day after last Jira sprint (' + data.suggested_start + ')';
            }
        }

        // Auto-Generate — sprint length select
        var genLength = document.querySelector('form[action*="auto-generate"] select[name="sprint_length"]');
        if (genLength) {
            genLength.value = sprintLengthDays;
        }

        // Auto-Generate — capacity
        var genCap = document.querySelector('form[action*="auto-generate"] input[name="capacity"]');
        if (genCap && !genCap.value && data.suggested_capacity) {
            genCap.value       = data.suggested_capacity;
            genCap.placeholder = 'e.g. ' + data.suggested_capacity + ' (Jira avg)';
        }
    }

    // Fetch Jira defaults in background; fall back to local-only defaults
    window.loadJiraDefaults = function loadJiraDefaults(boardId) {
        var url = '/app/sprints/jira-defaults?project_id=' + PROJECT_ID + '&board_id=' + (boardId || 0);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
            .then(applyDefaults)
            .catch(function() {
                // Jira unreachable — apply tomorrow + local sprint count only
                applyDefaults({ sprint_length_days: 14, suggested_start: null,
                                 next_sprint_number: null, suggested_capacity: null });
            });
    }

    window.autoSetSprintEndDate = function() {
        var startEl = document.getElementById('sprint-start-date');
        var endEl   = document.getElementById('sprint-end-date');
        if (startEl && startEl.value) {
            endEl.value = addDays(startEl.value, sprintLengthDays - 1);
        }
    };

    // On page load: fetch defaults for the initially selected team's board
    document.addEventListener('DOMContentLoaded', function() {
        var sel = document.getElementById('active-team-selector');
        if (sel) {
            var opt     = sel.options[sel.selectedIndex];
            var boardId = opt ? (parseInt(opt.dataset.jiraBoardId, 10) || 0) : 0;
            loadJiraDefaults(boardId);
        } else {
            // No team selector — fetch without board to get local defaults
            loadJiraDefaults(0);
        }
    });
})();
</script>

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
                    <label>First sprint starts
                        <?php if (!empty($jira_connected)): ?>
                            <span style="font-weight:400; font-size:0.75rem; color:var(--text-muted);"> — day after last Jira sprint</span>
                        <?php endif; ?>
                    </label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div class="sprint-gen-field">
                    <label>Sprint length
                        <?php if (!empty($jira_connected)): ?>
                            <span style="font-weight:400; font-size:0.75rem; color:var(--text-muted);"> — from Jira history</span>
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
                            <span style="font-weight:400; font-size:0.75rem; color:var(--text-muted);"> — Jira avg velocity</span>
                        <?php endif; ?>
                    </label>
                    <input type="number" name="capacity" class="form-control" min="1" required placeholder="e.g. 50">
                </div>
                <div class="sprint-gen-field" style="align-self: end;">
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
                    <button type="submit" class="btn btn-ai btn-sm" onclick="return confirm('Auto-fill sprints with backlog stories by priority?')">Auto-Fill Sprints</button>
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

<?php endif; /* end teams check */ ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<script>
function filterSprintsByTeam(teamId) {
    // Update hidden team_id in sprint creation form
    var hidden = document.getElementById('sprint-team-id');
    if (hidden) hidden.value = teamId;

    // Show/hide sprint cards by team
    document.querySelectorAll('.sprint-card').forEach(function(card) {
        var cardTeamId = card.dataset.teamId || '';
        // Show all if no team filter, or show matching + unassigned
        if (!teamId || cardTeamId === teamId || cardTeamId === '' || cardTeamId === '0') {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });

    // Re-fetch Jira defaults for the newly selected board
    var sel = document.getElementById('active-team-selector');
    var opt = sel ? sel.querySelector('option[value="' + teamId + '"]') : null;
    var boardId = opt ? (parseInt(opt.dataset.jiraBoardId, 10) || 0) : 0;
    if (typeof loadJiraDefaults === 'function') {
        loadJiraDefaults(boardId);
    }
}
</script>
