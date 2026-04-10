# OKR / KR Progress Tracking & Executive Dashboard Redesign

**Date:** 2026-04-10  
**Status:** Approved — ready for implementation planning  
**Approach:** Incremental — data model + exec dashboard first, AI scoring layered on top

---

## Problem

StratFlow's executive dashboard shows Jira sync stats and generic project health. Executives don't care about sync counts — they care about whether the company is moving towards its strategic objectives. There is no way to see, at a glance, whether a Key Result is on track, what engineering work is contributing to it, or which risks threaten it.

---

## Goals

1. Introduce Key Results as a first-class entity, hanging off existing OKR work items.
2. Track which merged PRs contribute to each KR (explicit `SF-123` tags + AI fallback matching).
3. AI-score each contributing PR against each KR to produce a "value delivered" signal.
4. Redesign the executive dashboard: OKRs front and centre, KR progress bars, linked risks and dependencies, AI momentum summaries.
5. Sync KRs with Atlassian Goals (extending existing `pushOkrsToGoals` flow).

---

## Out of Scope (deferred)

- Real-time KR value updates from external data sources (analytics, Mixpanel, etc.)
- Two-way KR sync with Jira (push only for MVP)
- Commit-level granularity (PRs are the unit of delivery for MVP)
- AI confidence threshold UI / manual review queue for low-confidence matches
- Notifications when a KR status changes

---

## Data Model

### `key_results` table

One row per Key Result. Each KR belongs to one `hl_work_item` (the parent OKR).

```sql
CREATE TABLE key_results (
  id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  org_id           INT UNSIGNED     NOT NULL,
  hl_work_item_id  INT UNSIGNED     NOT NULL,
  title            VARCHAR(500)     NOT NULL,
  metric_description TEXT           NULL,
  baseline_value   DECIMAL(12,4)    NULL,
  target_value     DECIMAL(12,4)    NULL,
  current_value    DECIMAL(12,4)    NULL,
  unit             VARCHAR(50)      NULL,        -- "%", "NPS", "ms", "users"
  status           ENUM('not_started','on_track','at_risk','off_track','achieved')
                                    NOT NULL DEFAULT 'not_started',
  jira_goal_id     VARCHAR(255)     NULL,
  jira_goal_url    VARCHAR(500)     NULL,
  ai_momentum      TEXT             NULL,        -- AI summary, refreshed on each PR merge
  display_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_org (org_id),
  KEY ix_work_item (hl_work_item_id),
  CONSTRAINT fk_kr_work_item FOREIGN KEY (hl_work_item_id)
    REFERENCES hl_work_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `key_result_contributions` table

One row per (merged PR × KR) scored by AI. The PR is identified via `story_git_links.id`.

```sql
CREATE TABLE key_result_contributions (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  key_result_id       INT UNSIGNED  NOT NULL,
  story_git_link_id   INT UNSIGNED  NOT NULL,
  org_id              INT UNSIGNED  NOT NULL,
  ai_relevance_score  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0–10
  ai_rationale        TEXT          NULL,
  scored_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_kr_link (key_result_id, story_git_link_id),
  KEY ix_org (org_id),
  CONSTRAINT fk_krc_kr   FOREIGN KEY (key_result_id)     REFERENCES key_results(id)    ON DELETE CASCADE,
  CONSTRAINT fk_krc_link FOREIGN KEY (story_git_link_id) REFERENCES story_git_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `story_git_links` amendment

Add `ai_matched TINYINT(1) NOT NULL DEFAULT 0` — distinguishes AI-inferred links from explicit `SF-123` references.

---

## GitHub Integration & AI PR Matching

### Existing flow (unchanged)

`GitWebhookController::receiveGitHub()` → `GitLinkService::linkFromPrBody()` parses `SF-123` patterns → writes `story_git_links`. On close/merge → `updateStatusByRefUrl()` sets status to `merged` or `closed`.

### New: AI fallback matching (`GitPrMatcherService`)

Fires after `linkFromPrBody` when it returns 0 links (no explicit tag found) **and** the PR action is `opened` or `reopened`.

1. Load the org's open `user_stories` and `hl_work_items` — title + description only (no full content dump).
2. Send to Gemini: PR title, PR description, branch name, and the candidate item list.
3. Gemini returns candidate IDs with confidence 0.0–1.0.
4. Auto-link items with confidence ≥ 0.7, setting `ai_matched = 1`.
5. Items below 0.7 are silently skipped for MVP.

Prompt lives in `src/Services/Prompts/GitPrMatchPrompt.php` (constant), per project conventions.

### New: KR scoring (`KrScoringService`)

Fires after any PR transitions to `merged` status and has ≥ 1 linked story.

1. Resolve all `hl_work_items` touched by the PR (direct links + parent items of linked user stories).
2. Load `key_results` rows for those work items.
3. For each KR: send KR title, metric description, target value, and PR details (title + description) to Gemini.
4. Gemini returns `{ score: 0–10, rationale: "..." }`.
5. Upsert `key_result_contributions` row.
6. Recompute `key_results.ai_momentum`: summarise the last 10 contributions for that KR into one short paragraph.

Both services fire asynchronously after the webhook response is sent (`ignore_user_abort(true)` + `fastcgi_finish_request()` pattern, consistent with existing async Gemini calls in the codebase).

**Confidence thresholds are constants** in each service class — easy to tune without code changes to callers.

---

## KR Management UI (Work Item Detail Page)

PMs add/edit/reorder KRs on the work item detail page. Execs do not manage KRs.

- Inline table below the work item description: columns for KR title, baseline, current, target, unit, status.
- Add row / delete row controls.
- `current_value` is manually updated by the PM; AI momentum is read-only.
- Save via existing work item update flow (new `KrController` handles KR-specific CRUD).

---

## Executive Dashboard Redesign

Route: `GET /app/projects/{id}/executive` (replaces existing template).

### Layout

**Top strip**
- Project name + last-updated timestamp
- Health summary: `X on track · Y at risk · Z off track` across all OKRs
- Project selector dropdown

**OKR cards** (one per `hl_work_item` with a non-empty `okr_title`, ordered by `priority_number`)

Each card:

```
┌─────────────────────────────────────────────────────────────┐
│ [Status badge]  OKR Title                          [▼ / ▲] │
├─────────────────────────────────────────────────────────────┤
│ KEY RESULTS                                                  │
│  ● KR 1 title                              [on track]       │
│    ░░░░░░░░░░░░░░░░░░░░▓▓▓▓▓▓▓▓▓░░░░░  42% → 60% target   │
│    "2 PRs this sprint reduced checkout steps from 5 to 3"   │
│    [+ 3 contributing PRs ▸]                                 │
│  ● KR 2 title                              [at risk]        │
│    ░░░░░░▓▓░░░░░░░░░░░░░░░░░░░░░░░░░  12% → 50% target     │
│    "No PRs linked this sprint"                               │
├─────────────────────────────────────────────────────────────┤
│ RISKS                    │ DEPENDENCIES                     │
│  🔴 Payment timeout risk │  ← Blocked by: Auth overhaul    │
│  🟡 API rate limits      │  → Blocks: Mobile release       │
└─────────────────────────────────────────────────────────────┘
```

**KR progress bar** spans baseline → target, with current value plotted proportionally. Shows numeric value + unit.

**Contributing PRs** (expandable inline): PR title, repo, merged date, AI score badge, AI rationale one-liner.

**OKR card status** is the worst-performing KR status (if any KR is `off_track`, the OKR is `off_track`).

**Risks** — pulled from `risk_item_links` → `risks` for this work item. Title + severity badge only.

**Dependencies** — from `hl_item_dependencies`. Show direction (blocks / blocked-by) + other item title + status badge.

The page is **read-only**. No sync controls, no Jira buttons. Clean for exec consumption.

---

## Jira Goals Sync Extension

Existing `pushOkrsToGoals()` in `JiraSyncService` pushes OKR titles to Atlassian Goals. Extension:

- After creating/finding a Goal for an OKR, iterate its `key_results` and push each as a **child goal** (Atlassian Goals supports nested goals).
- Store the returned `jira_goal_id` on the `key_results` row.
- A new `pullKrStatusFromGoals()` method reads back goal status from Atlassian and syncs to `key_results.status` — one-way pull to keep StratFlow as the source of truth for AI-derived progress, with Jira as a secondary signal.

---

## Implementation Phases

### Phase 1 — Data model + KR management + exec dashboard shell
- Migration: `key_results`, `key_result_contributions`, `ai_matched` column
- `KeyResult` model (CRUD)
- `KrController` (CRUD endpoints, scoped by org_id)
- KR inline editor on work item detail page
- Executive dashboard redesign — OKR cards with KR progress bars (manual `current_value` only, no AI yet)
- Jira Goals sync extension (KRs as child goals)

### Phase 2 — AI matching + KR scoring
- `GitPrMatcherService` with Gemini prompt
- `KrScoringService` with Gemini prompt
- Hook both into `GitWebhookController` post-merge flow
- `ai_momentum` field populated and surfaced on exec dashboard
- Contributing PRs expandable section wired up

---

## Security

- Every query on `key_results` and `key_result_contributions` filters `org_id`.
- `KrController` verifies `project.org_id = session org_id` before any KR write.
- AI prompts never include secrets, tokens, or PII — only titles, descriptions, and numeric values.
- Gemini responses are treated as untrusted: scores are clamped to 0–10, rationale is stored as text and escaped on output.

---

## Testing

- `KeyResultTest` — CRUD, org isolation, cascade delete when work item deleted
- `GitPrMatcherServiceTest` — returns empty array when Gemini unavailable; does not auto-link below 0.7 threshold; marks links `ai_matched=1`
- `KrScoringServiceTest` — scores clamped to 0–10; does not score if no KRs exist for work item; org isolation (PR from org A does not score org B's KRs)
- `ExecutiveDashboardTest` — OKR cards appear only for work items with `okr_title`; worst-KR status rolls up to OKR badge; risks and dependencies render correctly
