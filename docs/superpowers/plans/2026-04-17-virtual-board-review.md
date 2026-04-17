# Virtual Board Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add conversational AI board review (Executive and Product Management boards) across four pages, where a single AI call simulates a multi-persona deliberation and produces an accept/reject recommendation that, when accepted, applies structured changes to the underlying data.

**Architecture:** One `GeminiService::generateJson()` call per review produces a conversation array + recommendation object (with `proposed_changes` broken out as a separate DB column). A new `PanelResolverService` is extracted from `SoundingBoardController` to avoid duplication — both controllers share it. Accept logic wraps DB mutations in transactions. Board type (`executive` / `product_management`) is stored explicitly in the `board_reviews` row.

**Tech Stack:** PHP 8.1+, MySQL/MariaDB, `GeminiService::generateJson()` (existing, with OpenAI fallback), vanilla JS + fetch API (matching app.js patterns), PHPUnit.

---

## Corrections to the Original Spec

Before implementation, note these issues discovered during codebase review:

1. **`$orgId` undeclared bug** — `SoundingBoardController::evaluate()` references `$orgId` in the `Subscription::hasEvaluationBoard()` call without ever assigning it. It must be `$orgId = (int) $user['org_id']` immediately after `$user = $this->auth->user()`. Do not repeat this in `BoardReviewController`.

2. **Panel resolution is private** — `findPanel()` and `seedDefaultPanel()` are private methods on `SoundingBoardController`. `BoardReviewController` cannot reuse them without a shared service. Task 3 extracts `PanelResolverService` and updates `SoundingBoardController` to use it.

3. **`proposed_changes` must be its own column** — burying it inside `recommendation_json` means every accept operation has to decode the full blob just to read the changes. Store `proposed_changes JSON NOT NULL` as a separate column.

4. **`board_type` should be explicit** — deriving board type from `screen_context` at query time is fragile. Store `board_type ENUM('executive','product_management') NOT NULL` at insert time.

5. **`generateJson()` confirmed** — `GeminiService::generateJson(string $prompt, string $input): array` exists with 5-attempt parse + retry loop. Use it (not `generate()`).

---

## File Map

### New Files

| File | Purpose |
|------|---------|
| `database/migrations/041_board_reviews.sql` | New table |
| `src/Models/BoardReview.php` | Data-access model (follows EvaluationResult pattern) |
| `src/Services/PanelResolverService.php` | Extracted panel lookup + seeding (shared by both controllers) |
| `src/Services/Prompts/BoardReviewPrompt.php` | AI prompt builder — returns exact JSON schema instruction |
| `src/Services/BoardReviewService.php` | Calls `generateJson()`, validates structure, returns parsed result |
| `src/Controllers/BoardReviewController.php` | HTTP handlers: evaluate, results, accept, reject, history |
| `templates/partials/board-review-modal.php` | Full-screen chat-thread + recommendation + accept/reject UI |
| `templates/partials/board-review-button.php` | Reusable button partial (context-aware label) |
| `tests/Unit/Models/BoardReviewTest.php` | Model unit tests |
| `tests/Unit/Services/BoardReviewServiceTest.php` | Service unit tests |
| `tests/Unit/Services/PanelResolverServiceTest.php` | PanelResolverService unit tests |
| `tests/Integration/BoardReviewControllerTest.php` | Controller integration tests |

### Modified Files

| File | Change |
|------|--------|
| `src/Controllers/SoundingBoardController.php` | Replace private `findPanel()`/`seedDefaultPanel()` with `PanelResolverService` |
| `src/Config/routes.php` | Add 5 new routes |
| `templates/layouts/app.php` | Include `board-review-modal.php` partial |
| `templates/upload.php` | Add board review button (summary context) |
| `templates/diagram.php` | Add board review button (roadmap context) |
| `templates/work-items.php` | Add board review button (work_items context) |
| `templates/user-stories.php` | Add board review button (user_stories context) |
| `src/Controllers/DiagramController.php` | Pass `has_evaluation_board` to render (if not already) |
| `src/Controllers/UploadController.php` | Pass `has_evaluation_board` to render (if not already) |
| `src/Controllers/WorkItemsController.php` | Pass `has_evaluation_board` to render |
| `src/Controllers/UserStoriesController.php` | Pass `has_evaluation_board` to render |
| `public/assets/js/app.js` | Add `openBoardReview()`, `runBoardReview()`, `acceptBoardReview()`, `rejectBoardReview()` |
| `public/assets/css/app.css` | Chat-thread bubble styles + recommendation card styles |

---

## Task 1: Database Migration

**Files:**
- Create: `database/migrations/041_board_reviews.sql`

- [ ] **Step 1: Write the migration**

```sql
-- database/migrations/041_board_reviews.sql
CREATE TABLE board_reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED NOT NULL,
    panel_id        INT UNSIGNED NOT NULL,
    board_type      ENUM('executive','product_management') NOT NULL,
    evaluation_level ENUM('devils_advocate','red_teaming','gordon_ramsay') NOT NULL,
    screen_context  VARCHAR(100) NOT NULL,
    content_snapshot MEDIUMTEXT NOT NULL,
    conversation_json JSON NOT NULL,
    recommendation_json JSON NOT NULL,
    proposed_changes JSON NOT NULL,
    status          ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    responded_by    INT UNSIGNED NULL,
    responded_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_screen (project_id, screen_context),
    INDEX idx_project_board_type (project_id, board_type),
    FOREIGN KEY (project_id)   REFERENCES projects(id)       ON DELETE CASCADE,
    FOREIGN KEY (panel_id)     REFERENCES persona_panels(id) ON DELETE CASCADE,
    FOREIGN KEY (responded_by) REFERENCES users(id)          ON DELETE SET NULL
);
```

- [ ] **Step 2: Run the migration**

```bash
mysql -u root stratflow < database/migrations/041_board_reviews.sql
```

Expected: No errors. Confirm with:

```bash
mysql -u root stratflow -e "DESCRIBE board_reviews;"
```

Expected: 14 columns including `board_type`, `proposed_changes`, `responded_by`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/041_board_reviews.sql
git commit -m "feat: add board_reviews migration with explicit board_type and proposed_changes columns"
```

---

## Task 2: BoardReview Model

**Files:**
- Create: `src/Models/BoardReview.php`
- Create: `tests/Unit/Models/BoardReviewTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Models/BoardReviewTest.php
namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Models\BoardReview;
use Tests\Helpers\DatabaseTestHelper;

class BoardReviewTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $this->db = DatabaseTestHelper::connection();
        $this->db->query("DELETE FROM board_reviews WHERE project_id = 99999");
    }

    public function testCreateReturnsId(): void
    {
        $id = BoardReview::create($this->db, [
            'project_id'          => 99999,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'summary',
            'content_snapshot'    => 'test content',
            'conversation_json'   => json_encode([['speaker' => 'CEO', 'message' => 'test']]),
            'recommendation_json' => json_encode(['summary' => 'test', 'rationale' => 'test']),
            'proposed_changes'    => json_encode(['revised_summary' => 'new text']),
        ]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testFindByIdReturnsRow(): void
    {
        $id = BoardReview::create($this->db, [
            'project_id'          => 99999,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'red_teaming',
            'screen_context'      => 'roadmap',
            'content_snapshot'    => 'diagram content',
            'conversation_json'   => json_encode([]),
            'recommendation_json' => json_encode(['summary' => 'test', 'rationale' => 'test']),
            'proposed_changes'    => json_encode(['revised_mermaid_code' => 'graph TD; A-->B']),
        ]);

        $row = BoardReview::findById($this->db, $id);
        $this->assertNotNull($row);
        $this->assertSame('roadmap', $row['screen_context']);
        $this->assertSame('executive', $row['board_type']);
        $this->assertSame('pending', $row['status']);
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $row = BoardReview::findById($this->db, 999999);
        $this->assertNull($row);
    }

    public function testUpdateStatusChangesRow(): void
    {
        $id = BoardReview::create($this->db, [
            'project_id'          => 99999,
            'panel_id'            => 1,
            'board_type'          => 'product_management',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'work_items',
            'content_snapshot'    => 'items content',
            'conversation_json'   => json_encode([]),
            'recommendation_json' => json_encode(['summary' => 'test', 'rationale' => 'test']),
            'proposed_changes'    => json_encode(['items' => []]),
        ]);

        BoardReview::updateStatus($this->db, $id, 'accepted', 42);

        $row = BoardReview::findById($this->db, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame(42, (int) $row['responded_by']);
        $this->assertNotNull($row['responded_at']);
    }

    public function testFindByProjectIdReturnsMostRecentFirst(): void
    {
        BoardReview::create($this->db, [
            'project_id'          => 99999,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'summary',
            'content_snapshot'    => 'a',
            'conversation_json'   => json_encode([]),
            'recommendation_json' => json_encode(['summary' => 's', 'rationale' => 'r']),
            'proposed_changes'    => json_encode([]),
        ]);
        BoardReview::create($this->db, [
            'project_id'          => 99999,
            'panel_id'            => 1,
            'board_type'          => 'executive',
            'evaluation_level'    => 'devils_advocate',
            'screen_context'      => 'roadmap',
            'content_snapshot'    => 'b',
            'conversation_json'   => json_encode([]),
            'recommendation_json' => json_encode(['summary' => 's', 'rationale' => 'r']),
            'proposed_changes'    => json_encode([]),
        ]);

        $rows = BoardReview::findByProjectId($this->db, 99999);
        $this->assertCount(2, $rows);
        // Most recent first — roadmap was inserted second
        $this->assertSame('roadmap', $rows[0]['screen_context']);
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd stratflow && vendor/bin/phpunit tests/Unit/Models/BoardReviewTest.php --no-coverage
```

Expected: FAIL — `Class 'StratFlow\Models\BoardReview' not found`

- [ ] **Step 3: Implement BoardReview model**

```php
<?php
// src/Models/BoardReview.php
namespace StratFlow\Models;

use StratFlow\Core\Database;

class BoardReview
{
    // ===========================
    // CREATE
    // ===========================

    /**
     * Insert a new board review row and return its ID.
     *
     * @param Database $db   Database instance
     * @param array    $data Keys: project_id, panel_id, board_type, evaluation_level,
     *                       screen_context, content_snapshot, conversation_json,
     *                       recommendation_json, proposed_changes
     * @return int           Inserted row ID
     */
    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO board_reviews
                (project_id, panel_id, board_type, evaluation_level, screen_context,
                 content_snapshot, conversation_json, recommendation_json, proposed_changes, status)
             VALUES
                (:project_id, :panel_id, :board_type, :evaluation_level, :screen_context,
                 :content_snapshot, :conversation_json, :recommendation_json, :proposed_changes, 'pending')",
            [
                ':project_id'          => $data['project_id'],
                ':panel_id'            => $data['panel_id'],
                ':board_type'          => $data['board_type'],
                ':evaluation_level'    => $data['evaluation_level'],
                ':screen_context'      => $data['screen_context'],
                ':content_snapshot'    => $data['content_snapshot'],
                ':conversation_json'   => $data['conversation_json'],
                ':recommendation_json' => $data['recommendation_json'],
                ':proposed_changes'    => $data['proposed_changes'],
            ]
        );
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    /**
     * Find a single board review by primary key.
     *
     * @param Database $db Database instance
     * @param int      $id Row primary key
     * @return array|null  Row as associative array, or null if not found
     */
    public static function findById(Database $db, int $id): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM board_reviews WHERE id = :id LIMIT 1",
            [':id' => $id]
        );
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Return all board reviews for a project, newest first.
     *
     * @param Database $db        Database instance
     * @param int      $projectId Project primary key
     * @return array              Array of rows
     */
    public static function findByProjectId(Database $db, int $projectId): array
    {
        $stmt = $db->query(
            "SELECT * FROM board_reviews WHERE project_id = :project_id ORDER BY created_at DESC",
            [':project_id' => $projectId]
        );
        return $stmt->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    /**
     * Set the status (and optionally record who responded and when).
     *
     * @param Database   $db          Database instance
     * @param int        $id          Row primary key
     * @param string     $status      'accepted' or 'rejected'
     * @param int|null   $respondedBy User ID of the person who responded
     */
    public static function updateStatus(Database $db, int $id, string $status, ?int $respondedBy = null): void
    {
        $db->query(
            "UPDATE board_reviews
             SET status = :status, responded_by = :responded_by, responded_at = NOW()
             WHERE id = :id",
            [
                ':status'       => $status,
                ':responded_by' => $respondedBy,
                ':id'           => $id,
            ]
        );
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
vendor/bin/phpunit tests/Unit/Models/BoardReviewTest.php --no-coverage
```

Expected: 5 tests, 5 assertions, all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models/BoardReview.php tests/Unit/Models/BoardReviewTest.php
git commit -m "feat: add BoardReview model with create/find/updateStatus (TDD)"
```

---

## Task 3: Extract PanelResolverService

**Files:**
- Create: `src/Services/PanelResolverService.php`
- Create: `tests/Unit/Services/PanelResolverServiceTest.php`
- Modify: `src/Controllers/SoundingBoardController.php`

- [ ] **Step 1: Write failing tests for PanelResolverService**

```php
<?php
// tests/Unit/Services/PanelResolverServiceTest.php
namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\PanelResolverService;
use Tests\Helpers\DatabaseTestHelper;

class PanelResolverServiceTest extends TestCase
{
    private $db;
    private PanelResolverService $resolver;

    protected function setUp(): void
    {
        $this->db       = DatabaseTestHelper::connection();
        $this->resolver = new PanelResolverService($this->db);
    }

    public function testResolveReturnsExistingOrgPanel(): void
    {
        // Assumes the DB has a seeded org panel for org 1 of type 'executive'
        // (or that seedDefaultPanel seeds one). Just assert the returned array
        // has required keys.
        $panel = $this->resolver->resolve(1, 'executive');
        $this->assertIsArray($panel);
        $this->assertArrayHasKey('id', $panel);
        $this->assertArrayHasKey('panel_type', $panel);
        $this->assertSame('executive', $panel['panel_type']);
    }

    public function testResolveReturnsMembersAlongWithPanel(): void
    {
        [$panel, $members] = $this->resolver->resolveWithMembers(1, 'product_management');
        $this->assertIsArray($panel);
        $this->assertNotEmpty($members);
        $this->assertArrayHasKey('role_title', $members[0]);
        $this->assertArrayHasKey('prompt_description', $members[0]);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Services/PanelResolverServiceTest.php --no-coverage
```

Expected: FAIL — `Class 'StratFlow\Services\PanelResolverService' not found`

- [ ] **Step 3: Implement PanelResolverService**

Extract directly from the private methods in `SoundingBoardController` (lines containing `findPanel` and `seedDefaultPanel`):

```php
<?php
// src/Services/PanelResolverService.php
namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\PersonaPanel;
use StratFlow\Models\PersonaMember;

class PanelResolverService
{
    // ===========================
    // DEFAULT PANEL DEFINITIONS
    // ===========================

    private const DEFAULT_PANELS = [
        'executive' => [
            'name'    => 'Executive Panel',
            'members' => [
                ['role_title' => 'CEO',                        'prompt_description' => 'You focus on overall strategic vision, market positioning, competitive advantage, and long-term value creation. Evaluate whether this aligns with organisational goals and sustainable growth.'],
                ['role_title' => 'CFO',                        'prompt_description' => 'You focus on financial viability, ROI, cost structures, budget implications, and resource allocation efficiency. Evaluate the financial soundness and risk-adjusted returns.'],
                ['role_title' => 'COO',                        'prompt_description' => 'You focus on operational feasibility, execution risk, process efficiency, scalability, and resource constraints. Evaluate whether this can be delivered practically.'],
                ['role_title' => 'CMO',                        'prompt_description' => 'You focus on market fit, customer value proposition, competitive differentiation, and go-to-market strategy. Evaluate the commercial viability and customer impact.'],
                ['role_title' => 'Enterprise Business Strategist', 'prompt_description' => 'You focus on strategic coherence, portfolio alignment, capability gaps, and transformation readiness. Evaluate how this fits the broader enterprise strategy.'],
            ],
        ],
        'product_management' => [
            'name'    => 'Product Management Panel',
            'members' => [
                ['role_title' => 'Agile Product Manager',   'prompt_description' => 'You focus on backlog prioritisation, stakeholder value, iterative delivery, and outcome-driven planning. Evaluate whether the right things are being built in the right order.'],
                ['role_title' => 'Product Owner',           'prompt_description' => 'You focus on user needs, acceptance criteria clarity, story completeness, and sprint readiness. Evaluate whether requirements are well-defined and deliverable.'],
                ['role_title' => 'Expert System Architect', 'prompt_description' => 'You focus on technical architecture, system design, integration complexity, technical debt, and non-functional requirements. Evaluate the technical soundness and scalability.'],
                ['role_title' => 'Senior Developer',        'prompt_description' => 'You focus on implementation complexity, code quality, testing strategy, and delivery estimates. Evaluate whether the work is practically implementable and well-scoped.'],
            ],
        ],
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Resolve a panel for a given org and type (org-specific → system default → seeded default).
     *
     * @param int    $orgId     Organisation ID
     * @param string $panelType 'executive' or 'product_management'
     * @return array            Panel row
     */
    public function resolve(int $orgId, string $panelType): array
    {
        $panel = $this->findExisting($orgId, $panelType);
        if ($panel !== null) {
            return $panel;
        }
        return $this->seedDefault($panelType);
    }

    /**
     * Resolve panel and return both the panel row and its members array.
     *
     * @param int    $orgId     Organisation ID
     * @param string $panelType 'executive' or 'product_management'
     * @return array{0: array, 1: array}  [panel, members]
     */
    public function resolveWithMembers(int $orgId, string $panelType): array
    {
        $panel   = $this->resolve($orgId, $panelType);
        $members = PersonaMember::findByPanelId($this->db, (int) $panel['id']);
        return [$panel, $members];
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Look for an existing panel (org-specific first, then system defaults).
     */
    private function findExisting(int $orgId, string $panelType): ?array
    {
        $orgPanels = PersonaPanel::findByOrgId($this->db, $orgId);
        foreach ($orgPanels as $panel) {
            if ($panel['panel_type'] === $panelType) {
                return $panel;
            }
        }

        $defaults = PersonaPanel::findDefaults($this->db);
        foreach ($defaults as $panel) {
            if ($panel['panel_type'] === $panelType) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * Create a system-default panel + members from the built-in definitions.
     */
    private function seedDefault(string $panelType): array
    {
        $definition = self::DEFAULT_PANELS[$panelType] ?? self::DEFAULT_PANELS['executive'];
        $panelId    = PersonaPanel::create($this->db, [
            'org_id'     => null,
            'panel_type' => $panelType,
            'name'       => $definition['name'],
        ]);
        foreach ($definition['members'] as $member) {
            PersonaMember::create($this->db, [
                'panel_id'           => $panelId,
                'role_title'         => $member['role_title'],
                'prompt_description' => $member['prompt_description'],
            ]);
        }
        return PersonaPanel::findById($this->db, $panelId);
    }
}
```

- [ ] **Step 4: Update SoundingBoardController to use PanelResolverService**

In `src/Controllers/SoundingBoardController.php`:

Replace the private helper calls in `evaluate()`:

```php
// OLD (lines containing findPanel and seedDefaultPanel calls):
$panel   = $this->findPanel($orgId, $panelType);
$members = $panel ? PersonaMember::findByPanelId($this->db, (int) $panel['id']) : [];
if (empty($members)) {
    $panel   = $this->seedDefaultPanel($panelType);
    $members = PersonaMember::findByPanelId($this->db, (int) $panel['id']);
}

// NEW:
$resolver          = new PanelResolverService($this->db);
[$panel, $members] = $resolver->resolveWithMembers($orgId, $panelType);
```

Add `use StratFlow\Services\PanelResolverService;` to the imports.

Delete the private `findPanel()` and `seedDefaultPanel()` methods from `SoundingBoardController` (they are now in `PanelResolverService`).

Also fix the latent `$orgId` bug while you're in this file — confirm `$orgId = (int) $user['org_id'];` is set immediately after `$user = $this->auth->user();` in `evaluate()`.

- [ ] **Step 5: Run all sounding board tests to confirm no regression**

```bash
vendor/bin/phpunit tests/Unit/Services/PanelResolverServiceTest.php tests/Unit/Services/SoundingBoardServiceTest.php tests/Integration/EvaluationResultTest.php --no-coverage
```

Expected: All PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/PanelResolverService.php src/Controllers/SoundingBoardController.php tests/Unit/Services/PanelResolverServiceTest.php
git commit -m "refactor: extract PanelResolverService from SoundingBoardController, fix latent orgId bug"
```

---

## Task 4: BoardReviewPrompt

**Files:**
- Create: `src/Services/Prompts/BoardReviewPrompt.php`

The prompt must tell the AI exactly what JSON structure to return. `generateJson()` handles parse/retry, but the schema must be unambiguous.

- [ ] **Step 1: Implement BoardReviewPrompt**

No failing test needed here — the prompt is a pure string builder with no logic branches worth unit-testing. Verify by inspection and integration test in Task 6.

```php
<?php
// src/Services/Prompts/BoardReviewPrompt.php
namespace StratFlow\Services\Prompts;

class BoardReviewPrompt
{
    // ===========================
    // LEVEL LABELS
    // ===========================

    private const LEVEL_LABELS = [
        'devils_advocate' => "Devil's Advocate — push back hard but remain constructive",
        'red_teaming'     => "Red Teaming — identify every failure mode, assume adversarial conditions",
        'gordon_ramsay'   => "Gordon Ramsay Mode — brutally direct, no sugar-coating, hold nothing back",
    ];

    // ===========================
    // BUILD
    // ===========================

    /**
     * Build the full AI prompt for a board review.
     *
     * @param array  $members         Panel member rows (role_title, prompt_description)
     * @param string $evaluationLevel One of: devils_advocate, red_teaming, gordon_ramsay
     * @param string $screenContext   One of: summary, roadmap, work_items, user_stories
     * @param string $screenContent   The page content to evaluate
     * @return string                 Complete prompt string ready for generateJson()
     */
    public static function build(
        array  $members,
        string $evaluationLevel,
        string $screenContext,
        string $screenContent
    ): string {
        $levelLabel         = self::LEVEL_LABELS[$evaluationLevel] ?? self::LEVEL_LABELS['devils_advocate'];
        $memberDescriptions = self::formatMembers($members);
        $changesSchema      = self::changesSchema($screenContext);
        $roleNames          = implode(', ', array_column($members, 'role_title'));

        return <<<PROMPT
You are simulating a virtual board review session. The board members are: {$roleNames}.

## Evaluation Mode
{$levelLabel}

## Board Member Personas
{$memberDescriptions}

## Your Task
Simulate a multi-turn deliberation between the board members reviewing the content below.
Produce 10–14 conversation turns where members challenge, build on, and respond to each other's points.
After the deliberation, the board reaches a collective consensus and produces a single recommendation.

## Required JSON Output Format
Return ONLY valid JSON matching this exact structure — no markdown fences, no prose before or after:

{
  "conversation": [
    { "speaker": "<role_title>", "message": "<message text>" }
  ],
  "recommendation": {
    "summary": "<1-2 sentence summary of the board's collective verdict>",
    "rationale": "<2-3 sentences explaining the reasoning behind the recommendation>",
    "proposed_changes": {$changesSchema}
  }
}

The "conversation" array must have 10–14 entries.
The "proposed_changes" must match the schema shown above exactly.

## Content to Review
{$screenContent}
PROMPT;
    }

    // ===========================
    // HELPERS
    // ===========================

    private static function formatMembers(array $members): string
    {
        $lines = [];
        foreach ($members as $m) {
            $lines[] = "- **{$m['role_title']}**: {$m['prompt_description']}";
        }
        return implode("\n", $lines);
    }

    private static function changesSchema(string $screenContext): string
    {
        return match ($screenContext) {
            'summary'     => '{ "revised_summary": "<full rewritten summary text>" }',
            'roadmap'     => '{ "revised_mermaid_code": "<complete valid Mermaid diagram code>" }',
            'work_items'  => '{ "items": [ { "action": "add|modify|remove", "id": null, "title": "<title>", "description": "<description>" } ] }',
            'user_stories' => '{ "stories": [ { "action": "add|modify|remove", "id": null, "title": "<title>", "description": "<description>" } ] }',
            default       => '{}',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/Prompts/BoardReviewPrompt.php
git commit -m "feat: add BoardReviewPrompt with exact JSON schema per screen context"
```

---

## Task 5: BoardReviewService

**Files:**
- Create: `src/Services/BoardReviewService.php`
- Create: `tests/Unit/Services/BoardReviewServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Services/BoardReviewServiceTest.php
namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\BoardReviewService;
use StratFlow\Services\GeminiService;

class BoardReviewServiceTest extends TestCase
{
    public function testRunReturnsConversationAndRecommendation(): void
    {
        $fakeResult = [
            'conversation' => [
                ['speaker' => 'CEO', 'message' => 'This looks weak.'],
                ['speaker' => 'CFO', 'message' => 'Agreed on cost risk.'],
            ],
            'recommendation' => [
                'summary'          => 'Board recommends a revised approach.',
                'rationale'        => 'Cost risk is unacceptable.',
                'proposed_changes' => ['revised_summary' => 'New summary text.'],
            ],
        ];

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())
               ->method('generateJson')
               ->willReturn($fakeResult);

        $service = new BoardReviewService($gemini);
        $result  = $service->run(
            members:         [['role_title' => 'CEO', 'prompt_description' => 'Focus on vision.'], ['role_title' => 'CFO', 'prompt_description' => 'Focus on cost.']],
            evaluationLevel: 'devils_advocate',
            screenContext:   'summary',
            screenContent:   'Our strategy is to grow fast.'
        );

        $this->assertArrayHasKey('conversation', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertArrayHasKey('proposed_changes', $result['recommendation']);
        $this->assertCount(2, $result['conversation']);
    }

    public function testRunThrowsOnMissingConversationKey(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn(['recommendation' => ['summary' => 'x', 'rationale' => 'x', 'proposed_changes' => []]]);

        $service = new BoardReviewService($gemini);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conversation/i');

        $service->run(
            members:         [['role_title' => 'CEO', 'prompt_description' => 'x']],
            evaluationLevel: 'devils_advocate',
            screenContext:   'summary',
            screenContent:   'content'
        );
    }

    public function testRunThrowsOnMissingProposedChanges(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            'conversation'   => [['speaker' => 'CEO', 'message' => 'hi']],
            'recommendation' => ['summary' => 'x', 'rationale' => 'x'],
        ]);

        $service = new BoardReviewService($gemini);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/proposed_changes/i');

        $service->run(
            members:         [['role_title' => 'CEO', 'prompt_description' => 'x']],
            evaluationLevel: 'devils_advocate',
            screenContext:   'summary',
            screenContent:   'content'
        );
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Unit/Services/BoardReviewServiceTest.php --no-coverage
```

Expected: FAIL — `Class 'StratFlow\Services\BoardReviewService' not found`

- [ ] **Step 3: Implement BoardReviewService**

```php
<?php
// src/Services/BoardReviewService.php
namespace StratFlow\Services;

use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\BoardReviewPrompt;

class BoardReviewService
{
    // ===========================
    // PROPERTIES
    // ===========================

    private GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    // ===========================
    // RUN
    // ===========================

    /**
     * Execute a board review: call the AI, validate the response shape, return parsed result.
     *
     * @param array  $members         Panel member rows (role_title, prompt_description)
     * @param string $evaluationLevel devils_advocate | red_teaming | gordon_ramsay
     * @param string $screenContext   summary | roadmap | work_items | user_stories
     * @param string $screenContent   The content to evaluate
     * @return array                  Validated response with keys: conversation, recommendation (incl. proposed_changes)
     * @throws \RuntimeException      If AI response is missing required keys
     */
    public function run(
        array  $members,
        string $evaluationLevel,
        string $screenContext,
        string $screenContent
    ): array {
        $prompt = BoardReviewPrompt::build($members, $evaluationLevel, $screenContext, $screenContent);
        $result = $this->gemini->generateJson($prompt, '');

        if (empty($result['conversation']) || !is_array($result['conversation'])) {
            throw new \RuntimeException('Board review AI response missing required "conversation" array');
        }
        if (!isset($result['recommendation']) || !is_array($result['recommendation'])) {
            throw new \RuntimeException('Board review AI response missing required "recommendation" object');
        }
        if (!array_key_exists('proposed_changes', $result['recommendation'])) {
            throw new \RuntimeException('Board review AI response missing required "proposed_changes" in recommendation');
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
vendor/bin/phpunit tests/Unit/Services/BoardReviewServiceTest.php --no-coverage
```

Expected: 3 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/BoardReviewService.php tests/Unit/Services/BoardReviewServiceTest.php
git commit -m "feat: add BoardReviewService with validation (TDD)"
```

---

## Task 6: BoardReviewController

**Files:**
- Create: `src/Controllers/BoardReviewController.php`
- Create: `tests/Integration/BoardReviewControllerTest.php`

- [ ] **Step 1: Write integration tests**

```php
<?php
// tests/Integration/BoardReviewControllerTest.php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpTestHelper;
use Tests\Helpers\DatabaseTestHelper;

class BoardReviewControllerTest extends TestCase
{
    private $db;
    private HttpTestHelper $http;

    protected function setUp(): void
    {
        $this->db   = DatabaseTestHelper::connection();
        $this->http = new HttpTestHelper();
        $this->http->loginAsTestUser(); // uses seeded test user with has_evaluation_board = true
        $this->db->query("DELETE FROM board_reviews WHERE project_id = 1");
    }

    public function testEvaluateReturns201WithIdAndConversation(): void
    {
        $response = $this->http->postJson('/app/board-review/evaluate', [
            'project_id'       => 1,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'summary',
            'screen_content'   => 'Our strategy is to grow fast in the SMB market.',
        ]);
        $this->assertSame(201, $response['status']);
        $this->assertArrayHasKey('id', $response['body']);
        $this->assertArrayHasKey('conversation', $response['body']);
        $this->assertArrayHasKey('recommendation', $response['body']);
        $this->assertIsInt($response['body']['id']);
    }

    public function testEvaluateReturns403WithoutSubscription(): void
    {
        $this->http->loginAsTestUserWithoutEvaluationBoard();
        $response = $this->http->postJson('/app/board-review/evaluate', [
            'project_id'       => 1,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'summary',
            'screen_content'   => 'content',
        ]);
        $this->assertSame(403, $response['status']);
    }

    public function testResultsReturnsStoredReview(): void
    {
        // Create a review first
        $evalResponse = $this->http->postJson('/app/board-review/evaluate', [
            'project_id'       => 1,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'summary',
            'screen_content'   => 'content for results test',
        ]);
        $id = $evalResponse['body']['id'];

        $response = $this->http->get("/app/board-review/results/{$id}");
        $this->assertSame(200, $response['status']);
        $this->assertSame($id, $response['body']['id']);
        $this->assertSame('pending', $response['body']['status']);
    }

    public function testRejectSetsStatusRejected(): void
    {
        $evalResponse = $this->http->postJson('/app/board-review/evaluate', [
            'project_id'       => 1,
            'evaluation_level' => 'devils_advocate',
            'screen_context'   => 'summary',
            'screen_content'   => 'content for reject test',
        ]);
        $id = $evalResponse['body']['id'];

        $response = $this->http->postJson("/app/board-review/{$id}/reject", []);
        $this->assertSame(200, $response['status']);

        $row = \StratFlow\Models\BoardReview::findById($this->db, $id);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('rejected', $row['status']); // status updated to rejected
        // Verify ai_summary NOT changed
        $doc = $this->db->query("SELECT ai_summary FROM documents WHERE project_id = 1 ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotSame('', $doc['ai_summary'] ?? ''); // original untouched
    }

    public function testHistoryReturnsProjectReviews(): void
    {
        $response = $this->http->get('/app/board-review/history?project_id=1');
        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['body']);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/phpunit tests/Integration/BoardReviewControllerTest.php --no-coverage
```

Expected: FAIL — route not found (404).

- [ ] **Step 3: Implement BoardReviewController**

```php
<?php
// src/Controllers/BoardReviewController.php
namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\BoardReview;
use StratFlow\Models\Subscription;
use StratFlow\Policies\ProjectPolicy;
use StratFlow\Services\BoardReviewService;
use StratFlow\Services\GeminiService;
use StratFlow\Services\PanelResolverService;

class BoardReviewController
{
    // ===========================
    // PROPERTIES
    // ===========================

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

    // ===========================
    // BOARD TYPE MAPPING
    // ===========================

    private const BOARD_TYPE_MAP = [
        'summary'      => 'executive',
        'roadmap'      => 'executive',
        'work_items'   => 'product_management',
        'user_stories' => 'product_management',
    ];

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * Run a board review AI evaluation for a given screen context.
     *
     * Expects JSON body: project_id, evaluation_level, screen_context, screen_content.
     * Returns JSON with id, conversation, recommendation.
     */
    public function evaluate(): void
    {
        $body = json_decode($this->request->body(), true);
        if (!$body) {
            $this->response->json(['error' => 'Invalid JSON body'], 400);
            return;
        }

        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $user['id'];

        $projectId = (int) ($body['project_id'] ?? 0);
        $project   = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        if (!Subscription::hasEvaluationBoard($this->db, $orgId)) {
            $this->response->json(['error' => 'Evaluation board not available on your plan'], 403);
            return;
        }

        $evaluationLevel = $body['evaluation_level'] ?? 'devils_advocate';
        $screenContext   = $body['screen_context']   ?? '';
        $screenContent   = $body['screen_content']   ?? '';

        $validLevels = ['devils_advocate', 'red_teaming', 'gordon_ramsay'];
        if (!in_array($evaluationLevel, $validLevels, true)) {
            $evaluationLevel = 'devils_advocate';
        }

        $validContexts = ['summary', 'roadmap', 'work_items', 'user_stories'];
        if (!in_array($screenContext, $validContexts, true) || empty($screenContent)) {
            $this->response->json(['error' => 'Invalid or missing screen_context / screen_content'], 400);
            return;
        }

        $boardType = self::BOARD_TYPE_MAP[$screenContext];
        $panelType = $boardType; // 'executive' | 'product_management'

        $resolver          = new PanelResolverService($this->db);
        [$panel, $members] = $resolver->resolveWithMembers($orgId, $panelType);

        if (empty($members)) {
            $this->response->json(['error' => 'No panel members configured'], 500);
            return;
        }

        $gemini  = new GeminiService($this->config);
        $service = new BoardReviewService($gemini);

        try {
            $result = $service->run($members, $evaluationLevel, $screenContext, $screenContent);
        } catch (\RuntimeException $e) {
            $this->response->json(['error' => 'Board review failed: ' . $e->getMessage()], 500);
            return;
        }

        $reviewId = BoardReview::create($this->db, [
            'project_id'          => $projectId,
            'panel_id'            => (int) $panel['id'],
            'board_type'          => $boardType,
            'evaluation_level'    => $evaluationLevel,
            'screen_context'      => $screenContext,
            'content_snapshot'    => $screenContent,
            'conversation_json'   => json_encode($result['conversation']),
            'recommendation_json' => json_encode([
                'summary'   => $result['recommendation']['summary'],
                'rationale' => $result['recommendation']['rationale'],
            ]),
            'proposed_changes'    => json_encode($result['recommendation']['proposed_changes']),
        ]);

        $this->response->json([
            'id'             => $reviewId,
            'conversation'   => $result['conversation'],
            'recommendation' => $result['recommendation'],
        ], 201);
    }

    /**
     * Fetch a stored board review by ID.
     *
     * @param int $id BoardReview primary key
     */
    public function results(int $id): void
    {
        $user  = $this->auth->user();
        $review = BoardReview::findById($this->db, $id);

        if ($review === null) {
            $this->response->json(['error' => 'Review not found'], 404);
            return;
        }

        $project = ProjectPolicy::findViewableProject($this->db, $user, (int) $review['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        $review['conversation']   = json_decode($review['conversation_json'], true);
        $review['recommendation'] = json_decode($review['recommendation_json'], true);
        $review['proposed_changes'] = json_decode($review['proposed_changes'], true);
        unset($review['conversation_json'], $review['recommendation_json']);

        $this->response->json($review);
    }

    /**
     * Accept a board review — apply proposed_changes to the underlying data.
     *
     * @param int $id BoardReview primary key
     */
    public function accept(int $id): void
    {
        $user   = $this->auth->user();
        $userId = (int) $user['id'];

        // Use FOR UPDATE to prevent TOCTOU: two concurrent accepts both passing the pending check
        $this->db->beginTransaction();
        try {
            $review = BoardReview::findByIdForUpdate($this->db, $id); // SELECT … FOR UPDATE

            if ($review === null) {
                $this->db->rollback();
                $this->response->json(['error' => 'Review not found'], 404);
                return;
            }
            if ($review['status'] !== 'pending') {
                $this->db->rollback();
                $this->response->json(['error' => 'Review already responded to'], 409);
                return;
            }

            $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $review['project_id']);
            if ($project === null) {
                $this->db->rollback();
                $this->response->json(['error' => 'Access denied'], 403);
                return;
            }

            $changes = json_decode($review['proposed_changes'], true) ?? [];
            $this->applyChanges($review['screen_context'], (int) $review['project_id'], $changes);
            BoardReview::updateStatus($this->db, $id, 'accepted', $userId);
            $this->db->commit();
        } catch (\RuntimeException $e) {
            $this->db->rollback();
            $this->response->json(['error' => 'Failed to apply changes: ' . $e->getMessage()], 500);
            return;
        }

        $this->response->json(['status' => 'accepted', 'id' => $id]);
    }

    /**
     * Reject a board review — record the outcome, apply no changes.
     *
     * @param int $id BoardReview primary key
     */
    public function reject(int $id): void
    {
        $user   = $this->auth->user();
        $userId = (int) $user['id'];
        $review = BoardReview::findById($this->db, $id);

        if ($review === null) {
            $this->response->json(['error' => 'Review not found'], 404);
            return;
        }
        if ($review['status'] !== 'pending') {
            $this->response->json(['error' => 'Review already responded to'], 409);
            return;
        }

        $project = ProjectPolicy::findEditableProject($this->db, $user, (int) $review['project_id']);
        if ($project === null) {
            $this->response->json(['error' => 'Access denied'], 403);
            return;
        }

        BoardReview::updateStatus($this->db, $id, 'rejected', $userId);
        $this->response->json(['status' => 'rejected', 'id' => $id]);
    }

    /**
     * Return board review history for a project.
     *
     * Expects query param: project_id.
     */
    public function history(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->get('project_id', 0);

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->json(['error' => 'Project not found'], 404);
            return;
        }

        $reviews = BoardReview::findByProjectId($this->db, $projectId);
        foreach ($reviews as &$r) {
            $r['conversation']    = json_decode($r['conversation_json'], true);
            $r['recommendation']  = json_decode($r['recommendation_json'], true);
            $r['proposed_changes'] = json_decode($r['proposed_changes'], true);
            unset($r['conversation_json'], $r['recommendation_json'], $r['content_snapshot']);
        }

        $this->response->json($reviews);
    }

    // ===========================
    // APPLY CHANGES
    // ===========================

    /**
     * Dispatch proposed changes to the correct apply method based on screen context.
     *
     * @param string $screenContext summary | roadmap | work_items | user_stories
     * @param int    $projectId     Project primary key
     * @param array  $changes       Decoded proposed_changes object
     * @throws \RuntimeException    On DB failure or invalid changes structure
     */
    private function applyChanges(string $screenContext, int $projectId, array $changes): void
    {
        match ($screenContext) {
            'summary'      => $this->applySummaryChanges($projectId, $changes),
            'roadmap'      => $this->applyRoadmapChanges($projectId, $changes),
            'work_items'   => $this->applyWorkItemChanges($projectId, $changes),
            'user_stories' => $this->applyUserStoryChanges($projectId, $changes),
            default        => throw new \RuntimeException("Unknown screen context: {$screenContext}"),
        };
    }

    /**
     * Update documents.ai_summary for the project's most recent document.
     */
    private function applySummaryChanges(int $projectId, array $changes): void
    {
        $revised = $changes['revised_summary'] ?? null;
        if (empty($revised)) {
            throw new \RuntimeException('proposed_changes missing revised_summary');
        }
        $this->db->query(
            "UPDATE documents SET ai_summary = :summary
             WHERE project_id = :project_id
             ORDER BY id DESC LIMIT 1",
            [':summary' => $revised, ':project_id' => $projectId]
        );
    }

    /**
     * Update strategy_diagrams.mermaid_code for the project's most recent diagram.
     * Re-parses node count after update (matching DiagramController approach).
     */
    private function applyRoadmapChanges(int $projectId, array $changes): void
    {
        $revised = $changes['revised_mermaid_code'] ?? null;
        if (empty($revised)) {
            throw new \RuntimeException('proposed_changes missing revised_mermaid_code');
        }
        $this->db->query(
            "UPDATE strategy_diagrams SET mermaid_code = :code
             WHERE project_id = :project_id
             ORDER BY id DESC LIMIT 1",
            [':code' => $revised, ':project_id' => $projectId]
        );
    }

    /**
     * Apply add/modify/remove actions to hl_work_items inside a transaction.
     * IDs are validated before modification/removal.
     */
    private function applyWorkItemChanges(int $projectId, array $changes): void
    {
        $items = $changes['items'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('proposed_changes missing items array');
        }

        $this->db->beginTransaction();
        try {
            foreach ($items as $item) {
                $action = $item['action'] ?? '';
                match ($action) {
                    'add' => $this->db->query(
                        "INSERT INTO hl_work_items (project_id, priority_number, title, description, created_at)
                         VALUES (:project_id,
                                 COALESCE((SELECT MAX(priority_number) FROM hl_work_items WHERE project_id = :project_id2), 0) + 100,
                                 :title, :description, NOW())",
                        [':project_id' => $projectId, ':project_id2' => $projectId, ':title' => $item['title'] ?? '', ':description' => $item['description'] ?? '']
                    ),
                    'modify' => $this->db->query(
                        "UPDATE hl_work_items SET title = :title, description = :description
                         WHERE id = :id AND project_id = :project_id",
                        [':id' => (int) ($item['id'] ?? 0), ':project_id' => $projectId, ':title' => $item['title'] ?? '', ':description' => $item['description'] ?? '']
                    ),
                    'remove' => $this->db->query(
                        "DELETE FROM hl_work_items WHERE id = :id AND project_id = :project_id",
                        [':id' => (int) ($item['id'] ?? 0), ':project_id' => $projectId]
                    ),
                    default => throw new \RuntimeException("Unknown work item action: {$action}"),
                };
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw new \RuntimeException('Work item update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply add/modify/remove actions to user_stories inside a transaction.
     * IDs are validated before modification/removal.
     */
    private function applyUserStoryChanges(int $projectId, array $changes): void
    {
        $stories = $changes['stories'] ?? null;
        if (!is_array($stories)) {
            throw new \RuntimeException('proposed_changes missing stories array');
        }

        $this->db->beginTransaction();
        try {
            foreach ($stories as $story) {
                $action = $story['action'] ?? '';
                match ($action) {
                    'add' => $this->db->query(
                        "INSERT INTO user_stories (project_id, priority_number, title, description, created_at)
                         VALUES (:project_id,
                                 COALESCE((SELECT MAX(priority_number) FROM user_stories WHERE project_id = :project_id2), 0) + 100,
                                 :title, :description, NOW())",
                        [':project_id' => $projectId, ':project_id2' => $projectId, ':title' => $story['title'] ?? '', ':description' => $story['description'] ?? '']
                    ),
                    'modify' => $this->db->query(
                        "UPDATE user_stories SET title = :title, description = :description
                         WHERE id = :id AND project_id = :project_id",
                        [':id' => (int) ($story['id'] ?? 0), ':project_id' => $projectId, ':title' => $story['title'] ?? '', ':description' => $story['description'] ?? '']
                    ),
                    'remove' => $this->db->query(
                        "DELETE FROM user_stories WHERE id = :id AND project_id = :project_id",
                        [':id' => (int) ($story['id'] ?? 0), ':project_id' => $projectId]
                    ),
                    default => throw new \RuntimeException("Unknown user story action: {$action}"),
                };
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw new \RuntimeException('User story update failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
```

- [ ] **Step 4: Run integration tests**

```bash
vendor/bin/phpunit tests/Integration/BoardReviewControllerTest.php --no-coverage
```

Expected: All PASS (routes not yet added — this step will still fail; proceed to Task 7 to add routes, then re-run).

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/BoardReviewController.php tests/Integration/BoardReviewControllerTest.php
git commit -m "feat: add BoardReviewController with evaluate/results/accept/reject/history (TDD)"
```

---

## Task 7: Routes

**Files:**
- Modify: `src/Config/routes.php`

- [ ] **Step 1: Add the 5 new routes**

Open `src/Config/routes.php` and add these routes in the `board-review` group, following the same pattern as the existing sounding board routes:

```php
// Board Review
$router->post('/app/board-review/evaluate',       [BoardReviewController::class, 'evaluate'],   ['auth', 'workflow_write', 'csrf']);
$router->get('/app/board-review/results/{id}',    [BoardReviewController::class, 'results'],    ['auth']);
$router->post('/app/board-review/{id}/accept',    [BoardReviewController::class, 'accept'],     ['auth', 'workflow_write', 'csrf']);
$router->post('/app/board-review/{id}/reject',    [BoardReviewController::class, 'reject'],     ['auth', 'workflow_write', 'csrf']);
$router->get('/app/board-review/history',         [BoardReviewController::class, 'history'],    ['auth']);
```

Add `use StratFlow\Controllers\BoardReviewController;` to the imports at the top of `routes.php`.

- [ ] **Step 2: Run integration tests**

```bash
vendor/bin/phpunit tests/Integration/BoardReviewControllerTest.php --no-coverage
```

Expected: All PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Config/routes.php
git commit -m "feat: register board review routes"
```

---

## Task 8: Page Controller Updates (Pass `has_evaluation_board`)

**Files:**
- Modify: `src/Controllers/DiagramController.php`
- Modify: `src/Controllers/UploadController.php`
- Modify: `src/Controllers/WorkItemsController.php`
- Modify: `src/Controllers/UserStoriesController.php`

Check each controller's render call to confirm `has_evaluation_board` is passed. If any are missing, add it following this pattern (copy from whichever controller already has it):

- [ ] **Step 1: Check DiagramController**

Search for `has_evaluation_board` in `src/Controllers/DiagramController.php`. If not present, add it to the data array passed to the template:

```php
'has_evaluation_board' => Subscription::hasEvaluationBoard($this->db, $orgId),
```

- [ ] **Step 2: Repeat for UploadController, WorkItemsController, UserStoriesController**

Same check and same fix if missing.

- [ ] **Step 3: Run full test suite to confirm no regression**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: All PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/DiagramController.php src/Controllers/UploadController.php src/Controllers/WorkItemsController.php src/Controllers/UserStoriesController.php
git commit -m "feat: pass has_evaluation_board to diagram/upload/work-items/user-stories templates"
```

---

## Task 9: Button and Modal Partials

**Files:**
- Create: `templates/partials/board-review-button.php`
- Create: `templates/partials/board-review-modal.php`
- Modify: `templates/layouts/app.php`
- Modify: `templates/upload.php`
- Modify: `templates/diagram.php`
- Modify: `templates/work-items.php`
- Modify: `templates/user-stories.php`

- [ ] **Step 1: Create board-review-button.php**

```php
<?php
// templates/partials/board-review-button.php
// Required variables (passed from parent template):
//   $screenContext       — 'summary' | 'roadmap' | 'work_items' | 'user_stories'
//   $has_evaluation_board — bool
if (!($has_evaluation_board ?? false)) {
    return;
}

$labels = [
    'summary'      => 'Virtual Executive Board',
    'roadmap'      => 'Virtual Executive Board',
    'work_items'   => 'Virtual Product Management Board',
    'user_stories' => 'Virtual Product Management Board',
];
$label = $labels[$screenContext] ?? 'Virtual Board Review';
?>
<button
    type="button"
    class="btn btn-outline-primary btn-sm"
    onclick="openBoardReview('<?= htmlspecialchars($screenContext) ?>')"
    data-context="<?= htmlspecialchars($screenContext) ?>">
    <?= htmlspecialchars($label) ?>
</button>
```

- [ ] **Step 2: Create board-review-modal.php**

```php
<?php
// templates/partials/board-review-modal.php
?>
<div id="boardReviewModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Config view -->
            <div id="brConfig">
                <div class="modal-header">
                    <h5 class="modal-title" id="brModalTitle">Virtual Board Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Criticism Level</label>
                    <div class="d-flex gap-3 flex-wrap">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="brLevel" id="brLevelDA" value="devils_advocate" checked>
                            <label class="form-check-label" for="brLevelDA">Devil's Advocate</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="brLevel" id="brLevelRT" value="red_teaming">
                            <label class="form-check-label" for="brLevelRT">Red Teaming</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="brLevel" id="brLevelGR" value="gordon_ramsay">
                            <label class="form-check-label" for="brLevelGR">Gordon Ramsay</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="brStartBtn" onclick="runBoardReview()">Start Review</button>
                </div>
            </div>

            <!-- Loading view -->
            <div id="brLoading" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div class="spinner-border text-primary mb-3"></div>
                    <p class="text-muted">The board is deliberating…</p>
                </div>
            </div>

            <!-- Results view -->
            <div id="brResults" style="display:none;">
                <div class="modal-header">
                    <h5 class="modal-title">Board Deliberation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Chat thread -->
                    <div id="brConversation" class="br-chat-thread mb-4"></div>
                    <!-- Recommendation card -->
                    <div class="card br-recommendation-card">
                        <div class="card-header fw-semibold">Board Recommendation</div>
                        <div class="card-body">
                            <p id="brRecSummary" class="mb-2"></p>
                            <p id="brRecRationale" class="text-muted small mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="rejectBoardReview()">Reject</button>
                    <button type="button" class="btn btn-success" onclick="acceptBoardReview()">Accept Recommendation</button>
                </div>
            </div>

            <!-- Error view -->
            <div id="brError" style="display:none;">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Review Failed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="brErrorMsg" class="text-danger"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>

        </div>
    </div>
</div>
```

- [ ] **Step 3: Include modal in layouts/app.php**

Find the section where other modals are included (e.g. sounding board modal) and add:

```php
<?php require __DIR__ . '/../partials/board-review-modal.php'; ?>
```

- [ ] **Step 4: Add buttons to the 4 page templates**

In `templates/upload.php` — inside the action buttons area, after the summary is generated (follow the same conditional pattern as the sounding board button):

```php
<?php if (!empty($summary)): ?>
    <?php $screenContext = 'summary'; include __DIR__ . '/../partials/board-review-button.php'; ?>
<?php endif; ?>
```

In `templates/diagram.php` — after diagram exists:

```php
<?php if (!empty($diagram)): ?>
    <?php $screenContext = 'roadmap'; include __DIR__ . '/../partials/board-review-button.php'; ?>
<?php endif; ?>
```

In `templates/work-items.php`:

```php
<?php $screenContext = 'work_items'; include __DIR__ . '/../partials/board-review-button.php'; ?>
```

In `templates/user-stories.php`:

```php
<?php $screenContext = 'user_stories'; include __DIR__ . '/../partials/board-review-button.php'; ?>
```

- [ ] **Step 5: Commit**

```bash
git add templates/partials/board-review-button.php templates/partials/board-review-modal.php templates/layouts/app.php templates/upload.php templates/diagram.php templates/work-items.php templates/user-stories.php
git commit -m "feat: add board review button and modal partials, integrate into 4 page templates"
```

---

## Task 10: JavaScript

**Files:**
- Modify: `public/assets/js/app.js`

Add after the sounding board JS functions. The four new functions mirror the `openSoundingBoard` / `runSoundingBoard` patterns.

- [ ] **Step 1: Add JS functions to app.js**

```javascript
// ===========================
// BOARD REVIEW
// ===========================

let _brContext = '';
let _brProjectId = 0;
let _brReviewId = null;

/**
 * Open the board review modal, set the context, and reset to config view.
 */
function openBoardReview(screenContext) {
    _brContext   = screenContext;
    _brProjectId = window.currentProjectId || 0;
    _brReviewId  = null;

    const labels = {
        summary:      'Virtual Executive Board',
        roadmap:      'Virtual Executive Board',
        work_items:   'Virtual Product Management Board',
        user_stories: 'Virtual Product Management Board',
    };
    document.getElementById('brModalTitle').textContent = labels[screenContext] || 'Virtual Board Review';

    ['brConfig', 'brLoading', 'brResults', 'brError'].forEach(id => {
        document.getElementById(id).style.display = (id === 'brConfig') ? '' : 'none';
    });
    document.getElementById('brStartBtn').disabled = false;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('boardReviewModal'));
    modal.show();
}

/**
 * Call the evaluate endpoint and render the board deliberation on success.
 */
async function runBoardReview() {
    const level = document.querySelector('input[name="brLevel"]:checked')?.value || 'devils_advocate';

    document.getElementById('brConfig').style.display  = 'none';
    document.getElementById('brLoading').style.display = '';

    // Collect screen content from the current page context
    const screenContent = collectBoardReviewContent(_brContext);

    try {
        const res = await fetch('/app/board-review/evaluate', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken || '' },
            body:    JSON.stringify({
                project_id:       _brProjectId,
                evaluation_level: level,
                screen_context:   _brContext,
                screen_content:   screenContent,
            }),
        });

        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || `Server error ${res.status}`);
        }

        _brReviewId = data.id;
        renderBoardReview(data.conversation, data.recommendation);

    } catch (err) {
        document.getElementById('brLoading').style.display = 'none';
        document.getElementById('brError').style.display   = '';
        document.getElementById('brErrorMsg').textContent  = err.message;
    }
}

/**
 * Render conversation turns and recommendation card, switch to results view.
 */
function renderBoardReview(conversation, recommendation) {
    const thread = document.getElementById('brConversation');
    thread.innerHTML = conversation.map(turn => `
        <div class="br-chat-bubble">
            <span class="br-speaker">${escapeHtml(turn.speaker)}</span>
            <p class="br-message">${escapeHtml(turn.message)}</p>
        </div>
    `).join('');

    document.getElementById('brRecSummary').textContent  = recommendation.summary  || '';
    document.getElementById('brRecRationale').textContent = recommendation.rationale || '';

    document.getElementById('brLoading').style.display  = 'none';
    document.getElementById('brResults').style.display  = '';
}

/**
 * Accept the board recommendation — applies proposed changes server-side.
 */
async function acceptBoardReview() {
    if (!_brReviewId) return;

    try {
        const res = await fetch(`/app/board-review/${_brReviewId}/accept`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken || '' },
            body:    JSON.stringify({}),
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `Server error ${res.status}`);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('boardReviewModal')).hide();
        window.location.reload(); // Refresh page to show applied changes

    } catch (err) {
        document.getElementById('brResults').style.display = 'none';
        document.getElementById('brError').style.display   = '';
        document.getElementById('brErrorMsg').textContent  = 'Accept failed: ' + err.message;
    }
}

/**
 * Reject the board recommendation — records outcome, no changes applied.
 */
async function rejectBoardReview() {
    if (!_brReviewId) return;

    try {
        const res = await fetch(`/app/board-review/${_brReviewId}/reject`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken || '' },
            body:    JSON.stringify({}),
        });

        if (!res.ok) {
            const data = await res.json();
            throw new Error(data.error || `Server error ${res.status}`);
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('boardReviewModal')).hide();

    } catch (err) {
        document.getElementById('brResults').style.display = 'none';
        document.getElementById('brError').style.display   = '';
        document.getElementById('brErrorMsg').textContent  = 'Reject failed: ' + err.message;
    }
}

/**
 * Collect the relevant page content for the current screen context.
 * Each context reads from the DOM element that holds the live content.
 */
function collectBoardReviewContent(context) {
    switch (context) {
        case 'summary':
            return document.getElementById('aiSummaryText')?.innerText
                || document.getElementById('aiSummaryContent')?.innerText
                || '';
        case 'roadmap':
            return document.getElementById('mermaidSource')?.value
                || document.getElementById('diagramMermaidCode')?.textContent
                || '';
        case 'work_items':
            return Array.from(document.querySelectorAll('.work-item-row'))
                .map(el => el.dataset.title + ': ' + el.dataset.description)
                .join('\n');
        case 'user_stories':
            return Array.from(document.querySelectorAll('.user-story-row'))
                .map(el => el.dataset.title + ': ' + el.dataset.description)
                .join('\n');
        default:
            return '';
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
```

**Note:** `collectBoardReviewContent()` reads from DOM elements using IDs/classes that must match the actual page markup. Verify the correct selectors for `aiSummaryText`, `mermaidSource`, `.work-item-row`, and `.user-story-row` by inspecting the rendered HTML of each page before deploying. Update the selectors to match actual element IDs/classes if they differ.

- [ ] **Step 2: Commit**

```bash
git add public/assets/js/app.js
git commit -m "feat: add board review JS (openBoardReview, runBoardReview, acceptBoardReview, rejectBoardReview)"
```

---

## Task 11: CSS

**Files:**
- Modify: `public/assets/css/app.css`

- [ ] **Step 1: Add board review styles to app.css**

```css
/* ===========================
   BOARD REVIEW
   =========================== */

.br-chat-thread {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-height: 420px;
    overflow-y: auto;
    padding: 0.5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    background: var(--bs-body-bg);
}

.br-chat-bubble {
    padding: 0.6rem 0.85rem;
    border-radius: 0.5rem;
    background: var(--bs-secondary-bg);
    border-left: 3px solid var(--bs-primary);
    max-width: 92%;
}

.br-chat-bubble:nth-child(even) {
    align-self: flex-end;
    border-left: none;
    border-right: 3px solid var(--bs-info);
}

.br-speaker {
    display: block;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--bs-primary);
    margin-bottom: 0.25rem;
}

.br-chat-bubble:nth-child(even) .br-speaker {
    color: var(--bs-info);
}

.br-message {
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

.br-recommendation-card {
    border: 2px solid var(--bs-success);
}

.br-recommendation-card .card-header {
    background: rgba(var(--bs-success-rgb), 0.1);
    color: var(--bs-success);
}
```

- [ ] **Step 2: Commit**

```bash
git add public/assets/css/app.css
git commit -m "feat: add board review chat thread and recommendation card styles"
```

---

## Task 12: Final Verification

- [ ] **Step 1: Run the full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: All PASS, no regressions.

- [ ] **Step 2: Smoke-test the feature manually**

1. Log in as a user with `has_evaluation_board = true`.
2. Upload page → generate summary → confirm "Virtual Executive Board" button appears.
3. Click → select criticality → Start Review → verify chat thread renders with named speakers.
4. Accept → confirm `documents.ai_summary` is updated (check DB: `SELECT ai_summary FROM documents WHERE project_id = X ORDER BY id DESC LIMIT 1`).
5. Repeat steps 2–4 for diagram (roadmap), work items, and user stories.
6. Reject → verify no DB changes applied and `board_reviews.status = 'rejected'`.
7. Remove `has_evaluation_board` subscription flag → confirm buttons are hidden on all 4 pages.
8. Test with a user who lacks subscription → confirm `/evaluate` returns 403.

- [ ] **Step 3: Commit any fixes found during smoke test**

```bash
git add -p
git commit -m "fix: smoke test corrections for board review feature"
```

---

## Self-Review Spec Coverage Check

| Spec requirement | Task covering it |
|-----------------|-----------------|
| Virtual Executive Board on Upload + Diagram pages | Tasks 8, 9 |
| Virtual Product Management Board on Work Items + User Stories pages | Tasks 8, 9 |
| Single AI call per review (not N×15) | Task 5 — `BoardReviewService::run()` calls `generateJson()` once |
| Multi-persona chat-thread conversation | Tasks 4, 10, 11 |
| Recommendation card (summary, rationale, changes) | Tasks 4, 5, 10, 11 |
| Accept/Reject as whole recommendation | Tasks 6, 10 |
| Accept applies changes: summary | Task 6 `applySummaryChanges()` |
| Accept applies changes: roadmap (Mermaid wholesale replace) | Task 6 `applyRoadmapChanges()` |
| Accept applies changes: work items (transaction) | Task 6 `applyWorkItemChanges()` |
| Accept applies changes: user stories (transaction) | Task 6 `applyUserStoryChanges()` |
| Reject records outcome, no changes | Task 6 `reject()` |
| Content snapshot for audit | Task 6 — `content_snapshot` stored at evaluate time |
| Subscription gate (`has_evaluation_board`) | Tasks 6, 8, 9 |
| 3 criticality levels | Tasks 4, 9 (modal radio buttons) |
| `board_reviews` table | Task 1 |
| History endpoint | Task 6 `history()`, Task 7 route |
| No regression to existing sounding board | Task 3 — refactor covered by test suite |
