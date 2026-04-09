<?php
// Build project query string — persist across pages
$pid = isset($project) ? (int) $project['id'] : (int) ($_SESSION['_last_project_id'] ?? 0);
$pq = $pid ? "?project_id={$pid}" : '';
if ($pid) { $_SESSION['_last_project_id'] = $pid; }

// Determine current page path for project switcher (stay on same page)
$currentPath = strtok($_SERVER['REQUEST_URI'] ?? '/app/upload', '?');

// ---- Icon helper: returns an inline SVG by name (stroke-based, 20x20) ----
$icon = function (string $name): string {
    $attrs = 'class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    switch ($name) {
        case 'home':
            return "<svg $attrs><path d='M3 12l9-9 9 9'/><path d='M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10'/></svg>";
        case 'upload':
            return "<svg $attrs><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='17 8 12 3 7 8'/><line x1='12' y1='3' x2='12' y2='15'/></svg>";
        case 'roadmap':
            return "<svg $attrs><circle cx='6' cy='5' r='2'/><circle cx='18' cy='19' r='2'/><path d='M6 7v4a4 4 0 0 0 4 4h4a4 4 0 0 1 4 4'/></svg>";
        case 'work-items':
            return "<svg $attrs><rect x='3' y='4' width='18' height='4' rx='1'/><rect x='3' y='10' width='18' height='4' rx='1'/><rect x='3' y='16' width='18' height='4' rx='1'/></svg>";
        case 'prioritisation':
            return "<svg $attrs><line x1='6' y1='4' x2='6' y2='20'/><polyline points='2 8 6 4 10 8'/><line x1='18' y1='4' x2='18' y2='20'/><polyline points='14 16 18 20 22 16'/></svg>";
        case 'risks':
            return "<svg $attrs><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/><line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/></svg>";
        case 'user-stories':
            return "<svg $attrs><path d='M4 19.5A2.5 2.5 0 0 1 6.5 17H20'/><path d='M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z'/></svg>";
        case 'sprints':
            return "<svg $attrs><polygon points='13 2 3 14 12 14 11 22 21 10 12 10 13 2'/></svg>";
        case 'governance':
            return "<svg $attrs><path d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'/><polyline points='9 12 11 14 15 10'/></svg>";
        case 'traceability':
            return "<svg $attrs><path d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/><path d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/><line x1='8' y1='8' x2='16' y2='16'/></svg>";
        case 'admin':
            return "<svg $attrs><circle cx='12' cy='12' r='3'/><path d='M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z'/></svg>";
        case 'integrations':
            return "<svg $attrs><path d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/><path d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/></svg>";
        case 'audit':
            return "<svg $attrs><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/><line x1='9' y1='13' x2='15' y2='13'/><line x1='9' y1='17' x2='15' y2='17'/></svg>";
        case 'billing':
            return "<svg $attrs><rect x='2' y='5' width='20' height='14' rx='2'/><line x1='2' y1='10' x2='22' y2='10'/></svg>";
        case 'superadmin':
            return "<svg $attrs><path d='M3 19h18M5 19V9l7 4 7-4v10'/></svg>";
        case 'chevron-left':
            return "<svg $attrs><polyline points='15 18 9 12 15 6'/></svg>";
    }
    return '';
};
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="/app/home" class="sidebar-brand-full">StratFlow</a>
        <a href="/app/home" class="sidebar-brand-mark" aria-label="StratFlow home">S</a>
    </div>
    <div class="sidebar-project">
        <span class="sidebar-project-label">CURRENT PROJECT</span>
        <?php if (!empty($all_projects)): ?>
        <select class="sidebar-project-select" onchange="if(this.value) window.location='<?= htmlspecialchars($currentPath) ?>?project_id='+this.value">
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
        <a href="/app/home" class="nav-link <?= ($active_page ?? '') === 'home' ? 'active' : '' ?>" data-label="Home">
            <?= $icon('home') ?><span class="nav-label">Home</span>
        </a>

        <?php
        // Compute step completion for dots next to each workflow nav link
        $sidebar_completion = [];
        if ($pid) {
            try {
                $sidebar_completion = \StratFlow\Controllers\HomeController::computeStepCompletion(
                    \StratFlow\Core\Database::getInstance(),
                    $pid
                );
            } catch (\Throwable $e) { /* non-critical */ }
        }
        $stepDot = function(string $key) use ($sidebar_completion) {
            if (empty($sidebar_completion)) return '';
            $done = !empty($sidebar_completion[$key]);
            $cls = $done ? 'nav-dot nav-dot--done' : 'nav-dot';
            return '<span class="' . $cls . '" aria-hidden="true"></span>';
        };
        ?>

        <div class="sidebar-section-label">Plan</div>
        <a href="/app/upload<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'upload' ? 'active' : '' ?>" data-label="Upload">
            <?= $icon('upload') ?><span class="nav-label">Upload</span><?= $stepDot('upload') ?>
        </a>
        <a href="/app/diagram<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'diagram' ? 'active' : '' ?>" data-label="Roadmap">
            <?= $icon('roadmap') ?><span class="nav-label">Roadmap</span><?= $stepDot('diagram') ?>
        </a>
        <a href="/app/work-items<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'work-items' ? 'active' : '' ?>" data-label="Work Items">
            <?= $icon('work-items') ?><span class="nav-label">Work Items</span><?= $stepDot('work-items') ?>
        </a>

        <div class="sidebar-section-label">Execute</div>
        <a href="/app/prioritisation<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'prioritisation' ? 'active' : '' ?>" data-label="Prioritisation">
            <?= $icon('prioritisation') ?><span class="nav-label">Prioritisation</span><?= $stepDot('prioritisation') ?>
        </a>
        <a href="/app/risks<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'risks' ? 'active' : '' ?>" data-label="Risks">
            <?= $icon('risks') ?><span class="nav-label">Risks</span><?= $stepDot('risks') ?>
        </a>
        <a href="/app/user-stories<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'user-stories' ? 'active' : '' ?>" data-label="User Stories">
            <?= $icon('user-stories') ?><span class="nav-label">User Stories</span><?= $stepDot('user-stories') ?>
        </a>
        <a href="/app/sprints<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'sprints' ? 'active' : '' ?>" data-label="Sprints">
            <?= $icon('sprints') ?><span class="nav-label">Sprints</span><?= $stepDot('sprints') ?>
        </a>

        <div class="sidebar-section-label">Monitor</div>
        <a href="/app/governance<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'governance' ? 'active' : '' ?>" data-label="Governance">
            <?= $icon('governance') ?><span class="nav-label">Governance</span><?= $stepDot('governance') ?>
        </a>
        <a href="/app/traceability<?= $pq ?>" class="nav-link <?= ($active_page ?? '') === 'traceability' ? 'active' : '' ?>" data-label="Traceability">
            <?= $icon('traceability') ?><span class="nav-label">Traceability</span>
        </a>

        <?php if (in_array($user['role'] ?? '', ['org_admin', 'superadmin'])): ?>
            <hr class="sidebar-divider">
            <a href="/app/admin" class="nav-link <?= ($active_page ?? '') === 'admin' ? 'active' : '' ?>" data-label="Admin">
                <?= $icon('admin') ?><span class="nav-label">Admin</span>
            </a>
            <a href="/app/admin/integrations" class="nav-link <?= ($active_page ?? '') === 'integrations' ? 'active' : '' ?>" data-label="Integrations">
                <?= $icon('integrations') ?><span class="nav-label">Integrations</span>
            </a>
            <a href="/app/admin/audit-logs" class="nav-link <?= ($active_page ?? '') === 'audit-logs' ? 'active' : '' ?>" data-label="Audit Logs">
                <?= $icon('audit') ?><span class="nav-label">Audit Logs</span>
            </a>
        <?php endif; ?>

        <?php
        // Billing visible to: superadmin, explicit billing flag, or org_admin when no dedicated billing user exists
        $showBilling = ($user['role'] ?? '') === 'superadmin' || ($user['has_billing_access'] ?? false);
        if (!$showBilling && ($user['role'] ?? '') === 'org_admin') {
            try {
                $billingUsers = \StratFlow\Core\Database::getInstance()->query(
                    "SELECT COUNT(*) AS cnt FROM users WHERE org_id = :oid AND has_billing_access = 1",
                    [':oid' => $user['org_id']]
                )->fetch();
                $showBilling = ((int) ($billingUsers['cnt'] ?? 0) === 0);
            } catch (\Throwable $e) { $showBilling = true; }
        }
        ?>
        <?php if ($showBilling): ?>
            <a href="/app/admin/billing" class="nav-link <?= ($active_page ?? '') === 'billing' ? 'active' : '' ?>" data-label="Billing">
                <?= $icon('billing') ?><span class="nav-label">Billing</span>
            </a>
        <?php endif; ?>

        <?php if (($user['role'] ?? '') === 'superadmin'): ?>
            <a href="/superadmin" class="nav-link <?= ($active_page ?? '') === 'superadmin' ? 'active' : '' ?>" data-label="Superadmin">
                <?= $icon('superadmin') ?><span class="nav-label">Superadmin</span>
            </a>
        <?php endif; ?>
    </nav>

    <button type="button" class="sidebar-collapse-btn" id="sidebar-collapse-btn"
            aria-label="Collapse sidebar" aria-pressed="false"
            onclick="toggleSidebarCollapsed()">
        <?= $icon('chevron-left') ?>
        <span class="nav-label">Collapse</span>
    </button>
</aside>
