# Story Quality Phase B — Quality Scoring

## Overview

Phase B adds AI-driven quality scores to work items and user stories. Every item gets a 0–100
score across 6 dimensions (INVEST, AC quality, Value, KR linkage, SMART, Splitting). Scores
are computed automatically on AI generation and on every manual save. A coloured score pill
appears on each list row; the full per-dimension breakdown lives inside the expandable row panel.

**Phase A** (AC fields, KR hypothesis, org quality config, Jira sync) is already shipped.
**Phase C** ("Improve with AI" button) is the next phase — out of scope here.

---

## Architecture

**New service:** `src/Services/StoryQualityScorer.php`
- Takes a `GeminiService` instance in its constructor
- Two public methods: `scoreWorkItem(array $item, string $qualityBlock): array` and `scoreStory(array $story, string $qualityBlock): array`
- Returns `['score' => int|null, 'breakdown' => array|null]`
- Scoring failure (Gemini error, malformed response) returns `['score' => null, 'breakdown' => null]` — never throws, never blocks a save

**New prompt constants:**
- `WorkItemPrompt::QUALITY_PROMPT` — scores a work item across 6 dimensions, returns structured JSON
- `UserStoryPrompt::QUALITY_PROMPT` — same for stories (story-specific INVEST sizing: ~3 days, not ~2 sprints)

**New migration:** `database/migrations/024_quality_scores.sql`

**Modified:** `HLWorkItem`, `UserStory` models (new columns in UPDATABLE_COLUMNS + create)
**Modified:** `WorkItemController`, `UserStoryController` (score after create + after update)
**Modified:** `templates/partials/work-item-row.php`, `templates/partials/user-story-row.php` (score pill + breakdown)
**Modified:** `public/assets/css/app.css` (score pill + breakdown bar styles)

---

## Data Model

Migration `024_quality_scores.sql`:

```sql
ALTER TABLE hl_work_items
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;

ALTER TABLE user_stories
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;
```

### quality_breakdown JSON shape

```json
{
  "invest":              {"score": 15, "max": 20, "issues": ["Not independent — depends on auth epic"]},
  "acceptance_criteria": {"score": 18, "max": 20, "issues": []},
  "value":               {"score": 10, "max": 20, "issues": ["Outcome is vague — no measurable target"]},
  "kr_linkage":          {"score": 15, "max": 20, "issues": []},
  "smart":               {"score":  8, "max": 10, "issues": ["Not time-bound"]},
  "splitting":           {"score":  7, "max": 10, "issues": []}
}
```

Six dimensions, fixed keys. Scores sum to `quality_score` (0–100).

| Dimension | Max | Notes |
|-----------|-----|-------|
| `invest` | 20 | All 6 INVEST criteria checked |
| `acceptance_criteria` | 20 | Presence, Given/When/Then format, completeness |
| `value` | 20 | "so that..." ends with measurable business outcome |
| `kr_linkage` | 20 | References a specific KR with predicted % contribution |
| `smart` | 10 | Specific, Measurable, Achievable, Relevant, Time-bound |
| `splitting` | 10 | Splitting pattern named and appropriate |

---

## StoryQualityScorer Service

`src/Services/StoryQualityScorer.php`

```php
class StoryQualityScorer
{
    public function __construct(private GeminiService $gemini) {}

    public function scoreWorkItem(array $item, string $qualityBlock): array
    public function scoreStory(array $story, string $qualityBlock): array
}
```

Both methods:
1. Build compact input string: title + description + acceptance_criteria + kr_hypothesis + splitting_pattern
2. Append `$qualityBlock` (org splitting patterns + mandatory conditions)
3. Call `$this->gemini->generateJson(Prompt::QUALITY_PROMPT, $input)`
4. Validate response has all 6 dimension keys and an `overall` key
5. On success: return `['score' => $overall, 'breakdown' => $dimensions]`
6. On any failure: `error_log(...)` and return `['score' => null, 'breakdown' => null]`

### QUALITY_PROMPT shape (both work items and stories)

The prompt instructs Gemini to:
- Read the item and score it strictly across the 6 dimensions
- For each dimension: assign a score (0–max), list specific issues (empty array if none)
- Compute overall as the sum
- Return **only** valid JSON matching the `quality_breakdown` shape plus a top-level `"overall"` key
- Be strict: a vague "so that users benefit" must cost value points; missing ACs must cost AC points

Return shape:

```json
{
  "overall": 73,
  "invest":              {"score": 15, "max": 20, "issues": [...]},
  "acceptance_criteria": {"score": 18, "max": 20, "issues": [...]},
  "value":               {"score": 10, "max": 20, "issues": [...]},
  "kr_linkage":          {"score": 15, "max": 20, "issues": [...]},
  "smart":               {"score":  8, "max": 10, "issues": [...]},
  "splitting":           {"score":  7, "max": 10, "issues": [...]}
}
```

---

## When Scoring Runs

Scoring is synchronous. A scoring failure never blocks the primary operation.

| Trigger | Action |
|---------|--------|
| `WorkItemController::generate()` — after each `HLWorkItem::create()` | Score item, call `HLWorkItem::update()` with `quality_score` + `quality_breakdown` |
| `WorkItemController::update()` — after `HLWorkItem::update()` | Re-score updated item, call `HLWorkItem::update()` with new score |
| `UserStoryController::generate()` — after each `UserStory::create()` | Score story, call `UserStory::update()` with scores |
| `UserStoryController::update()` — after `UserStory::update()` | Re-score, update |

`$qualityBlock` is loaded once per controller method via `StoryQualityConfig::buildPromptBlock($this->db, $orgId)` and passed to the scorer.

**Not triggered by:** Jira sync, reorder, suggest-size, export. Scores are StratFlow-internal metadata.

---

## UI

### Score pill (summary row — always visible)

A compact pill rendered in the `<summary>` bar of each row, between size/sprints and team/owner:

| Score | Colour | CSS var |
|-------|--------|---------|
| ≥ 80 | Green | `#10b981` |
| 50–79 | Amber | `#f59e0b` |
| < 50 | Red | `#ef4444` |
| `null` | Not shown | — |

Rendered as: `<span class="quality-pill quality-pill--green">87</span>`

### Per-dimension breakdown (expanded panel)

Inside the existing `.story-row-expand` panel, below the AC and meta sections.
Only rendered when `quality_breakdown` is non-null.

A 6-row table — each row shows:
- Dimension label
- Mini progress bar (CSS-only, `width: X%` inline style)
- `score/max` text
- Issues in italic below the bar (only when non-empty)

Example rendered output:
```
INVEST              ████████████████░░░░  15/20
Acceptance Criteria ████████████████████  20/20
Value               ██████████░░░░░░░░░░  10/20
                    ↳ Outcome is vague — no measurable target
KR Linkage          ████████████████░░░░  16/20
SMART               ████████░░░░░░░░░░░░   8/10
                    ↳ Not time-bound
Splitting           ██████████░░░░░░░░░░   7/10
```

PHP decodes `quality_breakdown` JSON at render time — no JavaScript needed.

---

## Model Changes

Both `HLWorkItem` and `UserStory`:

- Add `quality_score` and `quality_breakdown` to `UPDATABLE_COLUMNS`
- Add them to `create()` method signatures (nullable, default null)
- `findById()` and `findByProjectId()` return them automatically (SELECT * queries)

---

## Error Handling

- Scoring failure → `error_log('[StoryQualityScorer] scoring failed: ' . $e->getMessage())`, return null scores
- Null scores → pill not rendered, breakdown section not rendered
- Invalid JSON breakdown (missing dimension key) → treat whole breakdown as null

---

## Out of Scope

- Bulk retroactive scoring of existing items (no batch job)
- Score history / audit trail
- Slack/email alerts when score drops below threshold
- Phase C ("Improve with AI" button) — separate plan
