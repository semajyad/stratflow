<?php
/**
 * KR Inline Editor Partial
 *
 * Renders an inline key-results table for a single work item.
 * Uses AJAX to create / update / delete KRs without a page reload.
 *
 * Variables expected:
 *   $work_item   (array)  - the work item row (needs $work_item['id'])
 *   $key_results (array)  - KR rows for this work item
 *   $csrf_token  (string) - CSRF token
 */
?>
<div class="kr-editor" data-item-id="<?= (int) $work_item['id'] ?>">
    <h4 class="kr-editor-title">
        Key Results
        <span class="kr-editor-title-note">(optional - track measurable outcomes for this OKR)</span>
    </h4>

    <table class="kr-editor-table">
        <thead>
            <tr class="kr-editor-head-row">
                <th class="kr-editor-head kr-editor-head--title">Key Result</th>
                <th class="kr-editor-head kr-editor-head--number">Baseline</th>
                <th class="kr-editor-head kr-editor-head--number">Current</th>
                <th class="kr-editor-head kr-editor-head--number">Target</th>
                <th class="kr-editor-head kr-editor-head--unit">Unit</th>
                <th class="kr-editor-head kr-editor-head--status">Status</th>
                <th class="kr-editor-head kr-editor-head--actions"></th>
            </tr>
        </thead>
        <tbody id="kr-rows-<?= (int) $work_item['id'] ?>">
        <?php foreach ($key_results as $kr): ?>
            <tr class="kr-row kr-editor-row" data-kr-id="<?= (int) $kr['id'] ?>">
                <td class="kr-editor-cell">
                    <input type="text" class="kr-field kr-editor-input" data-field="title"
                           value="<?= htmlspecialchars((string) $kr['title'], ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Increase MRR to $50k" />
                </td>
                <td class="kr-editor-cell">
                    <input type="number" step="any" class="kr-field kr-editor-input" data-field="baseline_value"
                           value="<?= htmlspecialchars((string) ($kr['baseline_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </td>
                <td class="kr-editor-cell">
                    <input type="number" step="any" class="kr-field kr-editor-input" data-field="current_value"
                           value="<?= htmlspecialchars((string) ($kr['current_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </td>
                <td class="kr-editor-cell">
                    <input type="number" step="any" class="kr-field kr-editor-input" data-field="target_value"
                           value="<?= htmlspecialchars((string) ($kr['target_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </td>
                <td class="kr-editor-cell">
                    <input type="text" class="kr-field kr-editor-input" data-field="unit"
                           value="<?= htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="%" />
                </td>
                <td class="kr-editor-cell">
                    <select class="kr-field kr-editor-select" data-field="status">
                        <?php foreach (['not_started', 'on_track', 'at_risk', 'off_track', 'achieved'] as $s): ?>
                            <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= ($kr['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s)), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="kr-editor-cell kr-editor-cell--actions">
                    <button type="button" class="kr-delete-btn kr-editor-delete" data-kr-id="<?= (int) $kr['id'] ?>" title="Delete">&times;</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="kr-editor-actions">
        <button type="button" class="kr-add-btn kr-editor-add" data-item-id="<?= (int) $work_item['id'] ?>">
            + Add Key Result
        </button>
        <button type="button" class="kr-save-btn kr-editor-save" data-item-id="<?= (int) $work_item['id'] ?>">
            Save KRs
        </button>
        <span class="kr-status-msg kr-editor-status"></span>
    </div>
</div>
