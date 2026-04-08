<?php
/**
 * Alert Card Partial
 *
 * A single drift alert card, colour-coded by severity.
 * Used inside a loop in governance.php.
 * Expects $alert (array), $csrf_token (string), $project (array).
 */
?>
<div class="alert-card severity-<?= htmlspecialchars($alert['severity']) ?>">
    <div class="alert-header">
        <span class="alert-type badge"><?= htmlspecialchars(str_replace('_', ' ', $alert['alert_type'])) ?></span>
        <span class="alert-severity badge badge-<?= htmlspecialchars($alert['severity']) ?>"><?= ucfirst(htmlspecialchars($alert['severity'])) ?></span>
        <span class="alert-date"><?= htmlspecialchars($alert['created_at']) ?></span>
    </div>
    <div class="alert-details">
        <?php $details = json_decode($alert['details_json'], true); ?>
        <?php if ($alert['alert_type'] === 'capacity_tripwire'): ?>
            <p>
                <strong><?= htmlspecialchars($details['parent_title'] ?? 'Unknown') ?></strong> has grown
                <strong><?= htmlspecialchars((string) ($details['growth_percent'] ?? 0)) ?>%</strong> beyond baseline.
                (Baseline: <?= (int) ($details['baseline_size'] ?? 0) ?> pts &rarr; Current: <?= (int) ($details['current_size'] ?? 0) ?> pts)
            </p>
        <?php elseif ($alert['alert_type'] === 'dependency_tripwire'): ?>
            <p>
                <strong><?= htmlspecialchars($details['blocked_story_title'] ?? '') ?></strong>
                (<?= htmlspecialchars($details['blocked_team'] ?? '') ?>) is blocked by
                <strong><?= htmlspecialchars($details['blocker_story_title'] ?? '') ?></strong>
                (<?= htmlspecialchars($details['blocker_team'] ?? '') ?>) &mdash; cross-team dependency.
            </p>
        <?php elseif ($alert['alert_type'] === 'alignment_drift'): ?>
            <p>
                <?= htmlspecialchars($details['explanation'] ?? 'Alignment issue detected.') ?>
                Confidence: <?= (int) ($details['confidence'] ?? 0) ?>%
            </p>
        <?php else: ?>
            <p><?= htmlspecialchars(json_encode($details)) ?></p>
        <?php endif; ?>
    </div>
    <div class="alert-actions">
        <form method="POST" action="/app/governance/alerts/<?= (int) $alert['id'] ?>" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="action" value="acknowledge">
            <button class="btn btn-sm btn-secondary">Acknowledge</button>
        </form>
        <form method="POST" action="/app/governance/alerts/<?= (int) $alert['id'] ?>" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="action" value="resolve">
            <button class="btn btn-sm btn-primary">Resolve</button>
        </form>
    </div>
</div>
