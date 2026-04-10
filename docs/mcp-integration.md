# StratFlow MCP Integration

Connects Claude Code (and other MCP-compatible IDEs) directly to StratFlow user stories — no copy-pasting.

## How it works

1. You create a Personal Access Token in StratFlow.
2. You configure Claude Code to run the `stratflow-mcp` MCP server with that token.
3. From your IDE you can list stories, read full context, update status, and get branch/commit suggestions.
4. Git commits and PRs containing `SF-{id}` auto-link back to the story via the existing GitHub App webhook.

## 1. Create a Personal Access Token

1. Log in to StratFlow.
2. Click **Developer Tokens** in the sidebar (or go to `/app/account/tokens`).
3. Enter a name (e.g. `laptop-claude-code`) and click **Generate token**.
4. Copy the `sf_pat_...` value — **it is shown only once**.

## 2. Configure Claude Code

Create or edit `.mcp.json` in your project root (or `~/.claude.json` for global config):

```json
{
  "mcpServers": {
    "stratflow": {
      "command": "npx",
      "args": ["-y", "stratflow-mcp"],
      "env": {
        "STRATFLOW_URL": "https://your-stratflow.example.com",
        "STRATFLOW_TOKEN": "sf_pat_..."
      }
    }
  }
}
```

Restart Claude Code. The `stratflow` server will appear automatically.

## 3. Developer workflow

### Start a story
```
list_my_stories                        → see your backlog
get_story({ id: 42 })                  → read SF-42 description, AC, KR hypothesis
start_story({ id: 42 })                → mark in_progress, get branch suggestion
git checkout -b sf-42-add-payment-flow
```

### Implement
Work as normal. Claude Code has the story context in-session.

### Commit with auto-link
```bash
git commit -m "feat: add payment flow

Refs SF-42"
git push origin sf-42-add-payment-flow
```

The GitHub App webhook picks up `SF-42` in the commit message and creates a link in StratFlow automatically.

### Mark as in review
```
complete_story({ id: 42 })   → status → in_review
```

Raise a PR — include `Refs SF-42` in the PR description and StratFlow links it too.

## Available MCP tools

| Tool | Description |
|------|-------------|
| `list_my_stories` | Stories assigned to you. Params: `status`, `project_id`, `limit`. |
| `get_story` | Full story context: description, AC, KR hypothesis, parent, sprint, git links. |
| `start_story` | Set status to `in_progress`. Returns branch name suggestion. |
| `complete_story` | Set status to `in_review`. |

## Story resource URIs

Attach a story as a resource in Claude Code using:

```
stratflow://story/42
```

## Troubleshooting

**"STRATFLOW_TOKEN is not set"** — Check the env block in `.mcp.json`.

**"Token invalid or expired"** — Regenerate at `/app/account/tokens` and update `STRATFLOW_TOKEN`.

**"Story not found or not in your organisation"** — The story ID doesn't exist or you don't have access.

**Network errors** — Ensure `STRATFLOW_URL` is reachable from your machine (VPN, firewall, etc.).
