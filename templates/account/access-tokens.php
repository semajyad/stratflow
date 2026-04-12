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
<section class="card mb-4" style="border:2px solid var(--success, #16a34a);">
    <div class="card-body">
        <h2 class="card-title" style="color:var(--success,#16a34a);font-size:1rem;margin-bottom:0.5rem;">
            Token created — copy it now
        </h2>
        <p class="text-muted" style="font-size:0.875rem;margin-bottom:0.75rem;">
            This is the only time the token will be shown. Store it in a safe place such as your shell profile or a password manager.
        </p>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <input
                id="new-token-value"
                type="text"
                readonly
                value="<?= htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') ?>"
                style="font-family:monospace;font-size:0.85rem;flex:1;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:6px;background:var(--surface-2,#f8fafc);">
            <button
                type="button"
                class="btn btn-secondary btn-sm js-copy-text"
                data-copy-target-id="new-token-value"
                data-copy-default-label="Copy"
                id="copy-btn">
                Copy
            </button>
        </div>
        <p class="text-muted" style="font-size:0.8rem;margin-top:0.5rem;">
            Add to <code>.mcp.json</code>: <code>"STRATFLOW_TOKEN": "<?= htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') ?>"</code>
        </p>
    </div>
</section>
<?php endif; ?>

<!-- Team membership — used by list_team_stories MCP tool -->
<section class="card mb-4">
    <div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <label for="user-team" style="display:block;font-weight:600;font-size:0.875rem;margin-bottom:0.25rem;">Your team</label>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 0.5rem;">Used by <code>list_team_stories</code> in Claude Code to show your team's backlog.</p>
        </div>
        <form method="POST" action="/app/account/team" style="display:flex;gap:0.5rem;align-items:center;flex-shrink:0;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($team_options)): ?>
                <select id="user-team" name="team" class="form-control" style="width:220px;">
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
                       class="form-control" style="width:220px;">
            <?php endif; ?>
            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
        </form>
    </div>
</section>

<!-- Claude Code MCP setup guide — always visible, collapsible -->
<section class="card mb-4">
    <button type="button"
            class="js-toggle-mcp-guide"
            style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:none;border:none;cursor:pointer;text-align:left;">
        <div style="display:flex;align-items:center;gap:0.625rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary,#2563eb)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
            <span style="font-weight:600;font-size:0.9375rem;">Claude Code MCP setup</span>
        </div>
        <svg id="mcp-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;transition:transform 0.2s;<?= empty($tokens) ? '' : 'transform:rotate(180deg)' ?>">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </button>
    <div id="mcp-guide" style="border-top:1px solid var(--border);" class="<?= empty($tokens) ? '' : 'hidden' ?>">
        <div class="card-body" style="display:flex;flex-direction:column;gap:1rem;">

            <div>
                <p style="font-size:0.875rem;margin-bottom:0.5rem;"><strong>Step 1</strong> — Create a token above, then add this to your project's <code>.mcp.json</code>:</p>
                <div style="position:relative;">
                    <pre id="mcp-snippet" style="background:var(--surface-2,#f8fafc);border:1px solid var(--border);border-radius:6px;padding:1rem;font-size:0.78rem;overflow-x:auto;margin:0;line-height:1.6;">{
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
                    <button type="button" class="btn btn-secondary btn-sm js-copy-text" data-copy-target-id="mcp-snippet" data-copy-default-label="Copy" id="copy-snippet-btn"
                            style="position:absolute;top:0.5rem;right:0.5rem;font-size:0.75rem;padding:0.2rem 0.6rem;"
                            >Copy</button>
                </div>
            </div>

            <div>
                <p style="font-size:0.875rem;margin:0 0 0.5rem;"><strong>Step 2</strong> — Restart Claude Code. The following tools will be available in any project:</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:0.5rem;">
                    <?php foreach ([
                        ['list_my_stories',   'Stories assigned to you'],
                        ['list_team_stories', 'Unassigned stories for your team'],
                        ['claim_story',       'Assign + start in one step'],
                        ['get_story',         'Full story + AC + KR + git links'],
                        ['start_story',       'Set status → in progress'],
                        ['complete_story',    'Set status → in review'],
                    ] as [$tool, $desc]): ?>
                    <div style="background:var(--surface-2,#f8fafc);border:1px solid var(--border);border-radius:6px;padding:0.5rem 0.75rem;">
                        <code style="font-size:0.78rem;color:var(--primary-dark,#1d4ed8);"><?= $tool ?></code>
                        <p style="font-size:0.75rem;color:var(--text-muted);margin:0.15rem 0 0;"><?= $desc ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                When you run <code>start_story</code>, a git hook is automatically installed in your repo. Every commit will include <code>Refs SF-{id}</code> until you run <code>complete_story</code> — no manual copy-paste needed.
            </p>

        </div>
    </div>
</section>

<!-- Create new token form -->
<section class="card mb-4">
    <div class="card-body">
        <h2 class="card-title" style="font-size:1rem;margin-bottom:1rem;">Create new token</h2>
        <form method="POST" action="/app/account/tokens" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <div style="flex:1;min-width:200px;">
                <label for="token-name" style="display:block;font-size:0.875rem;font-weight:600;margin-bottom:0.25rem;">Token name</label>
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
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($tokens)): ?>
            <p class="empty-state" style="padding:2rem;text-align:center;color:var(--text-muted);">
                No active tokens. Create one above to connect external tools.
            </p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Name</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Prefix</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Last used</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Expires</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Created</th>
                        <th style="padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:0.5rem 0.75rem;font-weight:600;">
                                <?= htmlspecialchars($token['name'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-family:monospace;font-size:0.85rem;">
                                <?= htmlspecialchars($token['token_prefix'], ENT_QUOTES, 'UTF-8') ?>...
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-size:0.85rem;color:var(--text-muted);">
                                <?= $token['last_used_at'] ? htmlspecialchars($token['last_used_at'], ENT_QUOTES, 'UTF-8') : 'Never' ?>
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-size:0.85rem;color:var(--text-muted);">
                                <?= $token['expires_at'] ? htmlspecialchars($token['expires_at'], ENT_QUOTES, 'UTF-8') : 'No expiry' ?>
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-size:0.85rem;color:var(--text-muted);">
                                <?= htmlspecialchars($token['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="padding:0.5rem 0.75rem;text-align:right;">
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
