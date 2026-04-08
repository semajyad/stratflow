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
<div class="page-header">
    <h1 class="page-title">Welcome, <?= htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User') ?></h1>
    <p class="page-subtitle">
        StratFlow turns your strategy documents into a prioritised, AI-ready engineering roadmap.
        Upload a strategy document to extract objectives, generate a visual roadmap, and break
        down work into high-level items your team can act on immediately.
    </p>
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
        </div>
        <a href="/app/upload?project_id=<?= (int) $lastProject['id'] ?>" class="btn btn-primary">Resume Project</a>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Your Projects
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Your Projects</h2>
    </div>

    <?php if (empty($projects)): ?>
        <p class="empty-state">No projects yet. Create one below to get started.</p>
    <?php else: ?>
        <div class="project-list">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-info">
                        <span class="project-name"><?= htmlspecialchars($project['name']) ?></span>
                        <span class="status-badge status-<?= htmlspecialchars($project['status']) ?>">
                            <?= ucfirst(htmlspecialchars($project['status'])) ?>
                        </span>
                        <span class="project-date">
                            Created <?= date('j M Y', strtotime($project['created_at'])) ?>
                        </span>
                    </div>
                    <div class="project-actions">
                        <a href="/app/upload?project_id=<?= (int) $project['id'] ?>"
                           class="btn btn-primary btn-sm">
                            Start: Document Upload
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ===========================
     New Project Form
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">New Project</h2>
    </div>
    <form method="POST" action="/app/projects" class="new-project-form">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row">
            <input
                type="text"
                name="name"
                class="form-input"
                placeholder="Project name"
                required
                maxlength="255"
            >
            <button type="submit" class="btn btn-primary">Create Project</button>
        </div>
    </form>
</section>
