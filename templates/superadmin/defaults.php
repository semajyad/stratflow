<?php
/**
 * App-Wide Defaults Template
 *
 * Superadmin-only page for configuring system-wide defaults.
 * Uses collapsible accordion layout matching admin/settings.php.
 *
 * Variables: $user (array), $settings (array), $api_keys (array), $csrf_token (string)
 */
$s = $settings;
?>

<div class="page-header">
    <h1 class="page-title">App Wide Defaults</h1>
    <p class="page-subtitle">
        <a href="/superadmin">&larr; Back to Superadmin Dashboard</a>
    </p>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form method="POST" action="/superadmin/defaults">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <div class="settings-stack">

        <!-- ===========================
             AI Provider & Model
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">AI Provider &amp; Model</span>
                <span class="settings-accordion-meta">
                    Default AI used for story generation, scoring, and analysis
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div class="settings-provider-grid">
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="ai_provider" class="form-input js-superadmin-ai-provider" id="ai-provider-select">
                            <option value="google"    <?= ($s['ai_provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Gemini</option>
                            <option value="openai"    <?= ($s['ai_provider'] ?? '') === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                            <option value="anthropic" <?= ($s['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Model Identifier</label>
                        <input type="text" name="ai_model" class="form-input"
                               value="<?= htmlspecialchars($s['ai_model'] ?? 'gemini-3.0-preview') ?>"
                               placeholder="e.g. gemini-3.0-preview, gpt-4o, claude-sonnet-4-6">
                        <small class="text-muted">Enter the exact model identifier.</small>
                    </div>
                    <?php
                    $providerKeyLabels = [
                        'google'    => ['label' => 'Google API Key',    'env' => 'GEMINI_API_KEY'],
                        'openai'    => ['label' => 'OpenAI API Key',    'env' => 'OPENAI_API_KEY'],
                        'anthropic' => ['label' => 'Anthropic API Key', 'env' => 'ANTHROPIC_API_KEY'],
                    ];
                    foreach ($providerKeyLabels as $providerSlug => $meta):
                        $maskedKey = $api_keys[$providerSlug] ?? '';
                    ?>
                    <div class="form-group<?= ($s['ai_provider'] ?? 'google') !== $providerSlug ? ' settings-hidden-panel hidden' : '' ?>"
                         id="api-key-<?= $providerSlug ?>">
                        <label class="form-label"><?= htmlspecialchars($meta['label']) ?></label>
                        <input type="text" class="form-input settings-readonly-input"
                               value="<?= htmlspecialchars($maskedKey) ?>"
                               placeholder="Not set — add <?= htmlspecialchars($meta['env']) ?> to environment"
                               readonly>
                        <small class="text-muted">Read from <code><?= htmlspecialchars($meta['env']) ?></code> environment variable.</small>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="settings-divider-row">
                    <button type="button" class="btn btn-secondary js-superadmin-test-ai" id="test-ai-btn">
                        Test Connection
                    </button>
                    <span id="test-ai-result" class="superadmin-test-ai-result"></span>
                </div>
            </div>
        </div>

        <!-- ===========================
             New Organisation Defaults
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">New Organisation Defaults</span>
                <span class="settings-accordion-meta">
                    Applied when creating an organisation without explicit values
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div class="settings-grid-4">
                    <div class="form-group">
                        <label class="form-label">Default Seat Limit</label>
                        <input type="number" name="default_seat_limit" class="form-input settings-input-width-xs"
                               value="<?= (int) ($s['default_seat_limit'] ?? 5) ?>" min="1" max="10000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Cost / Seat</label>
                        <div class="settings-inline-currency-row">
                            <span class="settings-inline-muted"><?= htmlspecialchars($s['billing_currency'] ?? 'NZD') ?></span>
                            <input type="number" name="default_price_per_seat" step="0.01" min="0"
                                   value="<?= ($s['default_price_per_seat_cents'] ?? 0) > 0 ? number_format(($s['default_price_per_seat_cents'] ?? 0) / 100, 2) : '' ?>"
                                   class="form-input settings-input-width-md" placeholder="0.00">
                        </div>
                        <small class="text-muted">Per seat / month.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Plan Type</label>
                        <select name="default_plan_type" class="form-input">
                            <option value="product"     <?= ($s['default_plan_type'] ?? '') === 'product'     ? 'selected' : '' ?>>Product</option>
                            <option value="consultancy" <?= ($s['default_plan_type'] ?? '') === 'consultancy' ? 'selected' : '' ?>>Consultancy</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Billing Method</label>
                        <select name="default_billing_method" class="form-input">
                            <option value="invoiced" <?= ($s['default_billing_method'] ?? '') === 'invoiced' ? 'selected' : '' ?>>Invoiced</option>
                            <option value="stripe"   <?= ($s['default_billing_method'] ?? '') === 'stripe'   ? 'selected' : '' ?>>Stripe</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             Billing Rates
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Billing Rates</span>
                <span class="settings-accordion-meta">
                    Standard pricing per seat for each billing period
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted settings-intro">
                    Set the standard price per seat for monthly and annual billing cycles. These are used as defaults when assigning billing to a new organisation.
                </p>
                <div class="settings-rates-grid">
                    <!-- Currency -->
                    <label class="form-label settings-rates-label">Currency</label>
                    <div class="settings-rates-cell">
                        <select name="billing_currency" class="form-input settings-input-width-sm">
                            <?php foreach (['NZD','AUD','USD','GBP','EUR'] as $cur): ?>
                                <option value="<?= $cur ?>" <?= ($s['billing_currency'] ?? 'NZD') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    $rateRows = [
                        ['billing_rate_monthly',   'Monthly',    1,  'billing_rate_monthly_cents'],
                        ['billing_rate_annual',     'Annual',     12, 'billing_rate_annual_cents'],
                    ];
                    foreach ($rateRows as [$fieldName, $label, $months, $settingsKey]):
                        $cents = (int) ($s[$settingsKey] ?? 0);
                        $dollars = $cents > 0 ? number_format($cents / 100, 2) : '';
                    ?>
                    <label class="form-label settings-rates-label">
                        <?= $label ?>
                        <span class="settings-rates-subtext">per seat / <?= $months === 1 ? 'month' : ($months . ' months') ?></span>
                    </label>
                    <div class="settings-rates-cell settings-rates-row">
                        <span class="settings-inline-muted"><?= htmlspecialchars($s['billing_currency'] ?? 'NZD') ?></span>
                        <input type="number" name="<?= $fieldName ?>" step="0.01" min="0"
                               value="<?= $dollars ?>"
                               class="form-input settings-input-width-sm" placeholder="0.00">
                        <?php if ($months > 1 && $cents > 0): ?>
                            <span class="settings-rates-per-month">= $<?= number_format($cents / 100 / $months, 2) ?>/seat/mo</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="settings-callout">
                    These rates are defaults only — you can override the price per seat on individual organisations from the
                    <a href="/superadmin/organisations">Manage Organisations</a> page.
                </div>
            </div>
        </div>

        <!-- ===========================
             Feature Flags
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Feature Flags</span>
                <span class="settings-accordion-meta">
                    Enable or disable features globally for all organisations
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <?php
                $flags = [
                    'feature_sounding_board' => ['Sounding Board', 'AI persona evaluation tool for strategic decisions'],
                    'feature_executive'      => ['Executive Dashboard', 'Cross-project executive rollup and insights'],
                    'feature_xero'           => ['Xero Integration', 'Invoice management via Xero'],
                    'feature_jira'           => ['Jira Integration', 'Two-way sync with Jira boards'],
                    'feature_github'         => ['GitHub Integration', 'Link pull requests and commits to work items'],
                    'feature_gitlab'         => ['GitLab Integration', 'Link merge requests and commits to work items'],
                    'feature_story_quality'  => ['Story Quality', 'AI-powered story quality scoring and enforcement'],
                ];
                ?>
                <div class="settings-checkbox-card-grid">
                    <?php foreach ($flags as $key => [$label, $desc]):
                        $checked = !empty($s[$key]);
                    ?>
                    <label class="settings-checkbox-card">
                        <input type="hidden" name="<?= $key ?>" value="0">
                        <input type="checkbox" name="<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>
                               class="settings-checkbox-card__input">
                        <div>
                            <div class="settings-checkbox-card__title"><?= htmlspecialchars($label) ?></div>
                            <div class="settings-checkbox-card__desc"><?= htmlspecialchars($desc) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ===========================
             Story Quality
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Story Quality</span>
                <span class="settings-accordion-meta">
                    Default quality gate settings applied to new organisations
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div class="settings-grid-2">
                    <div class="form-group">
                        <label class="form-label">Quality Threshold (%)</label>
                        <div class="settings-slider-row">
                            <input type="range" name="quality_threshold"
                                   min="0" max="100" step="5"
                                   value="<?= (int) ($s['quality_threshold'] ?? 70) ?>"
                                   class="js-quality-threshold-range settings-slider"
                                   data-output-id="qt-val">
                            <span id="qt-val" class="settings-slider-output">
                                <?= (int) ($s['quality_threshold'] ?? 70) ?>%
                            </span>
                        </div>
                        <small class="text-muted">Stories below this score are flagged.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Enforcement Mode</label>
                        <select name="quality_enforcement" class="form-input">
                            <option value="warn"  <?= ($s['quality_enforcement'] ?? '') === 'warn'  ? 'selected' : '' ?>>Warn only — show badge, allow save</option>
                            <option value="block" <?= ($s['quality_enforcement'] ?? '') === 'block' ? 'selected' : '' ?>>Block — prevent saving low-quality stories</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             Email & Notifications
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="accordion-title">Email &amp; Notifications</span>
                <span class="settings-accordion-meta">
                    Default sender identity used in system emails
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div class="settings-grid-2">
                    <div class="form-group">
                        <label class="form-label">Support Email</label>
                        <input type="email" name="support_email" class="form-input"
                               value="<?= htmlspecialchars($s['support_email'] ?? '') ?>"
                               placeholder="support@stratflow.io">
                        <small class="text-muted">Shown to users in billing and error messages.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mail From Name</label>
                        <input type="text" name="mail_from_name" class="form-input"
                               value="<?= htmlspecialchars($s['mail_from_name'] ?? 'StratFlow') ?>"
                               placeholder="StratFlow">
                        <small class="text-muted">Display name on outbound emails.</small>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /accordion stack -->

    <div class="settings-save-row flex justify-end">
        <button type="submit" class="btn btn-primary btn-min-width-140">Save Defaults</button>
    </div>
</form>
