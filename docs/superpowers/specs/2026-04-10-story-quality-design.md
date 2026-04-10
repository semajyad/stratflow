# Story Quality Design — Better Epics & User Stories

## Overview

StratFlow's AI currently generates epics and user stories but the output quality is poor:
no acceptance criteria, no KR linkage, vague value statements, no INVEST compliance.
This spec covers three phases to fix that.

---

## Design Principles (from the agile coach's 6 best practices)

1. **INVEST** — every story must be Independent, Negotiable, Valuable, Estimable, Small, Testable
2. **Acceptance Criteria on everything** — epics and stories alike, in Given/When/Then format
3. **SMART objectives** — Specific, Measurable, Achievable, Relevant, Time-bound
4. **Value communication** — "so that..." ends with a measurable business outcome, not a vague benefit
5. **KR linkage** — every item explicitly states which Key Result it contributes to, with a predicted % contribution (testable in production)
6. **Story splitting patterns** — AI names the splitting pattern used (SPIDR, Happy/Unhappy Path, User Role, Performance Tier, CRUD, etc.) so teams understand the decomposition rationale

---

## Phase A — Prompt Engineering + New Fields + Org Config + Jira Sync

### Data Model Changes

**`hl_work_items` table** — two new nullable columns:
- `acceptance_criteria TEXT NULL` — AI-generated ACs (editable, persisted separately from description)
- `kr_hypothesis VARCHAR(500) NULL` — AI prediction of % contribution to a specific KR (e.g. "Expected to increase conversion rate from 2.1% → 3.5%")

**`user_stories` table** — same two columns added.

**`story_quality_config` table** — new org-configurable table:

```sql
CREATE TABLE story_quality_config (
  id            INT UNSIGNED     PRIMARY KEY AUTO_INCREMENT,
  org_id        INT UNSIGNED     NOT NULL,
  rule_type     ENUM('splitting_pattern','mandatory_condition') NOT NULL,
  label         VARCHAR(255)     NOT NULL,
  is_default    TINYINT(1)       NOT NULL DEFAULT 0,
  is_active     TINYINT(1)       NOT NULL DEFAULT 1,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_org_type (org_id, rule_type, is_active, display_order)
) ENGINE=InnoDB;
```

Default splitting patterns (seeded for every new org): SPIDR, Happy/Unhappy Path, User Role, Performance Tier, CRUD Operations.

### Prompt Changes

**`WorkItemPrompt::PROMPT`** and **`UserStoryPrompt::DECOMPOSE_PROMPT`** rewritten to:
- Self-check against all 6 INVEST criteria before outputting
- Generate ACs in Given/When/Then format (array for stories, bullet list for epics)
- Include a KR hypothesis field with predicted % contribution
- Name the splitting pattern used (from org config list)
- End "so that..." with a measurable business outcome
- Respect org custom mandatory conditions

**Output JSON shape** (new fields highlighted):

```json
{
  "title": "...",
  "description": "...",
  "acceptance_criteria": [
    "Given..when..then..",
    "Given..when..then.."
  ],
  "kr_hypothesis": "Expected to contribute +1.4pp to conversion rate KR",
  "splitting_pattern": "Happy/Unhappy Path",
  "estimated_sprints": 3,
  "okr_title": "...",
  "strategic_context": "..."
}
```

**Org config injection** — at generation time, the controller loads active `story_quality_config` rows and appends them to the prompt:

```
--- ORG QUALITY RULES ---
Splitting patterns available: SPIDR, Happy/Unhappy Path, User Role, Performance Tier, CRUD
Mandatory conditions:
  - Every story must include at least one non-functional requirement
  - Every epic must reference a specific market segment
-------------------------
```

**KR data injection** — KR titles + current_value + target_value + unit injected from `key_results` so the AI can generate accurate hypotheses.

### UI Changes — Edit Modal

The work item and story edit modals gain two new collapsible sections:

**Acceptance Criteria** (green-tinted, collapsible `<details>` element):
- Label: "Acceptance Criteria (AI-generated · editable)"
- Textarea — pre-populated by AI, fully editable by user
- Collapsed by default to keep the modal compact; expands on click

**KR Hypothesis** (purple-tinted, collapsible `<details>` element):
- Label: "KR Hypothesis (predicted contribution · editable)"
- Badge showing which KR it links to
- Single-line input — pre-populated by AI, editable
- Collapsed by default

Both sections use `<details>/<summary>` to avoid page length issues.

### UI Changes — Settings (Admin)

New page: **Settings → Story Quality Rules**

- Two-column layout: Splitting Patterns | Mandatory Conditions
- Default patterns shown with "default" tag (read-only)
- Custom patterns/conditions shown with "custom ✕" tag (deletable)
- "+" Add buttons to add custom entries via inline form
- Changes saved via AJAX or standard form POST

### Jira Sync

When pushing a work item or story to Jira:
- `acceptance_criteria` → Jira field `acceptance_criteria` (standard field on Story/Epic). If not present on the issue type, appended as a labelled section in the description.
- `kr_hypothesis` → Jira custom field `cf[kr_hypothesis]` if configured, otherwise appended to description under "KR Hypothesis" heading.

### Settings Route

`GET/POST /app/admin/story-quality-rules` — admin-only, renders settings page, handles add/delete of custom rules.

---

## Phase B — Quality Scoring

**New prompt constants:**
- `WorkItemPrompt::QUALITY_PROMPT` — scores a work item 0–100 across 6 dimensions (INVEST, SMART, ACs, Value, KR linkage, Splitting) and returns breakdown JSON
- `UserStoryPrompt::QUALITY_PROMPT` — same for stories

**New columns** on `hl_work_items` and `user_stories`:
- `quality_score TINYINT UNSIGNED NULL` — 0–100 overall score
- `quality_breakdown JSON NULL` — per-dimension scores, refreshed on AI generation or "Improve" action

**Quality badges** on list views:
- ≥80: green pill
- 50–79: yellow pill
- <50: red pill
- Hover shows per-dimension breakdown tooltip (title attribute with JSON summary)
- Scores refresh on AI regeneration or "Improve with AI" action

---

## Phase C — "Improve with AI" Button

Adds a `✨ Improve with AI` button to the edit modal when quality score < 80.

**New prompt constants:**
- `WorkItemPrompt::REFINE_PROMPT` — takes existing item + quality breakdown + org rules, rewrites to be compliant
- `UserStoryPrompt::REFINE_PROMPT` — same for stories

**UI:**
- Button shown in modal footer alongside Cancel / Save
- Inline quality issues hint: "Quality issues: missing ACs · vague value · no KR linkage · not SMART"
- Clicking replaces current field values with AI-improved versions (user still reviews and saves)
- After improvement, quality score is recalculated and badge updates

---

## New Files

| File | Purpose |
|------|---------|
| `database/migrations/023_story_quality.sql` | Adds AC + KR hypothesis columns and story_quality_config table |
| `src/Services/Prompts/WorkItemPrompt.php` | Updated PROMPT + new QUALITY_PROMPT + REFINE_PROMPT |
| `src/Services/Prompts/UserStoryPrompt.php` | Updated DECOMPOSE_PROMPT + new QUALITY_PROMPT + REFINE_PROMPT |
| `src/Controllers/StoryQualityController.php` | Admin settings CRUD for story_quality_config |
| `src/Models/StoryQualityConfig.php` | DAO for story_quality_config |
| `templates/admin/story-quality-rules.php` | Settings UI — splitting patterns + mandatory conditions |

## Modified Files

| File | Change |
|------|--------|
| `src/Controllers/WorkItemController.php` | Inject KR + org config data before generation; save AC + kr_hypothesis from AI output |
| `src/Controllers/UserStoryController.php` | Same |
| `src/Models/HLWorkItem.php` | Add acceptance_criteria + kr_hypothesis to UPDATABLE_COLUMNS and create/update |
| `src/Models/UserStory.php` | Same |
| `src/Services/JiraSyncService.php` | Push AC to Jira AC field; push kr_hypothesis to description fallback |
| `templates/partials/work-item-modal.php` | Add collapsible AC + KR hypothesis sections |
| `templates/partials/user-story-modal.php` | Same |
| `templates/work-items.php` | Add quality score badges to work item rows (Phase B) |
| `templates/user-stories.php` | Same (Phase B) |
| `src/Config/routes.php` | Add /app/admin/story-quality-rules routes |

---

## Phased Delivery

- **Phase A** (immediate): Prompt rewrite + AC/KR hypothesis fields + org config settings + Jira sync
- **Phase B** (next): Quality scoring badges on list views (new QUALITY_PROMPT, score column, badge UI)
- **Phase C** (following): "Improve with AI" button in edit modal (new REFINE_PROMPT, button UI)

Phases can be shipped independently. Phase A provides the biggest quality lift on its own.

---

## Out of Scope

- Bulk retroactive quality scoring of existing items (deferred — expensive)
- Public quality report exports
- Slack/email notifications when quality drops below threshold
