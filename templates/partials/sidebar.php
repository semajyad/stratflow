<?php
// Build project query string if we're in a project context
$pid = isset($project) ? (int) $project['id'] : 0;
$pq = $pid ? "?project_id={$pid}" : '';
// Persist last project for home page "Continue Working" section
if ($pid) { $_SESSION['_last_project_id'] = $pid; }
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="/app/home">StratFlow</a>
    </div>
    <div class="sidebar-project">
        <span class="sidebar-project-label">Current Project</span>
        <?php if (!empty($all_projects)): ?>
        <select class="sidebar-project-select" onchange="if(this.value) window.location='/app/upload?project_id='+this.value">
            <option value="">Select a project...</option>
            <?php foreach ($all_projects as $ap): ?>
                <option value="<?= (int) $ap['id'] ?>" <?= $pid === (int) $ap['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ap['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php elseif ($pid && isset($project)): ?>
        <span class="sidebar-project-name"><?= htmlspecialchars($project['name']) ?></span>
        <?php else: ?>
        <span class="sidebar-project-name" style="color: #64748b; font-style: italic;">No project selected</span>
        <?php endif; ?>
    </div>
    <nav class="sidebar-nav">
        <a href="/app/home" class="nav-link <?= ($active_page ?? '') === 'home' ? 'active' : '' ?>">
            Home
        </a>
        <a href="/app/upload<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'upload' ? 'active' : '' ?>">
            Document Upload
        </a>
        <a href="/app/diagram<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'diagram' ? 'active' : '' ?>">
            Strategy Roadmap
        </a>
        <a href="/app/work-items<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'work-items' ? 'active' : '' ?>">
            High-Level Work Items
        </a>
        <a href="/app/prioritisation<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'prioritisation' ? 'active' : '' ?>">
            Prioritisation
        </a>
        <a href="/app/risks<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'risks' ? 'active' : '' ?>">
            Risk Modelling
        </a>
        <a href="/app/user-stories<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'user-stories' ? 'active' : '' ?>">
            User Stories
        </a>
        <a href="/app/sprints<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'sprints' ? 'active' : '' ?>">
            Sprint Allocation
        </a>
        <a href="/app/governance<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'governance' ? 'active' : '' ?>">
            Governance
        </a>

        <?php if (in_array($user['role'] ?? '', ['org_admin', 'superadmin'])): ?>
            <hr class="sidebar-divider">
            <a href="/app/admin" class="nav-link <?= ($active_page ?? '') === 'admin' ? 'active' : '' ?>">
                &#9881; Administration
            </a>
            <a href="/app/admin/integrations" class="nav-link <?= ($active_page ?? '') === 'integrations' ? 'active' : '' ?>">
                Integrations
            </a>
        <?php endif; ?>

        <?php if (($user['role'] ?? '') === 'superadmin'): ?>
            <a href="/superadmin" class="nav-link <?= ($active_page ?? '') === 'superadmin' ? 'active' : '' ?>">
                &#128081; Superadmin
            </a>
        <?php endif; ?>
    </nav>
</aside>
