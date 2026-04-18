# StratFlow Agent Rules

These rules mirror the Claude Code workflow so Codex, Claude, and other coding
agents operate with the same guardrails.

## Startup

- Start with `MEMORY.md`, then open only the smallest relevant doc set.
- If the task touches auth, permissions, sessions, secrets, uploads, billing,
  webhooks, external providers, HTTP headers, middleware, controllers, or
  templates, read `docs/SECURE_CODING.md` before editing.
- For codebase work, call vexp `run_pipeline` first and prefer targeted context
  reads after that.
- If the task spans more than 3 files, write a short plan before editing.

## Workflow

- Keep changes scoped and atomic.
- Rebase from `origin/main`; do not merge `main` into a feature branch.
- Use `python scripts/agent/session-start.py --agent-id <id> --goal "<goal>" --files "<glob>"`
  when starting a fresh agent session.
- Prefer `python scripts/agent/safe-commit.py -m "..." <explicit files>` over raw
  `git commit`.
- Before assuming work is lost, run `python scripts/agent/recover.py`.

## Required Checks

- Before any user-facing commit, do a quick browser sanity check of the changed
  feature.
- Every major user-facing flow needs at least one Playwright or integration test.
- Every modified `src/**/*.php` file needs a corresponding unit test unless a
  documented exemption is used.
- PHP/template commits are blocked unless fresh review markers exist:
  `.claude/.security-audit-ok`, `.claude/.review-ok`, and `.claude/.playwright-ok`.
  Touch these only after the matching review or test has actually been done.
- Shared pre-commit hooks enforce PHP lint, security rules, coverage, bug-test,
  test-touch, docs sync, and the agent marker gates.

## Security Rules

- Keep tenant-scoped queries filtered by `org_id`.
- Use prepared statements only.
- Escape user-visible template output with
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Keep CSRF protection on every state-changing browser route except verified
  webhooks.
- Never log secrets, password-reset URLs, raw tokens, provider credentials, or raw
  API responses.
- Hash recovery tokens at rest; encrypt stored provider/customer secrets via
  `SecretManager`.
- Deny inactive users on every auth path and fail closed on unclear authorization
  checks.
- Prefer delegated JavaScript in shared bundles; do not introduce inline handlers
  or CSP regressions.
- Every early-exit path must call `Response::applySecurityHeaders()`.
- Never use `filemtime()` or `time()` as asset cache-busters in templates; use
  `ASSET_VERSION`.
- New binary files must be marked `binary` in `.gitattributes`; prefer SVG for
  logos and icons.

## PR Rules

- For security-touching PRs, include `## Security notes` with 3-5 bullets covering
  data access, abuse risk, and controls.
- Record non-obvious CI, security, or test failures with
  `python scripts/ci/record_learning.py`.
- If GitHub `pull_request:synchronize` does not fire, trigger Tests manually with
  `gh workflow run tests.yml --repo semajyad/stratflow --ref <branch>`.

## Useful Commands

```bash
sh scripts/install-hooks.sh
python scripts/ci/check_security_rules.py --staged
python scripts/ci/check_agent_commit_gates.py --staged
python scripts/ci/check_test_touches.py --staged
python scripts/ci/check_docs.py --staged
docker compose exec php vendor/bin/phpunit
```
