<?php
/**
 * Quality Breakdown Partial
 *
 * Renders the breakdown of quality scores into bars and issues.
 *
 * Variables:
 * $breakdownData (array|null)
 * $itemId (int)
 * $itemType (string: 'story' or 'work-item')
 * $csrf_token (string)
 */

if ($breakdownData === null) {
    return;
}
?>
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
            if (!isset($breakdownData[$dimKey])) continue;
            $dim      = $breakdownData[$dimKey];
            $dimScore = (int) ($dim['score'] ?? 0);
            $dimMax   = (int) ($dim['max'] ?? 1);
            $dimPct   = $dimMax > 0 ? (int) round($dimScore / $dimMax * 100) : 0;
            $dimColor = $dimPct >= 80 ? '#10b981' : ($dimPct >= 50 ? '#f59e0b' : '#ef4444');
        ?>
        <div class="quality-dim">
            <div class="quality-dim-header">
                <span class="quality-dim-label"><?= htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="quality-dim-score" data-style-color="<?= $dimColor ?>"><?= $dimScore ?>/<?= $dimMax ?></span>
            </div>
            <div class="quality-dim-bar-track">
                <div class="quality-dim-bar-fill" data-style-width="<?= $dimPct ?>%" data-style-background="<?= $dimColor ?>"></div>
            </div>
            <?php foreach ($dim['issues'] ?? [] as $issue): ?>
            <div class="quality-dim-issue">&#8627; <?= htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    $improveUrl = $itemType === 'story'
        ? '/app/user-stories/' . (int) $itemId . '/improve'
        : '/app/work-items/' . (int) $itemId . '/improve';
    ?>
    <form method="POST" action="<?= $improveUrl ?>"
          class="quality-improve-form" data-loading="Improving with AI…">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-ai btn-sm"
                data-confirm="Improve this item with AI? The description, acceptance criteria, and KR hypothesis may be rewritten based on the quality score.">Improve with AI</button>
    </form>
</div>
