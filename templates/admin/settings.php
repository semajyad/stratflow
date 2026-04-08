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
