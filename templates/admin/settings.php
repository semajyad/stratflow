<?php
/**
 * Admin Organisation Settings Template
 *
 * Editable workflow personas, HL item/user story defaults, and
 * capacity/dependency tripwire configuration.
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

    <!-- ===========================
         Workflow Personas
         =========================== -->
    <section class="card settings-section">
        <div class="card-header">
            <h2 class="card-title">Workflow Personas</h2>
        </div>
        <div class="card-body persona-editor">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
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

            <?php foreach ($personaLabels as $key => $label): ?>
                <div class="form-group mb-4">
                    <label class="form-label"><?= htmlspecialchars($label) ?></label>
                    <textarea name="persona_<?= htmlspecialchars($key) ?>"
                              class="form-input"
                              rows="3"
                              placeholder="Describe this persona's role..."
                    ><?= htmlspecialchars($settings['personas'][$key] ?? '') ?></textarea>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ===========================
         AI Model
         =========================== -->
    <section class="card settings-section mt-4">
        <div class="card-header">
            <h2 class="card-title">AI Model</h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Override the AI provider, model, and API key for your organisation.
                Leave blank to use the StratFlow default (Google Gemini — gemini-2.5-flash).
            </p>
            <div class="form-row gap-4" style="flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:180px;">
                    <label class="form-label">Provider</label>
                    <select name="ai_provider" id="ai-provider-select" class="form-input" onchange="updateAiModelPlaceholder()">
                        <option value="" <?= empty($settings['ai']['provider']) ? 'selected' : '' ?>>Platform default (Google Gemini)</option>
                        <option value="google" <?= ($settings['ai']['provider'] ?? '') === 'google' ? 'selected' : '' ?>>Google Gemini</option>
                        <option value="openai" <?= ($settings['ai']['provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
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
    </section>

    <script>
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

    <!-- ===========================
         Defaults
         =========================== -->
    <section class="card settings-section mt-4">
        <div class="card-header">
            <h2 class="card-title">Defaults</h2>
        </div>
        <div class="card-body">
            <div class="form-row gap-4">
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
                            <option value="<?= $f ?>" <?= $currentMax === $f ? 'selected' : '' ?>>
                                <?= $f ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <!-- ===========================
         Tripwires
         =========================== -->
    <section class="card settings-section mt-4">
        <div class="card-header">
            <h2 class="card-title">Tripwires</h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4" style="font-size: 0.875rem;">
                Tripwires alert you when sprints or dependencies exceed configurable thresholds.
            </p>

            <div class="form-row gap-4">
                <div class="form-group">
                    <label class="form-label">Capacity Tripwire (%)</label>
                    <input type="number" name="capacity_tripwire_percent"
                           class="form-input" style="max-width: 120px;"
                           value="<?= (int) ($settings['capacity_tripwire_percent'] ?? 20) ?>"
                           min="0" max="100">
                    <small class="text-muted">Warn when sprint capacity exceeds this threshold above 100%</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Dependency Tripwire</label>
                    <div class="flex items-center gap-2 mt-1">
                        <input type="hidden" name="dependency_tripwire_enabled" value="0">
                        <input type="checkbox" name="dependency_tripwire_enabled" value="1"
                               id="dep-tripwire"
                               <?= !empty($settings['dependency_tripwire_enabled']) ? 'checked' : '' ?>>
                        <label for="dep-tripwire" style="font-weight: normal; cursor: pointer;">
                            Enable dependency conflict warnings
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===========================
         Save Button
         =========================== -->
    <div class="mt-4 mb-6">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>
