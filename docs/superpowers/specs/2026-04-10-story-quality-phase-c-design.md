# Story Quality Phase C — "Improve with AI"

## Overview

Phase C adds a single "Improve with AI" button to the quality breakdown panel on each work
item and user story row. Clicking it triggers a direct AI rewrite of the fields that scored
below 80% of their maximum quality score, saves the improved item, re-scores it, and reloads
the page. No preview modal — the improvement is applied immediately.

**Phase A** (AC fields, KR hypothesis, org quality config, Jira sync) — shipped.
**Phase B** (0–100 quality scores, score pills, breakdown panel) — shipped.
**Phase C** (this document) — AI-driven field improvement based on Phase B breakdown.

---

## Architecture

**New service:** `src/Services/StoryImprovementService.php`
- Constructor takes `GeminiService $gemini`
- Two public methods: `improveWorkItem(array $item, array $breakdown, string $qualityBlock): array`
  and `improveStory(array $story, array $breakdown, string $qualityBlock): array`
- Returns array of improved field values (only fields that scored below 80%)
- On any failure (Gemini error, malformed response): `error_log(...)` and return `[]`
- Never throws, never blocks a save

**New prompt constants:**
- `WorkItemPrompt::IMPROVE_PROMPT` — improvement prompt for work items
- `UserStoryPrompt::IMPROVE_PROMPT` — improvement prompt for user stories (story-specific sizing)

**New controller actions:**
- `WorkItemController::improve(int $id)` — POST handler
- `UserStoryController::improve(int $id)` — POST handler

**New routes** in `src/Config/routes.php`:
- `POST /app/work-items/{id}/improve` — `auth` + `csrf` middleware
- `POST /app/user-stories/{id}/improve` — `auth` + `csrf` middleware

**Modified templates:**
- `templates/partials/work-item-row.php` — add "Improve with AI" button to breakdown panel
- `templates/partials/user-story-row.php` — same

No new CSS. Button uses existing `.btn-secondary` class.

---

## Dimension → Field Mapping

Only fields whose corresponding dimension(s) score below 80% of max are rewritten.
`title` is never rewritten.

| Dimension | Max | 80% threshold | Rewrites field |
|-----------|-----|---------------|----------------|
| `acceptance_criteria` | 20 | < 16 | `acceptance_criteria` |
| `kr_linkage` | 20 | < 16 | `kr_hypothesis` |
| `invest` | 20 | < 16 | `description` |
| `value` | 20 | < 16 | `description` |
| `smart` | 10 | < 8 | `description` |
| `splitting` | 10 | < 8 | `description` |

Multiple low-scoring dimensions can all trigger `description`. All their `issues` are passed
to Gemini together and `description` is rewritten once addressing all of them.

---

## StoryImprovementService

```php
class StoryImprovementService
{
    public function __construct(private GeminiService $gemini) {}

    public function improveWorkItem(array $item, array $breakdown, string $qualityBlock): array
    public function improveStory(array $story, array $breakdown, string $qualityBlock): array
}
```

Both methods:
1. Identify which dimensions score below 80% of max
2. If none → return `[]` immediately (no Gemini call needed)
3. Build input string: current field values + issues from failing dimensions + `$qualityBlock`
4. Call `$this->gemini->generateJson(Prompt::IMPROVE_PROMPT, $input)`
5. Validate response contains only known field keys (`description`, `acceptance_criteria`, `kr_hypothesis`)
6. Return validated array (only keys present in the response)
7. On any failure: `error_log('[StoryImprovementService] improvement failed: ' . $e->getMessage())`, return `[]`

---

## Improvement Prompt Shape

Both `WorkItemPrompt::IMPROVE_PROMPT` and `UserStoryPrompt::IMPROVE_PROMPT` instruct Gemini to:

- Read the current item fields
- Read the quality issues for each failing dimension
- Rewrite only the fields that map to those dimensions
- Return **only** valid JSON containing the fields being rewritten
- Do not return fields that are already at or above threshold
- Do not return `title`

Input format passed to the prompt:

```
Title: [title]
Description: [description]
Acceptance Criteria: [acceptance_criteria]
KR Hypothesis: [kr_hypothesis]

Dimensions to improve:
- acceptance_criteria (12/20): ["Missing Given/When/Then format", "Only 1 scenario covered"]
- kr_linkage (10/20): ["No specific KR referenced", "No predicted contribution %"]
- value (14/20): ["Outcome is vague — no measurable target"]

Org quality config:
[qualityBlock]
```

Return shape:

```json
{
  "acceptance_criteria": "Given...\nWhen...\nThen...",
  "kr_hypothesis": "Contributes ~15% toward KR: Increase activation rate to 40% by Q3...",
  "description": "As a product manager I want... so that [measurable outcome]..."
}
```

Only the fields being rewritten appear in the response. A field at or above threshold is
not present.

---

## Controller Flow

### `WorkItemController::improve(int $id)`

1. Fetch item by `$id` — verify `org_id` matches session; 404 if not found or wrong org
2. Load `$qualityBlock` via `StoryQualityConfig::buildPromptBlock($this->db, $orgId)`
3. If `quality_score` is null:
   a. Instantiate `StoryQualityScorer`, call `scoreWorkItem($item, $qualityBlock)`
   b. `HLWorkItem::update($id, ['quality_score' => ..., 'quality_breakdown' => ...])`
   c. Re-fetch item
4. Decode `quality_breakdown` JSON; if null → redirect back with `?improved=0`
5. Instantiate `StoryImprovementService`, call `improveWorkItem($item, $breakdown, $qualityBlock)`
6. If returned array is empty → redirect back with `?improved=0`
7. `HLWorkItem::update($id, $improvedFields)` — updates only the returned keys
8. Re-score: `scoreWorkItem(re-fetched item, $qualityBlock)` → `HLWorkItem::update($id, scores)`
9. Redirect back to referring page with `?improved=1`

`UserStoryController::improve(int $id)` follows the identical flow using `UserStory` and
`StoryQualityScorer::scoreStory()`.

---

## UI

### "Improve with AI" button

Added to the quality breakdown panel inside `.story-row-expand`, directly below the
dimension table. Only rendered when `quality_breakdown` is non-null.

```html
<form method="POST" action="/app/work-items/<?= $item['id'] ?>/improve" class="quality-improve-form">
  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
  <button type="submit" class="btn-secondary btn-sm">Improve with AI</button>
</form>
```

Button is always visible when a breakdown exists (even if all dimensions are at threshold —
the controller handles the no-op case gracefully by redirecting back with `?improved=0`).

---

## Routes

```php
// Work items
$router->post('/app/work-items/{id}/improve', [WorkItemController::class, 'improve'], ['auth', 'csrf']);

// User stories
$router->post('/app/user-stories/{id}/improve', [UserStoryController::class, 'improve'], ['auth', 'csrf']);
```

---

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Gemini fails / malformed JSON | Service returns `[]`; controller redirects back with `?improved=0` |
| All dimensions ≥ 80% | Service returns `[]`; controller redirects back with `?improved=0` (no-op) |
| Item not found or wrong `org_id` | Controller returns 404 |
| Re-score after improvement fails | Null scores saved; improvement update still committed |
| CSRF failure | Framework rejects before controller is reached |

---

## Model Changes

No new columns. `improveWorkItem` and `improveStory` return a subset of existing
`UPDATABLE_COLUMNS` — `description`, `acceptance_criteria`, `kr_hypothesis` — which are
already in both models' update paths.

---

## Out of Scope

- Preview modal — improvements are applied directly (by design)
- Per-field accept/reject — Phase D if needed
- Improvement history / diff view
- Bulk improvement of all items in a project
- Improvement triggered from the generate flow (Phase B generate already creates scored items)
