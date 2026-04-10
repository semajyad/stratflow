<?php
/**
 * Admin Organisation Settings Template
 *
 * Editable workflow personas, HL item/user story defaults, and
 * capacity/dependency tripwire configuration.
 * Sections are collapsible accordion cards, closed by default.
 *
 * Variables: $user (array), $settings (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Organisation Settings</h1>
    <p class="page-subtitle">
        <a href="/app/admin">&larr; Back to Administration</a>
    </p>
</div>

<form method="POST" action="/app/admin/settings">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <div style="display:flex; flex-direction:column; gap:0.75rem;">

        <!-- ===========================
             Workflow Personas
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Workflow Personas</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    AI agents for each pipeline stage
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted" style="font-size:0.875rem; margin-bottom:1.25rem;">
                    Each persona defines the AI prompt used at a specific pipeline stage.
                    Edit to customise how your organisation's AI agents behave.
                </p>

                <?php
                $personaLabels = [
                    'agile_product_manager'          => 'Agile Product Manager',
                    'technical_project_manager'      => 'Technical Project Manager',
                    'expert_system_architect'        => 'Expert System Architect',
                    'enterprise_risk_manager'        => 'Enterprise Risk Manager',
                    'agile_product_owner'            => 'Agile Product Owner',
                    'enterprise_business_strategist' => 'Enterprise Business Strategist',
                ];
                ?>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem;">
                    <?php foreach ($personaLabels as $key => $label): ?>
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars($label) ?></label>
                            <textarea name="persona_<?= htmlspecialchars($key) ?>"
                                      class="form-input"
                                      rows="3"
                                      placeholder="Describe this persona's role..."
                            ><?= htmlspecialchars($settings['personas'][$key] ?? '') ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ===========================
             AI Model
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">AI Model</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Provider, model, and API key override
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted" style="font-size:0.875rem; margin-bottom:1.25rem;">
                    Override the AI provider, model, and API key for your organisation.
                    Leave blank to use the StratFlow default (Google Gemini — gemini-2.5-flash).
                </p>
                <div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:180px;">
                        <label class="form-label">Provider</label>
                        <select name="ai_provider" id="ai-provider-select" class="form-input" onchange="updateAiModelPlaceholder()">
                            <option value="" <?= empty($settings['ai']['provider']) ? 'selected' : '' ?>>Platform default (Google Gemini)</option>
                            <option value="google"    <?= ($settings['ai']['provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Gemini</option>
                            <option value="openai"    <?= ($settings['ai']['provider'] ?? '') === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                            <option value="anthropic" <?= ($settings['ai']['provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:180px;">
                        <label class="form-label">Model</label>
                        <input type="text"
                               name="ai_model"
                               id="ai-model-input"
                               class="form-input"
                               value="<?= htmlspecialchars($settings['ai']['model'] ?? '') ?>"
                               placeholder="e.g. gemini-2.5-flash">
                        <small class="text-muted" id="ai-model-hint">Leave blank to use the platform default.</small>
                    </div>
                    <div class="form-group" style="flex:1; min-width:180px;">
                        <label class="form-label">API Key <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                        <input type="password"
                               name="ai_api_key"
                               id="ai-api-key-input"
                               class="form-input"
                               autocomplete="new-password"
                               placeholder="<?= empty($settings['ai']['api_key']) ? 'Using platform key' : '••••••••••••' ?>"
                               value="">
                        <small class="text-muted">Leave blank to use the StratFlow shared key.</small>
                    </div>
                </div>
                <div style="margin-top:1rem; display:flex; align-items:center; gap:1rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="testAiConnection()">Test Connection</button>
                    <span id="ai-test-result" style="font-size:0.875rem;"></span>
                </div>
            </div>
        </div>

        <!-- ===========================
             Defaults
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Defaults</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    HL item size and story point limits
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
                    <div class="form-group">
                        <label class="form-label">HL Item Default Size (months)</label>
                        <select name="hl_item_default_months" class="form-input">
                            <?php for ($m = 1; $m <= 6; $m++): ?>
                                <option value="<?= $m ?>" <?= (int) ($settings['hl_item_default_months'] ?? 2) === $m ? 'selected' : '' ?>>
                                    <?= $m ?> month<?= $m !== 1 ? 's' : '' ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">User Story Max Size (points)</label>
                        <select name="user_story_max_size" class="form-input">
                            <?php
                            $fibonacci = [1, 2, 3, 5, 8, 13, 20];
                            $currentMax = (int) ($settings['user_story_max_size'] ?? 13);
                            ?>
                            <?php foreach ($fibonacci as $f): ?>
                                <option value="<?= $f ?>" <?= $currentMax === $f ? 'selected' : '' ?>><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             Field Ordering
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Field Ordering</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Drag to reorder fields in work item and story modals
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <?php
                $wiLabels = [
                    'title'               => 'Title',
                    'okr_title'           => 'OKR Title',
                    'okr_description'     => 'OKR Description',
                    'owner'               => 'Owner',
                    'estimated_sprints'   => 'Estimated Sprints',
                    'description'         => 'Description',
                    'acceptance_criteria' => 'Acceptance Criteria',
                    'kr_hypothesis'       => 'KR Hypothesis',
                    'git_links'           => 'Git Links',
                ];
                $stLabels = [
                    'title'               => 'Title',
                    'description'         => 'Description',
                    'parent_hl_item_id'   => 'Parent Work Item',
                    'team_assigned'       => 'Team Assigned',
                    'size'                => 'Size (Story Points)',
                    'acceptance_criteria' => 'Acceptance Criteria',
                    'kr_hypothesis'       => 'KR Hypothesis',
                    'blocked_by'          => 'Blocked By',
                    'git_links'           => 'Git Links',
                ];

                // Apply saved order
                $savedWiOrder = $settings['field_order_work_item'] ?? array_keys($wiLabels);
                $savedStOrder = $settings['field_order_story']     ?? array_keys($stLabels);

                // Build ordered label arrays, append any new fields not yet in saved order
                $orderedWi = [];
                foreach ($savedWiOrder as $k) { if (isset($wiLabels[$k])) $orderedWi[$k] = $wiLabels[$k]; }
                foreach ($wiLabels as $k => $v) { if (!isset($orderedWi[$k])) $orderedWi[$k] = $v; }

                $orderedSt = [];
                foreach ($savedStOrder as $k) { if (isset($stLabels[$k])) $orderedSt[$k] = $stLabels[$k]; }
                foreach ($stLabels as $k => $v) { if (!isset($orderedSt[$k])) $orderedSt[$k] = $v; }
                ?>

                <style>
                .field-sort-list {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                    display: flex;
                    flex-direction: column;
                    gap: 0.375rem;
                }
                .field-sort-item {
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    padding: 0.5rem 0.75rem;
                    background: var(--bg, #fff);
                    border: 1px solid var(--border);
                    border-radius: 6px;
                    cursor: grab;
                    user-select: none;
                    font-size: 0.875rem;
                    transition: box-shadow 100ms ease, background 100ms ease;
                }
                .field-sort-item:active { cursor: grabbing; }
                .field-sort-item.drag-over {
                    border-color: var(--primary);
                    background: rgba(79,70,229,0.04);
                }
                .field-sort-item.dragging {
                    opacity: 0.4;
                }
                .field-sort-handle {
                    color: var(--text-muted);
                    font-size: 1rem;
                    line-height: 1;
                    flex-shrink: 0;
                }
                .field-sort-num {
                    width: 1.25rem;
                    font-size: 0.75rem;
                    color: var(--text-muted);
                    text-align: right;
                    flex-shrink: 0;
                }
                </style>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                    <!-- Work Item Fields -->
                    <div>
                        <div style="font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); margin-bottom:0.625rem;">
                            HL Work Item
                        </div>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin:0 0 0.75rem;">
                            <span style="font-size:0.75rem; color:#94a3b8;">Priority always appears first and is not reorderable.</span>
                        </p>
                        <input type="hidden" name="field_order_work_item" id="field-order-wi"
                               value="<?= htmlspecialchars(implode(',', array_keys($orderedWi))) ?>">
                        <ul class="field-sort-list" id="sort-list-wi">
                            <?php $n = 1; foreach ($orderedWi as $key => $label): ?>
                                <li class="field-sort-item" draggable="true" data-key="<?= htmlspecialchars($key) ?>"
                                    data-list="wi">
                                    <span class="field-sort-handle">⠿</span>
                                    <span class="field-sort-num"><?= $n++ ?></span>
                                    <span><?= htmlspecialchars($label) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Story Fields -->
                    <div>
                        <div style="font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); margin-bottom:0.625rem;">
                            User Story
                        </div>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin:0 0 0.75rem;">&nbsp;</p>
                        <input type="hidden" name="field_order_story" id="field-order-st"
                               value="<?= htmlspecialchars(implode(',', array_keys($orderedSt))) ?>">
                        <ul class="field-sort-list" id="sort-list-st">
                            <?php $n = 1; foreach ($orderedSt as $key => $label): ?>
                                <li class="field-sort-item" draggable="true" data-key="<?= htmlspecialchars($key) ?>"
                                    data-list="st">
                                    <span class="field-sort-handle">⠿</span>
                                    <span class="field-sort-num"><?= $n++ ?></span>
                                    <span><?= htmlspecialchars($label) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             Tripwires
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Tripwires</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Capacity and dependency alert thresholds
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted" style="font-size:0.875rem; margin-bottom:1.25rem;">
                    Tripwires alert you when sprints or dependencies exceed configurable thresholds.
                </p>
                <div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
                    <div class="form-group">
                        <label class="form-label">Capacity Tripwire (%)</label>
                        <input type="number" name="capacity_tripwire_percent"
                               class="form-input" style="max-width:120px;"
                               value="<?= (int) ($settings['capacity_tripwire_percent'] ?? 20) ?>"
                               min="0" max="100">
                        <small class="text-muted">Warn when sprint capacity exceeds this threshold above 100%</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Dependency Tripwire</label>
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-top:0.375rem;">
                            <input type="hidden" name="dependency_tripwire_enabled" value="0">
                            <input type="checkbox" name="dependency_tripwire_enabled" value="1"
                                   id="dep-tripwire"
                                   <?= !empty($settings['dependency_tripwire_enabled']) ? 'checked' : '' ?>>
                            <label for="dep-tripwire" style="font-weight:normal; cursor:pointer;">
                                Enable dependency conflict warnings
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /accordion stack -->

    <!-- ===========================
         Save Button
         =========================== -->
    <div style="margin-top:1.5rem; margin-bottom:1.5rem;">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<script>
// =========================================================================
// Drag-and-drop field ordering
// =========================================================================
(function () {
    var dragging = null;

    function initList(listId, inputId) {
        var list  = document.getElementById(listId);
        var input = document.getElementById(inputId);
        if (!list) return;

        list.addEventListener('dragstart', function (e) {
            dragging = e.target.closest('.field-sort-item');
            if (!dragging) return;
            dragging.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        list.addEventListener('dragend', function () {
            if (dragging) dragging.classList.remove('dragging');
            list.querySelectorAll('.field-sort-item').forEach(function (el) {
                el.classList.remove('drag-over');
            });
            dragging = null;
            syncOrder(list, input);
        });

        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            var target = e.target.closest('.field-sort-item');
            if (!target || target === dragging) return;
            var rect = target.getBoundingClientRect();
            var after = e.clientY > rect.top + rect.height / 2;
            list.querySelectorAll('.field-sort-item').forEach(function (el) { el.classList.remove('drag-over'); });
            target.classList.add('drag-over');
            if (after) {
                list.insertBefore(dragging, target.nextSibling);
            } else {
                list.insertBefore(dragging, target);
            }
        });
    }

    function syncOrder(list, input) {
        var keys = [];
        var items = list.querySelectorAll('.field-sort-item');
        items.forEach(function (el, i) {
            keys.push(el.dataset.key);
            var num = el.querySelector('.field-sort-num');
            if (num) num.textContent = i + 1;
        });
        input.value = keys.join(',');
    }

    initList('sort-list-wi', 'field-order-wi');
    initList('sort-list-st', 'field-order-st');
}());

var _aiProviderPlaceholders = {
    '':          'e.g. gemini-2.5-flash',
    'google':    'e.g. gemini-2.5-flash',
    'openai':    'e.g. gpt-4o',
    'anthropic': 'e.g. claude-sonnet-4-6',
};
var _aiProviderHints = {
    '':          'Leave blank to use the platform default.',
    'google':    'e.g. gemini-2.5-flash, gemini-2.5-pro',
    'openai':    'e.g. gpt-4o, gpt-4o-mini',
    'anthropic': 'e.g. claude-opus-4-6, claude-sonnet-4-6',
};
function updateAiModelPlaceholder() {
    var prov = document.getElementById('ai-provider-select').value;
    document.getElementById('ai-model-input').placeholder = _aiProviderPlaceholders[prov] || 'Enter model name';
    document.getElementById('ai-model-hint').textContent  = _aiProviderHints[prov] || '';
}
function testAiConnection() {
    var btn      = document.querySelector('[onclick="testAiConnection()"]');
    var result   = document.getElementById('ai-test-result');
    var provider = document.getElementById('ai-provider-select').value;
    var model    = document.getElementById('ai-model-input').value;
    var apiKey   = document.getElementById('ai-api-key-input').value;
    var csrf     = document.querySelector('input[name="_csrf_token"]');

    btn.disabled = true;
    btn.textContent = 'Testing...';
    result.style.color = '';
    result.textContent = '';

    var form = new FormData();
    form.append('_csrf_token', csrf ? csrf.value : '');
    form.append('ai_provider', provider);
    form.append('ai_model',    model);
    form.append('ai_api_key',  apiKey);

    fetch('/app/admin/test-ai', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        if (data.status === 'ok') {
            result.style.color = '#16a34a';
            result.textContent = '✓ ' + (data.message || 'Connection successful');
        } else {
            result.style.color = '#dc2626';
            result.textContent = '✗ ' + (data.message || 'Connection failed');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        result.style.color = '#dc2626';
        result.textContent = '✗ Request failed';
    });
}
</script>
