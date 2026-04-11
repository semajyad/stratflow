<?php
/**
 * Prioritisation Template
 *
 * Score work items using RICE or WSJF frameworks with real-time
 * calculation, AJAX saving, and AI-assisted baseline estimation.
 *
 * Variables: $project (array), $work_items (array), $framework (string),
 *            $csrf_token (string)
 */

$isRice = ($framework === 'rice');
$labels = $isRice
    ? ['Reach', 'Impact', 'Confidence', 'Effort']
    : ['Business Value', 'Time Criticality', 'Risk Reduction', 'Job Size'];
$fields = $isRice
    ? ['rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort']
    : ['wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size'];
$formula = $isRice
    ? '(Reach x Impact x Confidence) / Effort'
    : '(Business Value + Time Criticality + Risk Reduction) / Job Size';
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Prioritisation
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">Score your work items using industry-standard frameworks. RICE evaluates Reach, Impact, Confidence, and Effort. WSJF evaluates Business Value, Time Criticality, Risk Reduction, and Job Size.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'work_items'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
    </div>
</div>

<!-- ===========================
     Framework Selector
     =========================== -->
<div class="card mb-6">
    <div class="card-body flex items-center justify-between" style="flex-wrap: wrap; gap: 1rem;">
        <form method="POST" action="/app/prioritisation/framework" class="flex items-center gap-2 js-framework-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <label for="framework-select" style="margin-bottom: 0; white-space: nowrap;">Framework:</label>
            <select name="framework" id="framework-select" class="score-select js-framework-select" style="width: auto; min-width: 120px;">
                <option value="rice" <?= $isRice ? 'selected' : '' ?>>RICE</option>
                <option value="wsjf" <?= !$isRice ? 'selected' : '' ?>>WSJF</option>
            </select>
        </form>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm js-toggle-framework-info">
                Formula Info
            </button>
            <button type="button" id="ai-suggest-btn" class="btn btn-ai btn-sm ai-suggest-btn js-request-ai-baseline">
                AI Suggest Scores
            </button>
        </div>
    </div>
</div>

<!-- ===========================
     Framework Info Modal
     =========================== -->
<div id="framework-info-modal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h3>Prioritisation Frameworks</h3>
            <button class="modal-close js-toggle-framework-info" type="button">&times;</button>
        </div>
        <div class="modal-body">
            <h4>RICE</h4>
            <p><strong>Score = (Reach x Impact x Confidence) / Effort</strong></p>
            <ul style="list-style: disc; padding-left: 1.25rem; margin-bottom: 1.25rem;">
                <li><strong>Reach</strong> &mdash; How many users/stakeholders will this impact? (1=few, 10=everyone)</li>
                <li><strong>Impact</strong> &mdash; How significant is the impact per user? (1=minimal, 10=transformative)</li>
                <li><strong>Confidence</strong> &mdash; How confident are you in the estimates? (1=guess, 10=certain)</li>
                <li><strong>Effort</strong> &mdash; How much effort is required? (1=trivial, 10=enormous)</li>
            </ul>
            <h4>WSJF</h4>
            <p><strong>Score = (Business Value + Time Criticality + Risk Reduction) / Job Size</strong></p>
            <ul style="list-style: disc; padding-left: 1.25rem;">
                <li><strong>Business Value</strong> &mdash; How much value does this deliver? (1=minimal, 10=critical)</li>
                <li><strong>Time Criticality</strong> &mdash; How urgent is this? (1=can wait, 10=immediate)</li>
                <li><strong>Risk Reduction</strong> &mdash; How much risk/opportunity does this address? (1=none, 10=major)</li>
                <li><strong>Job Size</strong> &mdash; How large is the work? (1=tiny, 10=massive)</li>
            </ul>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm js-toggle-framework-info" type="button">Close</button>
        </div>
    </div>
</div>

<!-- ===========================
     Scoring Table
     =========================== -->
<?php if (!empty($work_items)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Score Work Items (<?= count($work_items) ?>)</h3>
        <span class="text-muted" style="font-size: 0.8125rem;"><?= $formula ?></span>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: hidden; max-height: calc(100vh - 260px);">
        <table id="prioritisation-table" data-framework="<?= htmlspecialchars($framework) ?>" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 32px;">#</th>
                    <th>Title</th>
                    <?php foreach ($labels as $label): ?>
                        <th style="width: 68px; text-align: center;"><?= $label ?></th>
                    <?php endforeach; ?>
                    <th style="width: 58px; text-align: center; position: sticky; right: 0; background: var(--bg, #fff); z-index: 1;">Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($work_items as $item): ?>
                    <?php
                    $vals = [];
                    foreach ($fields as $f) {
                        $vals[] = (int) ($item[$f] ?? 0);
                    }
                    // Calculate displayed score
                    if ($isRice) {
                        $displayScore = $vals[3] > 0
                            ? ($vals[0] * $vals[1] * $vals[2]) / $vals[3]
                            : 0;
                    } else {
                        $displayScore = $vals[3] > 0
                            ? ($vals[0] + $vals[1] + $vals[2]) / $vals[3]
                            : 0;
                    }
                    ?>
                    <tr class="prio-row" data-id="<?= (int) $item['id'] ?>">
                        <td class="text-center">
                            <span class="priority-number"><?= (int) $item['priority_number'] ?></span>
                        </td>
                        <td class="prio-title">
                            <strong><?= htmlspecialchars($item['title']) ?></strong>
                            <?php if (!empty($item['description'])): ?>
                                <p class="work-item-desc-preview"><?= htmlspecialchars(mb_substr($item['description'], 0, 60)) ?></p>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($fields as $i => $field): ?>
                            <td class="text-center">
                                <select class="score-dropdown" data-field="<?= $field ?>">
                                    <option value="0" <?= $vals[$i] === 0 ? 'selected' : '' ?>>-</option>
                                    <?php if ($isRice): ?>
                                        <?php for ($n = 1; $n <= 10; $n++): ?>
                                            <option value="<?= $n ?>" <?= $vals[$i] === $n ? 'selected' : '' ?>><?= $n ?></option>
                                        <?php endfor; ?>
                                    <?php else: ?>
                                        <?php foreach ([1, 2, 3, 5, 8, 13, 20] as $n): ?>
                                            <option value="<?= $n ?>" <?= $vals[$i] === $n ? 'selected' : '' ?>><?= $n ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-center">
                            <span class="final-score"><?= number_format($displayScore, 1) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===========================
     Actions
     =========================== -->
<div class="flex items-center justify-between mb-6" style="flex-wrap: wrap; gap: 1rem;">
    <form method="POST" action="/app/prioritisation/rerank"
          data-loading="Re-ranking by score...">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button type="submit" class="btn btn-primary" data-confirm="Re-rank all items by their calculated score?">
            Re-rank by Score
        </button>
    </form>
</div>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<?php else: ?>
<div class="card">
    <div class="card-body text-center" style="padding: 3rem;">
        <p class="text-muted" style="font-size: 1.125rem;">
            No work items to prioritise. <a href="/app/work-items?project_id=<?= (int) $project['id'] ?>">Generate work items</a> first.
        </p>
    </div>
</div>
<?php endif; ?>
