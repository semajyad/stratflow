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
    <p class="page-subtitle">System-wide configuration applied to all new organisations and features.</p>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_message) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form method="POST" action="/superadmin/defaults">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <div style="display:flex; flex-direction:column; gap:0.75rem;">

        <!-- ===========================
             AI Provider & Model
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">AI Provider &amp; Model</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Default AI used for story generation, scoring, and analysis
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start;">
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="ai_provider" class="form-input" id="ai-provider-select"
                                onchange="showApiKey(this.value)">
                            <option value="google"    <?= ($s['ai_provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Gemini</option>
                            <option value="openai"    <?= ($s['ai_provider'] ?? '') === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                            <option value="anthropic" <?= ($s['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Model Identifier</label>
                        <input type="text" name="ai_model" class="form-input"
                               value="<?= htmlspecialchars($s['ai_model'] ?? 'gemini-2.5-flash') ?>"
                               placeholder="e.g. gemini-2.5-flash, gpt-4o, claude-sonnet-4-6">
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
                    <div class="form-group" style="<?= ($s['ai_provider'] ?? 'google') !== $providerSlug ? 'display:none;' : '' ?>"
                         id="api-key-<?= $providerSlug ?>">
                        <label class="form-label"><?= htmlspecialchars($meta['label']) ?></label>
                        <input type="text" class="form-input"
                               value="<?= htmlspecialchars($maskedKey) ?>"
                               placeholder="Not set — add <?= htmlspecialchars($meta['env']) ?> to environment"
                               readonly style="font-family:monospace; background:var(--bg-muted, #f8fafc); cursor:default;">
                        <small class="text-muted">Read from <code><?= htmlspecialchars($meta['env']) ?></code> environment variable.</small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ===========================
             New Organisation Defaults
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">New Organisation Defaults</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Applied when creating an organisation without explicit values
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:1.25rem; align-items:start;">
                    <div class="form-group">
                        <label class="form-label">Default Seat Limit</label>
                        <input type="number" name="default_seat_limit" class="form-input"
                               value="<?= (int) ($s['default_seat_limit'] ?? 5) ?>" min="1" max="10000" style="width:100px;">
                        <small class="text-muted">Applied to new organisations on creation.</small>
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
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Billing Rates</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Standard pricing per seat for each billing period
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <p class="text-muted" style="font-size:0.875rem; margin-bottom:1.25rem;">
                    Set the standard price per seat for each billing cycle. These are used as defaults when assigning billing to a new organisation.
                    Discounts for longer periods are applied automatically (e.g. 10% off quarterly).
                </p>
                <div style="display:grid; grid-template-columns:180px 1fr; gap:0 1.5rem; align-items:center; max-width:600px;">
                    <!-- Currency -->
                    <label class="form-label" style="margin:0; padding:0.625rem 0; border-bottom:1px solid var(--border);">Currency</label>
                    <div style="padding:0.5rem 0; border-bottom:1px solid var(--border);">
                        <select name="billing_currency" class="form-input" style="width:120px;">
                            <?php foreach (['NZD','AUD','USD','GBP','EUR'] as $cur): ?>
                                <option value="<?= $cur ?>" <?= ($s['billing_currency'] ?? 'NZD') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    $rateRows = [
                        ['billing_rate_monthly',   'Monthly',    1,  'billing_rate_monthly_cents'],
                        ['billing_rate_quarterly',  'Quarterly',  3,  'billing_rate_quarterly_cents'],
                        ['billing_rate_6monthly',   '6-Monthly',  6,  'billing_rate_6monthly_cents'],
                        ['billing_rate_annual',     'Annual',     12, 'billing_rate_annual_cents'],
                    ];
                    foreach ($rateRows as [$fieldName, $label, $months, $settingsKey]):
                        $cents = (int) ($s[$settingsKey] ?? 0);
                        $dollars = $cents > 0 ? number_format($cents / 100, 2) : '';
                    ?>
                    <label class="form-label" style="margin:0; padding:0.625rem 0; border-bottom:1px solid var(--border);">
                        <?= $label ?>
                        <span style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:400;">per seat / <?= $months === 1 ? 'month' : ($months . ' months') ?></span>
                    </label>
                    <div style="padding:0.5rem 0; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:0.5rem;">
                        <span style="font-size:0.9rem; color:var(--text-muted);"><?= htmlspecialchars($s['billing_currency'] ?? 'NZD') ?></span>
                        <input type="number" name="<?= $fieldName ?>" step="0.01" min="0"
                               value="<?= $dollars ?>"
                               class="form-input" style="width:120px;" placeholder="0.00">
                        <?php if ($months > 1 && $cents > 0): ?>
                            <span style="font-size:0.78rem; color:#6b7280;">= $<?= number_format($cents / 100 / $months, 2) ?>/seat/mo</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:1rem; padding:0.75rem 1rem; background:#f8fafc; border:1px solid var(--border); border-radius:6px; font-size:0.82rem; color:var(--text-muted); max-width:600px;">
                    These rates are defaults only — you can override the price per seat on individual organisations from the
                    <a href="/superadmin/organisations">Manage Organisations</a> page.
                </div>
            </div>
        </div>

        <!-- ===========================
             Feature Flags
             =========================== -->
        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Feature Flags</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
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
                ];
                ?>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <?php foreach ($flags as $key => [$label, $desc]):
                        $checked = !empty($s[$key]);
                    ?>
                    <label style="display:flex; align-items:flex-start; gap:0.75rem; cursor:pointer; padding:0.75rem; background:#f9fafb; border:1px solid var(--border); border-radius:6px;">
                        <input type="hidden" name="<?= $key ?>" value="0">
                        <input type="checkbox" name="<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>
                               style="margin-top:3px; width:16px; height:16px; flex-shrink:0;">
                        <div>
                            <div style="font-weight:600; font-size:0.875rem;"><?= htmlspecialchars($label) ?></div>
                            <div style="font-size:0.78rem; color:var(--text-muted);"><?= htmlspecialchars($desc) ?></div>
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
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Story Quality</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Default quality gate settings applied to new organisations
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start;">
                    <div class="form-group">
                        <label class="form-label">Quality Threshold (%)</label>
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <input type="range" name="quality_threshold"
                                   min="0" max="100" step="5"
                                   value="<?= (int) ($s['quality_threshold'] ?? 70) ?>"
                                   style="flex:1;"
                                   oninput="document.getElementById('qt-val').textContent = this.value + '%'">
                            <span id="qt-val" style="font-weight:700; font-size:1rem; min-width:3rem;">
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
            <button type="button" class="accordion-header" onclick="this.parentElement.classList.toggle('accordion-item--open')">
                <span class="accordion-title">Email &amp; Notifications</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:400; margin-right:0.5rem;">
                    Default sender identity used in system emails
                </span>
                <svg class="accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start;">
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

    <div style="margin-top:1.5rem; display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary" style="min-width:140px;">Save Defaults</button>
    </div>
</form>

<script>
function showApiKey(provider) {
    ['google','openai','anthropic'].forEach(function(p) {
        var el = document.getElementById('api-key-' + p);
        if (el) el.style.display = p === provider ? '' : 'none';
    });
}
</script>
