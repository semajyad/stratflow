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
            <button type="submit" class="btn btn-ai btn-sm" onclick="return confirm('This will use AI to analyse your work items and generate 3-5 project risks. Continue?')">
                Generate Risks (AI)
            </button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" onclick="toggleRiskModal()">
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
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRiskModal()">Cancel</button>
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
                                 title="Likelihood <?= $l ?> &times; Impact <?= $i ?> = <?= $score ?> &middot; <?= $count ?> risk<?= $count !== 1 ? 's' : '' ?> (click to filter)"
                                 <?php if ($count > 0): ?>onclick="filterHeatmapRisks(<?= $l ?>, <?= $i ?>)" style="cursor:pointer;"<?php endif; ?>>
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

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<script>
var heatmapFilter = { likelihood: null, impact: null };

function filterHeatmapRisks(likelihood, impact) {
    // Toggle off if clicking the same cell
    if (heatmapFilter.likelihood === likelihood && heatmapFilter.impact === impact) {
        clearHeatmapFilter();
        return;
    }
    heatmapFilter.likelihood = likelihood;
    heatmapFilter.impact = impact;

    // Highlight the selected cell
    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.classList.remove('heatmap-cell--selected');
    });
    var selected = document.querySelector('.heatmap-cell[data-likelihood="' + likelihood + '"][data-impact="' + impact + '"]');
    if (selected) selected.classList.add('heatmap-cell--selected');

    // Filter and highlight matching risk rows
    document.querySelectorAll('.risk-row').forEach(function(row) {
        var l = parseInt(row.dataset.likelihood || 0, 10);
        var i = parseInt(row.dataset.impact || 0, 10);
        var match = (l === likelihood && i === impact);
        row.style.display = match ? '' : 'none';
        if (match) {
            row.classList.add('risk-highlighted');
        } else {
            row.classList.remove('risk-highlighted');
        }
    });

    // Show filter banner
    var banner = document.getElementById('heatmap-filter-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'heatmap-filter-banner';
        banner.style.cssText = 'padding:0.75rem 1rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; font-size:0.875rem;';
        var riskList = document.querySelector('.risk-list') || document.querySelector('.risks-list');
        if (riskList) riskList.parentNode.insertBefore(banner, riskList);
    }
    var count = document.querySelectorAll('.risk-row').length;
    var visible = 0;
    document.querySelectorAll('.risk-row').forEach(function(r) { if (r.style.display !== 'none') visible++; });
    banner.innerHTML = '<span>Showing <strong>' + visible + '</strong> risk' + (visible !== 1 ? 's' : '') + ' at Likelihood ' + likelihood + ' &times; Impact ' + impact + '</span>' +
        '<button type="button" class="btn btn-sm btn-secondary" onclick="clearHeatmapFilter()">Clear filter</button>';

    // Scroll to risk list
    var firstVisible = document.querySelector('.risk-row[style=""], .risk-row:not([style*="display: none"])');
    if (firstVisible) firstVisible.scrollIntoView({behavior:'smooth', block:'start'});
}

function clearHeatmapFilter() {
    heatmapFilter = { likelihood: null, impact: null };
    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.classList.remove('heatmap-cell--selected');
    });
    document.querySelectorAll('.risk-row').forEach(function(row) {
        row.style.display = '';
        row.classList.remove('risk-highlighted');
    });
    var banner = document.getElementById('heatmap-filter-banner');
    if (banner) banner.remove();
    var riskList = document.querySelector('.risk-list');
    if (riskList) riskList.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<style>
.heatmap-cell--selected {
    outline: 3px solid var(--primary);
    outline-offset: -3px;
    z-index: 2;
    position: relative;
}
</style>
