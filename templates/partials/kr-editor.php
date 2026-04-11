<?php
/**
 * KR Inline Editor Partial
 *
 * Renders an inline key-results table for a single work item.
 * Uses AJAX to create / update / delete KRs without a page reload.
 *
 * Variables expected:
 *   $work_item   (array)  — the work item row (needs $work_item['id'])
 *   $key_results (array)  — KR rows for this work item
 *   $csrf_token  (string) — CSRF token
 */
?>
<div class="kr-editor" data-item-id="<?= (int) $work_item['id'] ?>">
    <h4 style="margin: 1.25rem 0 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">
        Key Results
        <span style="font-weight: 400; color: #6b7280; font-size: 0.8rem;">(optional — track measurable outcomes for this OKR)</span>
    </h4>

    <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
        <thead>
            <tr style="border-bottom: 1px solid #e5e7eb; color: #6b7280;">
                <th style="padding: 4px 6px; text-align:left; width:35%;">Key Result</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Baseline</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Current</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Target</th>
                <th style="padding: 4px 6px; text-align:left; width:10%;">Unit</th>
                <th style="padding: 4px 6px; text-align:left; width:10%;">Status</th>
                <th style="padding: 4px 6px; width: 30px;"></th>
            </tr>
        </thead>
        <tbody id="kr-rows-<?= (int) $work_item['id'] ?>">
        <?php foreach ($key_results as $kr): ?>
            <tr class="kr-row" data-kr-id="<?= (int) $kr['id'] ?>" style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 4px 6px;">
                    <input type="text" class="kr-field" data-field="title"
                           value="<?= htmlspecialchars((string) $kr['title'], ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Increase MRR to $50k"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="baseline_value"
                           value="<?= htmlspecialchars((string) ($kr['baseline_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="current_value"
                           value="<?= htmlspecialchars((string) ($kr['current_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="target_value"
                           value="<?= htmlspecialchars((string) ($kr['target_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="text" class="kr-field" data-field="unit"
                           value="<?= htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="%" style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <select class="kr-field" data-field="status" style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 4px;">
                        <?php foreach (['not_started', 'on_track', 'at_risk', 'off_track', 'achieved'] as $s): ?>
                            <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= ($kr['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s)), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="padding: 4px 6px; text-align:center;">
                    <button type="button" class="kr-delete-btn" data-kr-id="<?= (int) $kr['id'] ?>"
                            style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:1rem;" title="Delete">&times;</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center;">
        <button type="button" class="kr-add-btn" data-item-id="<?= (int) $work_item['id'] ?>"
                style="font-size: 0.8rem; color: #6366f1; background: none; border: 1px dashed #c7d2fe; border-radius: 6px; padding: 4px 12px; cursor: pointer;">
            + Add Key Result
        </button>
        <button type="button" class="kr-save-btn" data-item-id="<?= (int) $work_item['id'] ?>"
                style="font-size: 0.8rem; background: #6366f1; color: #fff; border: none; border-radius: 6px; padding: 4px 12px; cursor: pointer;">
            Save KRs
        </button>
        <span class="kr-status-msg" style="font-size:0.75rem; color:#6b7280;"></span>
    </div>
</div>
