<?php
/**
 * Governance Dashboard Template
 *
 * Strategic drift detection and change-control dashboard showing active
 * alerts, pending governance queue items, and baseline history.
 *
 * Variables: $project (array), $alerts (array), $governance_items (array),
 *            $baselines (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center mb-6">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Governance</h1>
    <div class="flex items-center gap-2">
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <form method="POST" action="/app/governance/detect" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm">Run Detection</button>
        </form>
        <form method="POST" action="/app/governance/baseline" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Create a new baseline snapshot of the current project state?')">Create Baseline</button>
        </form>
    </div>
</div>

<!-- ===========================
     Active Alerts
     =========================== -->
<div class="governance-section">
    <h2>Active Alerts (<?= count($alerts) ?>)</h2>
    <?php if (empty($alerts)): ?>
        <div class="empty-state">
            <p>No active alerts. Run detection to check for strategic drift.</p>
        </div>
    <?php else: ?>
        <div class="alert-list">
            <?php foreach ($alerts as $alert): ?>
                <?php include __DIR__ . '/partials/alert-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================
     Pending Governance Queue
     =========================== -->
<div class="governance-section">
    <h2>Pending Reviews (<?= count($governance_items) ?>)</h2>
    <?php if (empty($governance_items)): ?>
        <div class="empty-state">
            <p>No changes awaiting review.</p>
        </div>
    <?php else: ?>
        <div class="governance-list">
            <?php foreach ($governance_items as $govItem): ?>
                <?php include __DIR__ . '/partials/governance-item.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================
     Baseline History
     =========================== -->
<div class="governance-section">
    <h2>Baseline History</h2>
    <?php if (empty($baselines)): ?>
        <div class="empty-state">
            <p>No baselines yet. Create one to start tracking strategic drift.</p>
        </div>
    <?php else: ?>
        <table class="baseline-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Work Items</th>
                    <th>Stories</th>
                    <th>Total Size (pts)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($baselines as $baseline): ?>
                    <tr>
                        <td><?= htmlspecialchars($baseline['created_at']) ?></td>
                        <td><?= (int) $baseline['work_item_count'] ?></td>
                        <td><?= (int) $baseline['story_count'] ?></td>
                        <td><?= (int) $baseline['total_story_size'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
