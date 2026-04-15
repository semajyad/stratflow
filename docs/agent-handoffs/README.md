# Agent Handoffs

This directory holds handoff documents written by agents (or humans) at the end of a work session.
The purpose is continuity: any agent starting a follow-on session can read the relevant handoff
and understand exactly where things were left.

---

## When to Write a Handoff

Write a handoff when:
- You are ending a session with work in progress (PR open but not merged).
- You made a significant decision that affects future work on the same area.
- You hit a dead-end or discovered a constraint that the next agent should know.
- You are explicitly handing off to another agent.

No handoff needed if:
- Your PR merged and there is no follow-on work.
- The session was purely exploratory and nothing was changed.

---

## File Naming

```
YYYY-MM-DD-<slug>.md
```

Examples:
```
2026-04-15-fix-stale-review-dedup.md
2026-04-15-add-stripe-webhook.md
2026-04-14-playwright-quarantine-setup.md
```

Use the same slug as your branch name's `<slug>` segment.

---

## Handoff Format

```markdown
# Handoff: <Short Title>

**Date:** YYYY-MM-DD
**Agent/Author:** <agent-id or name>
**Branch:** agent/<session-id>/<slug>
**PR:** #N (or "not yet opened")
**Status:** in-progress | blocked | ready-to-merge | abandoned

## What Was Done

<2-5 bullet points describing completed work>

## What Remains

<Specific next steps, ordered by priority>

## Decisions Made

<Any architectural or approach decisions made during this session that should persist>

## Known Issues / Gotchas

<Things the next agent should watch out for — don't omit these>

## Context Files

<List of files that are most relevant to understanding the state of the work>
```

---

## Commit Trailer Schema

Use these trailers in the final commit of a session (or any commit where they apply).
They are machine-readable and indexed by the nightly-triage workflow.

| Trailer | Format | Meaning |
|---|---|---|
| `Decision:` | `Decision: <plain text>` | Architectural or approach choice that should not be re-litigated |
| `Follow-up:` | `Follow-up: <plain text>` | Work that was not completed and should be picked up |
| `Known-issue:` | `Known-issue: <plain text>` | Bug or limitation the author is aware of but not fixing |
| `Blocks:` | `Blocks: #N` | This commit blocks or supersedes another PR or issue |
| `Resolves:` | `Resolves: #N` | Standard GitHub closes-issue trailer |

Multiple trailers of the same type are allowed. Each must be on its own line.

Example commit:

```
fix: pre-filter COMMENTED reviews before group_by in auto-merge

The root cause was decisive vs non-decisive review state confusion.
Only APPROVED and CHANGES_REQUESTED are decisive; COMMENTED is advisory.

Decision: pre-filter to decisive states before group_by — simpler than post-filter.
Follow-up: add unit tests for DISMISSED review edge case.
Known-issue: APPROVED after CHANGES_REQUESTED from the same user not yet handled.
Resolves: #17
```

---

## Reading Handoffs

At session start, `session-start.py` automatically prints recent handoffs related to the files
in your declared scope. To read them directly:

```bash
ls docs/agent-handoffs/
# Read the relevant one
cat docs/agent-handoffs/2026-04-15-fix-stale-review-dedup.md
```
