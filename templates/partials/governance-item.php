<?php
/**
 * Governance Item Partial
 *
 * A single governance queue item card with approve/reject actions.
 * Used inside a loop in governance.php.
 * Expects $govItem (array), $csrf_token (string), $project (array).
 */
$changeDetails = json_decode($govItem['proposed_change_json'], true);
?>
<div class="governance-item">
    <div class="alert-header">
        <span class="badge badge-info"><?= htmlspecialchars(str_replace('_', ' ', $govItem['change_type'])) ?></span>
        <span class="badge badge-secondary"><?= ucfirst(htmlspecialchars($govItem['status'])) ?></span>
        <span class="alert-date"><?= htmlspecialchars($govItem['created_at']) ?></span>
    </div>
    <div class="alert-details">
        <?php if ($govItem['change_type'] === 'new_story'): ?>
            <p>
                <strong><?= (int) ($changeDetails['stories_created'] ?? 0) ?></strong> new stories generated
                <?php if (!empty($changeDetails['parent_items'])): ?>
                    from work items: <?= htmlspecialchars(implode(', ', $changeDetails['parent_items'])) ?>
                <?php endif; ?>
            </p>
        <?php elseif ($govItem['change_type'] === 'scope_change'): ?>
            <p>
                Size changed on <strong><?= htmlspecialchars($changeDetails['story_title'] ?? 'Unknown') ?></strong>:
                <?= (int) ($changeDetails['old_size'] ?? 0) ?> &rarr; <?= (int) ($changeDetails['new_size'] ?? 0) ?> pts
            </p>
        <?php elseif ($govItem['change_type'] === 'description_change'): ?>
            <p>
                Description updated on <strong><?= htmlspecialchars($changeDetails['item_title'] ?? 'Unknown') ?></strong>
            </p>
        <?php elseif ($govItem['change_type'] === 'estimate_change'): ?>
            <p>
                Sprint estimate changed on <strong><?= htmlspecialchars($changeDetails['item_title'] ?? 'Unknown') ?></strong>:
                <?= (int) ($changeDetails['old_estimate'] ?? 0) ?> &rarr; <?= (int) ($changeDetails['new_estimate'] ?? 0) ?> sprints
            </p>
        <?php else: ?>
            <p><?= htmlspecialchars(json_encode($changeDetails)) ?></p>
        <?php endif; ?>
    </div>
    <div class="alert-actions">
        <form method="POST" action="/app/governance/queue/<?= (int) $govItem['id'] ?>" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-sm btn-success">Approve</button>
        </form>
        <form method="POST" action="/app/governance/queue/<?= (int) $govItem['id'] ?>" class="inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <button class="btn btn-sm btn-danger">Reject</button>
        </form>
    </div>
</div>
