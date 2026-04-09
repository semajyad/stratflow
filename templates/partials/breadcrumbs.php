<?php
/**
 * Breadcrumbs Partial
 *
 * Rendered in the app topbar. Builds a "Home > Project > Step" trail from the
 * current request path and the $project variable (if available in scope).
 *
 * Expects from scope (optional): $project (array)
 */

$path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

// Map workflow paths → display labels
$pathLabels = [
    '/app/home'           => 'Home',
    '/app/upload'         => 'Document Upload',
    '/app/diagram'        => 'Strategy Roadmap',
    '/app/work-items'     => 'Work Items',
    '/app/prioritisation' => 'Prioritisation',
    '/app/risks'          => 'Risk Modelling',
    '/app/user-stories'   => 'User Stories',
    '/app/sprints'        => 'Sprint Allocation',
    '/app/governance'     => 'Governance',
    '/app/admin'          => 'Administration',
    '/app/admin/users'    => 'Users',
    '/app/admin/teams'    => 'Teams',
    '/app/admin/settings' => 'Settings',
    '/app/admin/billing'  => 'Billing',
    '/app/admin/integrations' => 'Integrations',
    '/app/admin/audit-logs'   => 'Audit Logs',
    '/app/admin/invoices'     => 'Invoices',
    '/app/admin/sync-log'     => 'Sync History',
];

$crumbs = [];

// Suppress breadcrumbs on the home dashboard itself
if ($path === '/app/home') {
    return;
}

$crumbs[] = ['label' => 'Home', 'url' => '/app/home'];

$isAdminPath = strpos($path, '/app/admin') === 0;

if ($isAdminPath) {
    $crumbs[] = ['label' => 'Administration', 'url' => '/app/admin'];
    if ($path !== '/app/admin' && isset($pathLabels[$path])) {
        $crumbs[] = ['label' => $pathLabels[$path], 'url' => null];
    }
} else {
    // Workflow pages — include project name crumb if available
    if (!empty($project) && is_array($project) && !empty($project['name'])) {
        $crumbs[] = [
            'label' => $project['name'],
            'url'   => '/app/upload?project_id=' . (int) ($project['id'] ?? 0),
        ];
    }
    if (isset($pathLabels[$path])) {
        $crumbs[] = ['label' => $pathLabels[$path], 'url' => null];
    }
}

if (count($crumbs) < 2) {
    return; // Nothing meaningful to show
}
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
    <ol class="breadcrumbs-list">
        <?php foreach ($crumbs as $i => $c): $isLast = $i === count($crumbs) - 1; ?>
            <li class="breadcrumbs-item">
                <?php if ($c['url'] && !$isLast): ?>
                    <a href="<?= htmlspecialchars($c['url']) ?>"><?= htmlspecialchars($c['label']) ?></a>
                <?php else: ?>
                    <span aria-current="<?= $isLast ? 'page' : 'false' ?>"><?= htmlspecialchars($c['label']) ?></span>
                <?php endif; ?>
                <?php if (!$isLast): ?>
                    <svg class="breadcrumbs-sep" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
