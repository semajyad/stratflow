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
                class="btn btn-secondary btn-sm"
                onclick="copyToken()"
                id="copy-btn">
                Copy
            </button>
        </div>
        <p class="text-muted" style="font-size:0.8rem;margin-top:0.5rem;">
            Add to <code>.mcp.json</code>: <code>"STRATFLOW_TOKEN": "<?= htmlspecialchars($new_token_raw, ENT_QUOTES, 'UTF-8') ?>"</code>
        </p>
    </div>
</section>
<script>
function copyToken() {
    var input = document.getElementById('new-token-value');
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = document.getElementById('copy-btn');
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
    }).catch(function() {
        input.select();
        document.execCommand('copy');
    });
}
</script>
<?php endif; ?>

<?php if (($user['role'] ?? '') === 'developer' && empty($tokens) && empty($new_token_raw)): ?>
<!-- Developer onboarding: shown only on first visit before any token exists -->
<section class="card mb-4" style="border:2px solid var(--primary,#2563eb);background:var(--surface-2,#f8fafc);">
    <div class="card-body">
        <h2 class="card-title" style="font-size:1rem;margin-bottom:0.5rem;">Set up Claude Code integration</h2>
        <p style="font-size:0.875rem;margin-bottom:1rem;">
            Create a token below, then add this snippet to your project's <code>.mcp.json</code> file:
        </p>
        <pre style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:6px;padding:1rem;font-size:0.8rem;overflow-x:auto;margin-bottom:0.75rem;">{
  "mcpServers": {
    "stratflow": {
      "command": "npx",
      "args": ["-y", "stratflow-mcp"],
      "env": {
        "STRATFLOW_URL": "<?= htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') ?>",
        "STRATFLOW_TOKEN": "&lt;paste your token here&gt;"
      }
    }
  }
}</pre>
        <p style="font-size:0.8rem;color:var(--text-muted);">
            Once configured, use <code>list_my_stories</code>, <code>get_story</code>, <code>start_story</code>, and <code>complete_story</code> directly in Claude Code.
        </p>
    </div>
</section>
<?php endif; ?>

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
                                <form method="POST" action="/app/account/tokens/<?= (int) $token['id'] ?>/revoke"
                                      onsubmit="return confirm('Revoke this token? Any tools using it will stop working immediately.');">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
