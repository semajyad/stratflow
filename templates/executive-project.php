<?php
// stratflow/templates/executive-project.php
// Variables: $user, $project, $projects, $okr_items, $health_counts,
//            $flash_message, $flash_error
// $okr_items[]: id, okr_title, okr_description, kr_lines[],
//               story_total, story_done, story_pct,
//               kr_hypothesis{krText=>[done,total]},
//               structured_krs[]{kr_title,baseline_value,target_value,current_value,unit,kr_status,ai_momentum}
// $health_counts: total_okrs, total_krs

$statusColours = [
    'on_track'    => '#10b981',
    'at_risk'     => '#f59e0b',
    'off_track'   => '#ef4444',
    'not_started' => '#9ca3af',
    'achieved'    => '#6366f1',
];

$progressTone = static function (int $pct): string {
    if ($pct >= 80) {
        return 'success';
    }
    if ($pct >= 40) {
        return 'warning';
    }
    return 'primary';
};

$statusTone = static function (string $status): string {
    return match ($status) {
        'on_track' => 'success',
        'at_risk' => 'warning',
        'off_track' => 'danger',
        'not_started' => 'muted',
        'achieved' => 'primary',
        default => 'muted',
    };
};
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header executive-project-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; OKR Progress</h1>
        <p class="page-subtitle executive-project-subtitle">
            <?= (int) $health_counts['total_okrs'] ?> objective<?= $health_counts['total_okrs'] !== 1 ? 's' : '' ?>
            &middot;
            <?= (int) $health_counts['total_krs'] ?> key result<?= $health_counts['total_krs'] !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div class="executive-project-toolbar">
        <select class="js-executive-project-select executive-project-select" data-base-url="/app/projects/">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $project['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="executive-project-updated">
            Updated <?= htmlspecialchars($project['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if (empty($okr_items)): ?>
    <div class="card mt-6 executive-project-empty">
        <p>No OKRs defined for this project yet.</p>
        <p class="executive-project-empty-copy">Add OKR titles to roadmap nodes on the
            <a href="/app/diagram" class="executive-project-link">Strategy Roadmap</a> page.
        </p>
    </div>
<?php endif; ?>

<!-- OKR Cards -->
<?php foreach ($okr_items as $okr):
    $krLines      = $okr['kr_lines'] ?? [];
    $storyPct     = (int) ($okr['story_pct'] ?? 0);
    $storyDone    = (int) ($okr['story_done'] ?? 0);
    $storyTotal   = (int) ($okr['story_total'] ?? 0);
    $structuredKrs = $okr['structured_krs'] ?? [];
    $krHypData    = $okr['kr_hypothesis'] ?? [];
    $storyTone    = $progressTone($storyPct);
    $hasProgress  = $storyTotal > 0 || !empty($structuredKrs);
    $borderTone   = $storyPct >= 80 ? 'success' : ($storyPct > 0 ? 'warning' : 'primary');
?>
<div class="card mb-4 executive-project-card executive-project-card--<?= $borderTone ?>">
    <div class="card-body executive-project-card-body">

        <!-- OKR header row -->
        <div class="executive-project-card-head">
            <div class="executive-project-card-main">
                <div class="executive-project-title-row">
                    <span class="executive-project-objective-pill">
                        Objective
                    </span>
                    <strong class="executive-project-title">
                        <?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </div>
                <?php if (!empty($okr['description_lines'])): ?>
                    <p class="executive-project-description">
                        <?= htmlspecialchars(implode(' ', $okr['description_lines']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Overall progress indicator -->
            <?php if ($storyTotal > 0): ?>
            <div class="executive-project-progress">
                <div class="executive-project-progress-label">Progress</div>
                <div class="executive-project-progress-value executive-project-progress-value--<?= $storyTone ?>"><?= $storyPct ?>%</div>
                <div class="executive-project-progress-meta"><?= $storyDone ?>/<?= $storyTotal ?> stories</div>
                <progress class="okr-progress__meter okr-progress__meter--<?= $storyTone ?> executive-project-progress-meter" max="100" value="<?= $storyPct ?>"></progress>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($structuredKrs)): ?>
        <!-- Structured key_results with numeric progress bars -->
        <div class="executive-project-section">
            <div class="executive-project-section-label">Key Results</div>
            <?php foreach ($structuredKrs as $kr):
                $krStatus  = $kr['kr_status'] ?? 'not_started';
                $krTone    = $statusTone($krStatus);
                $baseline  = (float) ($kr['baseline_value'] ?? 0);
                $target    = (float) ($kr['target_value']   ?? 0);
                $current   = (float) ($kr['current_value']  ?? 0);
                $unit      = htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                $krPct     = 0;
                if ($target !== 0.0 && $target !== $baseline) {
                    $krPct = max(0, min(100, (int) round(($current - $baseline) / ($target - $baseline) * 100)));
                }
            ?>
            <div class="executive-project-kr-card executive-project-kr-card--<?= $krTone ?>">
                <div class="executive-project-kr-head">
                    <span class="executive-project-kr-title"><?= htmlspecialchars($kr['kr_title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="okr-status-pill executive-project-status-pill executive-project-status-pill--<?= $krTone ?>">
                        <?= htmlspecialchars(str_replace('_', ' ', $krStatus), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="okr-kr-progress-row">
                    <progress class="okr-progress__meter okr-progress__meter--<?= $krTone ?> executive-project-kr-meter" max="100" value="<?= $krPct ?>"></progress>
                    <span class="executive-project-kr-meta">
                        <?php if ($target > 0): ?>
                            <?= number_format($current, 0, '.', '') . $unit ?> / <?= number_format($target, 0, '.', '') . $unit ?>
                        <?php else: ?>
                            <?= $krPct ?>%
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($kr['ai_momentum'])): ?>
                <p class="executive-project-momentum">
                    &ldquo;<?= htmlspecialchars($kr['ai_momentum'], ENT_QUOTES, 'UTF-8') ?>&rdquo;
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif (!empty($krLines)): ?>
        <!-- Free-text KR lines with story-hypothesis progress -->
        <div class="executive-project-section executive-project-section--compact">
            <div class="executive-project-section-label">Key Results</div>
            <?php foreach ($krLines as $j => $krLine):
                $displayLine = preg_replace('/^\s*KR\d*\s*[:.\-]\s*/i', '', $krLine);
                // Try to find matching kr_hypothesis stories
                // Match if hypothesis contains any significant words from this KR line
                $matchedHyp = null;
                foreach ($krHypData as $hyp => $hd) {
                    // Simple substring match — hypothesis often contains the KR number or a key phrase
                    if (stripos($hyp, 'KR' . ($j + 1)) !== false
                        || stripos($krLine, $hyp) !== false
                        || stripos($hyp, $displayLine) !== false) {
                        $matchedHyp = $hd;
                        break;
                    }
                }
                $krPct = $matchedHyp && $matchedHyp['total'] > 0
                    ? (int) round($matchedHyp['done'] / $matchedHyp['total'] * 100)
                    : null;
                $krTone = $krPct !== null ? $progressTone($krPct) : 'primary';
            ?>
            <div class="executive-project-line-kr executive-project-line-kr--<?= $krTone ?>">
                <span class="executive-project-line-index"><?= (int) ($j + 1) ?>.</span>
                <div class="executive-project-line-copy">
                    <div class="executive-project-line-text<?= $matchedHyp ? ' executive-project-line-text--with-progress' : '' ?>">
                        <?= htmlspecialchars($displayLine, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if ($matchedHyp): ?>
                    <div class="executive-project-line-progress">
                        <progress class="okr-progress__meter okr-progress__meter--<?= $krTone ?> executive-project-line-meter" max="100" value="<?= $krPct ?>"></progress>
                        <span class="okr-progress__value okr-progress__value--<?= $krTone ?>"><?= $krPct ?>%</span>
                        <span class="executive-project-line-meta"><?= $matchedHyp['done'] ?>/<?= $matchedHyp['total'] ?> stories</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div><!-- /.card -->
<?php endforeach; ?>
