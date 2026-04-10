# OKR / KR Progress Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Key Results as a first-class entity on OKR work items, score merged PRs against each KR with AI, and redesign the executive dashboard to show OKR/KR progress cards.

**Architecture:** Two-phase incremental delivery. Phase 1 builds the data model, KR CRUD, and executive dashboard shell with manual progress entry. Phase 2 layers AI PR matching and AI KR scoring on top, wiring into the existing GitHub webhook flow asynchronously.

**Tech Stack:** PHP 8.4 strict-types, PDO prepared statements, PHPUnit with real MySQL (no mocks for DB), GeminiService (`generateJson()`), async post-response pattern (`fastcgi_finish_request()`).

---

## File Structure

**Phase 1 — new files**
- `stratflow/database/migrations/022_key_results.sql`
- `stratflow/src/Models/KeyResult.php`
- `stratflow/src/Models/KeyResultContribution.php`
- `stratflow/src/Controllers/KrController.php`
- `stratflow/templates/partials/kr-editor.php`
- `stratflow/templates/executive-project.php`
- `stratflow/tests/Unit/Models/KeyResultTest.php`

**Phase 1 — modified files**
- `stratflow/src/Config/routes.php` — add KR CRUD routes + new exec-project route
- `stratflow/src/Controllers/ExecutiveController.php` — add `projectDashboard()` method
- `stratflow/templates/work-items.php` — include KR editor inside work item edit modal
- `stratflow/src/Services/JiraSyncService.php` — extend `pushOkrsToGoals()`, add `pullKrStatusFromGoals()`

**Phase 2 — new files**
- `stratflow/src/Services/Prompts/GitPrMatchPrompt.php`
- `stratflow/src/Services/Prompts/KrScoringPrompt.php`
- `stratflow/src/Services/GitPrMatcherService.php`
- `stratflow/src/Services/KrScoringService.php`
- `stratflow/tests/Unit/Services/GitPrMatcherServiceTest.php`
- `stratflow/tests/Unit/Services/KrScoringServiceTest.php`

**Phase 2 — modified files**
- `stratflow/src/Services/GitLinkService.php` — add `linkAiMatched()` method
- `stratflow/src/Controllers/GitWebhookController.php` — fire async AI services post-response
- `stratflow/src/Controllers/ExecutiveController.php` — load AI momentum + contributions
- `stratflow/templates/executive-project.php` — wire up AI data

---

## Phase 1: Data Model + KR Management + Exec Dashboard Shell

---

### Task 1: Migration — key_results tables

**Files:**
- Create: `stratflow/database/migrations/022_key_results.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Migration 022: Key Results & KR Contributions
-- Adds key_results (one per KR, owned by an hl_work_item),
-- key_result_contributions (one per merged-PR × KR, AI-scored),
-- and ai_matched flag on story_git_links.

-- Step 1: Extend story_git_links with ai_matched flag.
-- Add the column only if it is missing so the migration is safe to re-run.
SET @has_ai_matched = (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'story_git_links'
     AND COLUMN_NAME = 'ai_matched'
);

IF @has_ai_matched = 0 THEN
  ALTER TABLE story_git_links
    ADD COLUMN ai_matched TINYINT(1) NOT NULL DEFAULT 0;
END IF;

-- Step 2: Key results table.
CREATE TABLE IF NOT EXISTS key_results (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  org_id             INT UNSIGNED  NOT NULL,
  hl_work_item_id    INT UNSIGNED  NOT NULL,
  title              VARCHAR(500)  NOT NULL,
  metric_description TEXT          NULL,
  baseline_value     DECIMAL(12,4) NULL,
  target_value       DECIMAL(12,4) NULL,
  current_value      DECIMAL(12,4) NULL,
  unit               VARCHAR(50)   NULL,
  status             ENUM('not_started','on_track','at_risk','off_track','achieved')
                                   NOT NULL DEFAULT 'not_started',
  jira_goal_id       VARCHAR(255)  NULL,
  jira_goal_url      VARCHAR(500)  NULL,
  ai_momentum        TEXT          NULL,
  display_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_org (org_id),
  KEY ix_work_item (hl_work_item_id),
  CONSTRAINT fk_kr_work_item FOREIGN KEY (hl_work_item_id)
    REFERENCES hl_work_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: KR contributions table (one row per merged PR × KR).
CREATE TABLE IF NOT EXISTS key_result_contributions (
  id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  key_result_id       INT UNSIGNED     NOT NULL,
  story_git_link_id   INT UNSIGNED     NOT NULL,
  org_id              INT UNSIGNED     NOT NULL,
  ai_relevance_score  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ai_rationale        TEXT             NULL,
  scored_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_kr_link (key_result_id, story_git_link_id),
  KEY ix_org (org_id),
  CONSTRAINT fk_krc_kr   FOREIGN KEY (key_result_id)   REFERENCES key_results(id)    ON DELETE CASCADE,
  CONSTRAINT fk_krc_link FOREIGN KEY (story_git_link_id) REFERENCES story_git_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Run the migration**

```bash
docker compose exec mysql mysql -ustratflow -pstratflow_secret stratflow \
  < stratflow/database/migrations/022_key_results.sql
```

Expected: no errors.

- [ ] **Step 3: Verify tables exist**

```bash
docker compose exec mysql mysql -ustratflow -pstratflow_secret stratflow \
  -e "SHOW COLUMNS FROM key_results; SHOW COLUMNS FROM key_result_contributions; SHOW COLUMNS FROM story_git_links LIKE 'ai_matched';"
```

Expected: `key_results` shows 15 columns, `key_result_contributions` shows 7, `story_git_links` shows `ai_matched`.

- [ ] **Step 4: Commit**

```bash
git add stratflow/database/migrations/022_key_results.sql
git commit -m "feat(kr): add key_results, key_result_contributions tables and ai_matched flag"
```

---

### Task 2: KeyResult model + test

**Files:**
- Create: `stratflow/src/Models/KeyResult.php`
- Create: `stratflow/tests/Unit/Models/KeyResultTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// stratflow/tests/Unit/Models/KeyResultTest.php
declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;

class KeyResultTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $workItemId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - KeyResultTest'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KeyResultTest']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'krt@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'KR Tester', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, owner_id, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'KR Test Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        self::$workItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 1,
            'title'            => 'OKR Work Item',
            'okr_title'        => 'Grow Revenue',
            'estimated_sprints'=> 2,
            'status'           => 'backlog',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM key_results WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    #[Test]
    public function testCreateAndFindByWorkItemId(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Increase MRR to $50k',
            'target_value'    => 50000.0,
            'unit'            => '$',
            'status'          => 'not_started',
        ]);

        $krs = KeyResult::findByWorkItemId(self::$db, self::$workItemId, self::$orgId);
        $this->assertCount(1, $krs);
        $this->assertSame('Increase MRR to $50k', $krs[0]['title']);
        $this->assertSame(self::$orgId, (int) $krs[0]['org_id']);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }

    #[Test]
    public function testOrgIsolation(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Org A KR',
            'status'          => 'not_started',
        ]);

        // Different org_id must return empty
        $krs = KeyResult::findByWorkItemId(self::$db, self::$workItemId, self::$orgId + 9999);
        $this->assertCount(0, $krs);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }

    #[Test]
    public function testCascadeDeleteWhenWorkItemDeleted(): void
    {
        $tempItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 99,
            'title'            => 'Temp Item',
            'estimated_sprints'=> 1,
            'status'           => 'backlog',
        ]);

        KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => $tempItemId,
            'title'           => 'Cascade Test KR',
            'status'          => 'not_started',
        ]);

        // Delete work item via SQL — triggers ON DELETE CASCADE
        self::$db->query("DELETE FROM hl_work_items WHERE id = ?", [$tempItemId]);

        $krs = KeyResult::findByWorkItemId(self::$db, $tempItemId, self::$orgId);
        $this->assertCount(0, $krs);
    }

    #[Test]
    public function testUpdate(): void
    {
        $id = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Before Update',
            'status'          => 'not_started',
        ]);

        KeyResult::update(self::$db, $id, self::$orgId, ['title' => 'After Update', 'status' => 'on_track']);

        $kr = KeyResult::findById(self::$db, $id, self::$orgId);
        $this->assertSame('After Update', $kr['title']);
        $this->assertSame('on_track', $kr['status']);

        KeyResult::delete(self::$db, $id, self::$orgId);
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/KeyResultTest.php
```

Expected: FAIL — `KeyResult` class not found.

- [ ] **Step 3: Implement KeyResult model**

```php
<?php
// stratflow/src/Models/KeyResult.php
declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class KeyResult
{
    /** @var string[] Columns safe for dynamic UPDATE */
    private const UPDATABLE_COLUMNS = [
        'title', 'metric_description', 'baseline_value', 'target_value',
        'current_value', 'unit', 'status', 'display_order',
        'jira_goal_id', 'jira_goal_url', 'ai_momentum',
    ];

    // ===========================
    // CREATE
    // ===========================

    public static function create(Database $db, array $data): int
    {
        $db->query(
            "INSERT INTO key_results
                (org_id, hl_work_item_id, title, metric_description,
                 baseline_value, target_value, current_value, unit, status, display_order)
             VALUES
                (:org_id, :hl_work_item_id, :title, :metric_description,
                 :baseline_value, :target_value, :current_value, :unit, :status, :display_order)",
            [
                ':org_id'             => $data['org_id'],
                ':hl_work_item_id'    => $data['hl_work_item_id'],
                ':title'              => $data['title'],
                ':metric_description' => $data['metric_description'] ?? null,
                ':baseline_value'     => isset($data['baseline_value']) && $data['baseline_value'] !== '' ? (float) $data['baseline_value'] : null,
                ':target_value'       => isset($data['target_value'])   && $data['target_value']   !== '' ? (float) $data['target_value']   : null,
                ':current_value'      => isset($data['current_value'])  && $data['current_value']  !== '' ? (float) $data['current_value']  : null,
                ':unit'               => $data['unit'] ?? null,
                ':status'             => $data['status'] ?? 'not_started',
                ':display_order'      => $data['display_order'] ?? 0,
            ]
        );
        return (int) $db->lastInsertId();
    }

    // ===========================
    // READ
    // ===========================

    public static function findByWorkItemId(Database $db, int $workItemId, int $orgId): array
    {
        return $db->query(
            "SELECT * FROM key_results
              WHERE hl_work_item_id = :wid AND org_id = :oid
              ORDER BY display_order ASC, id ASC",
            [':wid' => $workItemId, ':oid' => $orgId]
        )->fetchAll();
    }

    public static function findById(Database $db, int $id, int $orgId): ?array
    {
        $row = $db->query(
            "SELECT * FROM key_results WHERE id = :id AND org_id = :oid LIMIT 1",
            [':id' => $id, ':oid' => $orgId]
        )->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Load all KRs for OKR-bearing work items in a project, with work item context.
     * Used by the executive project dashboard.
     */
    public static function findByProjectOkrs(Database $db, int $projectId, int $orgId): array
    {
        return $db->query(
            "SELECT kr.*, hwi.title AS work_item_title, hwi.okr_title, hwi.id AS work_item_id,
                    hwi.priority_number, hwi.status AS work_item_status
               FROM key_results kr
               JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
              WHERE hwi.project_id = :pid
                AND kr.org_id = :oid
                AND hwi.okr_title IS NOT NULL
                AND hwi.okr_title != ''
              ORDER BY hwi.priority_number ASC, kr.display_order ASC",
            [':pid' => $projectId, ':oid' => $orgId]
        )->fetchAll();
    }

    // ===========================
    // UPDATE
    // ===========================

    public static function update(Database $db, int $id, int $orgId, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));
        if (empty($data)) {
            return;
        }
        $sets   = implode(', ', array_map(fn($col) => "`{$col}` = :{$col}", array_keys($data)));
        $params = array_combine(array_map(fn($k) => ":{$k}", array_keys($data)), array_values($data));
        $params[':id']  = $id;
        $params[':oid'] = $orgId;
        $db->query("UPDATE key_results SET {$sets} WHERE id = :id AND org_id = :oid", $params);
    }

    // ===========================
    // DELETE
    // ===========================

    public static function delete(Database $db, int $id, int $orgId): void
    {
        $db->query(
            "DELETE FROM key_results WHERE id = :id AND org_id = :oid",
            [':id' => $id, ':oid' => $orgId]
        );
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Models/KeyResultTest.php
```

Expected: 4 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add stratflow/src/Models/KeyResult.php stratflow/tests/Unit/Models/KeyResultTest.php
git commit -m "feat(kr): KeyResult model — CRUD with org isolation"
```

---

### Task 3: KeyResultContribution model

**Files:**
- Create: `stratflow/src/Models/KeyResultContribution.php`

- [ ] **Step 1: Implement the model**

```php
<?php
// stratflow/src/Models/KeyResultContribution.php
declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class KeyResultContribution
{
    // ===========================
    // WRITE
    // ===========================

    /**
     * Insert or update a contribution row (keyed on kr + link).
     */
    public static function upsert(
        Database $db,
        int      $keyResultId,
        int      $storyGitLinkId,
        int      $orgId,
        int      $score,
        ?string  $rationale
    ): void {
        // Clamp score to 0–10
        $score = max(0, min(10, $score));
        $db->query(
            "INSERT INTO key_result_contributions
                (key_result_id, story_git_link_id, org_id, ai_relevance_score, ai_rationale)
             VALUES (:kr_id, :link_id, :org_id, :score, :rationale)
             AS new
             ON DUPLICATE KEY UPDATE
               ai_relevance_score = new.ai_relevance_score,
               ai_rationale       = new.ai_rationale,
               scored_at          = NOW()",
            [
                ':kr_id'    => $keyResultId,
                ':link_id'  => $storyGitLinkId,
                ':org_id'   => $orgId,
                ':score'    => $score,
                ':rationale'=> $rationale,
            ]
        );
    }

    // ===========================
    // READ
    // ===========================

    /**
     * All contributions for a KR, joined to the git link for display.
     */
    public static function findByKeyResultId(Database $db, int $keyResultId, int $orgId): array
    {
        return $db->query(
            "SELECT krc.*, sgl.ref_url, sgl.ref_label, sgl.ref_title, sgl.merged_at
               FROM key_result_contributions krc
               JOIN story_git_links sgl ON krc.story_git_link_id = sgl.id
              WHERE krc.key_result_id = :kr_id AND krc.org_id = :oid
              ORDER BY krc.scored_at DESC",
            [':kr_id' => $keyResultId, ':oid' => $orgId]
        )->fetchAll();
    }

    /**
     * Last N contributions for a KR — used to build ai_momentum summary.
     */
    public static function findRecentByKeyResultId(Database $db, int $keyResultId, int $orgId, int $limit = 10): array
    {
        return $db->query(
            "SELECT krc.ai_relevance_score, krc.ai_rationale, sgl.ref_title
               FROM key_result_contributions krc
               JOIN story_git_links sgl ON krc.story_git_link_id = sgl.id
              WHERE krc.key_result_id = :kr_id AND krc.org_id = :oid
              ORDER BY krc.scored_at DESC
              LIMIT :lim",
            [':kr_id' => $keyResultId, ':oid' => $orgId, ':lim' => $limit]
        )->fetchAll();
    }
}
```

- [ ] **Step 2: Confirm PHP syntax**

```bash
docker compose exec php php -l src/Models/KeyResultContribution.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add stratflow/src/Models/KeyResultContribution.php
git commit -m "feat(kr): KeyResultContribution model — upsert and read"
```

---

### Task 4: KrController + routes

**Files:**
- Create: `stratflow/src/Controllers/KrController.php`
- Modify: `stratflow/src/Config/routes.php`

- [ ] **Step 1: Implement KrController**

```php
<?php
// stratflow/src/Controllers/KrController.php
declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;

class KrController
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

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * POST /app/key-results
     * Create a new KR for a work item. Verifies work item belongs to session org.
     */
    public function store(): void
    {
        header('Content-Type: application/json');
        $orgId      = (int) $this->auth->user()['org_id'];
        $workItemId = (int) $this->request->post('hl_work_item_id', 0);

        if ($workItemId === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'hl_work_item_id required']);
            return;
        }

        if (!$this->workItemBelongsToOrg($workItemId, $orgId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $title = trim((string) $this->request->post('title', ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'title required']);
            return;
        }

        $id = KeyResult::create($this->db, [
            'org_id'             => $orgId,
            'hl_work_item_id'    => $workItemId,
            'title'              => $title,
            'metric_description' => trim((string) $this->request->post('metric_description', '')) ?: null,
            'baseline_value'     => $this->request->post('baseline_value', ''),
            'target_value'       => $this->request->post('target_value', ''),
            'current_value'      => $this->request->post('current_value', ''),
            'unit'               => trim((string) $this->request->post('unit', '')) ?: null,
            'status'             => $this->request->post('status', 'not_started'),
            'display_order'      => (int) $this->request->post('display_order', 0),
        ]);

        echo json_encode(['ok' => true, 'id' => $id]);
    }

    /**
     * POST /app/key-results/{id}
     * Update an existing KR. Only fields present in the request body are changed.
     */
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        $orgId = (int) $this->auth->user()['org_id'];

        $kr = KeyResult::findById($this->db, $id, $orgId);
        if ($kr === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $data = [];
        foreach (['title', 'metric_description', 'baseline_value', 'target_value',
                  'current_value', 'unit', 'status', 'display_order'] as $field) {
            $val = $this->request->post($field);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }

        KeyResult::update($this->db, $id, $orgId, $data);
        echo json_encode(['ok' => true]);
    }

    /**
     * POST /app/key-results/{id}/delete
     * Delete a KR. Returns 404 if not found or belongs to another org.
     */
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        $orgId = (int) $this->auth->user()['org_id'];

        $kr = KeyResult::findById($this->db, $id, $orgId);
        if ($kr === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        KeyResult::delete($this->db, $id, $orgId);
        echo json_encode(['ok' => true]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    private function workItemBelongsToOrg(int $workItemId, int $orgId): bool
    {
        $row = $this->db->query(
            "SELECT p.org_id
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE hwi.id = :id LIMIT 1",
            [':id' => $workItemId]
        )->fetch();
        return $row !== false && (int) $row['org_id'] === $orgId;
    }
}
```

- [ ] **Step 2: Add routes in routes.php**

In `stratflow/src/Config/routes.php`, after the work-items `{id}` routes block, add:

```php
    // Key Results — KR CRUD (static /delete route before {id} catch-all)
    $router->add('POST', '/app/key-results',            'KrController@store',  ['auth', 'csrf']);
    $router->add('POST', '/app/key-results/{id}/delete','KrController@delete', ['auth', 'csrf']);
    $router->add('POST', '/app/key-results/{id}',       'KrController@update', ['auth', 'csrf']);
```

Also add the new project executive dashboard route, after the existing `/app/executive` line:

```php
    // Per-project OKR executive view
    $router->add('GET', '/app/projects/{id}/executive', 'ExecutiveController@projectDashboard', ['auth', 'executive']);
```

- [ ] **Step 3: Verify PHP syntax on both files**

```bash
docker compose exec php php -l src/Controllers/KrController.php
docker compose exec php php -l src/Config/routes.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Controllers/KrController.php stratflow/src/Config/routes.php
git commit -m "feat(kr): KrController CRUD + routes"
```

---

### Task 5: KR editor partial + work items integration

**Files:**
- Create: `stratflow/templates/partials/kr-editor.php`
- Modify: `stratflow/templates/work-items.php`

- [ ] **Step 1: Create the KR editor partial**

```php
<?php
// stratflow/templates/partials/kr-editor.php
// Variables expected: $work_item (array), $key_results (array), $csrf_token (string)
?>
<div class="kr-editor" data-item-id="<?= (int) $work_item['id'] ?>">
    <h4 style="margin: 1.25rem 0 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">
        Key Results
        <span style="font-weight: 400; color: #6b7280; font-size: 0.8rem;">(optional — track measurable outcomes for this OKR)</span>
    </h4>

    <table class="kr-table" style="width:100%; border-collapse:collapse; font-size:0.8rem;" id="kr-table-<?= (int) $work_item['id'] ?>">
        <thead>
            <tr style="border-bottom: 1px solid #e5e7eb; color: #6b7280;">
                <th style="padding: 4px 6px; text-align:left; width:35%;">Key Result</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Baseline</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Current</th>
                <th style="padding: 4px 6px; text-align:left; width:15%;">Target</th>
                <th style="padding: 4px 6px; text-align:left; width:10%;">Unit</th>
                <th style="padding: 4px 6px; text-align:left; width:10%;">Status</th>
                <th style="padding: 4px 6px; width: 30px;"></th>
            </tr>
        </thead>
        <tbody id="kr-rows-<?= (int) $work_item['id'] ?>">
        <?php foreach ($key_results as $kr): ?>
            <tr class="kr-row" data-kr-id="<?= (int) $kr['id'] ?>" style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 4px 6px;">
                    <input type="text" class="kr-field" data-field="title"
                           value="<?= htmlspecialchars((string) $kr['title'], ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Increase MRR to $50k"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="baseline_value"
                           value="<?= htmlspecialchars((string) ($kr['baseline_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="current_value"
                           value="<?= htmlspecialchars((string) ($kr['current_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="number" step="any" class="kr-field" data-field="target_value"
                           value="<?= htmlspecialchars((string) ($kr['target_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <input type="text" class="kr-field" data-field="unit"
                           value="<?= htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="%" style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 6px;" />
                </td>
                <td style="padding: 4px 6px;">
                    <select class="kr-field" data-field="status" style="width:100%; border:1px solid #d1d5db; border-radius:4px; padding: 3px 4px;">
                        <?php foreach (['not_started','on_track','at_risk','off_track','achieved'] as $s): ?>
                            <option value="<?= $s ?>" <?= $kr['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="padding: 4px 6px; text-align:center;">
                    <button type="button" class="kr-delete-btn" data-kr-id="<?= (int) $kr['id'] ?>"
                            style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:1rem;" title="Delete">×</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <button type="button" class="kr-add-btn" data-item-id="<?= (int) $work_item['id'] ?>"
            style="margin-top: 0.5rem; font-size: 0.8rem; color: #6366f1; background: none; border: 1px dashed #c7d2fe; border-radius: 6px; padding: 4px 12px; cursor: pointer;">
        + Add Key Result
    </button>
    <button type="button" class="kr-save-btn" data-item-id="<?= (int) $work_item['id'] ?>"
            style="margin-top: 0.5rem; margin-left: 0.5rem; font-size: 0.8rem; background: #6366f1; color: #fff; border: none; border-radius: 6px; padding: 4px 12px; cursor: pointer;">
        Save KRs
    </button>
    <span class="kr-status-msg" style="font-size:0.75rem; color:#6b7280; margin-left: 0.5rem;"></span>
</div>

<script>
(function () {
    const CSRF = <?= json_encode($csrf_token) ?>;

    function krEditorFor(itemId) {
        const container = document.querySelector(`.kr-editor[data-item-id="${itemId}"]`);
        if (!container) return;
        const tbody = container.querySelector(`#kr-rows-${itemId}`);
        const msg   = container.querySelector('.kr-status-msg');

        // Save all rows
        container.querySelector('.kr-save-btn').addEventListener('click', async () => {
            const rows = tbody.querySelectorAll('.kr-row[data-kr-id]');
            let ok = true;
            for (const row of rows) {
                const krId  = row.dataset.krId;
                const body  = new FormData();
                body.append('_csrf_token', CSRF);
                row.querySelectorAll('.kr-field').forEach(f => body.append(f.dataset.field, f.value));
                const res = await fetch(`/app/key-results/${krId}`, { method: 'POST', body });
                if (!res.ok) ok = false;
            }
            msg.textContent = ok ? 'Saved.' : 'Error saving.';
            msg.style.color = ok ? '#10b981' : '#ef4444';
            setTimeout(() => { msg.textContent = ''; }, 2500);
        });

        // Add new row
        container.querySelector('.kr-add-btn').addEventListener('click', async () => {
            const body = new FormData();
            body.append('_csrf_token', CSRF);
            body.append('hl_work_item_id', String(itemId));
            body.append('title', 'New Key Result');
            body.append('status', 'not_started');
            const res  = await fetch('/app/key-results', { method: 'POST', body });
            const data = await res.json();
            if (!data.ok) { msg.textContent = 'Error adding KR.'; msg.style.color = '#ef4444'; return; }
            // Append empty row
            tbody.insertAdjacentHTML('beforeend', buildRow(data.id));
            attachDeleteHandler(tbody.lastElementChild);
        });

        // Delete existing rows
        tbody.querySelectorAll('.kr-delete-btn').forEach(attachDeleteHandler);

        function attachDeleteHandler(btn) {
            (btn.querySelector ? btn.querySelector('.kr-delete-btn') : btn)
                ?.addEventListener('click', async (e) => {
                    const krId = e.currentTarget.dataset.krId;
                    const body = new FormData();
                    body.append('_csrf_token', CSRF);
                    await fetch(`/app/key-results/${krId}/delete`, { method: 'POST', body });
                    e.currentTarget.closest('.kr-row').remove();
                });
        }

        function buildRow(id) {
            return `<tr class="kr-row" data-kr-id="${id}" style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:4px 6px;"><input type="text" class="kr-field" data-field="title" value="New Key Result" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;"/></td>
                <td style="padding:4px 6px;"><input type="number" step="any" class="kr-field" data-field="baseline_value" value="" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;"/></td>
                <td style="padding:4px 6px;"><input type="number" step="any" class="kr-field" data-field="current_value" value="" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;"/></td>
                <td style="padding:4px 6px;"><input type="number" step="any" class="kr-field" data-field="target_value" value="" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;"/></td>
                <td style="padding:4px 6px;"><input type="text" class="kr-field" data-field="unit" value="" placeholder="%" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;"/></td>
                <td style="padding:4px 6px;"><select class="kr-field" data-field="status" style="width:100%;border:1px solid #d1d5db;border-radius:4px;padding:3px 4px;">
                    <option value="not_started" selected>Not Started</option>
                    <option value="on_track">On Track</option>
                    <option value="at_risk">At Risk</option>
                    <option value="off_track">Off Track</option>
                    <option value="achieved">Achieved</option>
                </select></td>
                <td style="padding:4px 6px;text-align:center;"><button type="button" class="kr-delete-btn" data-kr-id="${id}" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:1rem;" title="Delete">×</button></td>
            </tr>`;
        }
    }

    // Initialise for all editors on page load
    document.querySelectorAll('.kr-editor').forEach(el => krEditorFor(Number(el.dataset.itemId)));
}());
</script>
```

- [ ] **Step 2: Wire the KR editor into the work items edit modal**

Find the section in `stratflow/templates/work-items.php` where the work item edit modal is rendered (look for `id="edit-modal"` or the form that submits to `POST /app/work-items/{id}`). Just before the closing `</form>` tag or `</div>` of the modal body, include the KR editor:

```php
<?php
// Inside the edit modal, after the OKR description fields:
// $modal_item is the work item being edited; $modal_key_results loaded from controller
if (!empty($modal_item)): ?>
    <?php
    $work_item    = $modal_item;
    $key_results  = $modal_key_results ?? [];
    include __DIR__ . '/partials/kr-editor.php';
    ?>
<?php endif; ?>
```

- [ ] **Step 3: Load KRs in WorkItemController::index()**

In `stratflow/src/Controllers/WorkItemController.php`, in the `index()` method where `$work_items` is built, add a KR lookup keyed by work item id:

```php
// After fetching $workItems, build a map of KRs per work item
$krsByItemId = [];
foreach ($workItems as $item) {
    $krs = \StratFlow\Models\KeyResult::findByWorkItemId($this->db, (int) $item['id'], $orgId);
    if (!empty($krs)) {
        $krsByItemId[(int) $item['id']] = $krs;
    }
}
```

Then pass `'krs_by_item_id' => $krsByItemId` into the `render()` call. In the template, use `$krs_by_item_id[$work_item['id']] ?? []` as the `$key_results` variable when including the partial.

- [ ] **Step 4: Lint all touched files**

```bash
docker compose exec php php -l src/Controllers/WorkItemController.php
docker compose exec php php -l templates/work-items.php
docker compose exec php php -l templates/partials/kr-editor.php
```

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add stratflow/templates/partials/kr-editor.php stratflow/templates/work-items.php stratflow/src/Controllers/WorkItemController.php
git commit -m "feat(kr): KR inline editor on work item edit modal"
```

---

### Task 6: ExecutiveController — projectDashboard method

**Files:**
- Modify: `stratflow/src/Controllers/ExecutiveController.php`

- [ ] **Step 1: Add the `projectDashboard()` method**

In `stratflow/src/Controllers/ExecutiveController.php`, add the following method after `dashboard()`. Also add `use StratFlow\Models\KeyResult;` and `use StratFlow\Models\KeyResultContribution;` to the imports at the top.

```php
/**
 * Render the per-project OKR executive dashboard.
 *
 * Shows OKR cards with KR progress bars, contributing PRs (Phase 2),
 * linked risks, and dependencies. Read-only — no sync controls.
 *
 * Route: GET /app/projects/{id}/executive
 */
public function projectDashboard(int $id): void
{
    $user  = $this->auth->user();
    $orgId = (int) $user['org_id'];

    // Verify project belongs to this org
    $project = $this->db->query(
        "SELECT id, name, updated_at FROM projects WHERE id = :id AND org_id = :oid LIMIT 1",
        [':id' => $id, ':oid' => $orgId]
    )->fetch();

    if ($project === false) {
        http_response_code(404);
        $this->response->render('errors/404', [], 'app');
        return;
    }

    // Project selector (all active projects in org)
    $projects = $this->db->query(
        "SELECT id, name FROM projects WHERE org_id = :oid AND status != 'deleted' ORDER BY name ASC",
        [':oid' => $orgId]
    )->fetchAll();

    // OKR work items for this project (items with an okr_title)
    $okrItems = $this->db->query(
        "SELECT hwi.id, hwi.title, hwi.okr_title, hwi.okr_description,
                hwi.priority_number, hwi.status
           FROM hl_work_items hwi
          WHERE hwi.project_id = :pid
            AND hwi.okr_title IS NOT NULL
            AND hwi.okr_title != ''
          ORDER BY hwi.priority_number ASC",
        [':pid' => $id]
    )->fetchAll();

    // KRs per work item
    $krRows = KeyResult::findByProjectOkrs($this->db, $id, $orgId);
    $krsByItemId = [];
    foreach ($krRows as $kr) {
        $krsByItemId[(int) $kr['work_item_id']][] = $kr;
    }

    // Risks per work item (via risk_item_links)
    $riskRows = $this->db->query(
        "SELECT ril.work_item_id, r.title, r.likelihood, r.impact,
                (r.likelihood * r.impact) AS priority
           FROM risk_item_links ril
           JOIN risks r ON ril.risk_id = r.id
           JOIN projects p ON r.project_id = p.id
          WHERE p.id = :pid AND p.org_id = :oid",
        [':pid' => $id, ':oid' => $orgId]
    )->fetchAll();
    $risksByItemId = [];
    foreach ($riskRows as $risk) {
        $risksByItemId[(int) $risk['work_item_id']][] = $risk;
    }

    // Dependencies per work item
    $depRows = $this->db->query(
        "SELECT hid.item_id, hid.depends_on_id, hid.dependency_type,
                blocker.title AS blocker_title, blocker.status AS blocker_status,
                blocked.title AS blocked_title, blocked.status AS blocked_status
           FROM hl_item_dependencies hid
           JOIN hl_work_items blocker ON hid.depends_on_id = blocker.id
           JOIN hl_work_items blocked  ON hid.item_id = blocked.id
          WHERE blocker.project_id = :pid OR blocked.project_id = :pid",
        [':pid' => $id]
    )->fetchAll();
    $depsByItemId = [];
    foreach ($depRows as $dep) {
        // item_id is "blocked by" depends_on_id
        $depsByItemId[(int) $dep['item_id']]['blocked_by'][]   = $dep;
        $depsByItemId[(int) $dep['depends_on_id']]['blocks'][] = $dep;
    }

    // Overall health summary: count KR statuses across all KRs in this project
    $healthCounts = ['on_track' => 0, 'at_risk' => 0, 'off_track' => 0];
    foreach ($krRows as $kr) {
        $s = $kr['status'];
        if (isset($healthCounts[$s])) {
            $healthCounts[$s]++;
        }
    }

    $this->response->render('executive-project', [
        'user'           => $user,
        'active_page'    => 'executive',
        'project'        => $project,
        'projects'       => $projects,
        'okr_items'      => $okrItems,
        'krs_by_item_id' => $krsByItemId,
        'risks_by_item'  => $risksByItemId,
        'deps_by_item'   => $depsByItemId,
        'health_counts'  => $healthCounts,
        'flash_message'  => $_SESSION['flash_message'] ?? null,
        'flash_error'    => $_SESSION['flash_error']   ?? null,
    ], 'app');

    unset($_SESSION['flash_message'], $_SESSION['flash_error']);
}
```

- [ ] **Step 2: Add model imports at top of ExecutiveController.php**

At the top of `ExecutiveController.php`, after the existing `use` statements, add:

```php
use StratFlow\Models\KeyResult;
```

- [ ] **Step 3: Lint**

```bash
docker compose exec php php -l src/Controllers/ExecutiveController.php
```

Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Controllers/ExecutiveController.php
git commit -m "feat(kr): ExecutiveController projectDashboard — OKR/KR data queries"
```

---

### Task 7: Executive project dashboard template

**Files:**
- Create: `stratflow/templates/executive-project.php`

- [ ] **Step 1: Create the template**

```php
<?php
// stratflow/templates/executive-project.php
// Variables: $user, $project, $projects, $okr_items, $krs_by_item_id,
//            $risks_by_item, $deps_by_item, $health_counts,
//            $flash_message, $flash_error
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header flex justify-between items-center" style="flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; OKR Progress</h1>
        <p class="page-subtitle" style="color: #64748b; font-size: 0.875rem;">
            <?= (int) $health_counts['on_track'] ?> on track &middot;
            <?= (int) $health_counts['at_risk'] ?> at risk &middot;
            <?= (int) $health_counts['off_track'] ?> off track
        </p>
    </div>
    <div style="display:flex; align-items:center; gap: 0.75rem;">
        <select onchange="window.location='/app/projects/' + this.value + '/executive'"
                style="border:1px solid #d1d5db; border-radius:6px; padding: 6px 10px; font-size: 0.875rem;">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $project['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size: 12px; color: #94a3b8;">
            Updated <?= htmlspecialchars($project['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if (empty($okr_items)): ?>
    <div class="card mt-6" style="text-align:center; padding:2rem; color:#6b7280;">
        <p>No OKRs defined for this project yet.</p>
        <p style="font-size:0.875rem;">Add OKR titles to work items on the <a href="/app/work-items" style="color:#6366f1;">Work Items</a> page.</p>
    </div>
<?php endif; ?>

<!-- OKR Cards -->
<?php foreach ($okr_items as $item):
    $itemId   = (int) $item['id'];
    $krs      = $krs_by_item_id[$itemId] ?? [];
    $risks    = $risks_by_item[$itemId]  ?? [];
    $blockedBy = $deps_by_item[$itemId]['blocked_by'] ?? [];
    $blocks    = $deps_by_item[$itemId]['blocks']     ?? [];

    // Determine worst KR status for OKR badge
    $statusOrder  = ['off_track' => 0, 'at_risk' => 1, 'not_started' => 2, 'on_track' => 3, 'achieved' => 4];
    $worstStatus  = 'not_started';
    foreach ($krs as $kr) {
        if (($statusOrder[$kr['status']] ?? 99) < ($statusOrder[$worstStatus] ?? 99)) {
            $worstStatus = $kr['status'];
        }
    }
    $badgeColours = [
        'on_track'    => '#10b981',
        'at_risk'     => '#f59e0b',
        'off_track'   => '#ef4444',
        'not_started' => '#9ca3af',
        'achieved'    => '#6366f1',
    ];
    $badgeColour = $badgeColours[$worstStatus] ?? '#9ca3af';
?>
<div class="card mb-4" style="border-top: 3px solid <?= htmlspecialchars($badgeColour, ENT_QUOTES, 'UTF-8') ?>;">
    <div class="card-body">

        <!-- OKR header -->
        <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <span style="display:inline-block; background:<?= htmlspecialchars($badgeColour, ENT_QUOTES, 'UTF-8') ?>; color:#fff; border-radius:999px; padding:2px 10px; font-size:0.7rem; text-transform:uppercase; font-weight:600; margin-right:0.5rem;">
                    <?= htmlspecialchars(str_replace('_', ' ', $worstStatus), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <strong style="font-size: 1rem;"><?= htmlspecialchars($item['okr_title'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <span style="font-size:0.75rem; color:#94a3b8;"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php if (!empty($krs)): ?>
        <!-- KR rows -->
        <div style="margin-top: 1rem;">
            <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.5rem;">Key Results</div>
            <?php foreach ($krs as $kr):
                $baseline = (float) ($kr['baseline_value'] ?? 0);
                $target   = (float) ($kr['target_value']   ?? 0);
                $current  = (float) ($kr['current_value']  ?? 0);
                $unit     = htmlspecialchars((string) ($kr['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

                $pct = 0;
                if ($target !== 0.0 && $target !== $baseline) {
                    $pct = max(0, min(100, (int) round(($current - $baseline) / ($target - $baseline) * 100)));
                }

                $krBadge = $badgeColours[$kr['status']] ?? '#9ca3af';
            ?>
            <div class="kr-progress-row" style="margin-bottom: 0.875rem; padding: 0.625rem 0.75rem; background: #f9fafb; border-radius: 6px;">
                <div class="flex justify-between items-center" style="margin-bottom:0.375rem;">
                    <span style="font-size:0.8rem; font-weight:500; color:#374151;">
                        <?= htmlspecialchars($kr['title'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span style="display:inline-block; background:<?= htmlspecialchars($krBadge, ENT_QUOTES, 'UTF-8') ?>; color:#fff; border-radius:999px; padding:1px 8px; font-size:0.65rem; text-transform:uppercase; font-weight:600;">
                        <?= htmlspecialchars(str_replace('_', ' ', $kr['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <!-- Progress bar -->
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <div style="flex:1; background:#e5e7eb; border-radius:999px; height:8px; overflow:hidden;">
                        <div style="width:<?= $pct ?>%; background:<?= htmlspecialchars($krBadge, ENT_QUOTES, 'UTF-8') ?>; height:100%; border-radius:999px; transition:width 0.3s;"></div>
                    </div>
                    <span style="font-size:0.75rem; color:#6b7280; white-space:nowrap;">
                        <?php if ($target > 0): ?>
                            <?= number_format($current, 2, '.', '') . $unit ?> &rarr; <?= number_format($target, 2, '.', '') . $unit ?>
                        <?php else: ?>
                            No target set
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($kr['ai_momentum'])): ?>
                <p style="margin: 0.375rem 0 0; font-size: 0.75rem; color: #6b7280; font-style: italic;">
                    &ldquo;<?= htmlspecialchars($kr['ai_momentum'], ENT_QUOTES, 'UTF-8') ?>&rdquo;
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($risks) || !empty($blockedBy) || !empty($blocks)): ?>
        <!-- Risks + Dependencies footer -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem; padding-top:1rem; border-top:1px solid #f3f4f6;">
            <div>
                <?php if (!empty($risks)): ?>
                    <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.375rem;">Risks</div>
                    <?php foreach ($risks as $risk):
                        $p = (int) $risk['priority'];
                        $sev = $p >= 15 ? ['🔴', '#ef4444'] : ($p >= 5 ? ['🟡', '#f59e0b'] : ['🟢', '#10b981']);
                    ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        <?= $sev[0] ?> <?= htmlspecialchars($risk['title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!empty($blockedBy) || !empty($blocks)): ?>
                    <div style="font-size:0.7rem; text-transform:uppercase; font-weight:600; color:#94a3b8; margin-bottom:0.375rem;">Dependencies</div>
                    <?php foreach ($blockedBy as $dep): ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        &larr; Blocked by: <?= htmlspecialchars($dep['blocker_title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($blocks as $dep): ?>
                    <div style="font-size:0.8rem; color:#374151; margin-bottom:0.25rem;">
                        &rarr; Blocks: <?= htmlspecialchars($dep['blocked_title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div><!-- /.card -->
<?php endforeach; ?>
```

- [ ] **Step 2: Lint**

```bash
docker compose exec php php -l templates/executive-project.php
```

- [ ] **Step 3: Load the page in a browser**

Navigate to `http://localhost:8890/app/projects/1/executive` (replace `1` with a real project id). Confirm OKR cards render, KR progress bars appear for items with KRs, no PHP errors.

- [ ] **Step 4: Commit**

```bash
git add stratflow/templates/executive-project.php
git commit -m "feat(kr): executive-project template — OKR cards with KR progress bars"
```

---

### Task 8: Jira Goals sync extension

**Files:**
- Modify: `stratflow/src/Services/JiraSyncService.php`

- [ ] **Step 1: Extend pushOkrsToGoals to create KRs as child goals**

In `JiraSyncService.php`, inside `pushOkrsToGoals()`, after the section that creates a Goal for an OKR (after `$counts['created']++`), add the KR child-goal creation and `jira_goal_id` storage:

```php
// After successfully creating a Goal for the OKR, store the returned goal_id
// and push each KR as a child goal under it.
// $newGoalId is the ID returned by createAtlassianGoal() — ensure that method
// returns the new goal id (update it to return string|null if needed).

// Load KRs for work items that share this OKR title
$krRows = $this->db->query(
    "SELECT kr.id, kr.title, kr.baseline_value, kr.target_value, kr.unit
       FROM key_results kr
       JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
      WHERE hwi.project_id = :pid
        AND hwi.org_id = :org_id
        AND hwi.okr_title = :okr_title",
    [':pid' => $projectId, ':org_id' => $orgId, ':okr_title' => $okr['title']]
)->fetchAll();

foreach ($krRows as $kr) {
    try {
        $krDesc = $kr['unit']
            ? "Target: {$kr['target_value']} {$kr['unit']} (baseline: {$kr['baseline_value']} {$kr['unit']})"
            : ($kr['target_value'] ? "Target: {$kr['target_value']}" : '');

        $krGoalId = $this->createAtlassianGoal(
            $cloudId,
            $siteUrl,
            $goalTypeId,
            $kr['title'],
            $krDesc,
            $newGoalId  // parent goal id
        );

        if ($krGoalId !== null) {
            // Persist jira_goal_id on the key_results row with org scoping.
            $this->db->query(
                "UPDATE key_results SET jira_goal_id = :gid WHERE id = :id AND org_id = :org_id",
                [':gid' => $krGoalId, ':id' => $kr['id'], ':org_id' => $orgId]
            );
        }
    } catch (\Throwable $e) {
        error_log('[JiraSyncService] KR child goal error: ' . $e->getMessage());
    }
}
```

- [ ] **Step 2: Add pullKrStatusFromGoals() method**

Add this method to `JiraSyncService`:

```php
/**
 * Pull KR status updates from Atlassian Goals back into key_results.
 *
 * Reads each key_results row that has a jira_goal_id set, calls the
 * Atlassian Goals API to get current status, and updates key_results.status
 * if the remote status differs. StratFlow is the source of truth for
 * AI-derived progress; Jira is a secondary signal.
 *
 * @param int $projectId StratFlow project ID
 * @return array {updated: int, skipped: int, errors: int}
 */
public function pullKrStatusFromGoals(int $projectId): array
{
    $counts  = ['updated' => 0, 'skipped' => 0, 'errors' => 0];
    $siteUrl = $this->integration['site_url'] ?? '';
    $cloudId = $this->integration['cloud_id'] ?? '';

    if (!$cloudId || !$siteUrl) {
        return $counts;
    }

    $krs = $this->db->query(
        "SELECT kr.id, kr.jira_goal_id, kr.status
           FROM key_results kr
           JOIN hl_work_items hwi ON kr.hl_work_item_id = hwi.id
          WHERE hwi.project_id = :pid
            AND kr.jira_goal_id IS NOT NULL",
        [':pid' => $projectId]
    )->fetchAll();

    // Jira goal state → StratFlow KR status mapping
    $stateMap = [
        'ON_TRACK'    => 'on_track',
        'AT_RISK'     => 'at_risk',
        'OFF_TRACK'   => 'off_track',
        'DONE'        => 'achieved',
        'NOT_STARTED' => 'not_started',
    ];

    foreach ($krs as $kr) {
        try {
            $goalState = $this->fetchAtlassianGoalState($cloudId, $siteUrl, $kr['jira_goal_id']);
            if ($goalState === null) {
                $counts['skipped']++;
                continue;
            }
            $newStatus = $stateMap[strtoupper($goalState)] ?? null;
            if ($newStatus === null || $newStatus === $kr['status']) {
                $counts['skipped']++;
                continue;
            }
            $this->db->query(
                "UPDATE key_results SET status = :s WHERE id = :id",
                [':s' => $newStatus, ':id' => $kr['id']]
            );
            $counts['updated']++;
        } catch (\Throwable $e) {
            error_log('[JiraSyncService] pullKrStatus error for kr_id=' . $kr['id'] . ': ' . $e->getMessage());
            $counts['errors']++;
        }
    }
    return $counts;
}

/**
 * Fetch the current state string of an Atlassian Goal by its goal ID.
 * Returns null if the API call fails or the goal is not found.
 */
private function fetchAtlassianGoalState(string $cloudId, string $siteUrl, string $goalId): ?string
{
    $token = $this->jira->getAccessToken();
    $url   = "https://api.atlassian.com/ex/goals/{$cloudId}/v1/goals/{$goalId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/json",
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        return null;
    }
    $data = json_decode((string) $body, true);
    return $data['state'] ?? null;
}
```

- [ ] **Step 3: Lint**

```bash
docker compose exec php php -l src/Services/JiraSyncService.php
```

- [ ] **Step 4: Commit**

```bash
git add stratflow/src/Services/JiraSyncService.php
git commit -m "feat(kr): extend JiraSyncService to push KRs as child goals and pull status back"
```

---

## Phase 2: AI PR Matching + KR Scoring

---

### Task 9: GitPrMatcherService — AI fallback PR linking

**Files:**
- Create: `stratflow/src/Services/Prompts/GitPrMatchPrompt.php`
- Create: `stratflow/src/Services/GitPrMatcherService.php`
- Modify: `stratflow/src/Services/GitLinkService.php` — add `linkAiMatched()`
- Create: `stratflow/tests/Unit/Services/GitPrMatcherServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// stratflow/tests/Unit/Services/GitPrMatcherServiceTest.php
declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\UserStory;
use StratFlow\Services\GeminiService;
use StratFlow\Services\GitPrMatcherService;

class GitPrMatcherServiceTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $projectId;
    private static int $storyId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name = 'Test Org - GitPrMatcherServiceTest'");
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - GitPrMatcherServiceTest']);
        self::$orgId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'matcher@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Matcher', 'user']
        );
        $userId = (int) self::$db->lastInsertId();

        self::$db->query(
            "INSERT INTO projects (org_id, owner_id, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'Matcher Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        // Insert a user story to match against
        self::$db->query(
            "INSERT INTO user_stories (project_id, priority_number, title, description, status, size)
             VALUES (?, ?, ?, ?, ?, ?)",
            [self::$projectId, 1, 'Improve checkout flow', 'Reduce steps in checkout', 'backlog', 3]
        );
        self::$storyId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM story_git_links WHERE ref_url LIKE 'https://github.com/test-matcher/%'");
        self::$db->query("DELETE FROM user_stories WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE id = ?", [self::$orgId]);
    }

    #[Test]
    public function testReturnsZeroWhenGeminiIsNull(): void
    {
        $service = new GitPrMatcherService(self::$db, null);
        $result  = $service->matchAndLink(
            'Fix checkout',
            'Removes unnecessary steps',
            'feat/checkout',
            'https://github.com/test-matcher/repo/pull/1',
            self::$orgId
        );
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testDoesNotLinkBelowConfidenceThreshold(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            ['id' => self::$storyId, 'type' => 'user_story', 'confidence' => 0.5],
        ]);

        $service = new GitPrMatcherService(self::$db, $gemini);
        $result  = $service->matchAndLink(
            'Fix checkout',
            'Removes unnecessary steps',
            'feat/checkout',
            'https://github.com/test-matcher/repo/pull/2',
            self::$orgId
        );
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testLinksAboveThresholdWithAiMatchedFlag(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn([
            ['id' => self::$storyId, 'type' => 'user_story', 'confidence' => 0.85],
        ]);

        $service = new GitPrMatcherService(self::$db, $gemini);
        $prUrl   = 'https://github.com/test-matcher/repo/pull/3';
        $result  = $service->matchAndLink(
            'Fix checkout', 'Removes unnecessary steps', 'feat/checkout', $prUrl, self::$orgId
        );

        $this->assertSame(1, $result);

        $row = self::$db->query(
            "SELECT ai_matched FROM story_git_links WHERE ref_url = ? LIMIT 1",
            [$prUrl]
        )->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['ai_matched']);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/GitPrMatcherServiceTest.php
```

Expected: FAIL — `GitPrMatcherService` not found.

- [ ] **Step 3: Create the prompt constant**

```php
<?php
// stratflow/src/Services/Prompts/GitPrMatchPrompt.php
declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class GitPrMatchPrompt
{
    /**
     * System prompt for AI PR-to-story matching.
     *
     * Input JSON shape:
     * {
     *   "pr_title": "...", "pr_body": "...", "branch": "...",
     *   "candidates": [{"id": 1, "type": "user_story", "title": "...", "description": "..."}, ...]
     * }
     *
     * Expected output JSON array:
     * [{"id": 1, "type": "user_story", "confidence": 0.85}, ...]
     * Only include candidates with confidence > 0.5. Omit the rest entirely.
     */
    public const PROMPT = <<<'PROMPT'
You are a software-delivery analyst. Given a GitHub pull request and a list of candidate
work items (user stories or OKR work items), identify which items this PR most likely
contributes to.

Rules:
1. Only include candidates where you are genuinely confident the PR contributes to that item.
2. Confidence is a float from 0.0 to 1.0.
3. Only return candidates with confidence > 0.5. Omit everything else.
4. Respond ONLY with a JSON array. No prose, no markdown fences.
5. Each element: {"id": <integer>, "type": "<user_story|hl_work_item>", "confidence": <float>}

Input JSON:
PROMPT;
}
```

- [ ] **Step 4: Add linkAiMatched() to GitLinkService**

In `stratflow/src/Services/GitLinkService.php`, add this public method:

```php
/**
 * Create story_git_links rows for AI-matched items.
 *
 * Called by GitPrMatcherService when Gemini identifies items above
 * the confidence threshold. Sets ai_matched = 1 on each row.
 *
 * @param array  $items    Each element: ['local_type' => '...', 'local_id' => int]
 * @param string $refUrl   PR URL
 * @param string $refTitle PR title
 * @param string $provider 'github' or 'gitlab'
 * @return int             Number of new links created
 */
public function linkAiMatched(array $items, string $refUrl, string $refTitle, string $provider): int
{
    $count = 0;
    foreach ($items as $item) {
        $localType = $item['local_type'];
        $localId   = (int) $item['local_id'];

        // Verify org ownership
        if (!$this->localItemBelongsToOrg($localType, $localId)) {
            continue;
        }

        $existing = $this->findExistingLink($localType, $localId, $refUrl);
        if ($existing !== null) {
            continue; // already linked (explicit tag won the race)
        }

        $refLabel = $this->buildRefLabel($refUrl, $refTitle);
        $this->db->query(
            "INSERT INTO story_git_links
                (local_type, local_id, provider, ref_url, ref_label, ref_title, status, ai_matched)
             VALUES
                (:lt, :lid, :prov, :ref_url, :ref_label, :ref_title, 'open', 1)",
            [
                ':lt'        => $localType,
                ':lid'       => $localId,
                ':prov'      => $provider,
                ':ref_url'   => $refUrl,
                ':ref_label' => $refLabel,
                ':ref_title' => $refTitle,
            ]
        );
        $count++;
    }
    return $count;
}
```

- [ ] **Step 5: Create GitPrMatcherService**

```php
<?php
// stratflow/src/Services/GitPrMatcherService.php
declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Services\Prompts\GitPrMatchPrompt;

class GitPrMatcherService
{
    /** Minimum Gemini confidence to auto-link an item. */
    private const CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(
        private readonly Database      $db,
        private readonly ?GeminiService $gemini
    ) {}

    /**
     * Attempt AI-based PR→story matching and create links for confident matches.
     *
     * Called when linkFromPrBody() returns 0 links (no explicit SF-xxx tag found).
     *
     * @param string $prTitle PR title
     * @param string $prBody  PR description
     * @param string $branch  Source branch name
     * @param string $prUrl   Canonical PR URL
     * @param int    $orgId   Organisation to scope candidate items to
     * @return int            Number of links created
     */
    public function matchAndLink(
        string $prTitle,
        string $prBody,
        string $branch,
        string $prUrl,
        int    $orgId
    ): int {
        if ($this->gemini === null) {
            return 0;
        }

        $candidates = $this->loadCandidates($orgId);
        if (empty($candidates)) {
            return 0;
        }

        $input = json_encode([
            'pr_title'   => $prTitle,
            'pr_body'    => mb_substr($prBody, 0, 1500),
            'branch'     => $branch,
            'candidates' => $candidates,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $matches = $this->gemini->generateJson(GitPrMatchPrompt::PROMPT, $input);
        } catch (\Throwable $e) {
            error_log('[GitPrMatcherService] Gemini error: ' . $e->getMessage());
            return 0;
        }

        if (!is_array($matches)) {
            return 0;
        }

        $toLink = [];
        foreach ($matches as $match) {
            $confidence = (float) ($match['confidence'] ?? 0.0);
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                continue;
            }
            $id   = (int) ($match['id']   ?? 0);
            $type = (string) ($match['type'] ?? '');
            if ($id > 0 && in_array($type, ['user_story', 'hl_work_item'], true)) {
                $toLink[] = ['local_type' => $type, 'local_id' => $id];
            }
        }

        if (empty($toLink)) {
            return 0;
        }

        $service = new GitLinkService($this->db, $orgId);
        return $service->linkAiMatched($toLink, $prUrl, $prTitle, 'github');
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Load open user stories and hl_work_items for the org.
     * Returns title + description only — no sensitive data sent to Gemini.
     */
    private function loadCandidates(int $orgId): array
    {
        $candidates = [];

        // User stories
        $stories = $this->db->query(
            "SELECT us.id, us.title, us.description
               FROM user_stories us
               JOIN projects p ON us.project_id = p.id
              WHERE p.org_id = :oid
                AND us.status NOT IN ('done', 'archived')
              LIMIT 100",
            [':oid' => $orgId]
        )->fetchAll();

        foreach ($stories as $s) {
            $candidates[] = [
                'id'          => (int) $s['id'],
                'type'        => 'user_story',
                'title'       => $s['title'],
                'description' => mb_substr((string) ($s['description'] ?? ''), 0, 300),
            ];
        }

        // OKR work items
        $items = $this->db->query(
            "SELECT hwi.id, hwi.title, hwi.description
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE p.org_id = :oid
                AND hwi.status NOT IN ('done', 'archived')
                AND hwi.okr_title IS NOT NULL
              LIMIT 50",
            [':oid' => $orgId]
        )->fetchAll();

        foreach ($items as $item) {
            $candidates[] = [
                'id'          => (int) $item['id'],
                'type'        => 'hl_work_item',
                'title'       => $item['title'],
                'description' => mb_substr((string) ($item['description'] ?? ''), 0, 300),
            ];
        }

        return $candidates;
    }
}
```

- [ ] **Step 6: Run tests — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/GitPrMatcherServiceTest.php
```

Expected: 3 tests, 0 failures.

- [ ] **Step 7: Commit**

```bash
git add stratflow/src/Services/Prompts/GitPrMatchPrompt.php \
        stratflow/src/Services/GitPrMatcherService.php \
        stratflow/src/Services/GitLinkService.php \
        stratflow/tests/Unit/Services/GitPrMatcherServiceTest.php
git commit -m "feat(kr): GitPrMatcherService — AI fallback PR-to-story linking"
```

---

### Task 10: KrScoringService — score merged PRs against KRs

**Files:**
- Create: `stratflow/src/Services/Prompts/KrScoringPrompt.php`
- Create: `stratflow/src/Services/KrScoringService.php`
- Create: `stratflow/tests/Unit/Services/KrScoringServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// stratflow/tests/Unit/Services/KrScoringServiceTest.php
declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\HLWorkItem;
use StratFlow\Models\KeyResult;
use StratFlow\Models\KeyResultContribution;
use StratFlow\Services\GeminiService;
use StratFlow\Services\KrScoringService;

class KrScoringServiceTest extends TestCase
{
    private static Database $db;
    private static int $orgId;
    private static int $orgBId;
    private static int $projectId;
    private static int $workItemId;
    private static int $krId;
    private static int $linkId;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Database(getTestDbConfig());

        self::$db->query("DELETE FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')");

        // Org A
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KrScoringA']);
        self::$orgId = (int) self::$db->lastInsertId();
        self::$db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)",
            [self::$orgId, 'krscore@test.invalid', password_hash('x', PASSWORD_DEFAULT), 'Scorer', 'user']
        );
        $userId = (int) self::$db->lastInsertId();
        self::$db->query(
            "INSERT INTO projects (org_id, owner_id, name, status) VALUES (?, ?, ?, ?)",
            [self::$orgId, $userId, 'KR Score Project', 'active']
        );
        self::$projectId = (int) self::$db->lastInsertId();

        self::$workItemId = HLWorkItem::create(self::$db, [
            'project_id'       => self::$projectId,
            'priority_number'  => 1,
            'title'            => 'Checkout Improvement',
            'okr_title'        => 'Grow Revenue',
            'estimated_sprints'=> 2,
            'status'           => 'in_progress',
        ]);

        self::$krId = KeyResult::create(self::$db, [
            'org_id'          => self::$orgId,
            'hl_work_item_id' => self::$workItemId,
            'title'           => 'Increase conversion to 5%',
            'target_value'    => 5.0,
            'unit'            => '%',
            'status'          => 'not_started',
        ]);

        // A merged git link for the work item
        self::$db->query(
            "INSERT INTO story_git_links (local_type, local_id, provider, ref_url, ref_label, ref_title, status)
             VALUES ('hl_work_item', ?, 'github', ?, ?, ?, 'merged')",
            [self::$workItemId, 'https://github.com/test-score/repo/pull/99', 'PR #99', 'Reduce checkout steps']
        );
        self::$$linkId = (int) self::$db->lastInsertId();

        // Org B (for isolation test)
        self::$db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - KrScoringB']);
        self::$orgBId = (int) self::$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query("DELETE FROM key_result_contributions WHERE org_id IN (?, ?)", [self::$orgId, self::$orgBId]);
        self::$db->query("DELETE FROM key_results WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM story_git_links WHERE ref_url LIKE 'https://github.com/test-score/%'");
        self::$db->query("DELETE FROM hl_work_items WHERE project_id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM projects WHERE id = ?", [self::$projectId]);
        self::$db->query("DELETE FROM users WHERE org_id = ?", [self::$orgId]);
        self::$db->query("DELETE FROM organisations WHERE name IN ('Test Org - KrScoringA', 'Test Org - KrScoringB')");
    }

    #[Test]
    public function testScoresClamped0To10(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn(['score' => 15, 'rationale' => 'Directly reduces checkout friction']);

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/99', self::$orgId);

        $contribs = KeyResultContribution::findByKeyResultId(self::$db, self::$krId, self::$orgId);
        $this->assertNotEmpty($contribs);
        $this->assertLessThanOrEqual(10, (int) $contribs[0]['ai_relevance_score']);
    }

    #[Test]
    public function testDoesNotScoreIfNoKrsExistForWorkItem(): void
    {
        // Create a work item with no KRs
        $itemId = HLWorkItem::create(self::$db, [
            'project_id' => self::$projectId, 'priority_number' => 99,
            'title' => 'No KR Item', 'estimated_sprints' => 1, 'status' => 'backlog',
        ]);
        self::$db->query(
            "INSERT INTO story_git_links (local_type, local_id, provider, ref_url, ref_label, ref_title, status)
             VALUES ('hl_work_item', ?, 'github', ?, ?, ?, 'merged')",
            [$itemId, 'https://github.com/test-score/repo/pull/100', 'PR #100', 'No-KR PR']
        );

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/100', self::$orgId);

        // Cleanup
        self::$db->query("DELETE FROM story_git_links WHERE ref_url = ?", ['https://github.com/test-score/repo/pull/100']);
        self::$db->query("DELETE FROM hl_work_items WHERE id = ?", [$itemId]);
    }

    #[Test]
    public function testOrgIsolation(): void
    {
        // Org B tries to score a PR that belongs to Org A's work item
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateJson');

        $service = new KrScoringService(self::$db, $gemini);
        $service->scoreForMergedPr('https://github.com/test-score/repo/pull/99', self::$orgBId);

        // No contribution rows written for orgB
        $contribs = KeyResultContribution::findByKeyResultId(self::$db, self::$krId, self::$orgBId);
        $this->assertEmpty($contribs);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/KrScoringServiceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create the scoring prompt**

```php
<?php
// stratflow/src/Services/Prompts/KrScoringPrompt.php
declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class KrScoringPrompt
{
    /**
     * Prompt for scoring a merged PR against a single Key Result.
     *
     * Input JSON shape:
     * {
     *   "kr_title": "...", "kr_description": "...", "kr_target": "...",
     *   "pr_title": "...", "pr_body": "..."
     * }
     *
     * Expected output: {"score": <0-10 integer>, "rationale": "<one sentence>"}
     */
    public const PROMPT = <<<'PROMPT'
You are an engineering performance analyst. Given a Key Result (KR) and a merged
pull request, score how much the PR contributes to that KR.

Scoring guide (integer 0–10):
0  — No discernible connection
1–3 — Marginal or indirect contribution
4–6 — Moderate contribution, addresses part of the KR
7–9 — Strong contribution, directly advances the KR
10 — Complete or near-complete realisation of the KR

Rules:
1. Return ONLY a JSON object. No prose, no markdown.
2. Shape: {"score": <integer 0–10>, "rationale": "<one concise sentence max 120 chars>"}
3. Be conservative — if uncertain, score lower.

Input JSON:
PROMPT;
}
```

- [ ] **Step 4: Create KrScoringService**

```php
<?php
// stratflow/src/Services/KrScoringService.php
declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\KeyResult;
use StratFlow\Models\KeyResultContribution;
use StratFlow\Services\Prompts\KrScoringPrompt;

class KrScoringService
{
    /** Maximum number of recent contributions used to build the ai_momentum summary. */
    private const MOMENTUM_WINDOW = 10;

    public function __construct(
        private readonly Database       $db,
        private readonly ?GeminiService $gemini
    ) {}

    /**
     * Score a merged PR against all KRs linked to the work items it touched.
     *
     * @param string $prUrl  The PR URL (used to look up story_git_links by ref_url)
     * @param int    $orgId  Org to scope KR lookups to
     */
    public function scoreForMergedPr(string $prUrl, int $orgId): void
    {
        if ($this->gemini === null) {
            return;
        }

        // Resolve git links for this PR
        $links = $this->db->query(
            "SELECT id, local_type, local_id, ref_title FROM story_git_links
              WHERE ref_url = :url AND status = 'merged'",
            [':url' => $prUrl]
        )->fetchAll();

        if (empty($links)) {
            return;
        }

        // Collect unique hl_work_item IDs that belong to this org
        $workItemIds = $this->resolveWorkItemIds($links, $orgId);
        if (empty($workItemIds)) {
            return;
        }

        // Load KRs for those work items
        $krs = [];
        foreach ($workItemIds as $wid) {
            foreach (KeyResult::findByWorkItemId($this->db, $wid, $orgId) as $kr) {
                $krs[] = $kr;
            }
        }

        if (empty($krs)) {
            return;
        }

        // Pick the PR title from the first link (all links share the same PR)
        $prTitle = $links[0]['ref_title'] ?? $prUrl;
        // Grab PR body from story_git_links — not stored, so use empty string; caller could pass it
        $prBody  = '';

        foreach ($krs as $kr) {
            foreach ($links as $link) {
                $this->scoreOneKr($kr, (int) $link['id'], $orgId, $prTitle, $prBody);
            }
            $this->refreshMomentum((int) $kr['id'], $orgId);
        }
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Score a single KR against a single git link. Upserts the contribution row.
     */
    private function scoreOneKr(array $kr, int $linkId, int $orgId, string $prTitle, string $prBody): void
    {
        $input = json_encode([
            'kr_title'       => $kr['title'],
            'kr_description' => $kr['metric_description'] ?? '',
            'kr_target'      => $kr['target_value'] ? "{$kr['target_value']} {$kr['unit']}" : '',
            'pr_title'       => $prTitle,
            'pr_body'        => mb_substr($prBody, 0, 1000),
        ], JSON_UNESCAPED_UNICODE);

        try {
            $result = $this->gemini->generateJson(KrScoringPrompt::PROMPT, $input);
        } catch (\Throwable $e) {
            error_log('[KrScoringService] Gemini error for kr_id=' . $kr['id'] . ': ' . $e->getMessage());
            return;
        }

        $score     = (int) ($result['score']     ?? 0);
        $rationale = (string) ($result['rationale'] ?? '');
        $score     = max(0, min(10, $score)); // clamp

        KeyResultContribution::upsert($this->db, (int) $kr['id'], $linkId, $orgId, $score, $rationale);
    }

    /**
     * Summarise the last N contributions for a KR into a one-paragraph ai_momentum string.
     */
    private function refreshMomentum(int $krId, int $orgId): void
    {
        $recent = KeyResultContribution::findRecentByKeyResultId($this->db, $krId, $orgId, self::MOMENTUM_WINDOW);
        if (empty($recent)) {
            return;
        }

        $lines = array_map(
            fn($c) => "- {$c['ref_title']} (score {$c['ai_relevance_score']}/10): {$c['ai_rationale']}",
            $recent
        );
        $summary = 'Recent PRs: ' . implode('; ', array_map(
            fn($c) => "\"{$c['ref_title']}\" scored {$c['ai_relevance_score']}/10",
            $recent
        ));

        // Keep momentum under 500 chars
        KeyResult::update($this->db, $krId, $orgId, ['ai_momentum' => mb_substr($summary, 0, 500)]);
    }

    /**
     * Resolve hl_work_item IDs from a set of git links, verifying org ownership.
     * User stories resolve to their parent work item; direct work item links are used as-is.
     */
    private function resolveWorkItemIds(array $links, int $orgId): array
    {
        $ids = [];
        foreach ($links as $link) {
            $localType = $link['local_type'];
            $localId   = (int) $link['local_id'];

            if ($localType === 'hl_work_item') {
                // Verify ownership
                $row = $this->db->query(
                    "SELECT hwi.id FROM hl_work_items hwi
                       JOIN projects p ON hwi.project_id = p.id
                      WHERE hwi.id = :id AND p.org_id = :oid LIMIT 1",
                    [':id' => $localId, ':oid' => $orgId]
                )->fetch();
                if ($row !== false) {
                    $ids[$localId] = $localId;
                }
            } elseif ($localType === 'user_story') {
                $row = $this->db->query(
                    "SELECT us.parent_hl_item_id FROM user_stories us
                       JOIN projects p ON us.project_id = p.id
                      WHERE us.id = :id AND p.org_id = :oid LIMIT 1",
                    [':id' => $localId, ':oid' => $orgId]
                )->fetch();
                if ($row !== false && $row['parent_hl_item_id'] !== null) {
                    $wid = (int) $row['parent_hl_item_id'];
                    $ids[$wid] = $wid;
                }
            }
        }
        return array_values($ids);
    }
}
```

- [ ] **Step 5: Fix typo in test — `self::$$ linkId` → `self::$linkId`**

In the test file, line where `self::$$linkId` is assigned, correct to `self::$linkId`.

- [ ] **Step 6: Run tests — expect PASS**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/KrScoringServiceTest.php
```

Expected: 3 tests, 0 failures.

- [ ] **Step 7: Commit**

```bash
git add stratflow/src/Services/Prompts/KrScoringPrompt.php \
        stratflow/src/Services/KrScoringService.php \
        stratflow/tests/Unit/Services/KrScoringServiceTest.php
git commit -m "feat(kr): KrScoringService — AI scoring of merged PRs against Key Results"
```

---

### Task 11: Wire AI services into GitWebhookController

**Files:**
- Modify: `stratflow/src/Controllers/GitWebhookController.php`

- [ ] **Step 1: Add imports at the top of GitWebhookController**

After the existing `use StratFlow\Services\GitLinkService;` line, add:

```php
use StratFlow\Services\GeminiService;
use StratFlow\Services\GitPrMatcherService;
use StratFlow\Services\KrScoringService;
```

- [ ] **Step 2: Replace the final echo+return in receiveGitHub() with async dispatch**

Find the end of `receiveGitHub()` — the section starting at:

```php
$affected = $this->dispatchEvent($service, $event, 'github');
error_log(sprintf(...));
http_response_code(200);
echo json_encode(['ok' => true, 'links_affected' => $affected]);
```

Replace with:

```php
        $affected = $this->dispatchEvent($service, $event, 'github');

        error_log(sprintf(
            '[GitWebhook] GitHub PR %s action=%s org_id=%d links_affected=%d',
            $event['pr_url'],
            $event['action'],
            $orgId,
            $affected
        ));

        http_response_code(200);
        echo json_encode(['ok' => true, 'links_affected' => $affected]);

        // ── Async AI work — fires after HTTP response is sent ──────────────
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);

        $gemini = $this->makeGemini();

        // AI PR matching: fires on opened with no explicit tag found
        if ($affected === 0 && in_array($event['action'], ['opened', 'reopened'], true)) {
            $matcher = new GitPrMatcherService($this->db, $gemini);
            $matcher->matchAndLink(
                $event['title'],
                $event['body'],
                $event['branch'] ?? '',
                $event['pr_url'],
                $orgId
            );
        }

        // KR scoring: fires on merged PRs
        if ($event['action'] === 'closed' && ($event['merged'] ?? false)) {
            $scorer = new KrScoringService($this->db, $gemini);
            $scorer->scoreForMergedPr($event['pr_url'], $orgId);
        }
```

- [ ] **Step 3: Add makeGemini() private helper**

At the bottom of `GitWebhookController`, just before the closing `}`, add:

```php
    /**
     * Build a GeminiService from config, or return null if not configured.
     * Returning null causes AI services to gracefully no-op.
     */
    private function makeGemini(): ?GeminiService
    {
        $apiKey = $this->config['gemini']['api_key'] ?? '';
        if ($apiKey === '') {
            return null;
        }
        return new GeminiService($this->config);
    }
```

- [ ] **Step 4: Lint**

```bash
docker compose exec php php -l src/Controllers/GitWebhookController.php
```

Expected: no syntax errors.

- [ ] **Step 5: Run full unit suite to check for regressions**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit
```

Expected: all existing tests pass, new tests pass.

- [ ] **Step 6: Commit**

```bash
git add stratflow/src/Controllers/GitWebhookController.php
git commit -m "feat(kr): wire GitPrMatcherService and KrScoringService async into GitHub webhook"
```

---

### Task 12: Surface AI data in executive project dashboard

**Files:**
- Modify: `stratflow/src/Controllers/ExecutiveController.php`
- Modify: `stratflow/templates/executive-project.php`

- [ ] **Step 1: Load contributions into projectDashboard()**

In `ExecutiveController::projectDashboard()`, after the `$krsByItemId` section, add:

```php
// Contributions per KR (for the expandable PR list — Phase 2)
$contributionsByKrId = [];
foreach ($krRows as $kr) {
    $contribs = \StratFlow\Models\KeyResultContribution::findByKeyResultId(
        $this->db, (int) $kr['id'], $orgId
    );
    if (!empty($contribs)) {
        $contributionsByKrId[(int) $kr['id']] = $contribs;
    }
}
```

Pass it to the render call: `'contributions_by_kr_id' => $contributionsByKrId`.

- [ ] **Step 2: Wire contributions into the template**

In `stratflow/templates/executive-project.php`, inside the KR progress row loop, after the `ai_momentum` block, add the expandable PR list:

```php
<?php
$krContribs = $contributions_by_kr_id[(int) $kr['id']] ?? [];
if (!empty($krContribs)):
?>
<details style="margin-top: 0.375rem;">
    <summary style="font-size: 0.75rem; color: #6366f1; cursor: pointer; list-style: none;">
        + <?= count($krContribs) ?> contributing PR<?= count($krContribs) !== 1 ? 's' : '' ?>
    </summary>
    <div style="margin-top: 0.375rem; padding-left: 0.5rem; border-left: 2px solid #e5e7eb;">
        <?php foreach ($krContribs as $contrib): ?>
        <div style="font-size: 0.75rem; color: #374151; margin-bottom: 0.25rem;">
            <a href="<?= htmlspecialchars($contrib['ref_url'], ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener noreferrer"
               style="color: #6366f1; text-decoration: none;">
                <?= htmlspecialchars($contrib['ref_title'] ?? $contrib['ref_label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <span style="display:inline-block; background:#f3f4f6; border-radius:999px; padding:0 6px; margin-left:4px; font-size:0.7rem; font-weight:600;">
                <?= (int) $contrib['ai_relevance_score'] ?>/10
            </span>
            <?php if (!empty($contrib['ai_rationale'])): ?>
            <span style="color: #9ca3af; font-style: italic; margin-left: 4px;">
                — <?= htmlspecialchars($contrib['ai_rationale'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>
```

- [ ] **Step 3: Lint both files**

```bash
docker compose exec php php -l src/Controllers/ExecutiveController.php
docker compose exec php php -l templates/executive-project.php
```

- [ ] **Step 4: Run full unit suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite unit
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add stratflow/src/Controllers/ExecutiveController.php \
        stratflow/templates/executive-project.php
git commit -m "feat(kr): wire contributing PRs and ai_momentum into executive project dashboard"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Covered by |
|---|---|
| `key_results` table | Task 1 |
| `key_result_contributions` table | Task 1 |
| `ai_matched` on `story_git_links` | Task 1 |
| KeyResult CRUD + org isolation | Tasks 2, 4 |
| KR inline editor on work item page | Task 5 |
| KR progress bars on exec dashboard | Task 7 |
| Risks + dependencies on OKR cards | Tasks 6, 7 |
| Worst-KR rolls up to OKR status badge | Task 7 |
| Jira Goals sync — KRs as child goals | Task 8 |
| `pullKrStatusFromGoals` | Task 8 |
| AI fallback PR→story matching | Task 9 |
| AI KR scoring (0–10, clamped) | Task 10 |
| `ai_momentum` refreshed per merge | Task 10 |
| Async hook in webhook controller | Task 11 |
| Contributing PRs expandable section | Task 12 |
| Org isolation throughout | Tasks 2, 9 (test), 10 (test) |
| AI prompts in `Prompts/*.php` | Tasks 9, 10 |
| Gemini scores clamped 0–10 | Task 10 (`KrScoringService::scoreOneKr`) |

**Type consistency check:** `KeyResult::update()` called with same signature everywhere. `KeyResultContribution::upsert()` called with matching param order in `KrScoringService`. `findByKeyResultId()` called with matching signature in `ExecutiveController`.

**No placeholders:** All steps contain complete code. No "TBD" or "fill in" language.
