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

<!-- Claude Code MCP setup guide — always visible, collapsible -->
<section class="card mb-4">
    <button type="button"
            class="js-toggle-mcp-guide access-token-guide-toggle">
        <div class="access-token-guide-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary,#2563eb)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span class="access-token-guide-label">Claude Code MCP setup</span>
        </div>
        <svg id="mcp-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="access-token-guide-chevron<?= empty($tokens) ? '' : ' mcp-chevron--expanded' ?>">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </button>
    <div id="mcp-guide" class="access-token-guide<?= empty($tokens) ? '' : ' hidden' ?>">
        <div class="card-body access-token-guide-body">

            <div>
                <p class="access-token-step-copy"><strong>Step 1</strong> — Create a token above, then add this to your project's <code>.mcp.json</code>:</p>
                <div class="access-token-snippet-wrap">
                    <pre id="mcp-snippet" class="access-token-snippet">{
  "mcpServers": {
    "stratflow": {
      "command": "npx",
      "args": ["-y", "stratflow-mcp"],
      "env": {
        "STRATFLOW_URL": "<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>",
        "STRATFLOW_TOKEN": "<?= $new_token_raw ? htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') : '&lt;your-token&gt;' ?>"
      }
    }
  }
}</pre>
                    <button type="button" class="btn btn-secondary btn-sm js-copy-text access-token-copy-btn" data-copy-target-id="mcp-snippet" data-copy-default-label="Copy" id="copy-snippet-btn">Copy</button>
                </div>
            </div>

            <div>
                <p class="access-token-step-copy"><strong>Step 2</strong> — Restart Claude Code. The following tools will be available in any project:</p>
                <div class="access-token-tools-grid">
                    <?php foreach ([
                        ['list_my_stories',   'Stories assigned to you'],
                        ['list_team_stories', 'Unassigned stories for your team'],
                        ['claim_story',       'Assign + start in one step'],
                        ['get_story',         'Full story + AC + KR + git links'],
                        ['start_story',       'Set status → in progress'],
                        ['complete_story',    'Set status → in review'],
                    ] as [$tool, $desc]): ?>
                    <div class="access-token-tool-card">
                        <code class="access-token-tool-name"><?= $tool ?></code>
                        <p class="access-token-tool-copy"><?= $desc ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <p class="access-token-footnote">
                When you run <code>start_story</code>, a git hook is automatically installed in your repo. Every commit will include <code>Refs SF-{id}</code> until you run <code>complete_story</code> — no manual copy-paste needed.
            </p>

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
