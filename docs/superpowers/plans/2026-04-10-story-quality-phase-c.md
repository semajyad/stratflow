# Story Quality Phase C — "Improve with AI" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Improve with AI" button to the quality breakdown panel that rewrites only the fields whose dimensions score below 80%, saves the result, and re-scores.

**Architecture:** New `StoryImprovementService` follows the same shape as `StoryQualityScorer` — constructor takes `GeminiService`, two public methods, never throws. New `improve()` action on each controller (POST, auth+csrf). Button is a plain form in the existing breakdown panel partial.

**Tech Stack:** PHP 8.4 strict types, PSR-4, PHPUnit 11, Gemini AI via `GeminiService::generateJson()`

---

## File Map

| Action | File |
|--------|------|
| CREATE | `stratflow/src/Services/StoryImprovementService.php` |
| CREATE | `stratflow/tests/Unit/Services/StoryImprovementServiceTest.php` |
| MODIFY | `stratflow/src/Services/Prompts/WorkItemPrompt.php` — add `IMPROVE_PROMPT` constant |
| MODIFY | `stratflow/src/Services/Prompts/UserStoryPrompt.php` — add `IMPROVE_PROMPT` constant |
| MODIFY | `stratflow/src/Config/routes.php` — add 2 POST routes before `{id}` catch-alls |
| MODIFY | `stratflow/src/Controllers/WorkItemController.php` — add `use StoryImprovementService`, add `improve()` |
| MODIFY | `stratflow/src/Controllers/UserStoryController.php` — add `use StoryImprovementService`, add `improve()` |
| MODIFY | `stratflow/templates/partials/work-item-row.php` — add button after breakdown table |
| MODIFY | `stratflow/templates/partials/user-story-row.php` — add button after breakdown table |

---

## Task 1: Write failing tests for StoryImprovementService

**Files:**
- Create: `stratflow/tests/Unit/Services/StoryImprovementServiceTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GeminiService;
use StratFlow\Services\StoryImprovementService;

class StoryImprovementServiceTest extends TestCase
{
    private function makeBreakdownWithIssues(): array
    {
        return [
            'invest'              => ['score' => 12, 'max' => 20, 'issues' => ['Not independent']],
            'acceptance_criteria' => ['score' => 10, 'max' => 20, 'issues' => ['No Given/When/Then format']],
            'value'               => ['score' => 8,  'max' => 20, 'issues' => ['Vague outcome — no numbers']],
            'kr_linkage'          => ['score' => 8,  'max' => 20, 'issues' => ['No KR referenced']],
            'smart'               => ['score' => 5,  'max' => 10, 'issues' => ['Not time-bound']],
            'splitting'           => ['score' => 5,  'max' => 10, 'issues' => ['No pattern named']],
        ];
    }

    private function makeBreakdownAtThreshold(): array
    {
        // All dimensions exactly at 80% — should NOT trigger improvement
        return [
            'invest'              => ['score' => 16, 'max' => 20, 'issues' => []],
            'acceptance_criteria' => ['score' => 16, 'max' => 20, 'issues' => []],
            'value'               => ['score' => 16, 'max' => 20, 'issues' => []],
            'kr_linkage'          => ['score' => 16, 'max' => 20, 'issues' => []],
            'smart'               => ['score' => 8,  'max' => 10, 'issues' => []],
            'splitting'           => ['score' => 8,  'max' => 10, 'issues' => []],
        ];
    }

    #[Test]
    public function improveWorkItemReturnsImprovedFieldsOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'acceptance_criteria' => "Given the user is logged in\nWhen they submit the form\nThen they see a confirmation",
            'kr_hypothesis'       => 'Contributes +15% toward KR: Increase conversion rate to 5% by Q3',
            'description'         => 'Improved scope description addressing the issues.',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item', 'description' => 'Old description'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayHasKey('acceptance_criteria', $result);
        $this->assertArrayHasKey('kr_hypothesis', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertIsString($result['description']);
    }

    #[Test]
    public function improveStoryReturnsImprovedFieldsOnSuccess(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'description' => 'As a product manager I want dashboards so that conversion increases by 5% by Q3',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveStory(
            ['title' => 'As a user I want x so that y'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayHasKey('description', $result);
        $this->assertIsString($result['description']);
    }

    #[Test]
    public function returnsEmptyArrayWhenAllDimensionsAtThreshold(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownAtThreshold(),
            ''
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyArrayWhenGeminiThrows(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function stripsUnknownFieldsFromGeminiResponse(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'title'               => 'This should be stripped — PM owns the title',
            'description'         => 'Valid improved description',
            'acceptance_criteria' => 'Given x when y then z',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('acceptance_criteria', $result);
    }

    #[Test]
    public function stripsEmptyStringFieldsFromGeminiResponse(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'description'         => '',
            'acceptance_criteria' => 'Given x when y then z',
        ]);

        $service = new StoryImprovementService($gemini);
        $result  = $service->improveWorkItem(
            ['title' => 'Test item'],
            $this->makeBreakdownWithIssues(),
            ''
        );

        $this->assertArrayNotHasKey('description', $result);
        $this->assertArrayHasKey('acceptance_criteria', $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail with class-not-found**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/StoryImprovementServiceTest.php --no-coverage
```

Expected: 6 tests fail with `Class "StratFlow\Services\StoryImprovementService" not found`

---

## Task 2: Implement StoryImprovementService

**Files:**
- Create: `stratflow/src/Services/StoryImprovementService.php`

- [ ] **Step 1: Create the service**

```php
<?php
/**
 * StoryImprovementService
 *
 * Rewrites only the fields of a work item or user story that score below
 * 80% of their quality dimension maximum, using Gemini AI.
 *
 * Usage:
 *   $service = new StoryImprovementService(new GeminiService($config));
 *   $fields  = $service->improveWorkItem($item, $breakdown, $qualityBlock);
 *   // Returns array of improved fields, or [] on failure / all-at-threshold
 */

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Services\Prompts\WorkItemPrompt;
use StratFlow\Services\Prompts\UserStoryPrompt;

class StoryImprovementService
{
    // Dimensions and their maximum scores — mirrors StoryQualityScorer::REQUIRED_DIMENSIONS
    private const DIMENSION_MAX = [
        'acceptance_criteria' => 20,
        'kr_linkage'          => 20,
        'invest'              => 20,
        'value'               => 20,
        'smart'               => 10,
        'splitting'           => 10,
    ];

    // Only these fields may be rewritten — title is PM-owned, never touched
    private const ALLOWED_FIELDS = ['description', 'acceptance_criteria', 'kr_hypothesis'];

    public function __construct(private GeminiService $gemini) {}

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Improve a High-Level Work Item's fields based on quality breakdown.
     *
     * @param array  $item         Work item row (title, description, acceptance_criteria, kr_hypothesis)
     * @param array  $breakdown    Decoded quality_breakdown JSON (6 dimension keys)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               Subset of improved fields, or [] if all at threshold / on failure
     */
    public function improveWorkItem(array $item, array $breakdown, string $qualityBlock): array
    {
        return $this->improve($item, $breakdown, $qualityBlock, WorkItemPrompt::IMPROVE_PROMPT);
    }

    /**
     * Improve a user story's fields based on quality breakdown.
     *
     * @param array  $story        User story row (title, description, acceptance_criteria, kr_hypothesis)
     * @param array  $breakdown    Decoded quality_breakdown JSON (6 dimension keys)
     * @param string $qualityBlock Org quality rules block from StoryQualityConfig::buildPromptBlock()
     * @return array               Subset of improved fields, or [] if all at threshold / on failure
     */
    public function improveStory(array $story, array $breakdown, string $qualityBlock): array
    {
        return $this->improve($story, $breakdown, $qualityBlock, UserStoryPrompt::IMPROVE_PROMPT);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Core improvement logic — shared between work items and stories.
     * Returns [] without calling Gemini if no dimensions are below threshold.
     */
    private function improve(array $item, array $breakdown, string $qualityBlock, string $prompt): array
    {
        $failing = $this->identifyFailingDimensions($breakdown);

        if (empty($failing)) {
            return [];
        }

        try {
            $input  = $this->buildInput($item, $failing) . $qualityBlock;
            $result = $this->gemini->generateJson($prompt, $input);
            return $this->validateResponse($result);
        } catch (\Throwable $e) {
            error_log('[StoryImprovementService] improvement failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return only the dimensions that score below 80% of their maximum.
     * Score exactly at 80% is not failing (e.g. 16/20 = 80% → skip).
     */
    private function identifyFailingDimensions(array $breakdown): array
    {
        $failing = [];
        foreach (self::DIMENSION_MAX as $dim => $max) {
            if (!isset($breakdown[$dim])) {
                continue;
            }
            $score = (int) ($breakdown[$dim]['score'] ?? 0);
            if ($score < (int) ($max * 0.8)) {
                $failing[$dim] = $breakdown[$dim];
            }
        }
        return $failing;
    }

    /**
     * Build the input string passed to Gemini: current fields + failing dimension issues.
     */
    private function buildInput(array $item, array $failingDimensions): string
    {
        $parts = [
            'Current item:',
            'Title: ' . ($item['title'] ?? ''),
        ];

        if (!empty($item['description'])) {
            $parts[] = 'Description: ' . $item['description'];
        }
        if (!empty($item['acceptance_criteria'])) {
            $parts[] = "Acceptance Criteria:\n" . $item['acceptance_criteria'];
        }
        if (!empty($item['kr_hypothesis'])) {
            $parts[] = 'KR Hypothesis: ' . $item['kr_hypothesis'];
        }

        $parts[] = "\nDimensions requiring improvement:";
        foreach ($failingDimensions as $dim => $data) {
            $score     = (int) ($data['score'] ?? 0);
            $max       = (int) ($data['max'] ?? 0);
            $issueList = implode('; ', (array) ($data['issues'] ?? []));
            $line      = "- {$dim} ({$score}/{$max})";
            if ($issueList !== '') {
                $line .= ": {$issueList}";
            }
            $parts[] = $line;
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * Strip unknown keys and empty strings from Gemini's response.
     * Only 'description', 'acceptance_criteria', 'kr_hypothesis' are accepted.
     */
    private function validateResponse(array $result): array
    {
        $filtered = array_intersect_key($result, array_flip(self::ALLOWED_FIELDS));
        foreach ($filtered as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                unset($filtered[$key]);
            }
        }
        return $filtered;
    }
}
```

- [ ] **Step 2: Run the tests to verify they pass**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/StoryImprovementServiceTest.php --no-coverage
```

Expected: 6 tests, 6 passed

- [ ] **Step 3: Run the full unit suite to check no regressions**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all green

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Services/StoryImprovementService.php stratflow/tests/Unit/Services/StoryImprovementServiceTest.php
git commit -m "feat(quality): add StoryImprovementService with tests"
```

---

## Task 3: Add IMPROVE_PROMPT constants

**Files:**
- Modify: `stratflow/src/Services/Prompts/WorkItemPrompt.php`
- Modify: `stratflow/src/Services/Prompts/UserStoryPrompt.php`

- [ ] **Step 1: Add `IMPROVE_PROMPT` to `WorkItemPrompt.php`**

Append this constant inside the class, after `QUALITY_PROMPT`:

```php
    public const IMPROVE_PROMPT = <<<'PROMPT'
You are an Agile quality improver. Rewrite ONLY the fields that map to the failing dimensions
listed in the input. Each failing dimension maps to a field as follows:

- acceptance_criteria dimension → rewrite "acceptance_criteria"
  Use 2-4 Given/When/Then clauses, one per line, prefixed with "Given", "When", "Then".
- kr_linkage dimension → rewrite "kr_hypothesis"
  Reference a specific Key Result with a predicted % or unit contribution
  (e.g. "+1.4pp to conversion rate KR: Increase checkout conversion from 2.1% to 3.5%").
- invest / value / smart / splitting dimensions → rewrite "description"
  Write a 2-3 sentence scope description. The "so that..." clause MUST end with a
  measurable business outcome that includes specific numbers or targets.

Rules:
- Do NOT return a "title" field.
- Do NOT return fields for dimensions not listed as failing.
- If multiple description-related dimensions are failing, fix them all in a single rewrite.

Return ONLY valid JSON — no markdown fences, no explanation.
Valid keys: "description", "acceptance_criteria", "kr_hypothesis".
Example: {"acceptance_criteria": "Given...\nWhen...\nThen...", "kr_hypothesis": "+10% toward KR: ..."}
PROMPT;
```

- [ ] **Step 2: Add `IMPROVE_PROMPT` to `UserStoryPrompt.php`**

Append this constant inside the class, after `QUALITY_PROMPT`:

```php
    public const IMPROVE_PROMPT = <<<'PROMPT'
You are an Agile quality improver. Rewrite ONLY the fields that map to the failing dimensions
listed in the input. Each failing dimension maps to a field as follows:

- acceptance_criteria dimension → rewrite "acceptance_criteria"
  Use 2-4 Given/When/Then clauses, one per line, prefixed with "Given", "When", "Then".
- kr_linkage dimension → rewrite "kr_hypothesis"
  Reference a specific Key Result with a predicted % or unit contribution.
- invest / value / smart / splitting dimensions → rewrite "description"
  Must follow: "As a [specific role], I want [specific action], so that [measurable outcome]".
  The "so that" clause MUST end with a measurable business outcome with numbers.
  Story should represent ~3 days of effort for one developer.

Rules:
- Do NOT return a "title" field.
- Do NOT return fields for dimensions not listed as failing.
- If multiple description-related dimensions are failing, fix them all in a single rewrite.

Return ONLY valid JSON — no markdown fences, no explanation.
Valid keys: "description", "acceptance_criteria", "kr_hypothesis".
Example: {"description": "As a product manager I want ... so that conversion increases by 5% by Q3."}
PROMPT;
```

- [ ] **Step 3: Verify syntax**

```bash
docker compose exec php php -l stratflow/src/Services/Prompts/WorkItemPrompt.php
docker compose exec php php -l stratflow/src/Services/Prompts/UserStoryPrompt.php
```

Expected: `No syntax errors detected` for both

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Services/Prompts/WorkItemPrompt.php stratflow/src/Services/Prompts/UserStoryPrompt.php
git commit -m "feat(quality): add IMPROVE_PROMPT constants for work items and user stories"
```

---

## Task 4: Add routes

**Files:**
- Modify: `stratflow/src/Config/routes.php`

Note: The new routes MUST be added **before** the catch-all `POST /app/work-items/{id}` and `POST /app/user-stories/{id}` routes respectively, otherwise the router matches the catch-all first.

- [ ] **Step 1: Add work-item improve route**

In `routes.php`, find the work items block:

```php
    $router->add('POST', '/app/work-items/{id}/generate-description', 'WorkItemController@generateDescription', ['auth']);
    $router->add('POST', '/app/work-items/{id}',                      'WorkItemController@update',              ['auth', 'csrf']);
```

Change it to:

```php
    $router->add('POST', '/app/work-items/{id}/generate-description', 'WorkItemController@generateDescription', ['auth']);
    $router->add('POST', '/app/work-items/{id}/improve',              'WorkItemController@improve',             ['auth', 'csrf']);
    $router->add('POST', '/app/work-items/{id}',                      'WorkItemController@update',              ['auth', 'csrf']);
```

- [ ] **Step 2: Add user-story improve route**

Find the user stories block:

```php
    $router->add('POST', '/app/user-stories/{id}/suggest-size',     'UserStoryController@suggestSize',     ['auth']);
    $router->add('POST', '/app/user-stories/{id}',                  'UserStoryController@update',          ['auth', 'csrf']);
```

Change it to:

```php
    $router->add('POST', '/app/user-stories/{id}/suggest-size',     'UserStoryController@suggestSize',     ['auth']);
    $router->add('POST', '/app/user-stories/{id}/improve',          'UserStoryController@improve',         ['auth', 'csrf']);
    $router->add('POST', '/app/user-stories/{id}',                  'UserStoryController@update',          ['auth', 'csrf']);
```

- [ ] **Step 3: Verify syntax**

```bash
docker compose exec php php -l stratflow/src/Config/routes.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Config/routes.php
git commit -m "feat(quality): add /improve routes for work items and user stories"
```

---

## Task 5: Implement WorkItemController::improve()

**Files:**
- Modify: `stratflow/src/Controllers/WorkItemController.php`

- [ ] **Step 1: Add the `use` import for `StoryImprovementService`**

Find the existing use statements at the top of the file (around line 30):

```php
use StratFlow\Services\StoryQualityScorer;
use StratFlow\Services\Prompts\WorkItemPrompt;
```

Add immediately after `use StratFlow\Services\StoryQualityScorer;`:

```php
use StratFlow\Services\StoryImprovementService;
```

- [ ] **Step 2: Add the `improve()` method**

Find the `delete()` method (starts around line 475). Add the `improve()` method immediately **before** `delete()`:

```php
    /**
     * Improve a work item's low-scoring fields using AI, then re-score.
     * If quality_score is null, scores first then improves in one request.
     *
     * @param int $id Work item primary key (from route parameter)
     */
    public function improve($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $item = HLWorkItem::findById($this->db, (int) $id);
        if ($item === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, (int) $item['project_id'], $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        // Score first if not yet scored — improvement needs the breakdown
        if ($item['quality_score'] === null) {
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
            $scored = $scorer->scoreWorkItem($item, $qualityBlock);
            if ($scored['score'] !== null) {
                HLWorkItem::update($this->db, (int) $id, [
                    'quality_score'     => $scored['score'],
                    'quality_breakdown' => json_encode($scored['breakdown']),
                ]);
                $item = HLWorkItem::findById($this->db, (int) $id);
            }
        }

        // Decode breakdown — if still null after scoring attempt, nothing to improve
        $breakdown = null;
        if (!empty($item['quality_breakdown'])) {
            $breakdown = json_decode((string) $item['quality_breakdown'], true);
        }

        if ($breakdown === null) {
            $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=0');
            return;
        }

        // Improve fields that score below 80% of their max
        $improver       = new StoryImprovementService(new GeminiService($this->config));
        $improvedFields = $improver->improveWorkItem($item, $breakdown, $qualityBlock);

        if (empty($improvedFields)) {
            $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=0');
            return;
        }

        HLWorkItem::update($this->db, (int) $id, $improvedFields);

        // Re-score with the improved content — failure is non-fatal
        $itemForScore = array_merge($item, $improvedFields);
        $scorer       = new StoryQualityScorer(new GeminiService($this->config));
        $scored       = $scorer->scoreWorkItem($itemForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            HLWorkItem::update($this->db, (int) $id, [
                'quality_score'     => $scored['score'],
                'quality_breakdown' => json_encode($scored['breakdown']),
            ]);
        }

        $this->response->redirect('/app/work-items?project_id=' . (int) $item['project_id'] . '&improved=1');
    }
```

- [ ] **Step 3: Verify syntax**

```bash
docker compose exec php php -l stratflow/src/Controllers/WorkItemController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Controllers/WorkItemController.php
git commit -m "feat(quality): add WorkItemController::improve() action"
```

---

## Task 6: Implement UserStoryController::improve()

**Files:**
- Modify: `stratflow/src/Controllers/UserStoryController.php`

- [ ] **Step 1: Add the `use` import for `StoryImprovementService`**

Find in the use statements (around line 27):

```php
use StratFlow\Services\StoryQualityScorer;
use StratFlow\Services\Prompts\UserStoryPrompt;
```

Add immediately after `use StratFlow\Services\StoryQualityScorer;`:

```php
use StratFlow\Services\StoryImprovementService;
```

- [ ] **Step 2: Add the `improve()` method**

Find the `delete()` method. Add `improve()` immediately **before** it:

```php
    /**
     * Improve a user story's low-scoring fields using AI, then re-score.
     * If quality_score is null, scores first then improves in one request.
     *
     * @param int $id User story primary key (from route parameter)
     */
    public function improve($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $story = UserStory::findById($this->db, (int) $id);
        if ($story === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $project = Project::findById($this->db, (int) $story['project_id'], $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $qualityBlock = '';
        try {
            $qualityBlock = StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {}

        // Score first if not yet scored — improvement needs the breakdown
        if ($story['quality_score'] === null) {
            $scorer = new StoryQualityScorer(new GeminiService($this->config));
            $scored = $scorer->scoreStory($story, $qualityBlock);
            if ($scored['score'] !== null) {
                UserStory::update($this->db, (int) $id, [
                    'quality_score'     => $scored['score'],
                    'quality_breakdown' => json_encode($scored['breakdown']),
                ]);
                $story = UserStory::findById($this->db, (int) $id);
            }
        }

        // Decode breakdown — if still null after scoring attempt, nothing to improve
        $breakdown = null;
        if (!empty($story['quality_breakdown'])) {
            $breakdown = json_decode((string) $story['quality_breakdown'], true);
        }

        if ($breakdown === null) {
            $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=0');
            return;
        }

        // Improve fields that score below 80% of their max
        $improver       = new StoryImprovementService(new GeminiService($this->config));
        $improvedFields = $improver->improveStory($story, $breakdown, $qualityBlock);

        if (empty($improvedFields)) {
            $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=0');
            return;
        }

        UserStory::update($this->db, (int) $id, $improvedFields);

        // Re-score with the improved content — failure is non-fatal
        $storyForScore = array_merge($story, $improvedFields);
        $scorer        = new StoryQualityScorer(new GeminiService($this->config));
        $scored        = $scorer->scoreStory($storyForScore, $qualityBlock);
        if ($scored['score'] !== null) {
            UserStory::update($this->db, (int) $id, [
                'quality_score'     => $scored['score'],
                'quality_breakdown' => json_encode($scored['breakdown']),
            ]);
        }

        $this->response->redirect('/app/user-stories?project_id=' . (int) $story['project_id'] . '&improved=1');
    }
```

- [ ] **Step 3: Verify syntax**

```bash
docker compose exec php php -l stratflow/src/Controllers/UserStoryController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Controllers/UserStoryController.php
git commit -m "feat(quality): add UserStoryController::improve() action"
```

---

## Task 7: Add "Improve with AI" button to both row partials

**Files:**
- Modify: `stratflow/templates/partials/work-item-row.php`
- Modify: `stratflow/templates/partials/user-story-row.php`

### work-item-row.php

- [ ] **Step 1: Add the button after the breakdown table**

Find this block (around line 134):

```php
        </div>
    </div>
    <?php endif; ?>
</div>
</details>
```

The `<?php endif; ?>` closes the `if ($wiBreakdown !== null)` block. Replace the closing section so it reads:

```php
        </div>
        <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/improve"
              class="quality-improve-form"
              onsubmit="return confirm('Improve this item with AI? The description, acceptance criteria, and KR hypothesis may be rewritten based on the quality score.')">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary btn-sm">Improve with AI</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</details>
```

The `<form>` is placed inside the `if ($wiBreakdown !== null)` block (before the `endif`), so it only renders when a breakdown exists.

### user-story-row.php

- [ ] **Step 2: Add the button after the breakdown table**

Find this block (around line 133):

```php
        </div>
    </div>
    <?php endif; ?>
</div>
</details>
```

Replace with:

```php
        </div>
        <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/improve"
              class="quality-improve-form"
              onsubmit="return confirm('Improve this story with AI? The description, acceptance criteria, and KR hypothesis may be rewritten based on the quality score.')">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary btn-sm">Improve with AI</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</details>
```

- [ ] **Step 3: Verify syntax on both partials**

```bash
docker compose exec php php -l stratflow/templates/partials/work-item-row.php
docker compose exec php php -l stratflow/templates/partials/user-story-row.php
```

Expected: `No syntax errors detected` for both

- [ ] **Step 4: Commit**

```bash
git add stratflow/templates/partials/work-item-row.php stratflow/templates/partials/user-story-row.php
git commit -m "feat(quality): add Improve with AI button to work item and user story breakdown panels"
```

---

## Task 8: Final verification

- [ ] **Step 1: Run full unit test suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all green, 6 new tests in `StoryImprovementServiceTest`

- [ ] **Step 2: Smoke-test the integration (manual)**

1. Open a work item or user story with a quality score already displayed
2. Expand the row to see the breakdown panel
3. Verify "Improve with AI" button appears below the dimension table
4. Click it — browser shows the confirm dialog
5. Confirm — page reloads, quality score and field content updated
6. Open a work item with `quality_score = NULL` — expand → click Improve with AI → score-then-improve runs in one request

- [ ] **Step 3: Verify `improved=0` no-op case**

1. Find or create an item where all dimensions are ≥ 80% (score ≥ 80)
2. Click Improve with AI
3. Page reloads — URL contains `?improved=0` — fields unchanged

- [ ] **Step 4: Push**

```bash
git push
```
