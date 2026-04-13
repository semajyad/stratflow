<?php
/**
 * Admin Organisation Settings Template
 *
 * Editable workflow personas, High Level item/user story defaults, and
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

    <div class="settings-stack">

        <!-- ===========================
             Workflow Personas
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Workflow Personas</span>
                <span class="settings-accordion-meta">
                    AI agents for each pipeline stage
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
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

                <div class="settings-grid-2">
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
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">AI Model</span>
                <span class="settings-accordion-meta">
                    Provider, model, and API key override
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    Override the AI provider, model, and API key for your organisation.
                    Leave blank to use the StratFlow default (Google Gemini — gemini-3-flash-preview
                </p>
                <div class="settings-flex-wrap">
                    <div class="form-group settings-field-flex">
                        <label class="form-label">Provider</label>
                        <select name="ai_provider" id="ai-provider-select" class="form-input js-admin-ai-provider">
                            <option value="" <?= empty($settings['ai']['provider']) ? 'selected' : '' ?>>Platform default (Google Gemini)</option>
                            <option value="google"    <?= ($settings['ai']['provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Gemini</option>
                            <option value="openai"    <?= ($settings['ai']['provider'] ?? '') === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                            <option value="anthropic" <?= ($settings['ai']['provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                        </select>
                    </div>
                    <div class="form-group settings-field-flex">
                        <label class="form-label">Model</label>
                        <input type="text"
                               name="ai_model"
                               id="ai-model-input"
                               class="form-input"
                               value="<?= htmlspecialchars($settings['ai']['model'] ?? '') ?>"
                               placeholder="e.g. gemini-3-flash-preview">
                        <small class="text-muted" id="ai-model-hint">Leave blank to use the platform default.</small>
                    </div>
                    <div class="form-group settings-field-flex">
                        <label class="form-label">API Key <span class="text-muted settings-inline-muted-strong">(optional)</span></label>
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
                <div class="settings-flex-row">
                    <button type="button" class="btn btn-secondary btn-sm js-admin-test-ai">Test Connection</button>
                    <span id="ai-test-result" class="superadmin-test-ai-result"></span>
                </div>
            </div>
        </div>

        <!-- ===========================
             Defaults
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Default Values</span>
                <span class="settings-accordion-meta">
                    High Level item size and story point limits
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div class="settings-flex-wrap">
                    <div class="form-group">
                        <label class="form-label">Sprint Length (weeks)</label>
                        <input type="number" name="sprint_length_weeks" class="form-input settings-input-width-xs"
                               value="<?= (int) ($settings['sprint_length_weeks'] ?? 2) ?>" min="1" max="12">
                        <small class="text-muted">Used for sprint planning and capacity calculations.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">High Level Work Item Sizing</label>
                        <select name="hl_item_sizing_method" class="form-input">
                            <option value="sprints" <?= ($settings['hl_item_sizing_method'] ?? 'sprints') === 'sprints' ? 'selected' : '' ?>>Sprints</option>
                            <option value="weeks"   <?= ($settings['hl_item_sizing_method'] ?? '') === 'weeks'   ? 'selected' : '' ?>>Weeks</option>
                            <option value="months"  <?= ($settings['hl_item_sizing_method'] ?? '') === 'months'  ? 'selected' : '' ?>>Months</option>
                            <option value="t_shirt" <?= ($settings['hl_item_sizing_method'] ?? '') === 't_shirt' ? 'selected' : '' ?>>T-Shirt Sizes (XS → XXL)</option>
                        </select>
                        <small class="text-muted">Controls the size field in the work item modal.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">High Level Item Default Size (months)</label>
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
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Field Ordering</span>
                <span class="settings-accordion-meta">
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
                    'parent_hl_item_id'   => 'Parent High Level Item',
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

                <div class="settings-grid-2-wide">
                    <!-- Work Item Fields -->
                    <div>
                        <div class="settings-field-sort-heading">
                            High Level Work Items
                        </div>
                        <p class="settings-inline-help">
                            <span class="settings-inline-help-note">Priority always appears first and is not reorderable.</span>
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
                        <div class="settings-field-sort-heading">
                            User Story
                        </div>
                        <p class="settings-inline-help">&nbsp;</p>
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
             Story Quality
             =========================== -->
        <?php if (!empty($system_settings['feature_story_quality'])): ?>
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Story Quality</span>
                <span class="settings-accordion-meta">
                    AI quality scoring threshold and enforcement
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    When enabled, user stories are scored by AI after generation. Stories below the threshold are flagged or blocked depending on enforcement mode.
                </p>
                <div class="settings-stack">
                    <div class="form-group">
                        <div class="settings-quality-toggle">
                            <input type="hidden" name="quality_enabled" value="0">
                            <input type="checkbox" name="quality_enabled" value="1"
                                   id="quality-enabled-check"
                                   <?= !empty($settings['quality']['enabled']) ? 'checked' : '' ?>
                                   class="js-quality-enabled-toggle"
                                   data-target-id="quality-options">
                            <label for="quality-enabled-check" class="settings-quality-toggle-label">
                                Enable story quality checks for this organisation
                            </label>
                        </div>
                    </div>
                    <div id="quality-options" class="settings-quality-options<?= empty($settings['quality']['enabled']) ? ' hidden' : '' ?>">
                        <div class="form-group settings-field-flex-lg">
                            <label class="form-label">Quality Threshold (%)</label>
                            <div class="settings-slider-row">
                                <input type="range" name="quality_threshold"
                                       min="0" max="100" step="5"
                                       value="<?= (int) ($settings['quality']['threshold'] ?? 70) ?>"
                                       class="js-quality-threshold-range settings-slider"
                                       data-output-id="org-qt-val">
                                <span id="org-qt-val" class="settings-slider-output">
                                    <?= (int) ($settings['quality']['threshold'] ?? 70) ?>%
                                </span>
                            </div>
                            <small class="text-muted">Stories below this score are flagged. Platform default: <?= (int) ($system_settings['quality_threshold'] ?? 70) ?>%.</small>
                        </div>
                        <div class="form-group settings-field-flex-lg">
                            <label class="form-label">Enforcement Mode</label>
                            <select name="quality_enforcement" class="form-input">
                                <option value="warn"  <?= ($settings['quality']['enforcement'] ?? 'warn') === 'warn'  ? 'selected' : '' ?>>Warn only — show badge, allow save</option>
                                <option value="block" <?= ($settings['quality']['enforcement'] ?? '') === 'block' ? 'selected' : '' ?>>Block — prevent saving low-quality stories</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===========================
             Tripwires
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Tripwires</span>
                <span class="settings-accordion-meta">
                    Capacity and dependency alert thresholds
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    Tripwires alert you when sprints or dependencies exceed configurable thresholds.
                </p>
                <div class="settings-flex-wrap">
                    <div class="form-group">
                        <label class="form-label">Capacity Tripwire (%)</label>
                        <input type="number" name="capacity_tripwire_percent"
                               class="form-input settings-input-width-sm"
                               value="<?= (int) ($settings['capacity_tripwire_percent'] ?? 20) ?>"
                               min="0" max="100">
                        <small class="text-muted">Warn when sprint capacity exceeds this threshold above 100%</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Dependency Tripwire</label>
                        <div class="settings-checkbox-row">
                            <input type="hidden" name="dependency_tripwire_enabled" value="0">
                            <input type="checkbox" name="dependency_tripwire_enabled" value="1"
                                   id="dep-tripwire"
                                   <?= !empty($settings['dependency_tripwire_enabled']) ? 'checked' : '' ?>>
                            <label for="dep-tripwire" class="settings-checkbox-label">
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
    <div class="settings-save-row">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<?php /*

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
    '':          'e.g. gemini-3-flash-preview',
    'google':    'e.g. gemini-3-flash-preview',
    'openai':    'e.g. gpt-4o',
    'anthropic': 'e.g. claude-sonnet-4-6',
};
var _aiProviderHints = {
    '':          'Leave blank to use the platform default.',
    'google':    'e.g. gemini-3-flash-preview gemini-3-flash-preview',
    'openai':    'e.g. gpt-4o, gpt-4o-mini',
    'anthropic': 'e.g. claude-opus-4-6, claude-sonnet-4-6',
};
function updateAiModelPlaceholder() {
    var prov = document.getElementById('ai-provider-select').value;
    document.getElementById('ai-model-input').placeholder = _aiProviderPlaceholders[prov] || 'Enter model name';
    document.getElementById('ai-model-hint').textContent  = _aiProviderHints[prov] || '';
}
*/ ?>
<?php /*
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
*/ ?>
