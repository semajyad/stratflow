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
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header flex justify-between items-center" style="flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; OKR Progress</h1>
        <p class="page-subtitle" style="color: #64748b; font-size: 0.875rem;">
            <?= (int) $health_counts['total_okrs'] ?> objective<?= $health_counts['total_okrs'] !== 1 ? 's' : '' ?>
            &middot;
            <?= (int) $health_counts['total_krs'] ?> key result<?= $health_counts['total_krs'] !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div style="display:flex; align-items:center; gap: 0.75rem;">
        <select onchange="window.location='/app/projects/' + this.value + '/executive'"
                style="border:1px solid #d1d5db; border-radius:6px; padding: 6px 10px; font-size: 0.875rem;">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $project['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size: 12px; color: #94a3b8;">
            Updated <?= htmlspecialchars($project['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if (empty($okr_items)): ?>
    <div class="card mt-6" style="text-align:center; padding:2rem; color:#6b7280;">
        <p>No OKRs defined for this project yet.</p>
        <p style="font-size:0.875rem; margin-top:0.5rem;">Add OKR titles to roadmap nodes on the
            <a href="/app/diagram" style="color:#6366f1;">Strategy Roadmap</a> page.
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
    $barColour    = $storyPct >= 80 ? '#10b981' : ($storyPct >= 40 ? '#f59e0b' : '#6366f1');
    $hasProgress  = $storyTotal > 0 || !empty($structuredKrs);
    $borderColour = $storyPct >= 80 ? '#10b981' : ($storyPct > 0 ? '#f59e0b' : '#6366f1');
?>
<div class="card mb-4" style="border-top: 3px solid <?= htmlspecialchars($borderColour, ENT_QUOTES, 'UTF-8') ?>;">
    <div class="card-body" style="padding: 1rem 1.25rem;">

        <!-- OKR header row -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem; flex-wrap:wrap;">
            <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.25rem;">
                    <span style="display:inline-block; background:#6366f1; color:#fff; border-radius:999px; padding:2px 10px; font-size:0.65rem; text-transform:uppercase; font-weight:700; white-space:nowrap;">
                        Objective
                    </span>
                    <strong style="font-size: 0.95rem; color:#1e293b;">
                        <?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </div>
                <?php if (!empty($okr['description_lines'])): ?>
                    <p style="font-size:0.8rem; color:#64748b; margin:0.25rem 0 0; line-height:1.4;">
                        <?= htmlspecialchars(implode(' ', $okr['description_lines']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Overall progress indicator -->
            <?php if ($storyTotal > 0): ?>
            <div style="text-align:right; flex-shrink:0; min-width:90px;">
                <div style="font-size:0.65rem; text-transform:uppercase; font-weight:700; letter-spacing:.05em; color:#94a3b8; margin-bottom:4px;">Progress</div>
                <div style="font-size:1.5rem; font-weight:800; color:<?= $barColour ?>; line-height:1;"><?= $storyPct ?>%</div>
                <div style="font-size:0.7rem; color:#94a3b8;"><?= $storyDone ?>/<?= $storyTotal ?> stories</div>
                <div style="width:90px; background:#e5e7eb; border-radius:999px; height:6px; overflow:hidden; margin-top:4px;">
                    <div style="width:<?= $storyPct ?>%; background:<?= $barColour ?>; height:100%; border-radius:999px; transition:width 0.3s;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($structuredKrs)): ?>
        <!-- Structured key_results with numeric progress bars -->
        <div style="margin-top: 1rem; border-top:1px solid #f1f5f9; padding-top:0.75rem;">
            <div style="font-size:0.68rem; text-transform:uppercase; font-weight:700; letter-spacing:.05em; color:#94a3b8; margin-bottom:0.5rem;">Key Results</div>
            <?php foreach ($structuredKrs as $kr):
                $krStatus  = $kr['kr_status'] ?? 'not_started';
                $krColour  = $statusColours[$krStatus] ?? '#9ca3af';
                $baseline  = (float) ($kr['baseline_value'] ?? 0);
                $target    = (float) ($kr['target_value']   ?? 0);
                $current   = (float) ($kr['current_value']  ?? 0);
                $unit      = htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                $krPct     = 0;
                if ($target !== 0.0 && $target !== $baseline) {
                    $krPct = max(0, min(100, (int) round(($current - $baseline) / ($target - $baseline) * 100)));
                }
            ?>
            <div style="margin-bottom:0.625rem; padding:0.5rem 0.75rem; background:#f8fafc; border-radius:6px; border-left:3px solid <?= $krColour ?>;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.375rem; flex-wrap:wrap; gap:0.3rem;">
                    <span style="font-size:0.82rem; font-weight:500; color:#1e293b;"><?= htmlspecialchars($kr['kr_title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span style="display:inline-block; background:<?= $krColour ?>; color:#fff; border-radius:999px; padding:1px 8px; font-size:0.65rem; text-transform:uppercase; font-weight:700; white-space:nowrap;">
                        <?= htmlspecialchars(str_replace('_', ' ', $krStatus), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <div style="flex:1; background:#e5e7eb; border-radius:999px; height:8px; overflow:hidden;">
                        <div style="width:<?= $krPct ?>%; background:<?= $krColour ?>; height:100%; border-radius:999px; transition:width 0.3s;"></div>
                    </div>
                    <span style="font-size:0.72rem; color:#6b7280; white-space:nowrap;">
                        <?php if ($target > 0): ?>
                            <?= number_format($current, 0, '.', '') . $unit ?> / <?= number_format($target, 0, '.', '') . $unit ?>
                        <?php else: ?>
                            <?= $krPct ?>%
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($kr['ai_momentum'])): ?>
                <p style="margin:0.375rem 0 0; font-size:0.75rem; color:#6b7280; font-style:italic; line-height:1.4;">
                    &ldquo;<?= htmlspecialchars($kr['ai_momentum'], ENT_QUOTES, 'UTF-8') ?>&rdquo;
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif (!empty($krLines)): ?>
        <!-- Free-text KR lines with story-hypothesis progress -->
        <div style="margin-top: 0.875rem; border-top:1px solid #f1f5f9; padding-top:0.75rem;">
            <div style="font-size:0.68rem; text-transform:uppercase; font-weight:700; letter-spacing:.05em; color:#94a3b8; margin-bottom:0.5rem;">Key Results</div>
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
                $krBarColour = $krPct !== null
                    ? ($krPct >= 80 ? '#10b981' : ($krPct >= 40 ? '#f59e0b' : '#6366f1'))
                    : '#a5b4fc';
            ?>
            <div style="display:flex; align-items:flex-start; gap:0.5rem; padding:0.45rem 0.65rem; margin-bottom:0.35rem; background:#f8fafc; border-radius:5px; border-left:3px solid <?= $krBarColour ?>;">
                <span style="font-size:0.75rem; font-weight:700; color:#6366f1; white-space:nowrap; min-width:1.6rem; padding-top:1px;"><?= (int) ($j + 1) ?>.</span>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:0.8rem; color:#374151; line-height:1.4; margin-bottom:<?= $matchedHyp ? '0.3rem' : '0' ?>;">
                        <?= htmlspecialchars($displayLine, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if ($matchedHyp): ?>
                    <div style="display:flex; align-items:center; gap:0.4rem;">
                        <div style="flex:1; background:#e5e7eb; border-radius:999px; height:5px; overflow:hidden; max-width:120px;">
                            <div style="width:<?= $krPct ?>%; background:<?= $krBarColour ?>; height:100%; border-radius:999px;"></div>
                        </div>
                        <span style="font-size:0.7rem; color:<?= $krBarColour ?>; font-weight:700;"><?= $krPct ?>%</span>
                        <span style="font-size:0.7rem; color:#9ca3af;"><?= $matchedHyp['done'] ?>/<?= $matchedHyp['total'] ?> stories</span>
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

<style>
.mt-6 { margin-top: 1.5rem; }
.mb-4 { margin-bottom: 1rem; }
</style>
