<?php
/**
 * Risk Row Partial
 *
 * A single risk row in the risk list. Used inside a loop in risks.php.
 * Expects $risk (array with linked_items), $csrf_token (string),
 * $project (array), $work_items (array) from the parent scope.
 */

$rpn = (int) $risk['likelihood'] * (int) $risk['impact'];
$linkedItemIds = $risk['linked_item_ids'] ?? [];
?>
<div class="risk-row" data-id="<?= (int) $risk['id'] ?>"
     data-title="<?= htmlspecialchars($risk['title']) ?>"
     data-description="<?= htmlspecialchars($risk['description'] ?? '') ?>"
     data-likelihood="<?= (int) $risk['likelihood'] ?>"
     data-impact="<?= (int) $risk['impact'] ?>"
     data-linked-ids="<?= htmlspecialchars(json_encode(array_map('intval', $linkedItemIds))) ?>">
    <div class="risk-info">
        <strong><?= htmlspecialchars($risk['title']) ?></strong>
        <?php if (!empty($risk['description'])): ?>
            <p class="risk-desc-preview"><?= htmlspecialchars(mb_substr($risk['description'], 0, 150)) ?><?= mb_strlen($risk['description']) > 150 ? '...' : '' ?></p>
        <?php endif; ?>
        <div class="risk-linked-items">
            <?php foreach ($risk['linked_items'] as $li): ?>
                <span class="badge badge-secondary"><?= htmlspecialchars($li['title']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="risk-scores">
        <span class="risk-badge likelihood-<?= (int) $risk['likelihood'] ?>">L: <?= (int) $risk['likelihood'] ?></span>
        <span class="risk-badge impact-<?= (int) $risk['impact'] ?>">I: <?= (int) $risk['impact'] ?></span>
        <span class="risk-rpn">RPN: <?= $rpn ?></span>
    </div>
    <div class="risk-mitigation">
        <?php if (!empty($risk['mitigation'])): ?>
            <p class="mitigation-text"><?= htmlspecialchars($risk['mitigation']) ?></p>
        <?php else: ?>
            <button class="btn btn-sm btn-secondary generate-mitigation-btn" data-id="<?= (int) $risk['id'] ?>">Generate Mitigation (AI)</button>
        <?php endif; ?>
    </div>
    <?php
        $row_edit_class     = 'edit-risk-btn';
        $row_id             = (int) $risk['id'];
        $row_delete_action  = '/app/risks/' . (int) $risk['id'] . '/delete';
        $row_delete_confirm = 'Delete this risk?';
        include __DIR__ . '/row-actions-menu.php';
    ?>
</div>
