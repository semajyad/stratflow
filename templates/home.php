<?php
/**
 * Dashboard Home Template
 *
 * Displays the authenticated user's project list and a form
 * to create a new project. Uses the app layout (sidebar + topbar).
 *
 * Variables: $user (array), $projects (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Welcome Section
     =========================== -->
<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">Welcome, <?= htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User') ?></h1>
        <p class="page-subtitle" style="margin:0.25rem 0 0;">
            StratFlow turns your strategy documents into a prioritised, AI-ready engineering roadmap.
        </p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('new-project-modal').classList.remove('hidden'); setTimeout(function(){document.getElementById('new-project-name').focus();},50);">
        + New Project
    </button>
</div>

<!-- ===========================
     Continue Working
     =========================== -->
<?php
$lastProjectId = $_SESSION['_last_project_id'] ?? null;
$lastProject = null;
if ($lastProjectId && !empty($projects)) {
    foreach ($projects as $p) {
        if ((int)$p['id'] === (int)$lastProjectId) {
            $lastProject = $p;
            break;
        }
    }
}
?>
<?php if ($lastProject): ?>
<section class="card mb-4" style="border-left: 4px solid var(--primary);">
    <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem;">
        <div>
            <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Continue Working On</span>
            <h3 style="margin: 0.25rem 0 0; font-size: 1.125rem;"><?= htmlspecialchars($lastProject['name']) ?></h3>
            <small class="text-muted" style="display:block; margin-top:0.15rem;">
                <?= (int) ($lastProject['steps_complete'] ?? 0) ?> of <?= (int) ($lastProject['steps_total'] ?? 8) ?> steps complete &middot; Next: <?= htmlspecialchars($lastProject['next_step_label'] ?? 'Upload') ?>
            </small>
        </div>
        <a href="<?= htmlspecialchars($lastProject['next_step_url'] ?? '/app/upload?project_id=' . (int) $lastProject['id']) ?>" class="btn btn-primary">Resume Project</a>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Your Projects
     =========================== -->
<section class="card">
    <div class="card-header flex justify-between items-center">
        <h2 class="card-title" style="margin:0;">Your Projects <span class="text-muted" style="font-weight:400; font-size:0.85rem;">(<?= count($projects) ?>)</span></h2>
        <?php if (count($projects) > 3): ?>
            <input type="search" id="project-search" placeholder="Search projects..."
                   style="max-width:260px; padding:0.4rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.875rem;"
                   oninput="filterProjectList(this.value)">
        <?php endif; ?>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state" style="padding: 2rem; text-align: center;">
            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Create your first project to get started</p>
            <p class="text-muted" style="font-size: 0.875rem;">Each project takes a strategy document through the full workflow: upload, roadmap, work items, stories, sprints, and governance.</p>
        </div>
    <?php else: ?>
        <div class="project-list">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-info">
                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span class="project-name"><?= htmlspecialchars($project['name']) ?></span>
                            <span class="status-badge status-<?= htmlspecialchars($project['status']) ?>">
                                <?= ucfirst(htmlspecialchars($project['status'])) ?>
                            </span>
                            <span class="project-date">
                                Created <?= date('j M Y', strtotime($project['created_at'])) ?>
                            </span>
                        </div>
                        <?php if (isset($project['steps_total']) && !empty($project['completion'])):
                            $stepKeys   = ['upload','diagram','work-items','prioritisation','risks','user-stories','sprints','governance'];
                            $stepLabels = ['Upload','Roadmap','Work Items','Prioritise','Risks','Stories','Sprints','Governance'];
                        ?>
                            <div style="display:flex; align-items:center; gap:0.35rem; margin-top:0.5rem;">
                                <?php foreach ($stepKeys as $i => $k):
                                    $done = !empty($project['completion'][$k]);
                                ?>
                                    <span title="<?= $stepLabels[$i] ?><?= $done ? ' — complete' : '' ?>"
                                          style="display:inline-block; width:18px; height:4px; border-radius:2px; background:<?= $done ? '#059669' : '#e2e8f0' ?>;"></span>
                                <?php endforeach; ?>
                                <span class="text-muted" style="font-size:0.7rem; margin-left:0.3rem;">
                                    <?= (int) $project['steps_complete'] ?>/<?= (int) $project['steps_total'] ?> &middot; Next: <?= htmlspecialchars($project['next_step_label'] ?? '') ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="project-actions" style="display: flex; gap: 0.5rem; align-items: center;">
                        <?php if (!empty($jira_connected) && !empty($jira_projects)): ?>
                            <form method="POST" action="/app/projects/<?= (int) $project['id'] ?>/jira-link" class="inline-form" style="display:flex; align-items:center; gap:0.25rem;">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <select name="jira_project_key" class="form-control" style="font-size:0.75rem; padding:0.2rem 0.4rem; min-width:120px;"
                                        onchange="this.form.submit()">
                                    <option value="">Jira: None</option>
                                    <?php foreach ($jira_projects as $jp): ?>
                                        <option value="<?= htmlspecialchars($jp['key']) ?>"
                                            <?= ($project['jira_project_key'] ?? '') === $jp['key'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($jp['key'] . ' - ' . $jp['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php elseif (!empty($project['jira_project_key'])): ?>
                            <span class="badge badge-primary" style="font-size:0.7rem;"><?= htmlspecialchars($project['jira_project_key']) ?></span>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($project['next_step_url'] ?? '/app/upload?project_id=' . (int) $project['id']) ?>"
                           class="btn btn-primary btn-sm"
                           title="<?= (int) ($project['steps_complete'] ?? 0) ?>/<?= (int) ($project['steps_total'] ?? 8) ?> steps complete — next: <?= htmlspecialchars($project['next_step_label'] ?? 'Upload') ?>">
                            Open Project
                        </a>
                        <?php if (in_array($user['role'] ?? '', ['project_manager', 'org_admin', 'superadmin'])): ?>
                        <button type="button" class="btn btn-sm btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;"
                                onclick="renameProject(<?= (int) $project['id'] ?>, this.dataset.name, this.dataset.token)"
                                data-name="<?= htmlspecialchars($project['name'], ENT_QUOTES) ?>"
                                data-token="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                            Rename
                        </button>
                        <form method="POST" action="/app/projects/<?= (int) $project['id'] ?>/delete" class="inline-form"
                              onsubmit="return confirm('Delete project &quot;<?= htmlspecialchars($project['name'], ENT_QUOTES) ?>&quot;? This cannot be undone.')">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="btn btn-sm btn-danger" style="padding:0.25rem 0.5rem; font-size:0.75rem;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- New Project Modal -->
<div id="new-project-modal" class="modal-overlay hidden" style="position:fixed; inset:0; background:rgba(15,23,42,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;"
     onclick="if(event.target===this) this.classList.add('hidden');">
    <div class="card" style="max-width:480px; width:90%; margin:0;">
        <div class="card-header flex justify-between items-center">
            <h2 class="card-title" style="margin:0;">Create New Project</h2>
            <button type="button" onclick="document.getElementById('new-project-modal').classList.add('hidden');"
                    style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="/app/projects" class="card-body">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-group">
                <label class="form-label" for="new-project-name">Project Name</label>
                <input type="text" id="new-project-name" name="name" class="form-input"
                       placeholder="e.g. Q3 Platform Modernisation" required maxlength="255" autocomplete="off">
                <small class="text-muted" style="display:block; margin-top:0.4rem;">
                    You'll upload a strategy document next to generate your roadmap.
                </small>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-project-modal').classList.add('hidden');">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Project</button>
            </div>
        </form>
    </div>
</div>

<script>
function renameProject(id, currentName, token) {
    var name = prompt('Rename project:', currentName);
    if (name && name.trim()) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '/app/projects/' + id + '/rename';
        var t = document.createElement('input');
        t.type = 'hidden'; t.name = '_csrf_token'; t.value = token;
        var n = document.createElement('input');
        n.type = 'hidden'; n.name = 'name'; n.value = name.trim();
        f.appendChild(t);
        f.appendChild(n);
        document.body.appendChild(f);
        f.submit();
    }
}

function filterProjectList(query) {
    var q = query.trim().toLowerCase();
    document.querySelectorAll('.project-card').forEach(function(card) {
        var name = (card.querySelector('.project-name')?.textContent || '').toLowerCase();
        card.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
    });
}
</script>
