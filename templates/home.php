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
<?php
$canCreateProjects = \StratFlow\Security\PermissionService::can(
    $user,
    \StratFlow\Security\PermissionService::PROJECT_CREATE
);
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
    <?php if ($canCreateProjects): ?>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('new-project-modal').classList.remove('hidden'); clearMemberPicker('new'); setTimeout(function(){document.getElementById('new-project-name').focus();},50);">
        + New Project
    </button>
    <?php endif; ?>
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
                        <?php
                        $jiraKey = $project['jira_project_key'] ?? '';
                        $jiraLabel = 'Jira: None';
                        if ($jiraKey) {
                            // Find the project name from jira_projects list
                            foreach ($jira_projects ?? [] as $jp) {
                                if ($jp['key'] === $jiraKey) {
                                    $jiraLabel = $jp['key'] . ' - ' . $jp['name'];
                                    break;
                                }
                            }
                            if ($jiraLabel === 'Jira: None') $jiraLabel = $jiraKey;
                        }
                        ?>
                        <span class="badge" style="font-size:0.7rem; background:var(--secondary); color:#fff; white-space:nowrap;" title="Jira project">
                            <?= htmlspecialchars($jiraLabel) ?>
                        </span>
                        <a href="<?= htmlspecialchars($project['next_step_url'] ?? '/app/upload?project_id=' . (int) $project['id']) ?>"
                           class="btn btn-primary btn-sm"
                           title="<?= (int) ($project['steps_complete'] ?? 0) ?>/<?= (int) ($project['steps_total'] ?? 8) ?> steps complete — next: <?= htmlspecialchars($project['next_step_label'] ?? 'Upload') ?>">
                            Open Project
                        </a>
                        <?php if (\StratFlow\Security\ProjectPolicy::canManageProject(\StratFlow\Core\Database::getInstance(), $user, $project)): ?>
                        <button type="button" class="btn btn-sm btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.75rem;"
                                onclick="openEditProjectModal(<?= (int) $project['id'] ?>, <?= htmlspecialchars(json_encode($project['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($jiraKey), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($project['visibility'] ?? 'everyone'), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($project['memberships'] ?? []), ENT_QUOTES) ?>)">
                            Edit
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

<!-- Shared member picker partial used by both New and Edit modals -->
<?php
// Build org users JSON for JS (id, name, email)
// JSON_HEX_TAG prevents </script> injection; safe for inline <script> output (do NOT wrap in htmlspecialchars)
$orgUsersJson = json_encode(array_map(fn($u) => [
    'id'    => (int) $u['id'],
    'label' => $u['full_name'] . ' (' . $u['email'] . ')',
], $org_users ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>

<!-- New Project Modal -->
<div id="new-project-modal" class="modal-overlay hidden" style="position:fixed; inset:0; background:rgba(15,23,42,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;"
     onclick="if(event.target===this) this.classList.add('hidden');">
    <div class="card" style="max-width:520px; width:90%; margin:0;">
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
            </div>
            <div class="form-group">
                <label class="form-label">Access</label>
                <select name="visibility" id="new-visibility" class="form-input"
                        onchange="toggleMemberPicker('new', this.value)">
                    <option value="everyone">Everyone in organisation</option>
                    <option value="restricted">Restricted — specific users only</option>
                </select>
            </div>
            <div id="new-member-picker" style="display:none;">
                <?php include __DIR__ . '/partials/project-member-picker.php'; ?>
            </div>
            <div class="flex justify-end gap-2" style="margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-project-modal').classList.add('hidden');">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Project</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Project Modal -->
<div id="edit-project-modal" class="modal-overlay hidden" style="position:fixed; inset:0; background:rgba(15,23,42,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;"
     onclick="if(event.target===this) this.classList.add('hidden');">
    <div class="card" style="max-width:520px; width:90%; margin:0;">
        <div class="card-header flex justify-between items-center">
            <h2 class="card-title" style="margin:0;">Edit Project</h2>
            <button type="button" onclick="document.getElementById('edit-project-modal').classList.add('hidden');"
                    style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <form method="POST" id="edit-project-form" action="" class="card-body">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-group">
                <label class="form-label" for="edit-project-name">Project Name</label>
                <input type="text" id="edit-project-name" name="name" class="form-input"
                       placeholder="Project name" required maxlength="255" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Jira Project</label>
                <?php if (!empty($jira_projects)): ?>
                <select name="jira_project_key" id="edit-jira-key" class="form-input">
                    <option value="">None</option>
                    <?php foreach ($jira_projects as $jp): ?>
                        <option value="<?= htmlspecialchars($jp['key']) ?>">
                            <?= htmlspecialchars($jp['key'] . ' — ' . $jp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" name="jira_project_key" id="edit-jira-key" class="form-input"
                       placeholder="e.g. PROJ" maxlength="20">
                <small class="text-muted">Connect Jira in Integrations to see a project picker.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Access</label>
                <select name="visibility" id="edit-visibility" class="form-input"
                        onchange="toggleMemberPicker('edit', this.value)">
                    <option value="everyone">Everyone in organisation</option>
                    <option value="restricted">Restricted — specific users only</option>
                </select>
            </div>
            <div id="edit-member-picker" style="display:none;">
                <?php include __DIR__ . '/partials/project-member-picker.php'; ?>
            </div>
            <div class="flex justify-end gap-2" style="margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-project-modal').classList.add('hidden');">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Org users for member picker — safe inline JSON (no htmlspecialchars, uses JSON_HEX_TAG)
var _orgUsers = <?= $orgUsersJson ?? '[]' ?>;

// ── Modal helpers ──────────────────────────────────────────────────────────────

function openEditProjectModal(id, name, jiraKey, visibility, memberships) {
    document.getElementById('edit-project-form').action = '/app/projects/' + id + '/edit';
    document.getElementById('edit-project-name').value = name;
    var jiraEl = document.getElementById('edit-jira-key');
    if (jiraEl) { jiraEl.value = jiraKey || ''; }

    var visEl = document.getElementById('edit-visibility');
    if (visEl) {
        visEl.value = visibility || 'everyone';
        toggleMemberPicker('edit', visEl.value);
    }

    preloadMemberPicker('edit', Array.isArray(memberships) ? memberships : []);

    document.getElementById('edit-project-modal').classList.remove('hidden');
    setTimeout(function() { document.getElementById('edit-project-name').focus(); }, 50);
}

function toggleMemberPicker(prefix, value) {
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) return;
    picker.style.display = value === 'restricted' ? '' : 'none';
    if (value !== 'restricted') { clearMemberPicker(prefix); }
}

function filterProjectList(query) {
    var q = query.trim().toLowerCase();
    document.querySelectorAll('.project-card').forEach(function(card) {
        var name = (card.querySelector('.project-name')?.textContent || '').toLowerCase();
        card.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
    });
}

// ── Member picker: type-to-search + chips ─────────────────────────────────────

function _escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function _getSelectedIds(picker) {
    return Array.from(picker.querySelectorAll('.member-chip[data-member-id]'))
               .map(function(el) { return parseInt(el.dataset.memberId, 10); });
}

function memberPickerSearch(input) {
    var picker = input.closest('.member-picker-wrap');
    if (!picker) return;
    var q = input.value.trim().toLowerCase();
    var resultsEl = picker.querySelector('.member-search-results');
    var selected = _getSelectedIds(picker);

    var matches = _orgUsers.filter(function(u) {
        return selected.indexOf(u.id) === -1 &&
               (q === '' || u.label.toLowerCase().indexOf(q) !== -1);
    }).slice(0, 20);

    if (!matches.length) { resultsEl.style.display = 'none'; return; }

    resultsEl.innerHTML = matches.map(function(u) {
        return '<div class="member-result-item" data-id="' + u.id + '" data-label="' + _escHtml(u.label) + '" ' +
               'style="padding:0.45rem 0.75rem; cursor:pointer; font-size:0.875rem; border-bottom:1px solid var(--border);" ' +
               'onmousedown="addMemberChip(this)">' + _escHtml(u.label) + '</div>';
    }).join('');
    resultsEl.style.display = '';
}

function addMemberChip(resultItem) {
    var picker = resultItem.closest('.member-picker-wrap');
    var id = parseInt(resultItem.dataset.id, 10);
    var label = resultItem.dataset.label;
    var membershipRole = resultItem.dataset.role || 'editor';

    // Add chip
    var chipsEl = picker.querySelector('.member-chips');
    var chip = document.createElement('span');
    chip.className = 'member-chip';
    chip.dataset.memberId = id;
    chip.style.cssText = 'display:inline-flex;align-items:center;gap:0.45rem;padding:0.25rem 0.5rem;background:var(--primary);color:#fff;border-radius:6px;font-size:0.8rem;';
    chip.innerHTML = '<span>' + _escHtml(label) + '</span>' +
        '<select style="font-size:0.75rem;border:none;border-radius:4px;padding:0.15rem 0.35rem;" ' +
        'onchange="updateMemberRole(this)">' +
            '<option value="viewer"' + (membershipRole === 'viewer' ? ' selected' : '') + '>Viewer</option>' +
            '<option value="editor"' + (membershipRole === 'editor' ? ' selected' : '') + '>Editor</option>' +
            '<option value="project_admin"' + (membershipRole === 'project_admin' ? ' selected' : '') + '>Project Admin</option>' +
        '</select>' +
        '<button type="button" style="background:none;border:none;color:rgba(255,255,255,0.8);cursor:pointer;padding:0 0 0 0.2rem;font-size:1.1rem;line-height:1;" ' +
        'onclick="removeMemberChip(this)" title="Remove">&times;</button>';
    chipsEl.appendChild(chip);

    // Hidden input for form submission
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'member_roles[' + id + ']';
    hidden.value = membershipRole;
    hidden.dataset.memberId = id;
    picker.appendChild(hidden);

    // Clear search
    picker.querySelector('.member-search-input').value = '';
    picker.querySelector('.member-search-results').style.display = 'none';
}

function removeMemberChip(btn) {
    var chip = btn.closest('.member-chip');
    var picker = chip.closest('.member-picker-wrap');
    var id = chip.dataset.memberId;
    var hidden = picker.querySelector('input[type="hidden"][data-member-id="' + id + '"]');
    if (hidden) hidden.remove();
    chip.remove();
}

function updateMemberRole(select) {
    var chip = select.closest('.member-chip');
    var picker = chip.closest('.member-picker-wrap');
    var id = chip.dataset.memberId;
    var hidden = picker.querySelector('input[type="hidden"][data-member-id="' + id + '"]');
    if (hidden) {
        hidden.value = select.value;
    }
}

function clearMemberPicker(prefix) {
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) return;
    var wrap = picker.querySelector('.member-picker-wrap');
    if (!wrap) return;
    wrap.querySelectorAll('.member-chip').forEach(function(c) { c.remove(); });
    wrap.querySelectorAll('input[type="hidden"][data-member-id]').forEach(function(i) { i.remove(); });
    var si = wrap.querySelector('.member-search-input');
    if (si) si.value = '';
    var sr = wrap.querySelector('.member-search-results');
    if (sr) sr.style.display = 'none';
}

function preloadMemberPicker(prefix, memberships) {
    clearMemberPicker(prefix);
    if (!memberships.length) return;
    var picker = document.getElementById(prefix + '-member-picker');
    if (!picker) return;
    var wrap = picker.querySelector('.member-picker-wrap');
    if (!wrap) return;
    memberships.forEach(function(entry) {
        var id = parseInt(entry.user_id || entry.id, 10);
        var role = entry.membership_role || 'editor';
        var user = _orgUsers.find(function(u) { return u.id === id; });
        if (!user) return;
        var chipsEl = wrap.querySelector('.member-chips');
        var chip = document.createElement('span');
        chip.className = 'member-chip';
        chip.dataset.memberId = id;
        chip.style.cssText = 'display:inline-flex;align-items:center;gap:0.45rem;padding:0.25rem 0.5rem;background:var(--primary);color:#fff;border-radius:6px;font-size:0.8rem;';
        chip.innerHTML = '<span>' + _escHtml(user.label) + '</span>' +
            '<select style="font-size:0.75rem;border:none;border-radius:4px;padding:0.15rem 0.35rem;" ' +
            'onchange="updateMemberRole(this)">' +
                '<option value="viewer"' + (role === 'viewer' ? ' selected' : '') + '>Viewer</option>' +
                '<option value="editor"' + (role === 'editor' ? ' selected' : '') + '>Editor</option>' +
                '<option value="project_admin"' + (role === 'project_admin' ? ' selected' : '') + '>Project Admin</option>' +
            '</select>' +
            '<button type="button" style="background:none;border:none;color:rgba(255,255,255,0.8);cursor:pointer;padding:0 0 0 0.2rem;font-size:1.1rem;line-height:1;" ' +
            'onclick="removeMemberChip(this)" title="Remove">&times;</button>';
        chipsEl.appendChild(chip);
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'member_roles[' + id + ']';
        hidden.value = role;
        hidden.dataset.memberId = id;
        wrap.appendChild(hidden);
    });
}

// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.member-picker-wrap')) {
        document.querySelectorAll('.member-search-results').forEach(function(el) {
            el.style.display = 'none';
        });
    }
});
</script>
