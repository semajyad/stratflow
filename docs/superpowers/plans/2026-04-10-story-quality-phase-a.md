# Story Quality — Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade AI-generated epics and user stories to include INVEST-compliant acceptance criteria, KR hypothesis predictions, and named splitting patterns — with org-configurable quality rules and Jira sync for the new fields.

**Architecture:** Three layers change in parallel: (1) the DB schema grows two nullable columns on `hl_work_items` and `user_stories` plus a new `story_quality_config` table, (2) both prompt constants are rewritten to output the new JSON shape, and (3) the controllers inject KR+org-config context before calling Gemini and save the new fields back. The new admin settings page is a simple CRUD for the config table.

**Tech Stack:** PHP 8.4, MySQL/MariaDB (InnoDB), Gemini AI via `GeminiService`, PHPUnit for tests, existing StratFlow MVC conventions.

**Spec:** `stratflow/docs/superpowers/specs/2026-04-10-story-quality-design.md`

**Phases B and C** (quality scoring, "Improve with AI" button) are out of scope for this plan. They will be separate plans.

---

## File Map

| Action | File |
|--------|------|
| Create | `stratflow/database/migrations/023_story_quality.sql` |
| Create | `stratflow/src/Models/StoryQualityConfig.php` |
| Create | `stratflow/src/Controllers/StoryQualityController.php` |
| Create | `stratflow/templates/admin/story-quality-rules.php` |
| Create | `stratflow/tests/Unit/Models/StoryQualityConfigTest.php` |
| Modify | `stratflow/src/Services/Prompts/WorkItemPrompt.php` |
| Modify | `stratflow/src/Services/Prompts/UserStoryPrompt.php` |
| Modify | `stratflow/src/Models/HLWorkItem.php` |
| Modify | `stratflow/src/Models/UserStory.php` |
| Modify | `stratflow/src/Controllers/WorkItemController.php` |
| Modify | `stratflow/src/Controllers/UserStoryController.php` |
| Modify | `stratflow/src/Services/JiraSyncService.php` |
| Modify | `stratflow/templates/partials/work-item-modal.php` |
| Modify | `stratflow/templates/partials/user-story-modal.php` |
| Modify | `stratflow/src/Config/routes.php` |

---

## Task 1: Database Migration

**Files:**
- Create: `stratflow/database/migrations/023_story_quality.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Migration 023: Story Quality Phase A
-- Adds acceptance_criteria + kr_hypothesis to work items and stories,
-- and creates the org-configurable story_quality_config table.

-- Step 1: New columns on hl_work_items.
ALTER TABLE hl_work_items
  ADD COLUMN acceptance_criteria TEXT          NULL AFTER okr_description,
  ADD COLUMN kr_hypothesis        VARCHAR(500)  NULL AFTER acceptance_criteria;

-- Step 2: Same columns on user_stories.
ALTER TABLE user_stories
  ADD COLUMN acceptance_criteria TEXT          NULL AFTER description,
  ADD COLUMN kr_hypothesis        VARCHAR(500)  NULL AFTER acceptance_criteria;

-- Step 3: Org-configurable quality rules table.
CREATE TABLE IF NOT EXISTS story_quality_config (
  id            INT UNSIGNED                                     PRIMARY KEY AUTO_INCREMENT,
  org_id        INT UNSIGNED                                     NOT NULL,
  rule_type     ENUM('splitting_pattern','mandatory_condition')  NOT NULL,
  label         VARCHAR(255)                                     NOT NULL,
  is_default    TINYINT(1)                                       NOT NULL DEFAULT 0,
  is_active     TINYINT(1)                                       NOT NULL DEFAULT 1,
  display_order SMALLINT UNSIGNED                                NOT NULL DEFAULT 0,
  created_at    DATETIME                                         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_org_type (org_id, rule_type, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Seed default splitting patterns for all existing orgs.
-- New orgs will be seeded by StoryQualityController::seedDefaults() on first visit.
INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
SELECT o.id, 'splitting_pattern', p.label, 1, p.ord
FROM organisations o
CROSS JOIN (
  SELECT 'SPIDR'               AS label, 1 AS ord UNION ALL
  SELECT 'Happy/Unhappy Path',             2       UNION ALL
  SELECT 'User Role',                      3       UNION ALL
  SELECT 'Performance Tier',               4       UNION ALL
  SELECT 'CRUD Operations',                5
) p
WHERE NOT EXISTS (
  SELECT 1 FROM story_quality_config sq
  WHERE sq.org_id = o.id AND sq.rule_type = 'splitting_pattern' AND sq.is_default = 1
);
```

- [ ] **Step 2: Verify the migration runs locally**

```bash
docker compose exec php php public/init-db.php
```

Expected: no error output; the last line should reference migration 023.

- [ ] **Step 3: Verify the columns exist**

```bash
docker compose exec db mysql -u root -proot stratflow \
  -e "SHOW COLUMNS FROM hl_work_items LIKE 'acceptance_criteria';"
docker compose exec db mysql -u root -proot stratflow \
  -e "SHOW COLUMNS FROM user_stories LIKE 'kr_hypothesis';"
docker compose exec db mysql -u root -proot stratflow \
  -e "SELECT COUNT(*) FROM story_quality_config;"
```

Expected: both column rows exist; count >= 5 (the seeded patterns).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/023_story_quality.sql
git commit -m "feat(db): migration 023 — AC + KR hypothesis columns + story_quality_config"
```

---

## Task 2: StoryQualityConfig Model

**Files:**
- Create: `stratflow/src/Models/StoryQualityConfig.php`
- Create: `stratflow/tests/Unit/Models/StoryQualityConfigTest.php`

- [ ] **Step 1: Write the failing test**

`stratflow/tests/Unit/Models/StoryQualityConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\StoryQualityConfig;

class StoryQualityConfigTest extends TestCase
{
    private static Database $db;
    private static int $orgId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - StoryQualityConfigTest'");
        self::$db->query(
            "INSERT INTO organisations (name) VALUES (?)",
            ['Test Org - StoryQualityConfigTest']
        );
        self::$orgId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query(
            "DELETE FROM story_quality_config WHERE org_id = ?",
            [self::$orgId]
        );
        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - StoryQualityConfigTest'");
    }

    #[Test]
    public function canCreateAndFindCustomRule(): void
    {
        $id = StoryQualityConfig::create(self::$db, [
            'org_id'    => self::$orgId,
            'rule_type' => 'splitting_pattern',
            'label'     => 'My Custom Pattern',
        ]);

        $this->assertGreaterThan(0, $id);

        $rows = StoryQualityConfig::findByOrgId(self::$db, self::$orgId);
        $labels = array_column($rows, 'label');
        $this->assertContains('My Custom Pattern', $labels);
    }

    #[Test]
    public function canDeleteCustomRule(): void
    {
        $id = StoryQualityConfig::create(self::$db, [
            'org_id'    => self::$orgId,
            'rule_type' => 'mandatory_condition',
            'label'     => 'Delete Me',
        ]);

        StoryQualityConfig::delete(self::$db, $id, self::$orgId);

        $rows = StoryQualityConfig::findByOrgId(self::$db, self::$orgId);
        $labels = array_column($rows, 'label');
        $this->assertNotContains('Delete Me', $labels);
    }

    #[Test]
    public function deleteIgnoresDefaultRows(): void
    {
        // Insert a "default" row and try to delete it — should be a no-op
        self::$db->query(
            "INSERT INTO story_quality_config (org_id, rule_type, label, is_default)
             VALUES (?, 'splitting_pattern', 'Cannot Delete Default', 1)",
            [self::$orgId]
        );
        $id = (int) self::$db->lastInsertId();

        StoryQualityConfig::delete(self::$db, $id, self::$orgId);

        $stmt = self::$db->query(
            "SELECT id FROM story_quality_config WHERE id = ?",
            [$id]
        );
        $this->assertNotFalse($stmt->fetch(), 'Default row should not be deleted');
    }

    #[Test]
    public function seedDefaultsIsIdempotent(): void
    {
        StoryQualityConfig::seedDefaults(self::$db, self::$orgId);
        StoryQualityConfig::seedDefaults(self::$db, self::$orgId);

        $rows = StoryQualityConfig::findByOrgId(self::$db, self::$orgId);
        $defaults = array_filter($rows, fn($r) => (int) $r['is_default'] === 1 && $r['rule_type'] === 'splitting_pattern');
        // Should not have more than 5 default splitting patterns (idempotent)
        $this->assertLessThanOrEqual(5, count($defaults));
    }
}
```

- [ ] **Step 2: Run the test — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/StoryQualityConfigTest.php --no-coverage
```

Expected: FAIL — class `StoryQualityConfig` not found.

- [ ] **Step 3: Write the model**

`stratflow/src/Models/StoryQualityConfig.php`:

```php
<?php
/**
 * StoryQualityConfig Model
 *
 * DAO for the `story_quality_config` table.
 * Stores per-org splitting patterns and mandatory conditions used to
 * inject quality constraints into AI story/epic generation prompts.
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class StoryQualityConfig
{
    /** Default splitting patterns seeded for every new org. */
    private const DEFAULT_PATTERNS = [
        'SPIDR',
        'Happy/Unhappy Path',
        'User Role',
        'Performance Tier',
        'CRUD Operations',
    ];

    // ===========================
    // READ
    // ===========================

    /**
     * Return all active rules for an org, ordered by type then display_order.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     * @return array          Rows as associative arrays
     */
    public static function findByOrgId(Database $db, int $orgId): array
    {
        $stmt = $db->query(
            "SELECT * FROM story_quality_config
              WHERE org_id = :org_id AND is_active = 1
              ORDER BY rule_type ASC, display_order ASC, id ASC",
            [':org_id' => $orgId]
        );

        return $stmt->fetchAll();
    }

    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new custom quality rule for an org.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: org_id, rule_type, label
     * @return int           ID of the inserted row
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
             VALUES (:org_id, :rule_type, :label, 0,
                     (SELECT COALESCE(MAX(s.display_order), 0) + 1
                      FROM story_quality_config s
                      WHERE s.org_id = :org_id2 AND s.rule_type = :rule_type2))",
            [
                ':org_id'      => $data['org_id'],
                ':rule_type'   => $data['rule_type'],
                ':label'       => $data['label'],
                ':org_id2'     => $data['org_id'],
                ':rule_type2'  => $data['rule_type'],
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ===========================
    // DELETE
    // ===========================

    /**
     * Delete a custom rule by ID, scoped to org. Default rows are not deleted.
     *
     * @param Database $db    Database instance
     * @param int      $id    Row primary key
     * @param int      $orgId Organisation ID (ownership check)
     */
    public static function delete(Database $db, int $id, int $orgId): void
    {
        $db->query(
            "DELETE FROM story_quality_config
              WHERE id = :id AND org_id = :org_id AND is_default = 0",
            [':id' => $id, ':org_id' => $orgId]
        );
    }

    // ===========================
    // SEED
    // ===========================

    /**
     * Seed default splitting patterns for a new org.
     * Idempotent — does nothing if defaults already exist.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     */
    public static function seedDefaults(Database $db, int $orgId): void
    {
        // Check if any defaults already exist for this org
        $stmt = $db->query(
            "SELECT COUNT(*) AS cnt FROM story_quality_config
              WHERE org_id = :org_id AND is_default = 1 AND rule_type = 'splitting_pattern'",
            [':org_id' => $orgId]
        );
        $cnt = (int) ($stmt->fetch()['cnt'] ?? 0);
        if ($cnt > 0) {
            return;
        }

        foreach (self::DEFAULT_PATTERNS as $order => $label) {
            $db->query(
                "INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
                 VALUES (:org_id, 'splitting_pattern', :label, 1, :ord)",
                [':org_id' => $orgId, ':label' => $label, ':ord' => $order + 1]
            );
        }
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build the org quality rules block to inject into AI prompts.
     *
     * Returns a string section ready to append to the prompt input.
     * If the org has no rules, returns an empty string.
     *
     * @param Database $db    Database instance
     * @param int      $orgId Organisation ID
     * @return string         Formatted prompt section, or empty string
     */
    public static function buildPromptBlock(Database $db, int $orgId): string
    {
        $rows = self::findByOrgId($db, $orgId);
        if (empty($rows)) {
            return '';
        }

        $patterns   = array_filter($rows, fn($r) => $r['rule_type'] === 'splitting_pattern');
        $conditions = array_filter($rows, fn($r) => $r['rule_type'] === 'mandatory_condition');

        $patternLabels = implode(', ', array_column(array_values($patterns), 'label'));
        $block = "\n--- ORG QUALITY RULES ---\n";
        $block .= "Splitting patterns available: {$patternLabels}\n";

        if (!empty($conditions)) {
            $block .= "Mandatory conditions:\n";
            foreach ($conditions as $c) {
                $block .= "  - " . $c['label'] . "\n";
            }
        }

        $block .= "-------------------------\n";

        return $block;
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/StoryQualityConfigTest.php --no-coverage
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Models/StoryQualityConfig.php tests/Unit/Models/StoryQualityConfigTest.php
git commit -m "feat(models): StoryQualityConfig DAO + tests"
```

---

## Task 3: Add New Columns to HLWorkItem Model

**Files:**
- Modify: `stratflow/src/Models/HLWorkItem.php`
- Modify: `stratflow/tests/Unit/Models/HLWorkItemTest.php`

- [ ] **Step 1: Write a failing test** — add to `HLWorkItemTest.php` in the existing test class:

```php
#[Test]
public function canStoreAndRetrieveAcceptanceCriteriaAndKrHypothesis(): void
{
    $id = HLWorkItem::create(self::$db, [
        'project_id'        => self::$projectId,
        'priority_number'   => 99,
        'title'             => 'AC/KR Test Item',
        'acceptance_criteria' => "Given a user is logged in\nWhen they click Save\nThen a confirmation appears",
        'kr_hypothesis'     => 'Expected to contribute +1.5pp to conversion rate KR',
    ]);

    $row = HLWorkItem::findById(self::$db, $id);
    $this->assertNotNull($row);
    $this->assertStringContainsString('Then a confirmation appears', $row['acceptance_criteria']);
    $this->assertSame('Expected to contribute +1.5pp to conversion rate KR', $row['kr_hypothesis']);

    HLWorkItem::delete(self::$db, $id);
}
```

- [ ] **Step 2: Run the test — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/HLWorkItemTest.php --no-coverage
```

Expected: FAIL — "column not found" or value mismatch.

- [ ] **Step 3: Update `HLWorkItem::create()` — add the two new columns to the INSERT**

In `stratflow/src/Models/HLWorkItem.php`, replace the `create()` method body:

```php
public static function create(Database $db, array $data): int
{
    $db->query(
        "INSERT INTO hl_work_items
            (project_id, diagram_id, priority_number, title, description,
             strategic_context, okr_title, okr_description, owner, estimated_sprints,
             acceptance_criteria, kr_hypothesis, status)
         VALUES
            (:project_id, :diagram_id, :priority_number, :title, :description,
             :strategic_context, :okr_title, :okr_description, :owner, :estimated_sprints,
             :acceptance_criteria, :kr_hypothesis, :status)",
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
            ':status'              => $data['status'] ?? 'backlog',
        ]
    );

    return (int) $db->lastInsertId();
}
```

- [ ] **Step 4: Update `UPDATABLE_COLUMNS` constant** — add `acceptance_criteria` and `kr_hypothesis`:

```php
private const UPDATABLE_COLUMNS = [
    'priority_number', 'title', 'description', 'strategic_context',
    'okr_title', 'okr_description', 'owner', 'estimated_sprints',
    'acceptance_criteria', 'kr_hypothesis',
    'rice_reach', 'rice_impact', 'rice_confidence', 'rice_effort',
    'wsjf_business_value', 'wsjf_time_criticality', 'wsjf_risk_reduction', 'wsjf_job_size',
    'final_score', 'requires_review', 'status', 'last_jira_sync_at',
];
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/HLWorkItemTest.php --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Models/HLWorkItem.php tests/Unit/Models/HLWorkItemTest.php
git commit -m "feat(models): add acceptance_criteria + kr_hypothesis to HLWorkItem"
```

---

## Task 4: Add New Columns to UserStory Model

**Files:**
- Modify: `stratflow/src/Models/UserStory.php`
- Modify: `stratflow/tests/Unit/Models/UserStoryTest.php`

- [ ] **Step 1: Write a failing test** — add to the existing `UserStoryTest` class:

```php
#[Test]
public function canStoreAndRetrieveAcceptanceCriteriaAndKrHypothesis(): void
{
    $id = UserStory::create(self::$db, [
        'project_id'          => self::$projectId,
        'priority_number'     => 99,
        'title'               => 'As a user, I want AC stored, so that quality is tracked',
        'acceptance_criteria' => "Given the form is open\nWhen I submit\nThen data is saved",
        'kr_hypothesis'       => 'Expected to reduce churn by 2pp',
    ]);

    $row = UserStory::findById(self::$db, $id);
    $this->assertNotNull($row);
    $this->assertStringContainsString('Then data is saved', $row['acceptance_criteria']);
    $this->assertSame('Expected to reduce churn by 2pp', $row['kr_hypothesis']);

    UserStory::delete(self::$db, $id);
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/UserStoryTest.php --no-coverage
```

- [ ] **Step 3: Update `UserStory::create()` — add the two new columns to the INSERT**

Replace the `create()` method body in `stratflow/src/Models/UserStory.php`:

```php
public static function create(Database $db, array $data): int
{
    $db->query(
        "INSERT INTO user_stories
            (project_id, parent_hl_item_id, priority_number, title, description,
             parent_link, team_assigned, size, blocked_by,
             acceptance_criteria, kr_hypothesis, status)
         VALUES
            (:project_id, :parent_hl_item_id, :priority_number, :title, :description,
             :parent_link, :team_assigned, :size, :blocked_by,
             :acceptance_criteria, :kr_hypothesis, :status)",
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
            ':status'              => $data['status'] ?? 'backlog',
        ]
    );

    return (int) $db->lastInsertId();
}
```

- [ ] **Step 4: Update `UPDATABLE_COLUMNS` constant** in `UserStory.php`:

```php
private const UPDATABLE_COLUMNS = [
    'priority_number', 'title', 'description', 'parent_hl_item_id',
    'parent_link', 'team_assigned', 'size', 'blocked_by',
    'acceptance_criteria', 'kr_hypothesis',
    'requires_review', 'status', 'last_jira_sync_at',
];
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/UserStoryTest.php --no-coverage
```

- [ ] **Step 6: Commit**

```bash
git add src/Models/UserStory.php tests/Unit/Models/UserStoryTest.php
git commit -m "feat(models): add acceptance_criteria + kr_hypothesis to UserStory"
```

---

## Task 5: Rewrite WorkItemPrompt

**Files:**
- Modify: `stratflow/src/Services/Prompts/WorkItemPrompt.php`

The new `PROMPT` must: self-check INVEST before outputting, generate ACs as a bullet list in Given/When/Then format, emit `kr_hypothesis` and `splitting_pattern` fields, end every "so that..." with a measurable business outcome, and respect org quality rules injected into the input.

- [ ] **Step 1: Replace the `PROMPT` constant in `WorkItemPrompt.php`**

Replace everything from `public const PROMPT = <<<'PROMPT'` through the closing `PROMPT;` with:

```php
public const PROMPT = <<<'PROMPT'
You are the ThreePoints StratFlow Architect. Translate the following Mermaid strategy
diagram and OKR data into a prioritised backlog of High-Level Work Items (High Level Work Items).

Before writing each item, silently verify it against all INVEST criteria:
  Independent, Negotiable, Valuable, Estimable, Small (~2 sprints), Testable.
  If it fails any criterion, rewrite it until it passes.

Each item's "so that..." statement MUST end with a measurable business outcome
(e.g. "so that we increase conversion rate from 2.1% to 3.5%" — not "so that users benefit").

For acceptance_criteria use a bullet list where each bullet is one Given/When/Then clause.
For kr_hypothesis, predict the measurable % or unit contribution to the most relevant Key
Result listed in the KR data section below. Be specific (e.g. "+1.4pp to conversion rate KR").
For splitting_pattern, name which pattern from the ORG QUALITY RULES list best describes
how this item was decomposed from the diagram.

If org quality rules are provided below, honour every mandatory condition.

Task Constraints:
1. Each High Level Work Item represents approximately 1 month (2 sprints) of effort for a 5-9 person Scrum team.
2. Every item must directly map to a node or cluster of nodes in the diagram.
3. Respond strictly in JSON format — a JSON array only, no markdown fences.
4. Order by priority (most critical first).

Return a JSON array where each element has these exact keys:
- "priority_number" (integer, starting at 1)
- "title" (string, concise work item title)
- "description" (string, 2-3 sentence scope description)
- "acceptance_criteria" (array of strings, each "Given..when..then.." — 2-4 items)
- "kr_hypothesis" (string, predicted contribution to a specific KR — e.g. "+1.4pp to conversion rate KR")
- "splitting_pattern" (string, the pattern name used from the available list)
- "strategic_context" (string, which diagram nodes this maps to)
- "okr_title" (string, the relevant OKR if available, else empty string)
- "okr_description" (string, the relevant OKR description if available, else empty string)
- "estimated_sprints" (integer, default 2)
- "dependencies" (array of integers — priority_numbers of prerequisite items; [] if none)
PROMPT;
```

- [ ] **Step 2: Run the unit test suite to confirm no regressions**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all existing tests still pass (no tests depend on the old prompt text).

- [ ] **Step 3: Commit**

```bash
git add src/Services/Prompts/WorkItemPrompt.php
git commit -m "feat(prompts): rewrite WorkItemPrompt::PROMPT for INVEST + AC + KR hypothesis"
```

---

## Task 6: Rewrite UserStoryPrompt

**Files:**
- Modify: `stratflow/src/Services/Prompts/UserStoryPrompt.php`

- [ ] **Step 1: Replace the `DECOMPOSE_PROMPT` constant**

Replace `public const DECOMPOSE_PROMPT = <<<'PROMPT'` through its closing `PROMPT;`:

```php
public const DECOMPOSE_PROMPT = <<<'PROMPT'
You are an Experienced Agile Product Owner. Decompose the following high-level work item
into 5-10 actionable user stories.

Before writing each story, silently verify it passes all INVEST criteria:
  Independent, Negotiable, Valuable, Estimable, Small (~3 days), Testable.
  Rewrite until it passes.

Each story MUST:
- Follow the format: "As a [specific role], I want [specific action], so that [measurable outcome]"
- End "so that..." with a measurable business outcome tied to the KR data below (if provided)
- Have 2-4 acceptance criteria in Given/When/Then format
- Include a kr_hypothesis predicting its specific % contribution to a KR (if KR data is provided)
- Name the splitting_pattern used from the list in the org quality rules (if provided)

If org quality rules are provided, honour every mandatory condition.

Return a JSON array where each element has:
- "title" (string, the "As a..." story in full)
- "description" (string, 2-3 sentence technical description of what needs to be built)
- "acceptance_criteria" (array of strings, each a "Given..when..then.." clause — 2-4 items)
- "kr_hypothesis" (string, predicted contribution to a KR, or empty string if no KR data)
- "splitting_pattern" (string, pattern name used, or empty string if no rules provided)
- "size" (integer, story points: 1, 2, 3, 5, 8, or 13)
PROMPT;
```

- [ ] **Step 2: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all pass.

- [ ] **Step 3: Commit**

```bash
git add src/Services/Prompts/UserStoryPrompt.php
git commit -m "feat(prompts): rewrite UserStoryPrompt::DECOMPOSE_PROMPT for INVEST + AC + KR"
```

---

## Task 7: WorkItemController — Inject Context + Save New Fields

**Files:**
- Modify: `stratflow/src/Controllers/WorkItemController.php`

Two methods change: `generate()` (inject KR + org config, save AC/KR from AI) and `update()` (save AC/KR from form).

- [ ] **Step 1: Add `StoryQualityConfig` to the use block** at the top of `WorkItemController.php`:

```php
use StratFlow\Models\StoryQualityConfig;
```

Add it alongside the existing `use` statements.

- [ ] **Step 2: In `generate()`, inject KR data and org quality config before the Gemini call**

Find this block in `generate()`:

```php
        // Build combined input for AI
        $input = $this->buildGenerationInput($diagram, $nodes, $documentSummary);

        // Generate work items via Gemini
        try {
            $gemini    = new GeminiService($this->config);
            $itemsData = $gemini->generateJson(WorkItemPrompt::PROMPT, $input);
```

Replace with:

```php
        // Build combined input for AI
        $input = $this->buildGenerationInput($diagram, $nodes, $documentSummary);

        // Inject org quality rules (splitting patterns + mandatory conditions)
        try {
            $input .= StoryQualityConfig::buildPromptBlock($this->db, $orgId);
        } catch (\Throwable) {
            // Table may not exist on a fresh deploy — proceed without config
        }

        // Inject KR data so AI can generate accurate kr_hypothesis values
        try {
            $krRows = $this->db->query(
                "SELECT kr.title, kr.current_value, kr.target_value, kr.unit
                   FROM key_results kr
                   JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
                  WHERE hwi.project_id = :pid",
                [':pid' => $projectId]
            )->fetchAll();
            if (!empty($krRows)) {
                $input .= "\n--- KEY RESULTS ---\n";
                foreach ($krRows as $kr) {
                    $input .= "- {$kr['title']}: current={$kr['current_value']}, target={$kr['target_value']} {$kr['unit']}\n";
                }
                $input .= "-------------------\n";
            }
        } catch (\Throwable) {
            // key_results table may not exist on a fresh deploy — proceed without KR data
        }

        // Generate work items via Gemini
        try {
            $gemini    = new GeminiService($this->config);
            $itemsData = $gemini->generateJson(WorkItemPrompt::PROMPT, $input);
```

- [ ] **Step 3: In `generate()`, save acceptance_criteria and kr_hypothesis when creating each work item**

Find the `HLWorkItem::create()` call in the first pass loop inside `generate()`:

```php
            $newId = HLWorkItem::create($this->db, [
                'project_id'        => $projectId,
                'diagram_id'        => (int) $diagram['id'],
                'priority_number'   => $priorityNumber,
                'title'             => $item['title'] ?? 'Untitled Work Item',
                'description'       => $item['description'] ?? null,
                'strategic_context' => $item['strategic_context'] ?? null,
                'okr_title'         => $item['okr_title'] ?? null,
                'okr_description'   => $item['okr_description'] ?? null,
                'estimated_sprints' => $item['estimated_sprints'] ?? 2,
            ]);
```

Replace with:

```php
            // Normalise acceptance_criteria: AI may return array or newline-delimited string
            $acRaw = $item['acceptance_criteria'] ?? null;
            $ac = null;
            if (is_array($acRaw)) {
                $ac = implode("\n", $acRaw);
            } elseif (is_string($acRaw) && $acRaw !== '') {
                $ac = $acRaw;
            }

            $newId = HLWorkItem::create($this->db, [
                'project_id'          => $projectId,
                'diagram_id'          => (int) $diagram['id'],
                'priority_number'     => $priorityNumber,
                'title'               => $item['title'] ?? 'Untitled Work Item',
                'description'         => $item['description'] ?? null,
                'strategic_context'   => $item['strategic_context'] ?? null,
                'okr_title'           => $item['okr_title'] ?? null,
                'okr_description'     => $item['okr_description'] ?? null,
                'estimated_sprints'   => $item['estimated_sprints'] ?? 2,
                'acceptance_criteria' => $ac,
                'kr_hypothesis'       => isset($item['kr_hypothesis']) && $item['kr_hypothesis'] !== ''
                                         ? mb_substr((string) $item['kr_hypothesis'], 0, 500)
                                         : null,
            ]);
```

- [ ] **Step 4: In `update()`, add acceptance_criteria and kr_hypothesis to the update array**

Find `$updateData = [` in `update()` and add two fields:

```php
        $updateData = [
            'title'               => trim((string) $this->request->post('title', $item['title'])),
            'description'         => $newDescription,
            'okr_title'           => trim((string) $this->request->post('okr_title', $item['okr_title'] ?? '')),
            'okr_description'     => trim((string) $this->request->post('okr_description', $item['okr_description'] ?? '')),
            'owner'               => trim((string) $this->request->post('owner', $item['owner'] ?? '')),
            'acceptance_criteria' => trim((string) $this->request->post('acceptance_criteria', $item['acceptance_criteria'] ?? '')) ?: null,
            'kr_hypothesis'       => mb_substr(
                trim((string) $this->request->post('kr_hypothesis', $item['kr_hypothesis'] ?? '')),
                0, 500
            ) ?: null,
        ];
```

- [ ] **Step 5: Run the unit test suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/WorkItemController.php
git commit -m "feat(controllers): inject KR+org-config into work item generation; save AC/KR fields"
```

---

## Task 8: UserStoryController — Inject Context + Save New Fields

**Files:**
- Modify: `stratflow/src/Controllers/UserStoryController.php`

- [ ] **Step 1: Add `StoryQualityConfig` to the use block**

```php
use StratFlow\Models\StoryQualityConfig;
use StratFlow\Models\KeyResult;
```

Add alongside existing use statements.

- [ ] **Step 2: In `generate()`, inject KR data + org config into the input for each High Level item**

Find the block that builds `$input` for each `$hlItem`:

```php
            // Build input for AI
            $input = "## Work Item\nTitle: {$hlItem['title']}\n";
            if (!empty($hlItem['description'])) {
                $input .= "Description: {$hlItem['description']}\n";
            }
            if (!empty($hlItem['strategic_context'])) {
                $input .= "Strategic Context: {$hlItem['strategic_context']}\n";
            }

            try {
                $gemini     = new GeminiService($this->config);
                $storiesData = $gemini->generateJson(UserStoryPrompt::DECOMPOSE_PROMPT, $input);
```

Replace with:

```php
            // Build input for AI
            $input = "## Work Item\nTitle: {$hlItem['title']}\n";
            if (!empty($hlItem['description'])) {
                $input .= "Description: {$hlItem['description']}\n";
            }
            if (!empty($hlItem['strategic_context'])) {
                $input .= "Strategic Context: {$hlItem['strategic_context']}\n";
            }

            // Inject KR data from the work item's key results
            try {
                $krRows = $this->db->query(
                    "SELECT title, current_value, target_value, unit
                       FROM key_results
                      WHERE hl_work_item_id = :wid",
                    [':wid' => (int) $hlItemId]
                )->fetchAll();
                if (!empty($krRows)) {
                    $input .= "\n--- KEY RESULTS ---\n";
                    foreach ($krRows as $kr) {
                        $input .= "- {$kr['title']}: current={$kr['current_value']}, target={$kr['target_value']} {$kr['unit']}\n";
                    }
                    $input .= "-------------------\n";
                }
            } catch (\Throwable) {
                // key_results may not exist on a fresh deploy
            }

            // Inject org quality rules
            try {
                $input .= StoryQualityConfig::buildPromptBlock($this->db, $orgId);
            } catch (\Throwable) {
                // story_quality_config may not exist on a fresh deploy
            }

            try {
                $gemini     = new GeminiService($this->config);
                $storiesData = $gemini->generateJson(UserStoryPrompt::DECOMPOSE_PROMPT, $input);
```

- [ ] **Step 3: In `generate()`, save acceptance_criteria and kr_hypothesis when creating each story**

Find the `UserStory::create()` call in the inner foreach loop:

```php
                UserStory::create($this->db, [
                    'project_id'        => $projectId,
                    'parent_hl_item_id' => (int) $hlItemId,
                    'priority_number'   => $priorityNumber++,
                    'title'             => $storyData['title'] ?? 'Untitled Story',
                    'description'       => $storyData['description'] ?? null,
                    'size'              => isset($storyData['size']) ? (int) $storyData['size'] : null,
                ]);
```

Replace with:

```php
                // Normalise acceptance_criteria to newline-delimited string
                $acRaw = $storyData['acceptance_criteria'] ?? null;
                $ac = null;
                if (is_array($acRaw)) {
                    $ac = implode("\n", $acRaw);
                } elseif (is_string($acRaw) && $acRaw !== '') {
                    $ac = $acRaw;
                }

                UserStory::create($this->db, [
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
```

- [ ] **Step 4: In `update()`, add acceptance_criteria and kr_hypothesis to the update call**

Find the `UserStory::update()` call in `update()`:

```php
        UserStory::update($this->db, $id, [
            'title'             => trim((string) $this->request->post('title', $story['title'])),
            'description'       => trim((string) $this->request->post('description', $story['description'] ?? '')),
            'parent_hl_item_id' => $parentHlItemId !== '' ? (int) $parentHlItemId : null,
            'team_assigned'     => trim((string) $this->request->post('team_assigned', $story['team_assigned'] ?? '')),
            'size'              => $size !== '' ? (int) $size : null,
            'blocked_by'        => $blockedBy !== '' ? (int) $blockedBy : null,
        ]);
```

Replace with:

```php
        UserStory::update($this->db, $id, [
            'title'               => trim((string) $this->request->post('title', $story['title'])),
            'description'         => trim((string) $this->request->post('description', $story['description'] ?? '')),
            'parent_hl_item_id'   => $parentHlItemId !== '' ? (int) $parentHlItemId : null,
            'team_assigned'       => trim((string) $this->request->post('team_assigned', $story['team_assigned'] ?? '')),
            'size'                => $size !== '' ? (int) $size : null,
            'blocked_by'          => $blockedBy !== '' ? (int) $blockedBy : null,
            'acceptance_criteria' => trim((string) $this->request->post('acceptance_criteria', $story['acceptance_criteria'] ?? '')) ?: null,
            'kr_hypothesis'       => mb_substr(
                trim((string) $this->request->post('kr_hypothesis', $story['kr_hypothesis'] ?? '')),
                0, 500
            ) ?: null,
        ]);
```

- [ ] **Step 5: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/UserStoryController.php
git commit -m "feat(controllers): inject KR+org-config into story generation; save AC/KR fields"
```

---

## Task 9: Work Item Edit Modal — Add Collapsible AC + KR Sections

**Files:**
- Modify: `stratflow/templates/partials/work-item-modal.php`

- [ ] **Step 1: Add the two collapsible sections before the KR editor mount div**

In `work-item-modal.php`, find:

```php
                <!-- KR editor is injected here by openWorkItemModal() -->
                <div id="kr-editor-mount"></div>
```

Insert the following immediately **before** that comment:

```php
                <!-- Acceptance Criteria — collapsible, AI-generated + editable -->
                <details id="wi-ac-details" style="border:1px solid #d1fae5; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#065f46; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#ecfdf5; border-radius:6px; user-select:none;">
                        <span>Acceptance Criteria <span style="font-weight:400; color:#6b7280;">(AI-generated &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <textarea name="acceptance_criteria" id="modal-acceptance-criteria"
                                  rows="4" style="width:100%; font-size:0.8125rem; font-family:inherit; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem; resize:vertical;"
                                  placeholder="Given..&#10;When..&#10;Then.."></textarea>
                    </div>
                </details>

                <!-- KR Hypothesis — collapsible, AI-generated + editable -->
                <details id="wi-kr-details" style="border:1px solid #ede9fe; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#5b21b6; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#f5f3ff; border-radius:6px; user-select:none;">
                        <span>KR Hypothesis <span style="font-weight:400; color:#6b7280;">(predicted contribution &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <input type="text" name="kr_hypothesis" id="modal-kr-hypothesis"
                               style="width:100%; font-size:0.8125rem; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem;"
                               placeholder="e.g. Expected to increase conversion rate from 2.1% &rarr; 3.5%"
                               maxlength="500">
                    </div>
                </details>
```

- [ ] **Step 2: Populate the new fields from JavaScript**

Find the `openWorkItemModal` JavaScript function in `stratflow/templates/work-items.php` (or wherever it is defined). Look for where it sets field values like `document.getElementById('modal-title').value = item.title`. Add:

```javascript
document.getElementById('modal-acceptance-criteria').value = item.acceptance_criteria || '';
document.getElementById('modal-kr-hypothesis').value = item.kr_hypothesis || '';
```

To locate the JS: run `grep -n "modal-title" stratflow/templates/work-items.php` — it will show the line. Add the two lines immediately after the existing field assignments.

- [ ] **Step 3: Confirm the modal renders without PHP errors**

```bash
docker compose exec php php -l templates/partials/work-item-modal.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add templates/partials/work-item-modal.php templates/work-items.php
git commit -m "feat(ui): add collapsible AC + KR hypothesis sections to work item modal"
```

---

## Task 10: User Story Edit Modal — Add Collapsible AC + KR Sections

**Files:**
- Modify: `stratflow/templates/partials/user-story-modal.php`
- Modify: `stratflow/templates/user-stories.php` (for JS population)

- [ ] **Step 1: Add the two collapsible sections before the git-links-field include**

In `user-story-modal.php`, find:

```php
                <?php require __DIR__ . '/git-links-field.php'; ?>
```

Insert immediately **before** it:

```php
                <!-- Acceptance Criteria — collapsible, AI-generated + editable -->
                <details id="story-ac-details" style="border:1px solid #d1fae5; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#065f46; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#ecfdf5; border-radius:6px; user-select:none;">
                        <span>Acceptance Criteria <span style="font-weight:400; color:#6b7280;">(AI-generated &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <textarea name="acceptance_criteria" id="story-acceptance-criteria"
                                  rows="4" style="width:100%; font-size:0.8125rem; font-family:inherit; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem; resize:vertical;"
                                  placeholder="Given..&#10;When..&#10;Then.."></textarea>
                    </div>
                </details>

                <!-- KR Hypothesis — collapsible, AI-generated + editable -->
                <details id="story-kr-details" style="border:1px solid #ede9fe; border-radius:6px; margin-bottom:0.75rem;">
                    <summary style="padding:0.6rem 0.85rem; font-size:0.875rem; font-weight:600; color:#5b21b6; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; background:#f5f3ff; border-radius:6px; user-select:none;">
                        <span>KR Hypothesis <span style="font-weight:400; color:#6b7280;">(predicted contribution &middot; editable)</span></span>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:400;">&#9660;</span>
                    </summary>
                    <div style="padding:0.75rem 0.85rem 0.85rem;">
                        <input type="text" name="kr_hypothesis" id="story-kr-hypothesis"
                               style="width:100%; font-size:0.8125rem; border:1px solid #d1d5db; border-radius:4px; padding:0.5rem;"
                               placeholder="e.g. Expected to reduce churn by 2pp"
                               maxlength="500">
                    </div>
                </details>
```

- [ ] **Step 2: Populate the new fields from the edit JS in `user-stories.php`**

Find the `openEditStoryModal` (or equivalent) JS function in `stratflow/templates/user-stories.php` that populates the story modal for editing. Add:

```javascript
document.getElementById('story-acceptance-criteria').value = story.acceptance_criteria || '';
document.getElementById('story-kr-hypothesis').value = story.kr_hypothesis || '';
```

To locate it: `grep -n "story-title" stratflow/templates/user-stories.php`.

Also add these fields to the new-story case (clear them on open):

```javascript
document.getElementById('story-acceptance-criteria').value = '';
document.getElementById('story-kr-hypothesis').value = '';
```

- [ ] **Step 3: Verify PHP syntax**

```bash
docker compose exec php php -l templates/partials/user-story-modal.php
```

- [ ] **Step 4: Commit**

```bash
git add templates/partials/user-story-modal.php templates/user-stories.php
git commit -m "feat(ui): add collapsible AC + KR hypothesis sections to story modal"
```

---

## Task 11: Jira Sync — Push AC + KR Hypothesis

**Files:**
- Modify: `stratflow/src/Services/JiraSyncService.php`

Two changes: (1) `buildWorkItemDescription()` appends AC and KR hypothesis; (2) add a `buildStoryDescription()` method that `pushUserStories()` calls.

- [ ] **Step 1: Update `buildWorkItemDescription()`**

Find the method (around line 983) and replace it:

```php
private function buildWorkItemDescription(array $item): string
{
    $description = $item['description'] ?? '';

    if (!empty($item['okr_title']) || !empty($item['okr_description'])) {
        $description .= "\n\nOKR: " . ($item['okr_title'] ?? '');
        if (!empty($item['okr_description'])) {
            $description .= "\n" . $item['okr_description'];
        }
    }

    if (!empty($item['acceptance_criteria'])) {
        $description .= "\n\nAcceptance Criteria:\n" . $item['acceptance_criteria'];
    }

    if (!empty($item['kr_hypothesis'])) {
        $description .= "\n\nKR Hypothesis: " . $item['kr_hypothesis'];
    }

    return trim($description);
}
```

- [ ] **Step 2: Add a `buildStoryDescription()` private method** directly after `buildWorkItemDescription()`:

```php
/**
 * Build the Jira description string for a user story.
 * Appends acceptance criteria and KR hypothesis when present.
 *
 * @param array $story User story record
 * @return string      Combined description text
 */
private function buildStoryDescription(array $story): string
{
    $description = $story['description'] ?? '';

    if (!empty($story['acceptance_criteria'])) {
        $description .= "\n\nAcceptance Criteria:\n" . $story['acceptance_criteria'];
    }

    if (!empty($story['kr_hypothesis'])) {
        $description .= "\n\nKR Hypothesis: " . $story['kr_hypothesis'];
    }

    return trim($description);
}
```

- [ ] **Step 3: Update `pushUserStories()` to use `buildStoryDescription()`**

In `pushUserStories()`, find **both** places where the description is set to `$story['description'] ?? ''` and replace each with `$this->buildStoryDescription($story)`:

First occurrence (update path, around line 408):
```php
                        $updateFields = [
                            'summary'     => $story['title'],
                            'description' => $this->jira->textToAdf($this->buildStoryDescription($story)),
                            'priority'    => ['name' => $this->mapPriority((int) ($story['priority_number'] ?? 5))],
                        ];
```

Second occurrence (create path, around line 434):
```php
                    $fields = [
                        'project'     => ['key' => $jiraProjectKey],
                        'issuetype'   => ['name' => $this->mapping('story_type', 'Story')],
                        'summary'     => $story['title'],
                        'description' => $this->jira->textToAdf($this->buildStoryDescription($story)),
                    ];
```

- [ ] **Step 4: Add `acceptance_criteria` and `kr_hypothesis` to `computeSyncHash()`** so changes trigger a Jira re-push:

```php
    public function computeSyncHash(array $item): string
    {
        $parts = [
            strtolower($item['title'] ?? ''),
            $item['description'] ?? '',
            (string) ($item['priority_number'] ?? 0),
            $item['owner'] ?? '',
            (string) ($item['size'] ?? 0),
            $item['team_assigned'] ?? '',
            (string) ($item['parent_hl_item_id'] ?? 0),
            (string) ($item['estimated_sprints'] ?? 0),
            $item['acceptance_criteria'] ?? '',
            $item['kr_hypothesis'] ?? '',
        ];

        return hash('sha256', implode('|', $parts));
    }
```

- [ ] **Step 5: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit --no-coverage
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Services/JiraSyncService.php
git commit -m "feat(jira): push acceptance_criteria + kr_hypothesis to Jira description"
```

---

## Task 12: StoryQualityController

**Files:**
- Create: `stratflow/src/Controllers/StoryQualityController.php`

- [ ] **Step 1: Create the controller**

`stratflow/src/Controllers/StoryQualityController.php`:

```php
<?php
/**
 * StoryQualityController
 *
 * Admin CRUD for the story_quality_config table.
 * Handles the Settings → Story Quality Rules page where org admins
 * can add custom splitting patterns and mandatory conditions.
 *
 * GET  /app/admin/story-quality-rules       — index (render settings page)
 * POST /app/admin/story-quality-rules       — store (add custom rule)
 * POST /app/admin/story-quality-rules/{id}/delete — delete (custom only)
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\StoryQualityConfig;

class StoryQualityController
{
    protected Request  $request;
    protected Response $response;
    protected Auth     $auth;
    protected Database $db;
    protected array    $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    /**
     * Render the Story Quality Rules settings page.
     *
     * Seeds default splitting patterns for the org on first visit.
     *
     * GET /app/admin/story-quality-rules
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // Seed defaults if this org has never visited the page
        StoryQualityConfig::seedDefaults($this->db, $orgId);

        $rules = StoryQualityConfig::findByOrgId($this->db, $orgId);

        $this->response->render('admin/story-quality-rules', [
            'user'          => $user,
            'active_page'   => 'admin',
            'rules'         => $rules,
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Add a custom splitting pattern or mandatory condition.
     *
     * POST /app/admin/story-quality-rules
     */
    public function store(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $ruleType = $this->request->post('rule_type', '');
        $label    = trim((string) $this->request->post('label', ''));

        if ($label === '') {
            $_SESSION['flash_error'] = 'Label is required.';
            $this->response->redirect('/app/admin/story-quality-rules');
            return;
        }

        if (!in_array($ruleType, ['splitting_pattern', 'mandatory_condition'], true)) {
            $_SESSION['flash_error'] = 'Invalid rule type.';
            $this->response->redirect('/app/admin/story-quality-rules');
            return;
        }

        StoryQualityConfig::create($this->db, [
            'org_id'    => $orgId,
            'rule_type' => $ruleType,
            'label'     => $label,
        ]);

        $_SESSION['flash_message'] = 'Quality rule added.';
        $this->response->redirect('/app/admin/story-quality-rules');
    }

    /**
     * Delete a custom quality rule (defaults are protected).
     *
     * POST /app/admin/story-quality-rules/{id}/delete
     *
     * @param string|int $id Rule primary key from route
     */
    public function delete(string|int $id): void
    {
        $id    = (int) $id;
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        StoryQualityConfig::delete($this->db, $id, $orgId);

        $_SESSION['flash_message'] = 'Rule removed.';
        $this->response->redirect('/app/admin/story-quality-rules');
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec php php -l src/Controllers/StoryQualityController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/StoryQualityController.php
git commit -m "feat(controllers): StoryQualityController — admin CRUD for story quality rules"
```

---

## Task 13: Story Quality Rules Admin Template

**Files:**
- Create: `stratflow/templates/admin/story-quality-rules.php`

- [ ] **Step 1: Create the template**

`stratflow/templates/admin/story-quality-rules.php`:

```php
<?php
/**
 * Story Quality Rules Settings Page
 *
 * Two-column layout: Splitting Patterns | Mandatory Conditions.
 * Default rows are read-only; custom rows show a delete button.
 *
 * Variables: $user, $rules, $flash_message, $flash_error
 */
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title">Story Quality Rules</h1>
    <p class="page-subtitle">Configure splitting patterns and mandatory conditions injected into AI story generation.</p>
</div>

<?php
$patterns   = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'splitting_pattern'));
$conditions = array_values(array_filter($rules, fn($r) => $r['rule_type'] === 'mandatory_condition'));
?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1.5rem;">

    <!-- Splitting Patterns column -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Splitting Patterns</h2>
        </div>
        <div class="card-body">
            <?php if (empty($patterns)): ?>
                <p style="color:#9ca3af; font-size:0.875rem;">No patterns defined.</p>
            <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0 0 1rem;">
                <?php foreach ($patterns as $rule): ?>
                <li style="display:flex; align-items:center; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid #f3f4f6;">
                    <span>
                        <?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((int) $rule['is_default']): ?>
                            <span style="font-size:0.7rem; color:#6366f1; border:1px solid #c7d2fe; border-radius:999px; padding:1px 7px; margin-left:6px;">default</span>
                        <?php endif; ?>
                    </span>
                    <?php if (!(int) $rule['is_default']): ?>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete"
                          onsubmit="return confirm('Remove this splitting pattern?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.8rem; padding:0;">&#10005; remove</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom pattern -->
            <form method="POST" action="/app/admin/story-quality-rules" style="display:flex; gap:0.5rem;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="splitting_pattern">
                <input type="text" name="label" required maxlength="255"
                       style="flex:1; border:1px solid #d1d5db; border-radius:4px; padding:0.4rem 0.6rem; font-size:0.875rem;"
                       placeholder="e.g. Data Complexity">
                <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
            </form>
        </div>
    </div>

    <!-- Mandatory Conditions column -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Mandatory Conditions</h2>
        </div>
        <div class="card-body">
            <?php if (empty($conditions)): ?>
                <p style="color:#9ca3af; font-size:0.875rem;">No mandatory conditions defined. Add one to require the AI to include it in every generated story.</p>
            <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0 0 1rem;">
                <?php foreach ($conditions as $rule): ?>
                <li style="display:flex; align-items:center; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid #f3f4f6;">
                    <span style="font-size:0.875rem;"><?= htmlspecialchars($rule['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="POST" action="/app/admin/story-quality-rules/<?= (int) $rule['id'] ?>/delete"
                          onsubmit="return confirm('Remove this condition?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.8rem; padding:0;">&#10005; remove</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Add custom condition -->
            <form method="POST" action="/app/admin/story-quality-rules" style="display:flex; gap:0.5rem;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rule_type" value="mandatory_condition">
                <input type="text" name="label" required maxlength="255"
                       style="flex:1; border:1px solid #d1d5db; border-radius:4px; padding:0.4rem 0.6rem; font-size:0.875rem;"
                       placeholder="e.g. Every story must reference an API contract">
                <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
            </form>
        </div>
    </div>

</div>
```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec php php -l templates/admin/story-quality-rules.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/story-quality-rules.php
git commit -m "feat(ui): story quality rules admin settings page"
```

---

## Task 14: Routes

**Files:**
- Modify: `stratflow/src/Config/routes.php`

- [ ] **Step 1: Add the three story quality routes**

In `routes.php`, find the Admin section (around line 174):

```php
    // Admin — static routes MUST come before {id} routes
    $router->add('GET',  '/app/admin',                       'AdminController@index',            ['auth', 'admin']);
```

Insert the following **before** that comment block (so it sits with the other settings-style admin routes):

```php
    // Story quality rules — org-configurable AI quality constraints
    $router->add('GET',  '/app/admin/story-quality-rules',                    'StoryQualityController@index',  ['auth', 'admin']);
    $router->add('POST', '/app/admin/story-quality-rules',                    'StoryQualityController@store',  ['auth', 'admin', 'csrf']);
    $router->add('POST', '/app/admin/story-quality-rules/{id}/delete',        'StoryQualityController@delete', ['auth', 'admin', 'csrf']);

```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec php php -l src/Config/routes.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Run full test suite**

```bash
docker compose exec php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add src/Config/routes.php
git commit -m "feat(routes): add story quality rules admin routes"
```

---

## Final Verification

- [ ] **Smoke test — generate work items**

Log in locally. Navigate to a project's Work Items page. Click "Generate Work Items". Inspect one item's edit modal — the Acceptance Criteria `<details>` section should be pre-populated with Given/When/Then clauses. The KR Hypothesis section should show a predicted contribution.

- [ ] **Smoke test — generate stories**

Navigate to User Stories. Select a work item and click "Generate Stories". Open one story's edit modal. Both AC and KR hypothesis sections should be populated.

- [ ] **Smoke test — admin settings page**

Navigate to `/app/admin/story-quality-rules`. Should see 5 default splitting patterns (read-only). Add a custom condition, verify it appears. Delete it, verify it disappears. Defaults cannot be deleted.

- [ ] **Smoke test — Jira sync (if Jira configured)**

Trigger a Jira push. Inspect the Epic/Story description in Jira — it should include an "Acceptance Criteria" section and a "KR Hypothesis" line.

- [ ] **Run full test suite one final time**

```bash
docker compose exec php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

---

## Out of Scope (Phases B and C)

- **Phase B:** Quality scoring badges (0-100 score, `quality_score` + `quality_breakdown` columns, `QUALITY_PROMPT` constants, coloured pills on list views)
- **Phase C:** "Improve with AI" button in edit modal (`REFINE_PROMPT` constants, modal footer button, inline quality issues hint)

These are tracked for separate plan files.
