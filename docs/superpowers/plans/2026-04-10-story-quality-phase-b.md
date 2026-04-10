# Story Quality Phase B — Quality Scoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add AI-driven 0–100 quality scores (across 6 INVEST dimensions) to work items and user stories, with a coloured pill in each list row and a per-dimension breakdown in the expandable panel.

**Architecture:** A new `StoryQualityScorer` service wraps `GeminiService` and scores an item against two new prompt constants (`WorkItemPrompt::QUALITY_PROMPT`, `UserStoryPrompt::QUALITY_PROMPT`). Both controllers call the scorer after create (in generate) and after update. Scores are stored as `quality_score TINYINT` and `quality_breakdown JSON` on both tables. The row partials render a coloured pill in the `<summary>` bar and a mini bar-chart breakdown in the expanded panel.

**Tech Stack:** PHP 8.4, MySQL/MariaDB (InnoDB), Gemini AI via `GeminiService`, PHPUnit, StratFlow MVC.

---

## File Map

| Action | File |
|--------|------|
| Create | `database/migrations/024_quality_scores.sql` |
| Create | `src/Services/StoryQualityScorer.php` |
| Create | `tests/Unit/Services/StoryQualityScorerTest.php` |
| Modify | `src/Services/Prompts/WorkItemPrompt.php` — add `QUALITY_PROMPT` constant |
| Modify | `src/Services/Prompts/UserStoryPrompt.php` — add `QUALITY_PROMPT` constant |
| Modify | `src/Models/HLWorkItem.php` — add columns to `create()` + `UPDATABLE_COLUMNS` |
| Modify | `src/Models/UserStory.php` — same |
| Modify | `src/Controllers/WorkItemController.php` — score in `generate()` + `update()` |
| Modify | `src/Controllers/UserStoryController.php` — score in `generate()` + `update()` |
| Modify | `templates/partials/work-item-row.php` — score pill + breakdown |
| Modify | `templates/partials/user-story-row.php` — score pill + breakdown |
| Modify | `public/assets/css/app.css` — score pill + breakdown bar styles |

---

## Task 1: Database Migration

**Files:**
- Create: `database/migrations/024_quality_scores.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Migration 024: Story Quality Phase B
-- Adds quality_score and quality_breakdown columns to work items and stories.

ALTER TABLE hl_work_items
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;

ALTER TABLE user_stories
  ADD COLUMN quality_score     TINYINT UNSIGNED NULL AFTER kr_hypothesis,
  ADD COLUMN quality_breakdown JSON             NULL AFTER quality_score;
```

- [ ] **Step 2: Run the migration**

```bash
docker compose exec php php public/init-db.php
```

Expected: no errors; last line references migration 024.

- [ ] **Step 3: Verify columns exist**

```bash
docker compose exec db mysql -u root -proot stratflow \
  -e "SHOW COLUMNS FROM hl_work_items LIKE 'quality_%';"
docker compose exec db mysql -u root -proot stratflow \
  -e "SHOW COLUMNS FROM user_stories LIKE 'quality_%';"
```

Expected: two rows each — `quality_score` (tinyint unsigned, null) and `quality_breakdown` (json, null).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/024_quality_scores.sql
git commit -m "feat(db): migration 024 — quality_score + quality_breakdown columns"
```

---

## Task 2: Update HLWorkItem Model

**Files:**
- Modify: `src/Models/HLWorkItem.php`

- [ ] **Step 1: Write a failing test** — add to `tests/Unit/Models/HLWorkItemTest.php` inside the existing test class:

```php
#[Test]
public function canStoreAndRetrieveQualityScore(): void
{
    $id = HLWorkItem::create(self::$db, [
        'project_id'       => self::$projectId,
        'priority_number'  => 98,
        'title'            => 'Quality Score Test Item',
        'quality_score'    => 75,
        'quality_breakdown' => json_encode([
            'invest' => ['score' => 15, 'max' => 20, 'issues' => []],
        ]),
    ]);

    $row = HLWorkItem::findById(self::$db, $id);
    $this->assertNotNull($row);
    $this->assertSame(75, (int) $row['quality_score']);
    $this->assertStringContainsString('invest', $row['quality_breakdown']);

    HLWorkItem::delete(self::$db, $id);
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/HLWorkItemTest.php --no-coverage
```

Expected: FAIL — column unknown or value mismatch.

- [ ] **Step 3: Add columns to `create()` in `src/Models/HLWorkItem.php`**

Replace the INSERT statement in `create()`:

```php
public static function create(Database $db, array $data): int
{
    $db->query(
        "INSERT INTO hl_work_items
            (project_id, diagram_id, priority_number, title, description,
             strategic_context, okr_title, okr_description, owner, estimated_sprints,
             acceptance_criteria, kr_hypothesis, quality_score, quality_breakdown, status)
         VALUES
            (:project_id, :diagram_id, :priority_number, :title, :description,
             :strategic_context, :okr_title, :okr_description, :owner, :estimated_sprints,
             :acceptance_criteria, :kr_hypothesis, :quality_score, :quality_breakdown, :status)",
        [
            ':project_id'          => $data['project_id'],
            ':diagram_id'          => $data['diagram_id'] ?? null,
            ':priority_number'     => $data['priority_number'],
            ':title'               => $data['title'],
            ':description'         => $data['description'] ?? null,
            ':strategic_context'   => $data['strategic_context'] ?? null,
            ':okr_title'           => $data['okr_title'] ?? null,
            ':okr_description'     => $data['okr_description'] ?? null,
            ':owner'               => $data['owner'] ?? null,
            ':estimated_sprints'   => $data['estimated_sprints'] ?? 2,
            ':acceptance_criteria' => $data['acceptance_criteria'] ?? null,
            ':kr_hypothesis'       => $data['kr_hypothesis'] ?? null,
            ':quality_score'       => $data['quality_score'] ?? null,
            ':quality_breakdown'   => $data['quality_breakdown'] ?? null,
            ':status'              => $data['status'] ?? 'backlog',
        ]
    );

    return (int) $db->lastInsertId();
}
```

- [ ] **Step 4: Add columns to `UPDATABLE_COLUMNS`**

Replace the `UPDATABLE_COLUMNS` constant:

```php
private const UPDATABLE_COLUMNS = [
    'priority_number', 'title', 'description', 'strategic_context',
    'okr_title', 'okr_description', 'acceptance_criteria', 'kr_hypothesis', 'owner', 'estimated_sprints',
    'quality_score', 'quality_breakdown',
    'rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort',
    'wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size',
    'final_score', 'requires_review', 'status', 'last_jira_sync_at',
];
```

- [ ] **Step 5: Run — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/HLWorkItemTest.php --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Models/HLWorkItem.php tests/Unit/Models/HLWorkItemTest.php
git commit -m "feat(models): add quality_score + quality_breakdown to HLWorkItem"
```

---

## Task 3: Update UserStory Model

**Files:**
- Modify: `src/Models/UserStory.php`

- [ ] **Step 1: Write a failing test** — add to `tests/Unit/Models/UserStoryTest.php` inside the existing test class:

```php
#[Test]
public function canStoreAndRetrieveQualityScore(): void
{
    $id = UserStory::create(self::$db, [
        'project_id'        => self::$projectId,
        'priority_number'   => 98,
        'title'             => 'As a user I want quality scored, so that stories improve',
        'quality_score'     => 62,
        'quality_breakdown' => json_encode([
            'value' => ['score' => 10, 'max' => 20, 'issues' => ['Outcome is vague']],
        ]),
    ]);

    $row = UserStory::findById(self::$db, $id);
    $this->assertNotNull($row);
    $this->assertSame(62, (int) $row['quality_score']);
    $this->assertStringContainsString('vague', $row['quality_breakdown']);

    UserStory::delete(self::$db, $id);
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/UserStoryTest.php --no-coverage
```

- [ ] **Step 3: Add columns to `create()` in `src/Models/UserStory.php`**

Replace the INSERT in `create()`:

```php
public static function create(Database $db, array $data): int
{
    $db->query(
        "INSERT INTO user_stories
            (project_id, parent_hl_item_id, priority_number, title, description,
             parent_link, team_assigned, size, blocked_by,
             acceptance_criteria, kr_hypothesis, quality_score, quality_breakdown, status)
         VALUES
            (:project_id, :parent_hl_item_id, :priority_number, :title, :description,
             :parent_link, :team_assigned, :size, :blocked_by,
             :acceptance_criteria, :kr_hypothesis, :quality_score, :quality_breakdown, :status)",
        [
            ':project_id'          => $data['project_id'],
            ':parent_hl_item_id'   => $data['parent_hl_item_id'] ?? null,
            ':priority_number'     => $data['priority_number'],
            ':title'               => $data['title'],
            ':description'         => $data['description'] ?? null,
            ':parent_link'         => $data['parent_link'] ?? null,
            ':team_assigned'       => $data['team_assigned'] ?? null,
            ':size'                => $data['size'] ?? null,
            ':blocked_by'          => $data['blocked_by'] ?? null,
            ':acceptance_criteria' => $data['acceptance_criteria'] ?? null,
            ':kr_hypothesis'       => $data['kr_hypothesis'] ?? null,
            ':quality_score'       => $data['quality_score'] ?? null,
            ':quality_breakdown'   => $data['quality_breakdown'] ?? null,
            ':status'              => $data['status'] ?? 'backlog',
        ]
    );

    return (int) $db->lastInsertId();
}
```

- [ ] **Step 4: Add columns to `UPDATABLE_COLUMNS`**

Replace the `UPDATABLE_COLUMNS` constant:

```php
private const UPDATABLE_COLUMNS = [
    'priority_number', 'title', 'description', 'parent_hl_item_id',
    'parent_link', 'team_assigned', 'size', 'blocked_by',
    'acceptance_criteria', 'kr_hypothesis', 'quality_score', 'quality_breakdown',
    'requires_review', 'status', 'last_jira_sync_at',
];
```

- [ ] **Step 5: Run — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/UserStoryTest.php --no-coverage
```

- [ ] **Step 6: Commit**

```bash
git add src/Models/UserStory.php tests/Unit/Models/UserStoryTest.php
git commit -m "feat(models): add quality_score + quality_breakdown to UserStory"
```

---

## Task 4: Add QUALITY_PROMPT to WorkItemPrompt

**Files:**
- Modify: `src/Services/Prompts/WorkItemPrompt.php`

- [ ] **Step 1: Add the `QUALITY_PROMPT` constant** — append after the closing `PROMPT;` of `DESCRIPTION_PROMPT`, before the closing `}`:

```php
    public const QUALITY_PROMPT = <<<'PROMPT'
You are a strict Agile quality auditor. Score the following High-Level Work Item (HLWI)
across exactly 6 dimensions. Be strict — a vague "so that users benefit" must lose value
points; missing acceptance criteria must lose AC points; no KR reference means 0 for kr_linkage.

Dimensions and max scores:
- invest (max 20): Check all 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~2 sprints), Testable. Deduct points for each criterion not met.
- acceptance_criteria (max 20): Are there 2+ Given/When/Then clauses? Are they specific and
  testable? Missing or vague ACs score low.
- value (max 20): Does the "so that..." end with a measurable business outcome with numbers?
  Generic benefits ("users benefit") score ≤5.
- kr_linkage (max 20): Does the item reference a specific Key Result with a predicted %
  or unit contribution? No reference = 0. Vague reference = ≤8.
- smart (max 10): Is the objective Specific, Measurable, Achievable, Relevant, Time-bound?
  Deduct 2 points per missing criterion.
- splitting (max 10): Is a named splitting pattern present and appropriate for the scope?
  No pattern named = 0.

Return ONLY valid JSON — no markdown fences, no explanation. Shape:
{
  "overall": <integer 0-100>,
  "invest":              {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "acceptance_criteria": {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "value":               {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "kr_linkage":          {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "smart":               {"score": <int>, "max": 10, "issues": [<string>, ...]},
  "splitting":           {"score": <int>, "max": 10, "issues": [<string>, ...]}
}
"issues" is an array of strings describing problems (empty array [] if none).
"overall" MUST equal the sum of all dimension scores.
PROMPT;
```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec php php -l src/Services/Prompts/WorkItemPrompt.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Services/Prompts/WorkItemPrompt.php
git commit -m "feat(prompts): add WorkItemPrompt::QUALITY_PROMPT"
```

---

## Task 5: Add QUALITY_PROMPT to UserStoryPrompt

**Files:**
- Modify: `src/Services/Prompts/UserStoryPrompt.php`

- [ ] **Step 1: Add the `QUALITY_PROMPT` constant** — append after `SIZE_PROMPT`'s closing `PROMPT;`, before the closing `}`:

```php
    public const QUALITY_PROMPT = <<<'PROMPT'
You are a strict Agile quality auditor. Score the following user story across exactly 6
dimensions. Be strict — vague outcomes, missing Given/When/Then, or absent KR references
must lose points.

Dimensions and max scores:
- invest (max 20): Check all 6 INVEST criteria — Independent, Negotiable, Valuable, Estimable,
  Small (~3 days), Testable. Deduct points for each criterion not met.
- acceptance_criteria (max 20): Are there 2+ Given/When/Then clauses? Are they specific and
  testable? Missing or vague ACs score low.
- value (max 20): Does the "so that..." end with a measurable business outcome with numbers?
  Stories not in "As a [role], I want [action], so that [measurable outcome]" format score ≤5.
- kr_linkage (max 20): Does the story reference a specific Key Result with a predicted %
  or unit contribution? No reference = 0. Vague reference = ≤8.
- smart (max 10): Is the story objective Specific, Measurable, Achievable, Relevant, Time-bound?
  Deduct 2 points per missing criterion.
- splitting (max 10): Is a named splitting pattern present and appropriate?
  No pattern named = 0.

Return ONLY valid JSON — no markdown fences, no explanation. Shape:
{
  "overall": <integer 0-100>,
  "invest":              {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "acceptance_criteria": {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "value":               {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "kr_linkage":          {"score": <int>, "max": 20, "issues": [<string>, ...]},
  "smart":               {"score": <int>, "max": 10, "issues": [<string>, ...]},
  "splitting":           {"score": <int>, "max": 10, "issues": [<string>, ...]}
}
"issues" is an array of strings describing problems (empty array [] if none).
"overall" MUST equal the sum of all dimension scores.
PROMPT;
```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec php php -l src/Services/Prompts/UserStoryPrompt.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Services/Prompts/UserStoryPrompt.php
git commit -m "feat(prompts): add UserStoryPrompt::QUALITY_PROMPT"
```

---

## Task 6: StoryQualityScorer Service + Tests

**Files:**
- Create: `src/Services/StoryQualityScorer.php`
- Create: `tests/Unit/Services/StoryQualityScorerTest.php`

- [ ] **Step 1: Write the failing tests** — create `tests/Unit/Services/StoryQualityScorerTest.php`:

```php
<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryQualityScorer;

class StoryQualityScorerTest extends TestCase
{
    private function makeValidBreakdown(int $overall = 73): array
    {
        return [
            'overall'             => $overall,
            'invest'              => ['score' => 15, 'max' => 20, 'issues' => []],
            'acceptance_criteria' => ['score' => 18, 'max' => 20, 'issues' => []],
            'value'               => ['score' => 10, 'max' => 20, 'issues' => ['Outcome is vague']],
            'kr_linkage'          => ['score' => 15, 'max' => 20, 'issues' => []],
            'smart'               => ['score' =>  8, 'max' => 10, 'issues' => []],
            'splitting'           => ['score' =>  7, 'max' => 10, 'issues' => []],
        ];
    }

    #[Test]
    public function scoreWorkItemReturnsScoreAndBreakdownOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(73));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(
            ['title' => 'Test item', 'description' => 'desc', 'acceptance_criteria' => 'Given x when y then z'],
            ''
        );

        $this->assertSame(73, $result['score']);
        $this->assertIsArray($result['breakdown']);
        $this->assertArrayHasKey('invest', $result['breakdown']);
        $this->assertArrayNotHasKey('overall', $result['breakdown']);
    }

    #[Test]
    public function scoreStoryReturnsScoreAndBreakdownOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(81));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreStory(
            ['title' => 'As a user I want X so that Y increases by 5%', 'acceptance_criteria' => 'Given...'],
            ''
        );

        $this->assertSame(81, $result['score']);
        $this->assertIsArray($result['breakdown']);
    }

    #[Test]
    public function returnsNullScoreWhenGeminiThrows(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertNull($result['score']);
        $this->assertNull($result['breakdown']);
    }

    #[Test]
    public function returnsNullScoreWhenDimensionKeyMissing(): void
    {
        $incomplete = $this->makeValidBreakdown(73);
        unset($incomplete['kr_linkage']); // missing a required dimension

        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($incomplete);

        $scorer = new StoryQualityScorer($gemini);
        $result = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertNull($result['score']);
        $this->assertNull($result['breakdown']);
    }

    #[Test]
    public function overallKeyIsRemovedFromBreakdown(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($this->makeValidBreakdown(73));

        $scorer  = new StoryQualityScorer($gemini);
        $result  = $scorer->scoreWorkItem(['title' => 'Test'], '');

        $this->assertArrayNotHasKey('overall', $result['breakdown']);
        $this->assertSame(6, count($result['breakdown']));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/StoryQualityScorerTest.php --no-coverage
```

Expected: FAIL — class `StoryQualityScorer` not found.

- [ ] **Step 3: Create `src/Services/StoryQualityScorer.php`**

```php
<?php
/**
 * StoryQualityScorer
 *
 * Scores a work item or user story 0-100 across 6 quality dimensions
 * (INVEST, AC, Value, KR Linkage, SMART, Splitting) using Gemini AI.
 *
 * Usage:
 *   $scorer = new StoryQualityScorer(new GeminiService($config));
 *   $result = $scorer->scoreWorkItem($item, $qualityBlock);
 *   // $result = ['score' => 73, 'breakdown' => [...]]
 *   // or       ['score' => null, 'breakdown' => null] on failure
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\WorkItemPrompt;
use StratFlow\Services\Prompts\UserStoryPrompt;

class StoryQualityScorer
{
    private const REQUIRED_DIMENSIONS = [
        'invest', 'acceptance_criteria', 'value', 'kr_linkage', 'smart', 'splitting',
    ];

    public function __construct(private GeminiService $gemini) {}

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Score a High-Level Work Item.
     *
     * @param array  $item         Work item row (title, description, acceptance_criteria, kr_hypothesis)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               ['score' => int|null, 'breakdown' => array|null]
     */
    public function scoreWorkItem(array $item, string $qualityBlock): array
    {
        $input = $this->buildWorkItemInput($item) . $qualityBlock;
        return $this->score($input, WorkItemPrompt::QUALITY_PROMPT);
    }

    /**
     * Score a user story.
     *
     * @param array  $story        User story row (title, description, acceptance_criteria, kr_hypothesis)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               ['score' => int|null, 'breakdown' => array|null]
     */
    public function scoreStory(array $story, string $qualityBlock): array
    {
        $input = $this->buildStoryInput($story) . $qualityBlock;
        return $this->score($input, UserStoryPrompt::QUALITY_PROMPT);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Call Gemini and validate the response shape.
     * Returns null scores on any failure — never throws.
     */
    private function score(string $input, string $prompt): array
    {
        try {
            $result = $this->gemini->generateJson($prompt, $input);
            return $this->validate($result);
        } catch (\Throwable $e) {
            error_log('[StoryQualityScorer] scoring failed: ' . $e->getMessage());
            return ['score' => null, 'breakdown' => null];
        }
    }

    /**
     * Validate that all 6 dimension keys are present and extract overall score.
     */
    private function validate(array $result): array
    {
        foreach (self::REQUIRED_DIMENSIONS as $key) {
            if (!isset($result[$key])) {
                error_log('[StoryQualityScorer] missing dimension key: ' . $key);
                return ['score' => null, 'breakdown' => null];
            }
        }

        $overall = (int) ($result['overall'] ?? 0);

        // Remove overall from breakdown — it's stored separately as quality_score
        $breakdown = array_intersect_key($result, array_flip(self::REQUIRED_DIMENSIONS));

        return ['score' => $overall, 'breakdown' => $breakdown];
    }

    /**
     * Build compact input string for a work item.
     */
    private function buildWorkItemInput(array $item): string
    {
        $parts = ['Title: ' . ($item['title'] ?? '')];

        if (!empty($item['description'])) {
            $parts[] = 'Description: ' . $item['description'];
        }
        if (!empty($item['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $item['acceptance_criteria'];
        }
        if (!empty($item['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $item['kr_hypothesis'];
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * Build compact input string for a user story.
     */
    private function buildStoryInput(array $story): string
    {
        $parts = ['Title: ' . ($story['title'] ?? '')];

        if (!empty($story['description'])) {
            $parts[] = 'Description: ' . $story['description'];
        }
        if (!empty($story['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $story['acceptance_criteria'];
        }
        if (!empty($story['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $story['kr_hypothesis'];
        }

        return implode("\n", $parts) . "\n";
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/StoryQualityScorerTest.php --no-coverage
```

Expected: 5 tests, 5 assertions, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Services/StoryQualityScorer.php tests/Unit/Services/StoryQualityScorerTest.php
git commit -m "feat(services): StoryQualityScorer — AI quality scoring service + tests"
```

---

## Task 7: Wire Scoring into WorkItemController

**Files:**
- Modify: `src/Controllers/WorkItemController.php`

**Context:** `generate()` is around line 133; the `HLWorkItem::create()` call is around line 228. `update()` is around line 388; `HLWorkItem::update()` is around line 425.

- [ ] **Step 1: Add `StoryQualityScorer` to the use block** at the top of the file alongside existing use statements:

```php
use StratFlow\Services\StoryQualityScorer;
```

- [ ] **Step 2: In `generate()`, extract `$qualityBlock` as a separate variable** before it is appended to `$input`

Find:
```php
        // Inject org quality rules (splitting patterns + mandatory conditions)
        try {
            $input .= StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {
            // Table may not exist on a fresh deploy — proceed without config
        }
```

Replace with:
```php
        // Inject org quality rules (splitting patterns + mandatory conditions)
        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {
            // Table may not exist on a fresh deploy — proceed without config
        }
        $input .= $qualityBlock;
```

- [ ] **Step 3: Instantiate scorer once before the item loop in `generate()`**

Find the comment `// First pass: create all items and build a map` and insert immediately **before** it:

```php
        $scorer = new StoryQualityScorer(new GeminiService($this->config));
```

- [ ] **Step 4: Score each item immediately after `HLWorkItem::create()` in the first-pass loop**

Find the line:
```php
            $priorityToId[$priorityNumber] = $newId;
```

Insert immediately **before** that line:
```php
            // Score the new item — failure is non-fatal
            $scored = $scorer->scoreWorkItem(
                array_merge($item, ['acceptance_criteria' => $ac]),
                $qualityBlock
            );
            if ($scored['score'] !== null) {
                HLWorkItem::update($this->db, $newId, [
                    'quality_score'     => $scored['score'],
                    'quality_breakdown' => json_encode($scored['breakdown']),
                ]);
            }
```

- [ ] **Step 5: Score after save in `update()`**

Find in `update()`:
```php
        HLWorkItem::update($this->db, $id, $updateData);

        // Flag for review if description or sprint estimate changed
```

Insert between those two lines:
```php
        // Re-score after update — failure is non-fatal
        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}
        $scorer      = new StoryQualityScorer(new GeminiService($this->config));
        $itemForScore = array_merge($item, $updateData);
        $scored       = $scorer->scoreWorkItem($itemForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            HLWorkItem::update($this->db, (int) $id, [
                'quality_score'     => $scored['score'],
                'quality_breakdown' => json_encode($scored['breakdown']),
            ]);
        }

```

- [ ] **Step 6: Verify PHP syntax**

```bash
docker compose exec php php -l src/Controllers/WorkItemController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/WorkItemController.php
git commit -m "feat(controllers): score work items on generate + update"
```

---

## Task 8: Wire Scoring into UserStoryController

**Files:**
- Modify: `src/Controllers/UserStoryController.php`

**Context:** `generate()` is around line 105; `UserStory::create()` is around line 194. `update()` is around line 282; `UserStory::update()` is around line 306.

- [ ] **Step 1: Add `StoryQualityScorer` to the use block**

```php
use StratFlow\Services\StoryQualityScorer;
```

- [ ] **Step 2: In `generate()`, extract `$qualityBlock` as a separate variable**

Find:
```php
            // Inject org quality rules
            try {
                $input .= StoryQualityConfig::buildPromptBlock($this->db, $orgId);
            } catch (\Throwable) {
                // story_quality_config may not exist on a fresh deploy
            }
```

Replace with:
```php
            // Inject org quality rules
            $qualityBlock = '';
            try {
                $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
            } catch (\Throwable) {
                // story_quality_config may not exist on a fresh deploy
            }
            $input .= $qualityBlock;
```

- [ ] **Step 3: Instantiate scorer once per HL item loop iteration** — insert immediately after the `$qualityBlock =` block (still inside the `foreach ($hlItemIds as $hlItemId)` loop):

```php
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
```

- [ ] **Step 4: Score each story after `UserStory::create()`**

Find:
```php
                UserStory::create($this->db, [
                    ...
                ]);
                $totalCreated++;
```

The `UserStory::create()` call does not return the ID. We need the ID to update it. Replace the `UserStory::create()` call and the `$totalCreated++` line with:

```php
                $newStoryId = UserStory::create($this->db, [
                    'project_id'          => $projectId,
                    'parent_hl_item_id'   => (int) $hlItemId,
                    'priority_number'     => $priorityNumber++,
                    'title'               => $storyData['title'] ?? 'Untitled Story',
                    'description'         => $storyData['description'] ?? null,
                    'size'                => isset($storyData['size']) ? (int) $storyData['size'] : null,
                    'acceptance_criteria' => $ac,
                    'kr_hypothesis'       => isset($storyData['kr_hypothesis']) && $storyData['kr_hypothesis'] !== ''
                                             ? mb_substr((string) $storyData['kr_hypothesis'], 0, 500)
                                             : null,
                ]);
                $totalCreated++;

                // Score the new story — failure is non-fatal
                $scored = $scorer->scoreStory(
                    array_merge($storyData, ['acceptance_criteria' => $ac]),
                    $qualityBlock
                );
                if ($scored['score'] !== null) {
                    UserStory::update($this->db, $newStoryId, [
                        'quality_score'     => $scored['score'],
                        'quality_breakdown' => json_encode($scored['breakdown']),
                    ]);
                }
```

- [ ] **Step 5: Score after save in `update()`**

Find in `update()`:
```php
        UserStory::update($this->db, $id, [
            'title'               => ...
            ...
        ]);

        // Flag parent work item for review
```

Insert between `UserStory::update(...)` and the "Flag parent work item" comment:

```php
        // Re-score after update — failure is non-fatal
        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}
        $scorer = new StoryQualityScorer(new GeminiService($this->config));
        $storyForScore = array_merge($story, [
            'title'               => trim((string) $this->request->post('title', $story['title'])),
            'description'         => trim((string) $this->request->post('description', $story['description'] ?? '')),
            'acceptance_criteria' => trim((string) $this->request->post('acceptance_criteria', $story['acceptance_criteria'] ?? '')) ?: null,
            'kr_hypothesis'       => mb_substr(trim((string) $this->request->post('kr_hypothesis', $story['kr_hypothesis'] ?? '')), 0, 500) ?: null,
        ]);
        $scored = $scorer->scoreStory($storyForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            UserStory::update($this->db, (int) $id, [
                'quality_score'     => $scored['score'],
                'quality_breakdown' => json_encode($scored['breakdown']),
            ]);
        }

```

- [ ] **Step 6: Check that `UserStory::create()` return value is used correctly**

Look at the current `create()` method signature in `src/Models/UserStory.php` — it already returns `int` (the new ID). Confirm the existing call site does not assign the return value (it doesn't — you're adding the assignment in Step 4 above). The `$priorityNumber++` increment was inside the `create()` call arguments; confirm it moved to the new `$newStoryId = UserStory::create(...)` call in Step 4. The `priority_number` param is `$priorityNumber++` — post-increment, so the current value is used and then incremented. This is correct.

- [ ] **Step 7: Verify PHP syntax**

```bash
docker compose exec php php -l src/Controllers/UserStoryController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 8: Run full unit test suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/UserStoryController.php
git commit -m "feat(controllers): score user stories on generate + update"
```

---

## Task 9: Score Pill + Breakdown in work-item-row.php

**Files:**
- Modify: `templates/partials/work-item-row.php`

The file currently wraps the row in `<details class="story-row-details">` with `<summary class="work-item-row">` and a `<div class="story-row-expand">` panel.

- [ ] **Step 1: Add the score pill to the `<summary>` bar**

In the `<summary class="work-item-row">` element, find:
```php
    <span class="badge badge-primary"><?= (int) $item['estimated_sprints'] ?> sprint<?= $item['estimated_sprints'] != 1 ? 's' : '' ?></span>
    <span class="work-item-owner"><?= htmlspecialchars($item['owner'] ?? 'Unassigned') ?></span>
```

Insert the score pill **between** those two spans:
```php
    <?php if ($item['quality_score'] !== null): ?>
    <?php $qs = (int) $item['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" style="background:<?= $qc ?>;" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php endif; ?>
```

- [ ] **Step 2: Add the breakdown section to the expand panel**

Inside `<div class="story-row-expand">`, append the following **after** the existing meta section (after `</div>` closing the `story-expand-meta` div, before `</div>` closing `story-row-expand`):

```php
    <?php
    $wiBreakdown = null;
    if (!empty($item['quality_breakdown'])) {
        $wiBreakdown = json_decode($item['quality_breakdown'], true);
    }
    ?>
    <?php if ($wiBreakdown !== null): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Quality Breakdown</span>
        <div class="quality-breakdown">
            <?php
            $dimLabels = [
                'invest'              => 'INVEST',
                'acceptance_criteria' => 'Acceptance Criteria',
                'value'               => 'Value',
                'kr_linkage'          => 'KR Linkage',
                'smart'               => 'SMART',
                'splitting'           => 'Splitting',
            ];
            foreach ($dimLabels as $dimKey => $dimLabel):
                if (!isset($wiBreakdown[$dimKey])) continue;
                $dim      = $wiBreakdown[$dimKey];
                $dimScore = (int) ($dim['score'] ?? 0);
                $dimMax   = (int) ($dim['max'] ?? 1);
                $dimPct   = $dimMax > 0 ? (int) round($dimScore / $dimMax * 100) : 0;
                $dimColor = $dimPct >= 80 ? '#10b981' : ($dimPct >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="quality-dim">
                <div class="quality-dim-header">
                    <span class="quality-dim-label"><?= htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="quality-dim-score" style="color:<?= $dimColor ?>;"><?= $dimScore ?>/<?= $dimMax ?></span>
                </div>
                <div class="quality-dim-bar-track">
                    <div class="quality-dim-bar-fill" style="width:<?= $dimPct ?>%; background:<?= $dimColor ?>;"></div>
                </div>
                <?php foreach ($dim['issues'] ?? [] as $issue): ?>
                <div class="quality-dim-issue">&#8627; <?= htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 3: Verify PHP syntax**

```bash
docker compose exec php php -l templates/partials/work-item-row.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add templates/partials/work-item-row.php
git commit -m "feat(ui): quality score pill + breakdown in work item row"
```

---

## Task 10: Score Pill + Breakdown in user-story-row.php

**Files:**
- Modify: `templates/partials/user-story-row.php`

Same structure as work-item-row.php — `<details>/<summary class="story-row">` with `<div class="story-row-expand">`.

- [ ] **Step 1: Add the score pill to the `<summary>` bar**

Find:
```php
    <span class="story-size"><?= $story['size'] !== null ? (int) $story['size'] . ' pts' : '- pts' ?></span>
    <span class="story-team"><?= htmlspecialchars($story['team_assigned'] ?? 'Unassigned') ?></span>
```

Insert the pill **between** those two spans:
```php
    <?php if ($story['quality_score'] !== null): ?>
    <?php $qs = (int) $story['quality_score']; $qc = $qs >= 80 ? '#10b981' : ($qs >= 50 ? '#f59e0b' : '#ef4444'); ?>
    <span class="quality-pill" style="background:<?= $qc ?>;" title="Quality score: <?= $qs ?>/100"><?= $qs ?></span>
    <?php endif; ?>
```

- [ ] **Step 2: Add the breakdown section to the expand panel**

Inside `<div class="story-row-expand">`, append after the closing `</div>` of `story-expand-meta`, before `</div>` closing `story-row-expand`:

```php
    <?php
    $storyBreakdown = null;
    if (!empty($story['quality_breakdown'])) {
        $storyBreakdown = json_decode($story['quality_breakdown'], true);
    }
    ?>
    <?php if ($storyBreakdown !== null): ?>
    <div class="story-expand-section">
        <span class="story-expand-label">Quality Breakdown</span>
        <div class="quality-breakdown">
            <?php
            $dimLabels = [
                'invest'              => 'INVEST',
                'acceptance_criteria' => 'Acceptance Criteria',
                'value'               => 'Value',
                'kr_linkage'          => 'KR Linkage',
                'smart'               => 'SMART',
                'splitting'           => 'Splitting',
            ];
            foreach ($dimLabels as $dimKey => $dimLabel):
                if (!isset($storyBreakdown[$dimKey])) continue;
                $dim      = $storyBreakdown[$dimKey];
                $dimScore = (int) ($dim['score'] ?? 0);
                $dimMax   = (int) ($dim['max'] ?? 1);
                $dimPct   = $dimMax > 0 ? (int) round($dimScore / $dimMax * 100) : 0;
                $dimColor = $dimPct >= 80 ? '#10b981' : ($dimPct >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="quality-dim">
                <div class="quality-dim-header">
                    <span class="quality-dim-label"><?= htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="quality-dim-score" style="color:<?= $dimColor ?>;"><?= $dimScore ?>/<?= $dimMax ?></span>
                </div>
                <div class="quality-dim-bar-track">
                    <div class="quality-dim-bar-fill" style="width:<?= $dimPct ?>%; background:<?= $dimColor ?>;"></div>
                </div>
                <?php foreach ($dim['issues'] ?? [] as $issue): ?>
                <div class="quality-dim-issue">&#8627; <?= htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 3: Verify PHP syntax**

```bash
docker compose exec php php -l templates/partials/user-story-row.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add templates/partials/user-story-row.php
git commit -m "feat(ui): quality score pill + breakdown in user story row"
```

---

## Task 11: CSS for Score Pill and Breakdown Bars

**Files:**
- Modify: `public/assets/css/app.css`

- [ ] **Step 1: Append styles** at the end of the `/* === Story / Work Item Expandable Rows */` section (find the line `/* === Sprint Allocation` and insert immediately before it):

```css
/* === Quality Score Pill + Breakdown ======================================== */

.quality-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    padding: 0 0.4rem;
    height: 1.35rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.02em;
    flex-shrink: 0;
}

.quality-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    width: 100%;
}

.quality-dim {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.quality-dim-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.quality-dim-label {
    font-size: 0.75rem;
    color: #374151;
    font-weight: 500;
}

.quality-dim-score {
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
}

.quality-dim-bar-track {
    height: 5px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
    width: 100%;
}

.quality-dim-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s;
}

.quality-dim-issue {
    font-size: 0.72rem;
    color: #6b7280;
    font-style: italic;
    padding-left: 0.5rem;
}

```

- [ ] **Step 2: Verify no CSS syntax issues by linting the file length**

```bash
wc -l public/assets/css/app.css
```

Expected: more lines than before (no content was lost).

- [ ] **Step 3: Run full unit test suite one final time**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add public/assets/css/app.css
git commit -m "feat(ui): quality score pill + breakdown CSS"
```

---

## Final Verification

- [ ] **Smoke test — generate work items**

Navigate to a project's Work Items page → Generate Work Items. After generation completes:
- Each work item row should show a coloured pill (green/amber/red number) between the sprint badge and owner
- Expanding a row should show the Quality Breakdown section with 6 dimension bars
- Items without a score (if generation fails) show no pill — row still works normally

- [ ] **Smoke test — edit a work item**

Open a work item edit modal → change the description → Save. The row's quality pill should update to the new score.

- [ ] **Smoke test — user stories**

Same two tests on the User Stories page.

- [ ] **Push to trigger build**

```bash
git push
```
