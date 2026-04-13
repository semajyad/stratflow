# .claude — StratFlow AI Tooling

This directory contains Claude Code hooks, subagent definitions, and skills
for the StratFlow project. New engineers: read this before touching any hooks.

---

## Hooks (`hooks/`)

Hooks run automatically at specific Claude Code lifecycle events.

| File | Event | Purpose |
|---|---|---|
| `session_start_check.py` | SessionStart | Checks .env keys, Shannon/ZAP report freshness, HIGH/MEDIUM finding counts, and unapplied migrations |
| `vexp-guard.sh` | PreToolUse (Grep\|Glob) | Blocks raw grep/glob when vexp daemon is running — forces `run_pipeline` instead |
| `pre_bash_guard.py` | PreToolUse (Bash) | Blocks destructive shell commands (rm -rf, drop table, force push) |
| `pre_test_filter.py` | PreToolUse (Bash) | Rewrites PHPUnit invocations to show only failures, reducing output tokens |
| `pre_commit_gate.py` | PreToolUse (Bash) | Unified commit gate: requires security audit + code review + Playwright markers (5-min TTL each) before `git commit` |
| `post_edit_php_lint.py` | PostToolUse (Edit\|Write) | Runs `php -l` on every PHP edit. Uses host PHP if available, falls back to Docker |
| `post_edit_security_rules.py` | PostToolUse (Edit\|Write) | Enforces 4 ZAP-sourced security rules at edit time (unsafe-inline, filemtime, SRI, XSS echo) |
| `stop_session_log.py` | Stop | Appends a one-line session summary to `.claude/session-log.md` |
| `pre_compact_inject.py` | PreCompact | Injects the 5-item security checklist into the compaction prompt so rules survive compression |

### Commit gate markers

The `pre_commit_gate.py` hook requires three marker files to be present and
< 5 minutes old before allowing a commit on PHP files:

| Marker | Created by |
|---|---|
| `.claude/.security-audit-ok` | `security-auditor` subagent |
| `.claude/.review-ok` | `code-reviewer` subagent |
| `.claude/.playwright-ok` | `playwright-tester` subagent |

Touch the marker manually only if you've addressed all findings and want to
bypass the relevant gate: `touch .claude/.security-audit-ok`

---

## Subagents (`agents/`)

Subagents are invoked by Claude as part of the commit workflow or on demand.

| File | Purpose |
|---|---|
| `security-auditor.md` | Reviews staged diff for CRITICAL and HIGH security issues |
| `code-reviewer.md` | Reviews staged diff for code quality, return types, silent exceptions |
| `playwright-tester.md` | Runs appropriate Playwright test tier and writes `.playwright-ok` |
| `migration-reviewer.md` | Reviews new SQL migrations for safety (nullable columns, index gaps, destructive ops) |
| `route-auditor.md` | Audits all routes for missing CSRF, org_id scoping, and auth checks |
| `dependency-auditor.md` | Runs `composer audit` and checks for known-vulnerable packages |
| `performance-reviewer.md` | Reviews query plans, N+1 patterns, and missing indexes |
| `performance-report-generator.md` | Generates a structured performance report for a project area |
| `security-report-generator.md` | Synthesises Shannon + ZAP findings into a readable summary |
| `git-secret-scan.md` | Scans git history for accidentally committed secrets |
| `caiq-responder.md` | Answers CAIQ (Cloud Security Alliance) questionnaire items |

---

## Skills (`skills/`)

Skills are invoked with the `Skill` tool when a specific task matches.

| Skill | When to invoke |
|---|---|
| `php-conventions` | Writing or editing any PHP file — enforces PSR-12 and StratFlow MVC idioms |
| `security-policy-pack` | Generating compliance/security policy documents |
| `snyk-scan` | Running a manual Snyk vulnerability scan |
| `stripe-webhook-handler` | Adding or modifying Stripe webhook handlers |

---

## Files

| File | Purpose |
|---|---|
| `settings.json` | Claude Code hook wiring (events → hook scripts) |
| `CLAUDE.md` | vexp pipeline instructions (mandatory context tool) |
| `session-log.md` | Auto-generated session audit trail (one line per session) |
| `.security-audit-ok` | Ephemeral commit gate marker (consumed on commit) |
| `.review-ok` | Ephemeral commit gate marker (consumed on commit) |
| `.playwright-ok` | Ephemeral commit gate marker (consumed on commit) |
| `.last-migration` | Tracks last applied migration for session-start freshness check |

---

## GitHub Actions (`../.github/workflows/`)

| Workflow | Trigger | Purpose |
|---|---|---|
| `tests.yml` | push/PR to main | PHPUnit + PHPStan + `composer audit` + PHP lint |
| `claude-pr-review.yml` | PR to main | Claude reviews PHP diff, posts/updates single comment |
| `secret-scan.yml` | push/PR to main | gitleaks — fails build on any committed secret |
| `snyk.yml` | daily + manual | Snyk PHP CVE scan → GitHub code scanning |
| `security-shannon.yml` | daily + manual | Shannon overnight pen test → `security-reports/shannon-latest.md` |
| `security-zap.yml` | PR + daily + manual | ZAP baseline scan → `security-reports/zap-latest.md` |
| `branch-protection-check.yml` | daily | Asserts required status checks are configured on `main` |
