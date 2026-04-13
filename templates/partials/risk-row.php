<?php
/**
 * Risk Row Partial
 *
 * A single risk row in the risk list. Used inside a loop in risks.php.
 * Expects $risk (array with linked_items), $csrf_token (string),
 * $project (array), $work_items (array), $org_users (array) from the parent scope.
 */

$rpn           = (int) $risk['likelihood'] * (int) $risk['impact'];
$linkedItemIds = $risk['linked_item_ids'] ?? [];
$riskId        = (int) $risk['id'];

// Build owner display name
$ownerName = null;
if (!empty($risk['owner_user_id'])) {
    foreach ($org_users as $u) {
        if ((int) $u['id'] === (int) $risk['owner_user_id']) {
            $ownerName = $u['full_name'];
            break;
        }
    }
}

// ROAM badge
$roamLabels = [
    'resolved'  => ['label' => 'Resolved',  'class' => 'badge-success'],
    'owned'     => ['label' => 'Owned',     'class' => 'badge-info'],
    'accepted'  => ['label' => 'Accepted',  'class' => 'badge-warning'],
    'mitigated' => ['label' => 'Mitigated', 'class' => 'badge-primary'],
];
$roamStatus = $risk['roam_status'] ?? null;

?>
<div class="risk-row" data-id="<?= $riskId ?>"
     data-title="<?= htmlspecialchars($risk['title']) ?>"
     data-description="<?= htmlspecialchars($risk['description'] ?? '') ?>"
     data-likelihood="<?= (int) $risk['likelihood'] ?>"
     data-impact="<?= (int) $risk['impact'] ?>"
     data-owner-user-id="<?= (int) ($risk['owner_user_id'] ?? 0) ?>"
     data-roam-status="<?= htmlspecialchars($roamStatus ?? '') ?>"
     data-closed="<?= ($risk['status'] ?? 'open') === 'closed' ? '1' : '0' ?>"
     data-linked-ids="<?= htmlspecialchars(json_encode(array_map('intval', $linkedItemIds))) ?>">
    <div class="risk-info">
        <div class="gen-style-a530f6">
            <span class="risk-rpn">RPN: <?= $rpn ?></span>
            <strong><?= htmlspecialchars($risk['title']) ?></strong>
            <?php if ($roamStatus && isset($roamLabels[$roamStatus])): ?>
                <span class="badge <?= $roamLabels[$roamStatus]['class'] ?>"><?= $roamLabels[$roamStatus]['label'] ?></span>
            <?php endif; ?>
            <?php if (($risk['status'] ?? 'open') === 'closed'): ?>
                <span class="badge badge-secondary">Closed</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($risk['description'])): ?>
            <p class="risk-desc-preview"><?= htmlspecialchars(mb_substr($risk['description'], 0, 150)) ?><?= mb_strlen($risk['description']) > 150 ? '...' : '' ?></p>
        <?php endif; ?>
        <div class="risk-linked-items">
            <?php if ($ownerName !== null): ?>
                <span class="badge badge-secondary" title="Owner">&#128100; <?= htmlspecialchars($ownerName) ?></span>
            <?php endif; ?>
            <?php foreach ($risk['linked_items'] as $li): ?>
                <span class="badge badge-secondary"><?= htmlspecialchars($li['title']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="risk-scores">
        <span class="risk-badge likelihood-<?= (int) $risk['likelihood'] ?>">L: <?= (int) $risk['likelihood'] ?></span>
        <span class="risk-badge impact-<?= (int) $risk['impact'] ?>">I: <?= (int) $risk['impact'] ?></span>
    </div>
    <div class="risk-mitigation">
        <?php if (!empty($risk['mitigation'])): ?>
            <p class="mitigation-text"><?= htmlspecialchars($risk['mitigation']) ?></p>
        <?php else: ?>
            <button class="btn btn-sm btn-ai generate-mitigation-btn" data-id="<?= $riskId ?>">Generate Mitigation (AI)</button>
        <?php endif; ?>
    </div>
    <?php
        $row_edit_class       = 'edit-risk-btn';
        $row_id               = $riskId;
        $row_delete_action    = '/app/risks/' . $riskId . '/delete';
        $row_delete_confirm   = 'Delete this risk?';
        $row_close_action     = '/app/risks/' . $riskId . '/close';
        $row_extra_items_html = null;
        include __DIR__ . '/row-actions-menu.php';
    ?>
</div>
