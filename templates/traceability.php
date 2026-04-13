<?php
/**
 * Traceability Template
 *
 * Read-only collapsible tree: OKR nodes → High Level work items → user stories →
 * Jira keys → git link pills. Status rollups and progress bars at every level.
 *
 * Variables: $project (array), $tree (array with keys: project, okrs, unlinked_stories)
 */

$okrs             = $tree['okrs']             ?? [];
$unlinkedStories  = $tree['unlinked_stories'] ?? [];

// Badge class + label maps used throughout the template
$statusBadge = [
    'backlog'     => 'badge-secondary',
    'in_progress' => 'badge-info',
    'in_review'   => 'badge-warning',
    'done'        => 'badge-success',
];
$statusLabel = [
    'backlog'     => 'Backlog',
    'in_progress' => 'In Progress',
    'in_review'   => 'In Review',
    'done'        => 'Done',
];

/**
 * Render a status badge span.
 *
 * @param string $status Story/item status value
 * @return string        HTML badge span
 */
$statusBadgeHtml = function (string $status) use ($statusBadge, $statusLabel): string {
    $cls   = $statusBadge[$status]  ?? 'badge-secondary';
    $label = $statusLabel[$status]  ?? htmlspecialchars($status);
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
};

/**
 * Render a row of git link pills for a set of git link rows.
 *
 * @param array $links  Array of story_git_links rows
 * @return string       HTML string of pill anchors
 */
$gitPillsHtml = function (array $links): string {
    if (empty($links)) {
        return '';
    }
    $html = '';
    foreach ($links as $link) {
        $status = $link['status'] ?? 'unknown';
        $mod    = match ($status) {
            'open'   => 'git-pill--open',
            'merged' => 'git-pill--merged',
            'closed' => 'git-pill--closed',
            default  => 'git-pill--unknown',
        };
        $label   = htmlspecialchars($link['ref_label'] ?: $link['ref_type']);
        $rawUrl  = $link['ref_url'];
        $safeUrl = preg_match('#^https?://#i', $rawUrl) ? $rawUrl : '#';
        $url     = htmlspecialchars($safeUrl);
        $title   = htmlspecialchars(($link['provider'] ?? '') . ': ' . ($link['ref_label'] ?: $rawUrl));
        $html  .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
               .     'class="git-pill ' . $mod . '" title="' . $title . '">'
               .     $label
               . '</a> ';
    }
    return trim($html);
};

/**
 * Build a thin progress bar HTML string.
 *
 * @param int $done  Number of done items
 * @param int $total Total items
 * @return string    HTML progress bar div
 */
$progressBarHtml = function (int $done, int $total): string {
    $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
    return '<progress class="trace-progress trace-progress--meter" max="100" value="' . $pct . '" title="' . $pct . '% done"></progress>';
};
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Traceability &mdash; Strategy to Code
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">The complete chain from OKR to code. Each OKR node expands to show its work items, user stories, Jira issues, and git links with live status rollups. Use this view to confirm every strategic objective has traceable delivery.</span>
        </span>
    </h1>
</div>

<!-- ===========================
     Empty State
     =========================== -->
<?php if (empty($okrs) && empty($unlinkedStories)): ?>
<div class="card trace-tree">
    <div class="card-body trace-empty">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-light,#94a3b8)" stroke-width="1.5" class="trace-empty-icon" aria-hidden="true">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            <line x1="8" y1="8" x2="16" y2="16"/>
        </svg>
        <p class="text-muted trace-empty-copy">No OKR nodes found for this project. Add OKRs to your strategy diagram to unlock the traceability view.</p>
        <a href="/app/diagram?project_id=<?= (int) $project['id'] ?>" class="btn btn-primary btn-sm">Go to Strategy Diagram</a>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     OKR Accordion Tree
     =========================== -->
<div class="trace-tree">

<?php foreach ($okrs as $okrEntry):
    $storyTotal  = (int) $okrEntry['story_count'];
    $doneTotal   = (int) $okrEntry['done_count'];
    $gitTotal    = (int) $okrEntry['git_link_count'];
    $pct         = $storyTotal > 0 ? (int) round($doneTotal / $storyTotal * 100) : 0;
    $okrTitle    = $okrEntry['title'];
    $okrDesc     = $okrEntry['description'] ?? '';
?>
<details class="trace-okr" open>
    <summary<?= $okrDesc !== '' ? ' title="' . htmlspecialchars($okrDesc) . '"' : '' ?>>
        <span class="trace-chevron" aria-hidden="true">&#9658;</span>
        <strong class="trace-okr-title"><?= htmlspecialchars($okrTitle) ?></strong>
        <span class="badge badge-secondary trace-badge trace-badge--first"><?= $storyTotal ?> stor<?= $storyTotal !== 1 ? 'ies' : 'y' ?></span>
        <span class="badge badge-success trace-badge"><?= $doneTotal ?> done</span>
        <?php if ($gitTotal > 0): ?>
            <span class="badge badge-secondary trace-badge"><?= $gitTotal ?> git link<?= $gitTotal !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <progress class="trace-progress trace-progress--summary" max="100" value="<?= $pct ?>" title="<?= $pct ?>% done"></progress>
        <span class="trace-progress-label"><?= $pct ?>%</span>
    </summary>

    <?php if (empty($okrEntry['work_items'])): ?>
        <div class="trace-work-item trace-work-item--empty">
            No work items linked to this OKR yet.
            <a href="/app/work-items?project_id=<?= (int) $project['id'] ?>">Add work items</a>
        </div>
    <?php else: ?>

    <?php foreach ($okrEntry['work_items'] as $wiEntry):
        $wi        = $wiEntry['item'];
        $wiTitle   = $wi['title'] ?? '(Unnamed)';
        $wiStatus  = $wi['status'] ?? 'backlog';
        $wiJira    = $wiEntry['jira_key'];
        $wiStories = $wiEntry['stories'];
        $wiDone    = (int) $wiEntry['done_count'];
        $wiTotal   = (int) $wiEntry['story_count'];
        $wiGit     = (int) $wiEntry['git_link_count'];
    ?>
    <details class="trace-work-item">
        <summary>
            <span class="trace-chevron" aria-hidden="true">&#9658;</span>
            <span class="trace-work-item-title"><?= htmlspecialchars($wiTitle) ?></span>
            <?= $statusBadgeHtml($wiStatus) ?>
            <?php if ($wiJira): ?>
                <span class="badge badge-info trace-badge"><?= htmlspecialchars($wiJira) ?></span>
            <?php endif; ?>
            <span class="trace-meta trace-meta--first"><?= $wiTotal ?> stor<?= $wiTotal !== 1 ? 'ies' : 'y' ?>, <?= $wiDone ?> done</span>
            <?php if ($wiGit > 0): ?>
                <span class="trace-meta">&bull; <?= $wiGit ?> git link<?= $wiGit !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </summary>

        <?php if (empty($wiStories)): ?>
            <div class="trace-story trace-story--empty">No stories yet.</div>
        <?php else: ?>

        <?php foreach ($wiStories as $sNode):
            $story      = $sNode['story'];
            $storyJira  = $sNode['jira_key'];
            $storyLinks = $sNode['git_links'];
            $sStatus    = $story['status'] ?? 'backlog';
        ?>
        <div class="trace-story">
            <span class="trace-story-title"><?= htmlspecialchars($story['title'] ?? '') ?></span>
            <?= $statusBadgeHtml($sStatus) ?>
            <?php if ($storyJira): ?>
                <span class="badge badge-info trace-badge trace-badge--small"><?= htmlspecialchars($storyJira) ?></span>
            <?php endif; ?>
            <?= $gitPillsHtml($storyLinks) ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </details>
    <?php endforeach; ?>

    <?php endif; ?>
</details>
<?php endforeach; ?>

<!-- ===========================
     Unlinked Stories
     =========================== -->
<?php if (!empty($unlinkedStories)): ?>
<details class="trace-okr">
    <summary>
        <span class="trace-chevron" aria-hidden="true">&#9658;</span>
        <strong class="trace-okr-title">Unlinked Stories</strong>
        <span class="badge badge-warning trace-badge trace-badge--first"><?= count($unlinkedStories) ?> stor<?= count($unlinkedStories) !== 1 ? 'ies' : 'y' ?></span>
        <span class="trace-meta trace-meta--muted">not assigned to any work item</span>
    </summary>
    <?php foreach ($unlinkedStories as $sNode):
        $story      = $sNode['story'];
        $storyJira  = $sNode['jira_key'];
        $storyLinks = $sNode['git_links'];
        $sStatus    = $story['status'] ?? 'backlog';
    ?>
    <div class="trace-story">
        <span class="trace-story-title"><?= htmlspecialchars($story['title'] ?? '') ?></span>
        <?= $statusBadgeHtml($sStatus) ?>
        <?php if ($storyJira): ?>
            <span class="badge badge-info trace-badge trace-badge--small"><?= htmlspecialchars($storyJira) ?></span>
        <?php endif; ?>
        <?= $gitPillsHtml($storyLinks) ?>
    </div>
    <?php endforeach; ?>
</details>
<?php endif; ?>

</div><!-- /.trace-tree -->
