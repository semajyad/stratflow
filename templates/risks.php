<?php
/**
 * Risk Modelling Template
 *
 * Enterprise risk management screen with AI-generated risks, manual CRUD,
 * linked work items, AI mitigation strategies, and a 5x5 heatmap.
 *
 * Variables: $project (array), $risks (array), $work_items (array),
 *            $heatmap (array 5x5), $csrf_token (string)
 */

$likelihoodLabels = [1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost Certain'];
$impactLabels     = [1 => 'Negligible', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'];
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Risk Modelling
        <span class="page-title-count"><?= count($risks) ?></span>
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">Identify and manage project risks. Auto-generate risks from your work items using AI, set likelihood and impact scores, and generate mitigation strategies.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'risks'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <form method="POST" action="/app/risks/generate" class="inline-form"
              data-loading="Generating risks..."
              data-overlay="AI is identifying project risks from your work items. This may take 15-30 seconds.">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-ai btn-sm"
                    data-confirm="This will use AI to analyse your work items and generate 3-5 project risks. Continue?">
                Generate Risks (AI)
            </button>
        </form>
        <button type="button" class="btn btn-primary btn-sm js-toggle-risk-modal">
            Add Risk
        </button>
    </div>
</div>


<!-- ===========================
     Add/Edit Risk Modal
     =========================== -->
<div id="risk-modal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h3 id="risk-modal-title">Add Risk</h3>
            <button type="button" class="modal-close js-toggle-risk-modal">&times;</button>
        </div>
        <form id="risk-form" method="POST" action="/app/risks">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label for="risk-title">Title</label>
                    <input type="text" id="risk-title" name="title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="risk-description">Description</label>
                    <textarea id="risk-description" name="description" class="form-control" rows="3"></textarea>
                </div>
                <div class="flex gap-4">
                    <div class="form-group gen-style-49cdf8">
                        <label for="risk-likelihood">Likelihood</label>
                        <select id="risk-likelihood" name="likelihood" class="form-control">
                            <?php foreach ($likelihoodLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $val === 3 ? 'selected' : '' ?>><?= $val ?> &mdash; <?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group gen-style-49cdf8">
                        <label for="risk-impact">Impact</label>
                        <select id="risk-impact" name="impact" class="form-control">
                            <?php foreach ($impactLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $val === 3 ? 'selected' : '' ?>><?= $val ?> &mdash; <?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group gen-style-f8a732">
                    <label>RPN Preview: <span id="rpn-preview" class="risk-rpn">9</span></label>
                </div>
                <div class="form-group">
                    <label for="risk-roam">ROAM Status</label>
                    <select id="risk-roam" name="roam_status" class="form-control">
                        <option value="">— None —</option>
                        <option value="resolved">Resolved</option>
                        <option value="owned">Owned</option>
                        <option value="accepted">Accepted</option>
                        <option value="mitigated">Mitigated</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="risk-owner">Owner</label>
                    <select id="risk-owner" name="owner_user_id" class="form-control">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($org_users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($work_items)): ?>
                <div class="form-group">
                    <label>Linked Work Items</label>
                    <div class="checkbox-group">
                        <?php foreach ($work_items as $wi): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="work_item_ids[]" value="<?= (int) $wi['id'] ?>" class="work-item-checkbox">
                                <?= htmlspecialchars($wi['title']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm js-toggle-risk-modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save Risk</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================
     5x5 Heatmap
     =========================== -->
<div class="card mb-6">
    <div class="card-header">
        <h3>Risk Heatmap</h3>
        <span class="text-muted gen-style-0a07c7">Likelihood vs Impact (count of risks per cell)</span>
    </div>
    <div class="card-body">
        <div class="heatmap-container">
            <div class="heatmap-y-label">Likelihood</div>
            <div class="heatmap-wrapper">
                <div class="heatmap-grid">
                    <?php for ($l = 5; $l >= 1; $l--): ?>
                        <div class="heatmap-row-label"><?= $l ?></div>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php
                            $count = $heatmap[$l][$i] ?? 0;
                            $score = $l * $i;
                            if ($score <= 5)       { $level = 'low'; }
                            elseif ($score <= 10)   { $level = 'medium'; }
                            elseif ($score <= 15)   { $level = 'high'; }
                            else                    { $level = 'critical'; }
                            ?>
                            <div class="heatmap-cell <?= $level ?> <?= $count > 0 ? 'has-risks heatmap-cell--interactive js-heatmap-filter' : '' ?>"
                                 data-likelihood="<?= $l ?>" data-impact="<?= $i ?>"
                                 title="Likelihood <?= $l ?> &times; Impact <?= $i ?> = <?= $score ?> &middot; <?= $count ?> risk<?= $count !== 1 ? 's' : '' ?><?= $count > 0 ? ' (click to filter)' : '' ?>">
                                <?= $count > 0 ? $count : '' ?>
                            </div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                    <!-- Bottom axis labels -->
                    <div class="heatmap-row-label"></div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="heatmap-col-label"><?= $i ?></div>
                    <?php endfor; ?>
                </div>
                <div class="heatmap-x-label">Impact</div>
            </div>
        </div>
        <div class="heatmap-legend">
            <span class="legend-item"><span class="legend-swatch low"></span> Low (1-5)</span>
            <span class="legend-item"><span class="legend-swatch medium"></span> Medium (6-10)</span>
            <span class="legend-item"><span class="legend-swatch high"></span> High (11-15)</span>
            <span class="legend-item"><span class="legend-swatch critical"></span> Critical (16-25)</span>
        </div>
    </div>
</div>

<!-- ===========================
     Risk List
     =========================== -->
<?php if (!empty($risks)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Project Risks (<?= count($risks) ?>)</h3>
    </div>
    <div class="card-body gen-style-b662f2">
        <div class="risk-list">
            <?php foreach ($risks as $risk): ?>
                <?php require __DIR__ . '/partials/risk-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-6">
    <div class="card-body text-center gen-style-cfdf60">
        <p class="text-muted gen-style-2917c0">
            No risks identified yet. Use <strong>Auto-Generate Risks</strong> to analyse your work items,
            or <strong>Add Risk Manually</strong>.
        </p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
