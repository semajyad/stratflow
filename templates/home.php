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
        <p class="page-subtitle home-subtitle">
            StratFlow turns your strategy documents into a prioritised, AI-ready engineering roadmap.
        </p>
    </div>
    <?php if ($canCreateProjects): ?>
    <button type="button" class="btn btn-primary js-open-project-modal"
            data-modal-id="new-project-modal" data-prefix="new" data-focus-id="new-project-name">
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
        if ((int) $p['id'] === (int) $lastProjectId) {
            $lastProject = $p;
            break;
        }
    }
}
?>
<?php if ($lastProject): ?>
<section class="card mb-4 home-resume-card">
    <div class="card-body home-resume-body">
        <div>
            <span class="home-resume-label">Continue Working On</span>
            <h3 class="home-resume-title"><?= htmlspecialchars($lastProject['name']) ?></h3>
            <small class="text-muted home-resume-meta">
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
        <h2 class="card-title home-projects-title">Your Projects <span class="text-muted home-projects-count">(<?= count($projects) ?>)</span></h2>
        <?php if (count($projects) > 3): ?>
            <input type="search" id="project-search" placeholder="Search projects..."
                   class="js-project-search home-project-search">
        <?php endif; ?>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state home-empty-state">
            <p class="home-empty-title">Create your first project to get started</p>
            <p class="text-muted home-empty-copy">Each project takes a strategy document through the full workflow: upload, roadmap, work items, stories, sprints, and governance.</p>
        </div>
    <?php else: ?>
        <div class="project-list">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-info">
                        <div class="home-project-header">
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
                            <div class="home-project-progress">
                                <?php foreach ($stepKeys as $i => $k):
                                    $done = !empty($project['completion'][$k]);
                                ?>
                                    <span title="<?= $stepLabels[$i] ?><?= $done ? ' - complete' : '' ?>"
                                          class="home-step-bar<?= $done ? ' home-step-bar--done' : '' ?>"></span>
                                <?php endforeach; ?>
                                <span class="text-muted home-step-meta">
                                    <?= (int) $project['steps_complete'] ?>/<?= (int) $project['steps_total'] ?> &middot; Next: <?= htmlspecialchars($project['next_step_label'] ?? '') ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="project-actions home-project-actions">
                        <?php
                        $jiraKey = $project['jira_project_key'] ?? '';
                        $jiraLabel = 'Jira: None';
                        if ($jiraKey) {
                            foreach ($jira_projects ?? [] as $jp) {
                                if ($jp['key'] === $jiraKey) {
                                    $jiraLabel = $jp['key'] . ' - ' . $jp['name'];
                                    break;
                                }
                            }
                            if ($jiraLabel === 'Jira: None') {
                                $jiraLabel = $jiraKey;
                            }
                        }
                        ?>
                        <span class="badge home-jira-badge" title="Jira project">
                            <?= htmlspecialchars($jiraLabel) ?>
                        </span>
                        <a href="<?= htmlspecialchars($project['next_step_url'] ?? '/app/upload?project_id=' . (int) $project['id']) ?>"
                           class="btn btn-primary btn-sm"
                           title="<?= (int) ($project['steps_complete'] ?? 0) ?>/<?= (int) ($project['steps_total'] ?? 8) ?> steps complete - next: <?= htmlspecialchars($project['next_step_label'] ?? 'Upload') ?>">
                            Open Project
                        </a>
                        <?php if (\StratFlow\Security\ProjectPolicy::canManageProject(\StratFlow\Core\Database::getInstance(), $user, $project)): ?>
                        <button type="button"
                                class="btn btn-sm btn-secondary js-open-edit-project-modal home-project-action-btn"
                                data-project-id="<?= (int) $project['id'] ?>"
                                data-project-name="<?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-jira-key="<?= htmlspecialchars($jiraKey, ENT_QUOTES, 'UTF-8') ?>"
                                data-visibility="<?= htmlspecialchars($project['visibility'] ?? 'everyone', ENT_QUOTES, 'UTF-8') ?>"
                                data-memberships='<?= htmlspecialchars(json_encode($project["memberships"] ?? []), ENT_QUOTES, "UTF-8") ?>'>
                            Edit
                        </button>
                        <form method="POST" action="/app/projects/<?= (int) $project['id'] ?>/delete" class="inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="btn btn-sm btn-danger home-project-action-btn"
                                    data-confirm="Delete project &quot;<?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?>&quot;? This cannot be undone.">Delete</button>
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
$orgUsersJson = json_encode(array_map(fn($u) => [
    'id'    => (int) $u['id'],
    'label' => $u['full_name'] . ' (' . $u['email'] . ')',
], $org_users ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<textarea id="home-org-users-data" class="hidden" aria-hidden="true" aria-label="Organisation users data"><?= htmlspecialchars($orgUsersJson ?? '[]', ENT_QUOTES, 'UTF-8') ?></textarea>

<!-- New Project Modal -->
<div id="new-project-modal" class="modal-overlay hidden js-project-modal home-project-modal">
    <div class="card home-project-modal-card">
        <div class="card-header flex justify-between items-center">
            <h2 class="card-title home-modal-title">Create New Project</h2>
            <button type="button" class="js-close-project-modal home-modal-close" data-modal-id="new-project-modal">&times;</button>
        </div>
        <form method="POST" action="/app/projects" class="card-body">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-group">
                <label class="form-label" for="new-project-name">Project Name</label>
                <input type="text" id="new-project-name" name="name" class="form-input"
                       placeholder="e.g. Q3 Platform Modernisation" required maxlength="255" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="new-visibility">Access</label>
                <select name="visibility" id="new-visibility" class="form-input js-project-visibility" data-prefix="new">
                    <option value="everyone">Everyone in organisation</option>
                    <option value="restricted">Restricted - specific users only</option>
                </select>
            </div>
            <div id="new-member-picker" class="hidden">
                <?php include __DIR__ . '/partials/project-member-picker.php'; ?>
            </div>
            <div class="flex justify-end gap-2 home-modal-actions">
                <button type="button" class="btn btn-secondary js-close-project-modal" data-modal-id="new-project-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Project</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Project Modal -->
<div id="edit-project-modal" class="modal-overlay hidden js-project-modal home-project-modal">
    <div class="card home-project-modal-card">
        <div class="card-header flex justify-between items-center">
            <h2 class="card-title home-modal-title">Edit Project</h2>
            <button type="button" class="js-close-project-modal home-modal-close" data-modal-id="edit-project-modal">&times;</button>
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
                            <?= htmlspecialchars($jp['key'] . ' - ' . $jp['name']) ?>
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
                <label class="form-label" for="edit-visibility">Access</label>
                <select name="visibility" id="edit-visibility" class="form-input js-project-visibility" data-prefix="edit">
                    <option value="everyone">Everyone in organisation</option>
                    <option value="restricted">Restricted - specific users only</option>
                </select>
            </div>
            <div id="edit-member-picker" class="hidden">
                <?php include __DIR__ . '/partials/project-member-picker.php'; ?>
            </div>
            <div class="flex justify-end gap-2 home-modal-actions">
                <button type="button" class="btn btn-secondary js-close-project-modal" data-modal-id="edit-project-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
