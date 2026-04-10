<?php
/**
 * App-Wide Defaults Template
 *
 * Superadmin-only page for configuring system-wide defaults:
 * AI provider/model, new-org defaults, feature flags, story quality, email.
 *
 * Variables: $user (array), $settings (array), $csrf_token (string)
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

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; align-items:stretch;">

        <!-- ===========================
             AI Defaults
             =========================== -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">AI Provider &amp; Model</h2>
                <p class="card-subtitle text-muted" style="font-size:0.8rem; margin:0;">Default AI used for story generation, scoring, and analysis.</p>
            </div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Provider</label>
                    <select name="ai_provider" class="form-control" id="ai-provider-select"
                            onchange="showApiKey(this.value)">
                        <option value="google"    <?= ($s['ai_provider'] ?? '') === 'google'    ? 'selected' : '' ?>>Google Gemini</option>
                        <option value="openai"    <?= ($s['ai_provider'] ?? '') === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                        <option value="anthropic" <?= ($s['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="ai_model" class="form-control"
                           value="<?= htmlspecialchars($s['ai_model'] ?? 'gemini-2.5-flash') ?>"
                           placeholder="e.g. gemini-2.5-flash, gpt-4o, claude-sonnet-4-6">
                    <small class="text-muted">Free text — enter the exact model identifier.</small>
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
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($maskedKey) ?>"
                           placeholder="Not set — add <?= htmlspecialchars($meta['env']) ?> to environment"
                           readonly style="font-family:monospace; background:var(--bg-muted, #f8fafc); cursor:default;">
                    <small class="text-muted">Read from <code><?= htmlspecialchars($meta['env']) ?></code> environment variable.</small>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ===========================
             New Org Defaults
             =========================== -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">New Organisation Defaults</h2>
                <p class="card-subtitle text-muted" style="font-size:0.8rem; margin:0;">Applied when creating an organisation without explicit values.</p>
            </div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Default Seat Limit</label>
                    <input type="number" name="default_seat_limit" class="form-control"
                           value="<?= (int) ($s['default_seat_limit'] ?? 5) ?>" min="1" max="10000" style="width:100px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Default Plan Type</label>
                    <select name="default_plan_type" class="form-control">
                        <option value="product"     <?= ($s['default_plan_type'] ?? '') === 'product'     ? 'selected' : '' ?>>Product</option>
                        <option value="consultancy" <?= ($s['default_plan_type'] ?? '') === 'consultancy' ? 'selected' : '' ?>>Consultancy</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Billing Method</label>
                    <select name="default_billing_method" class="form-control">
                        <option value="invoiced" <?= ($s['default_billing_method'] ?? '') === 'invoiced' ? 'selected' : '' ?>>Invoiced</option>
                        <option value="stripe"   <?= ($s['default_billing_method'] ?? '') === 'stripe'   ? 'selected' : '' ?>>Stripe</option>
                    </select>
                </div>
            </div>
        </section>

        <!-- ===========================
             Feature Flags
             =========================== -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Feature Flags</h2>
                <p class="card-subtitle text-muted" style="font-size:0.8rem; margin:0;">Enable or disable features globally for all organisations.</p>
            </div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:0.875rem;">
                <?php
                $flags = [
                    'feature_sounding_board' => ['Sounding Board', 'AI persona evaluation tool for strategic decisions'],
                    'feature_executive'      => ['Executive Dashboard', 'Cross-project executive rollup and insights'],
                    'feature_xero'           => ['Xero Integration', 'Invoice management via Xero'],
                    'feature_jira'           => ['Jira Integration', 'Two-way sync with Jira boards'],
                ];
                foreach ($flags as $key => [$label, $desc]):
                    $checked = !empty($s[$key]);
                ?>
                <label style="display:flex; align-items:flex-start; gap:0.75rem; cursor:pointer;">
                    <input type="hidden" name="<?= $key ?>" value="0">
                    <input type="checkbox" name="<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>
                           style="margin-top:3px; width:16px; height:16px; flex-shrink:0;">
                    <div>
                        <div style="font-weight:600; font-size:0.875rem;"><?= htmlspecialchars($label) ?></div>
                        <div style="font-size:0.8rem; color:var(--text-muted);"><?= htmlspecialchars($desc) ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ===========================
             Story Quality
             =========================== -->
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Story Quality</h2>
                <p class="card-subtitle text-muted" style="font-size:0.8rem; margin:0;">Default quality gate settings applied to new organisations.</p>
            </div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:1rem;">
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
                    <select name="quality_enforcement" class="form-control">
                        <option value="warn"  <?= ($s['quality_enforcement'] ?? '') === 'warn'  ? 'selected' : '' ?>>Warn only — show badge, allow save</option>
                        <option value="block" <?= ($s['quality_enforcement'] ?? '') === 'block' ? 'selected' : '' ?>>Block — prevent saving low-quality stories</option>
                    </select>
                </div>
            </div>
        </section>

        <!-- ===========================
             Email / Notifications
             =========================== -->
        <section class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <h2 class="card-title">Email &amp; Notifications</h2>
                <p class="card-subtitle text-muted" style="font-size:0.8rem; margin:0;">Default sender identity used in system emails.</p>
            </div>
            <div class="card-body" style="display:flex; gap:1.5rem; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label class="form-label">Support Email</label>
                    <input type="email" name="support_email" class="form-control"
                           value="<?= htmlspecialchars($s['support_email'] ?? '') ?>"
                           placeholder="support@stratflow.io">
                    <small class="text-muted">Shown to users in billing and error messages.</small>
                </div>
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label class="form-label">Mail From Name</label>
                    <input type="text" name="mail_from_name" class="form-control"
                           value="<?= htmlspecialchars($s['mail_from_name'] ?? 'StratFlow') ?>"
                           placeholder="StratFlow">
                    <small class="text-muted">Display name on outbound emails.</small>
                </div>
            </div>
        </section>

    </div><!-- /grid -->

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
