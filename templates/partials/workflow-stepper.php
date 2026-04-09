<?php
/**
 * Workflow Stepper (top of page)
 *
 * Horizontal stepper showing all 8 workflow steps with completion status.
 * Clickable to jump between steps. Shows at the top of every workflow page.
 *
 * Required: $active_page (current page key), $project (current project)
 * Optional: $step_completion (array keyed by step name, value = bool)
 */

if (!isset($project) || empty($project['id'])) {
    return; // No project context, skip stepper
}

$steps = [
    'upload'         => 'Upload',
    'diagram'        => 'Roadmap',
    'work-items'     => 'Work Items',
    'prioritisation' => 'Prioritise',
    'risks'          => 'Risks',
    'user-stories'   => 'Stories',
    'sprints'        => 'Sprints',
    'governance'     => 'Governance',
];

$stepUrls = [
    'upload'         => '/app/upload',
    'diagram'        => '/app/diagram',
    'work-items'     => '/app/work-items',
    'prioritisation' => '/app/prioritisation',
    'risks'          => '/app/risks',
    'user-stories'   => '/app/user-stories',
    'sprints'        => '/app/sprints',
    'governance'     => '/app/governance',
];

// Compute completion if not provided
if (!isset($step_completion)) {
    try {
        $step_completion = \StratFlow\Controllers\HomeController::computeStepCompletion(
            \StratFlow\Core\Database::getInstance(),
            (int) $project['id']
        );
    } catch (\Throwable $e) {
        $step_completion = [];
    }
}

$projectId  = (int) $project['id'];
$currentKey = $active_page ?? '';
?>

<div class="workflow-stepper">
    <?php $i = 1; foreach ($steps as $key => $label):
        $isCurrent  = ($key === $currentKey);
        $isComplete = !empty($step_completion[$key]);
        $stateClass = $isCurrent ? 'stepper-step--current' : ($isComplete ? 'stepper-step--complete' : 'stepper-step--upcoming');
    ?>
        <a href="<?= $stepUrls[$key] ?>?project_id=<?= $projectId ?>"
           class="stepper-step <?= $stateClass ?>"
           title="<?= htmlspecialchars($label) ?><?= $isComplete ? ' — complete' : ($isCurrent ? ' — current step' : '') ?>">
            <span class="stepper-step-circle">
                <?php if ($isComplete && !$isCurrent): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                <?php else: ?>
                    <?= $i ?>
                <?php endif; ?>
            </span>
            <span class="stepper-step-label"><?= htmlspecialchars($label) ?></span>
        </a>
    <?php $i++; endforeach; ?>
</div>
