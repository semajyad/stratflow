<?php
/**
 * Account: Personal Access Tokens
 *
 * Allows any authenticated user to create and revoke Personal Access Tokens
 * for use with external tooling (e.g. the stratflow-mcp MCP server).
 *
 * The raw token value is shown ONCE immediately after creation (via session
 * flash). It is never displayed again — treat it like a password.
 */
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Developer Tokens</h1>
        <p class="page-subtitle">Personal access tokens authenticate external tools (e.g. Claude Code) against the StratFlow API. Treat tokens like passwords — they cannot be retrieved after creation.</p>
    </div>
</div>

<?php if (!empty($_SESSION['_flash']['error'])): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($_SESSION['_flash']['error']) ?></div>
    <?php unset($_SESSION['_flash']['error']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['_flash']['success'])): ?>
    <div class="alert alert-success mb-4"><?= htmlspecialchars($_SESSION['_flash']['success']) ?></div>
    <?php unset($_SESSION['_flash']['success']); ?>
<?php endif; ?>

<?php if (!empty($new_token_raw)): ?>
<section class="card mb-4 access-token-card--success">
    <div class="card-body">
        <h2 class="card-title access-token-title">
            Token created — copy it now
        </h2>
        <p class="text-muted access-token-copy">
            This is the only time the token will be shown. Store it in a safe place such as your shell profile or a password manager.
        </p>
        <div class="access-token-inline-row">
            <input
                id="new-token-value"
                type="text"
                readonly
                value="<?= htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') ?>"
                class="access-token-value">
            <button
                type="button"
                class="btn btn-secondary btn-sm js-copy-text"
                data-copy-target-id="new-token-value"
                data-copy-default-label="Copy"
                id="copy-btn">
                Copy
            </button>
        </div>
        <p class="text-muted access-token-copy--small">
            Add to <code>.mcp.json</code>: <code>"STRATFLOW_TOKEN": "<?= htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') ?>"</code>
        </p>
    </div>
</section>
<?php endif; ?>

<!-- Team membership — used by list_team_stories MCP tool -->
<section class="card mb-4">
    <div class="card-body access-token-team-row">
        <div class="access-token-team-copy">
            <label for="user-team" class="access-token-label">Your team</label>
            <p class="access-token-team-help">Used by <code>list_team_stories</code> in Claude Code to show your team's backlog.</p>
        </div>
        <form method="POST" action="/app/account/team" class="access-token-inline-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($team_options)): ?>
                <select id="user-team" name="team" class="form-control access-token-team-input">
                    <option value="">-- Select your team --</option>
                    <?php foreach ($team_options as $opt): ?>
                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"
                            <?= ($user['team'] ?? '') === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" id="user-team" name="team" maxlength="100"
                       value="<?= htmlspecialchars($user['team'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="e.g. Backend, Platform, Mobile"
                       class="form-control access-token-team-input">
            <?php endif; ?>
            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
        </form>
    </div>
</section>

<!-- Jira identity — who are you in Jira for story assignment -->
<section class="card mb-4">
    <div class="card-body access-token-team-row">
        <div class="access-token-team-copy">
            <label class="access-token-label">Your Jira identity</label>
            <p class="access-token-team-help">
                Links your StratFlow account to a Jira user so <code>claim_story</code> assigns
                the issue to you in Jira automatically.
            </p>
        </div>
        <?php if ($jira_connected ?? false): ?>
            <form method="POST" action="/app/account/jira-identity" class="access-token-inline-form" id="jira-identity-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="jira-identity-picker" style="position:relative;flex:1;">
                    <input type="text"
                           id="jira-identity-search"
                           class="form-control"
                           placeholder="Search Jira users..."
                           autocomplete="off"
                           value="<?= htmlspecialchars($user['jira_display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <ul id="jira-identity-suggestions" style="display:none;position:absolute;z-index:200;background:var(--bg-card,#fff);border:1px solid var(--border-color,#ddd);border-radius:6px;width:100%;max-height:220px;overflow-y:auto;margin:0;padding:0;list-style:none;box-shadow:0 4px 12px rgba(0,0,0,.12);"></ul>
                    <input type="hidden" name="jira_account_id"   id="jira-account-id"   value="<?= htmlspecialchars($user['jira_account_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="jira_display_name" id="jira-display-name" value="<?= htmlspecialchars($user['jira_display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
            </form>
            <?php if (!empty($user['jira_account_id'])): ?>
                <p class="text-muted" style="font-size:.8em;margin-top:4px;">
                    Currently linked: <strong><?= htmlspecialchars($user['jira_display_name'] ?? $user['jira_account_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                    &nbsp;<a href="#" id="clear-jira-identity" style="color:var(--danger,#dc2626);font-size:.9em;">Clear</a>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted" style="font-size:.9em;">
                <a href="/app/admin/integrations/jira/connect">Connect Jira</a> to enable Jira identity binding.
            </p>
        <?php endif; ?>
    </div>
</section>

<?php if ($jira_connected ?? false): ?>
<script>
(function () {
    const input   = document.getElementById('jira-identity-search');
    const list    = document.getElementById('jira-identity-suggestions');
    const idField = document.getElementById('jira-account-id');
    const dnField = document.getElementById('jira-display-name');
    const clearLink = document.getElementById('clear-jira-identity');
    let timer;

    if (clearLink) {
        clearLink.addEventListener('click', function (e) {
            e.preventDefault();
            input.value = ''; idField.value = ''; dnField.value = '';
            document.getElementById('jira-identity-form').submit();
        });
    }

    function fetchAndRender(q) {
        fetch('/app/account/jira/users?q=' + encodeURIComponent(q || ''))
            .then(r => r.json()).then(render).catch(() => {});
    }

    // Show list on focus — fetch all assignable users immediately
    input.addEventListener('focus', function () {
        if (list.children.length === 0) {
            fetchAndRender(this.value.trim());
        } else {
            list.style.display = 'block';
        }
    });

    input.addEventListener('input', function () {
        clearTimeout(timer);
        idField.value = ''; dnField.value = '';
        const q = this.value.trim();
        timer = setTimeout(() => fetchAndRender(q), 220);
    });

    input.addEventListener('keydown', function (e) {
        const items = [...list.querySelectorAll('li')];
        const active = list.querySelector('li.jip-active');
        const idx = items.indexOf(active);
        if (e.key === 'ArrowDown') { e.preventDefault(); items[Math.min(idx+1,items.length-1)]?.classList.add('jip-active'); active?.classList.remove('jip-active'); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); items[Math.max(idx-1,0)]?.classList.add('jip-active'); active?.classList.remove('jip-active'); }
        if (e.key === 'Enter' && active) { e.preventDefault(); pick(active.dataset); }
        if (e.key === 'Escape') { list.style.display = 'none'; }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.jira-identity-picker')) { list.style.display = 'none'; }
    });

    function render(data) {
        list.innerHTML = '';
        if (!data.users || !data.users.length) { list.style.display = 'none'; return; }
        data.users.forEach(u => {
            const li = document.createElement('li');
            li.style.cssText = 'padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;';
            li.dataset.accountId = u.accountId;
            li.dataset.displayName = u.displayName;
            li.innerHTML = (u.avatar ? `<img src="${u.avatar}" style="width:20px;height:20px;border-radius:50%;" alt="">` : '')
                + `<span style="font-size:.9em;font-weight:500;">${esc(u.displayName)}</span>`
                + (u.email ? `<span style="font-size:.8em;color:var(--text-muted,#888);margin-left:4px;">${esc(u.email)}</span>` : '');
            li.addEventListener('mouseenter', () => { list.querySelectorAll('li').forEach(i => i.classList.remove('jip-active')); li.classList.add('jip-active'); });
            li.addEventListener('click', () => pick(li.dataset));
            list.appendChild(li);
        });
        list.style.display = 'block';
    }

    function pick(d) {
        input.value = d.displayName; idField.value = d.accountId; dnField.value = d.displayName;
        list.style.display = 'none';
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
<?php endif; ?>

<?php
$mcp_token = $new_token_raw
    ? htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8')
    : '&lt;your-token&gt;';
$mcp_url = htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8');
?>

<!-- MCP Setup Guides -->
<section class="card mb-4">
    <div class="card-body pb-2">
        <h2 class="card-title access-token-form-title">MCP Setup Guides</h2>
        <p class="text-muted" style="font-size:.875rem;margin-bottom:0;">
            Connect StratFlow to your AI coding tool. Create a token above, then follow the guide for your IDE.
        </p>
    </div>

    <?php
    $mcp_accordions = [
        // IMPORTANT: 'sublabel', 'content', and 'logo' are developer-defined static HTML strings.
        // They MUST NOT be sourced from user input or the database — they are rendered unescaped.
        [
            'id'      => 'copilot',
            'label'   => 'GitHub Copilot',
            'sublabel'=> 'VS Code &middot; <code>.vscode/mcp.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r=".5" fill="currentColor"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to your project\'s <code>.vscode/mcp.json</code>:</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-copilot" class="access-token-snippet">{'
                . "\n  \"servers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"type\": \"stdio\","
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-copilot" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Reload VS Code. StratFlow tools will appear in Copilot Chat.</p>',
        ],
        [
            'id'      => 'claude-code',
            'label'   => 'Claude Code',
            'sublabel'=> 'Project <code>.mcp.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary,#2563eb)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to your project\'s <code>.mcp.json</code>:</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-claude-code" class="access-token-snippet">{'
                . "\n  \"mcpServers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-claude-code" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Restart Claude Code. StratFlow tools will be available in any project.</p>'
                . '<div class="access-token-tools-grid">'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">list_my_stories</code><p class="access-token-tool-copy">Stories assigned to you</p></div>'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">list_team_stories</code><p class="access-token-tool-copy">Unassigned stories for your team</p></div>'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">claim_story</code><p class="access-token-tool-copy">Assign + start in one step</p></div>'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">get_story</code><p class="access-token-tool-copy">Full story + AC + KR + git links</p></div>'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">start_story</code><p class="access-token-tool-copy">Set status &rarr; in progress</p></div>'
                . '<div class="access-token-tool-card"><code class="access-token-tool-name">complete_story</code><p class="access-token-tool-copy">Set status &rarr; in review</p></div>'
                . '</div>'
                . '<p class="access-token-footnote">When you run <code>start_story</code>, a git hook is automatically installed in your repo. Every commit will include <code>Refs SF-{id}</code> until you run <code>complete_story</code>.</p>',
        ],
        [
            'id'      => 'cursor',
            'label'   => 'Cursor',
            'sublabel'=> '<code>~/.cursor/mcp.json</code> or project <code>.cursor/mcp.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 8h8M8 12h5"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to <code>~/.cursor/mcp.json</code> (global) or <code>.cursor/mcp.json</code> (project):</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-cursor" class="access-token-snippet">{'
                . "\n  \"mcpServers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-cursor" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Restart Cursor. StratFlow tools appear under MCP in the Cursor settings panel.</p>',
        ],
        [
            'id'      => 'windsurf',
            'label'   => 'Windsurf',
            'sublabel'=> '<code>~/.codeium/windsurf/mcp_config.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3L4 9v12h16V9L12 3z"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to <code>~/.codeium/windsurf/mcp_config.json</code>:</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-windsurf" class="access-token-snippet">{'
                . "\n  \"mcpServers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-windsurf" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Restart Windsurf. The StratFlow MCP server will appear in Cascade\'s tool panel.</p>',
        ],
        [
            'id'      => 'amazonq',
            'label'   => 'Amazon Q Developer',
            'sublabel'=> '<code>~/.aws/amazonq/mcp.json</code> or project <code>.amazonq/mcp.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to <code>~/.aws/amazonq/mcp.json</code> (global) or <code>.amazonq/mcp.json</code> (project):</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-amazonq" class="access-token-snippet">{'
                . "\n  \"mcpServers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-amazonq" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Restart your IDE. StratFlow tools will appear in the Amazon Q chat panel.</p>',
        ],
        [
            'id'      => 'jetbrains',
            'label'   => 'JetBrains AI Assistant',
            'sublabel'=> 'IntelliJ IDEA &middot; PyCharm &middot; WebStorm &middot; configured via IDE UI',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="2"/><rect x="6" y="6" width="4" height="4"/><rect x="14" y="6" width="4" height="4"/><rect x="6" y="14" width="12" height="4"/></svg>',
            'content' => '<p class="access-token-step-copy">JetBrains is configured through the IDE settings UI, not a config file.</p>'
                . '<ol class="access-token-step-list">'
                . '<li>Open <strong>Settings</strong> (&#8984;, on Mac / Ctrl+Alt+S on Windows)</li>'
                . '<li>Navigate to <strong>Tools &rarr; AI Assistant &rarr; Model Context Protocol</strong></li>'
                . '<li>Click <strong>+</strong> &rarr; <strong>Add new MCP server</strong></li>'
                . '<li>Set <strong>Name</strong> to <code>stratflow</code></li>'
                . '<li>Set <strong>Command</strong> to <code>npx</code></li>'
                . '<li>Set <strong>Arguments</strong> to <code>-y stratflow-mcp</code></li>'
                . '<li>Add environment variables:<br><code>STRATFLOW_URL</code> = <code>' . $mcp_url . '</code><br><code>STRATFLOW_TOKEN</code> = <code>' . $mcp_token . '</code></li>'
                . '<li>Click <strong>OK</strong>, then restart the IDE</li>'
                . '</ol>'
                . '<p class="access-token-footnote">Requires JetBrains IDE 2025.1 or later with AI Assistant plugin installed.</p>',
        ],
        [
            'id'      => 'claude-desktop',
            'label'   => 'Claude Desktop',
            'sublabel'=> 'macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>',
            'logo'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary,#2563eb)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>',
            'content' => '<p class="access-token-step-copy"><strong>Step 1</strong> &mdash; Create a token above, then add this to your Claude Desktop config file:</p>'
                . '<div class="access-token-snippet-wrap">'
                . '<pre id="mcp-snippet-claude-desktop" class="access-token-snippet">{'
                . "\n  \"mcpServers\": {"
                . "\n    \"stratflow\": {"
                . "\n      \"command\": \"npx\","
                . "\n      \"args\": [\"-y\", \"stratflow-mcp\"],"
                . "\n      \"env\": {"
                . "\n        \"STRATFLOW_URL\": \"" . $mcp_url . "\","
                . "\n        \"STRATFLOW_TOKEN\": \"" . $mcp_token . "\""
                . "\n      }"
                . "\n    }"
                . "\n  }"
                . "\n}</pre>"
                . '<button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet-claude-desktop" data-copy-default-label="Copy">Copy</button>'
                . '</div>'
                . '<p class="access-token-step-copy" style="margin-top:1rem;"><strong>Step 2</strong> &mdash; Quit and relaunch Claude Desktop. StratFlow tools will appear in the tools panel.</p>',
        ],
    ];
    ?>

    <div class="card-body pt-0">
        <div class="settings-stack">
        <?php foreach ($mcp_accordions as $accordion): ?>
        <div class="accordion-item">
            <button type="button" class="accordion-header js-accordion-toggle">
                <span class="mcp-guide-logo" aria-hidden="true"><?= $accordion['logo'] ?></span>
                <span class="mcp-guide-info">
                    <span class="mcp-guide-name"><?= htmlspecialchars($accordion['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="mcp-guide-sublabel"><?= $accordion['sublabel'] ?></span>
                </span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                     class="accordion-chevron">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="accordion-body access-token-guide-body">
                <?= $accordion['content'] ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

</section>

<!-- Create new token form -->
<section class="card mb-4">
    <div class="card-body">
        <h2 class="card-title access-token-form-title">Create new token</h2>
        <form method="POST" action="/app/account/tokens" class="access-token-create-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="access-token-create-name">
                <label for="token-name" class="access-token-label">Token name</label>
                <input
                    type="text"
                    id="token-name"
                    name="name"
                    required
                    maxlength="100"
                    placeholder="e.g. laptop-claude-code"
                    class="form-control"
                    autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary">Generate token</button>
        </form>
    </div>
</section>

<!-- Existing tokens -->
<section class="card">
    <div class="card-body access-token-table-wrap">
        <?php if (empty($tokens)): ?>
            <p class="empty-state access-token-empty">
                No active tokens. Create one above to connect external tools.
            </p>
        <?php else: ?>
            <table class="access-token-table">
                <thead>
                    <tr>
                        <th class="access-token-table-head">Name</th>
                        <th class="access-token-table-head">Prefix</th>
                        <th class="access-token-table-head">Last used</th>
                        <th class="access-token-table-head">Expires</th>
                        <th class="access-token-table-head">Created</th>
                        <th class="access-token-table-head"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <tr class="access-token-table-row">
                            <td class="access-token-table-cell access-token-table-cell--strong">
                                <?= htmlspecialchars($token['name'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="access-token-table-cell access-token-table-cell--mono">
                                <?= htmlspecialchars($token['token_prefix'], ENT_QUOTES, 'UTF-8') ?>...
                            </td>
                            <td class="access-token-table-cell access-token-table-cell--muted">
                                <?= $token['last_used_at'] ? htmlspecialchars($token['last_used_at'], ENT_QUOTES, 'UTF-8') : 'Never' ?>
                            </td>
                            <td class="access-token-table-cell access-token-table-cell--muted">
                                <?= $token['expires_at'] ? htmlspecialchars($token['expires_at'], ENT_QUOTES, 'UTF-8') : 'No expiry' ?>
                            </td>
                            <td class="access-token-table-cell access-token-table-cell--muted">
                                <?= htmlspecialchars($token['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="access-token-table-cell access-token-table-cell--actions">
                                <form method="POST" action="/app/account/tokens/<?= (int) $token['id'] ?>/revoke">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Revoke this token? Any tools using it will stop working immediately.">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
