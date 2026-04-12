<?php
/**
 * User Story Row Partial
 *
 * A single draggable row in the user stories list. Used inside a loop
 * in user-stories.php. Expects $story (array), $csrf_token (string),
 * and $project (array) from the parent scope.
 */
?>
<details class="story-row-details">
<summary class="story-row" data-id="<?= (int) $story['id'] ?>"
         data-title="<?= htmlspecialchars($story['title']) ?>"
         data-description="<?= htmlspecialchars($story['description'] ?? '') ?>"
         data-team="<?= htmlspecialchars($story['team_assigned'] ?? '') ?>"
         data-size="<?= htmlspecialchars((string) ($story['size'] ?? '')) ?>"
         data-blocked-by="<?= htmlspecialchars((string) ($story['blocked_by'] ?? '')) ?>"
         data-parent-id="<?= htmlspecialchars((string) ($story['parent_hl_item_id'] ?? '')) ?>"
         data-acceptance-criteria="<?= htmlspecialchars($story['acceptance_criteria'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-kr-hypothesis="<?= htmlspecialchars($story['kr_hypothesis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-assignee-user-id="<?= htmlspecialchars((string) ($story['assignee_user_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
         data-closed="<?= ($story['status'] ?? '') === 'closed' ? '1' : '0' ?>">
    <span class="drag-handle" title="Drag to reorder">&#x2807;</span>
    <span class="priority-number"><?= (int) $story['priority_number'] ?></span>
    <div class="story-info">
        <strong><?= htmlspecialchars($story['title']) ?></strong>
        <span class="badge badge-secondary"><?= htmlspecialchars($story['parent_title'] ?? 'Unlinked') ?></span>
        <?php if (!empty($story['jira_url'])): ?>
            <a href="<?= htmlspecialchars($story['jira_url']) ?>" target="_blank" rel="noopener"
               title="Synced to Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>"
               class="badge badge-info" style="text-decoration: none; font-size: 0.75rem;">
                Jira: <?= htmlspecialchars($story['jira_key'] ?? '') ?>
            </a>
        <?php endif; ?>
        <?php
            $statusLabels = ['in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done', 'closed' => 'Closed'];
            $statusBadges = ['in_progress' => 'badge-info', 'in_review' => 'badge-warning', 'done' => 'badge-success', 'closed' => 'badge-secondary'];
            $storyStatus  = $story['status'] ?? 'backlog';
            if ($storyStatus !== 'backlog' && isset($statusLabels[$storyStatus])):
        ?>
            <span class="badge <?= $statusBadges[$storyStatus] ?>"><?= $statusLabels[$storyStatus] ?></span>
        <?php endif; ?>
        <?php if ($story['requires_review'] ?? false): ?>
            <span class="badge badge-warning">Requires Review</span>
        <?php endif; ?>
        <?php if ($story['blocked_by']): ?>
            <span class="badge badge-warning">Blocked</span>
        <?php endif; ?>
        <?php if (!empty($story['git_link_count'])): ?>
            <span class="badge badge-secondary git-link-badge">Git: <?= (int) $story['git_link_count'] ?></span>
        <?php endif; ?>
    </div>
    <span class="story-size"><?= $story['size'] !== null ? (int) $story['size'] . ' pts' : '- pts' ?></span>
    <?php if (($show_quality ?? true) && $story['quality_score'] !== null): ?>
    <?php $qs = (int) $story['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" style="background:<?= $qc ?>;" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php endif; ?>
    <span class="story-team"><?= htmlspecialchars($story['team_assigned'] ?? 'Unassigned') ?></span>
    <?php
        $row_edit_class     = 'edit-story-btn';
        $row_id             = (int) $story['id'];
        $row_delete_action  = '/app/user-stories/' . (int) $story['id'] . '/delete';
        $row_delete_confirm = 'Delete this user story?';
        $row_close_action   = '/app/user-stories/' . (int) $story['id'] . '/close';
        $row_extra_items_html = null;
        include __DIR__ . '/row-actions-menu.php';
    ?>
</summary>
<div class="story-row-expand">
    <?php if (!empty($story['acceptance_criteria'])): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Acceptance Criteria</span>
        <ul class="story-ac-list">
            <?php foreach (array_filter(array_map('trim', explode("\n", $story['acceptance_criteria']))) as $ac): ?>
                <li><?= htmlspecialchars($ac, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <div class="story-expand-meta">
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Size</span>
            <span><?= $story['size'] !== null ? (int) $story['size'] . ' story points' : 'Not estimated' ?></span>
        </div>
        <?php if (!empty($story['kr_hypothesis'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">KR Hypothesis</span>
            <span><?= htmlspecialchars($story['kr_hypothesis'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($story['team_assigned'])): ?>
        <div class="story-expand-meta-item">
            <span class="story-expand-label">Team</span>
            <span><?= htmlspecialchars($story['team_assigned'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
    $storyBreakdown = null;
    if (!empty($story['quality_breakdown'])) {
        $storyBreakdown = json_decode($story['quality_breakdown'], true);
    }
    ?>
    <?php if (($show_quality ?? true) && $storyBreakdown !== null): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Quality Breakdown</span>
        <div class="quality-breakdown">
            <?php
            $dimLabels = [
                'invest'              => 'INVEST',
                'acceptance_criteria' => 'Acceptance Criteria',
                'value'               => 'Value',
                'kr_linkage'          => 'KR Linkage',
                'smart'               => 'SMART',
                'splitting'           => 'Splitting',
            ];
            foreach ($dimLabels as $dimKey => $dimLabel):
                if (!isset($storyBreakdown[$dimKey])) continue;
                $dim      = $storyBreakdown[$dimKey];
                $dimScore = (int) ($dim['score'] ?? 0);
                $dimMax   = (int) ($dim['max'] ?? 1);
                $dimPct   = $dimMax > 0 ? (int) round($dimScore / $dimMax * 100) : 0;
                $dimColor = $dimPct >= 80 ? '#10b981' : ($dimPct >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="quality-dim">
                <div class="quality-dim-header">
                    <span class="quality-dim-label"><?= htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="quality-dim-score" style="color:<?= $dimColor ?>;"><?= $dimScore ?>/<?= $dimMax ?></span>
                </div>
                <div class="quality-dim-bar-track">
                    <div class="quality-dim-bar-fill" style="width:<?= $dimPct ?>%; background:<?= $dimColor ?>;"></div>
                </div>
                <?php foreach ($dim['issues'] ?? [] as $issue): ?>
                <div class="quality-dim-issue">&#8627; <?= htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/improve"
              class="quality-improve-form" data-loading="Improving with AI…">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-ai btn-sm"
                    data-confirm="Improve this story with AI? The description, acceptance criteria, and KR hypothesis may be rewritten based on the quality score.">Improve with AI</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</details>
