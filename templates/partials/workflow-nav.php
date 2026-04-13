<?php
/**
 * Workflow Navigation (bottom of page)
 *
 * Simple prev/next buttons with step counter. Used at the bottom of
 * each workflow page. The stepper at the top is a separate partial.
 *
 * Required: $project and $active_page set in rendering scope.
 */

$steps = [
    'upload'         => ['label' => 'Document Upload',  'url' => '/app/upload'],
    'diagram'        => ['label' => 'Strategy Roadmap', 'url' => '/app/diagram'],
    'work-items'     => ['label' => 'Work Items',       'url' => '/app/work-items'],
    'prioritisation' => ['label' => 'Prioritisation',   'url' => '/app/prioritisation'],
    'risks'          => ['label' => 'Risk Modelling',   'url' => '/app/risks'],
    'user-stories'   => ['label' => 'User Stories',     'url' => '/app/user-stories'],
    'sprints'        => ['label' => 'Sprint Allocation','url' => '/app/sprints'],
    'governance'     => ['label' => 'Governance',       'url' => '/app/governance'],
];

$stepKeys     = array_keys($steps);
$currentIndex = array_search($active_page ?? '', $stepKeys);
$prevStep     = ($currentIndex !== false && $currentIndex > 0)
    ? $steps[$stepKeys[$currentIndex - 1]]
    : null;
$nextStep     = ($currentIndex !== false && $currentIndex < count($stepKeys) - 1)
    ? $steps[$stepKeys[$currentIndex + 1]]
    : null;
?>
<?php if ($prevStep || $nextStep): ?>
<div class="workflow-nav">
    <?php if ($prevStep): ?>
        <a href="<?= $prevStep['url'] ?>?project_id=<?= (int) ($project['id'] ?? 0) ?>" class="btn btn-secondary">
            &larr; <?= htmlspecialchars($prevStep['label']) ?>
        </a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>

    <span class="text-muted gen-style-fb2a71">
        Step <?= ($currentIndex !== false ? $currentIndex + 1 : '?') ?> of <?= count($stepKeys) ?>
    </span>

    <?php if ($nextStep): ?>
        <a href="<?= $nextStep['url'] ?>?project_id=<?= (int) ($project['id'] ?? 0) ?>" class="btn btn-primary">
            <?= htmlspecialchars($nextStep['label']) ?> &rarr;
        </a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</div>
<?php endif; ?>
