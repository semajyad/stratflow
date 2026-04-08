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
<div class="page-header flex justify-between items-center mb-6">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Risk Modelling</h1>
    <div class="flex items-center gap-2">
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <form method="POST" action="/app/risks/generate" style="display: inline;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('This will use AI to analyse your work items and generate 3-5 project risks. Continue?')">
                Auto-Generate Risks (AI)
            </button>
        </form>
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRiskModal()">
            Add Risk Manually
        </button>
    </div>
</div>

<!-- ===========================
     Page Description
     =========================== -->
<div class="page-description">
    Identify and manage project risks. Auto-generate risks from your work items using AI, set likelihood and impact scores, and generate mitigation strategies.
</div>

<!-- ===========================
     Add/Edit Risk Modal
     =========================== -->
<div id="risk-modal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h3 id="risk-modal-title">Add Risk</h3>
            <button class="modal-close" onclick="toggleRiskModal()">&times;</button>
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
                    <div class="form-group" style="flex: 1;">
                        <label for="risk-likelihood">Likelihood</label>
                        <select id="risk-likelihood" name="likelihood" class="form-control">
                            <?php foreach ($likelihoodLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $val === 3 ? 'selected' : '' ?>><?= $val ?> &mdash; <?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="risk-impact">Impact</label>
                        <select id="risk-impact" name="impact" class="form-control">
                            <?php foreach ($impactLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $val === 3 ? 'selected' : '' ?>><?= $val ?> &mdash; <?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 0.5rem;">
                    <label>RPN Preview: <span id="rpn-preview" class="risk-rpn">9</span></label>
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
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRiskModal()">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save Risk</button>
            </div>
        </form>
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
    <div class="card-body" style="padding: 0;">
        <div class="risk-list">
            <?php foreach ($risks as $risk): ?>
                <?php require __DIR__ . '/partials/risk-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-6">
    <div class="card-body text-center" style="padding: 3rem;">
        <p class="text-muted" style="font-size: 1.125rem;">
            No risks identified yet. Use <strong>Auto-Generate Risks</strong> to analyse your work items,
            or <strong>Add Risk Manually</strong>.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     5x5 Heatmap
     =========================== -->
<div class="card mb-6">
    <div class="card-header">
        <h3>Risk Heatmap</h3>
        <span class="text-muted" style="font-size: 0.8125rem;">Likelihood vs Impact (count of risks per cell)</span>
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
                            <div class="heatmap-cell <?= $level ?> <?= $count > 0 ? 'has-risks' : '' ?>"
                                 data-likelihood="<?= $l ?>" data-impact="<?= $i ?>"
                                 title="L<?= $l ?> x I<?= $i ?> = <?= $score ?>">
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

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
