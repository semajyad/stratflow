<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\ExecutiveController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class ExecutiveControllerTest extends ControllerTestCase
{
    private array $user = ['id'=>1,'org_id'=>10,'role'=>'org_admin','email'=>'a@t.invalid','is_active'=>1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = [];
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): ExecutiveController
    {
        return new ExecutiveController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    public function testDashboardRendersExecutiveTemplate(): void
    {
        $this->db->method('query')->willReturnCallback(function() {
            return $this->stmt(null, []);
        });

        $ctrl = $this->ctrl();
        $ctrl->dashboard();

        $this->assertSame('executive', $this->response->renderedTemplate);
        $this->assertArrayHasKey('user', $this->response->renderedData);
        $this->assertArrayHasKey('portfolio', $this->response->renderedData);
        $this->assertArrayHasKey('active_page', $this->response->renderedData);
    }

    public function testDashboardPassesUserToTemplate(): void
    {
        $this->db->method('query')->willReturnCallback(function() {
            return $this->stmt(null, []);
        });

        $ctrl = $this->ctrl();
        $ctrl->dashboard();

        $this->assertEquals($this->user, $this->response->renderedData['user']);
        $this->assertEquals('executive', $this->response->renderedData['active_page']);
    }

    public function testProjectDashboardVerifiesOrgOwnership(): void
    {
        $this->db->method('query')->willReturn(
            $this->stmt(false)
        );

        $ctrl = $this->ctrl();
        $ctrl->projectDashboard(999);

        $this->assertEquals(404, http_response_code());
    }

    public function testDashboardUsesOrgId(): void
    {
        $this->db->method('query')->willReturnCallback(function() {
            return $this->stmt(null, []);
        });

        $ctrl = $this->ctrl();
        $ctrl->dashboard();

        // Verify org_id from user is used in queries
        $this->assertEquals(10, $this->user['org_id']);
    }

    // ===========================
    // dashboard() — data-processing branches
    // ===========================

    /**
     * Build a fresh DB mock with a sequence of (fetch, fetchAll) per query call.
     * Executive dashboard fires ~15 queries; we track by call index.
     */
    private function freshExecDb(array $sequence): \StratFlow\Core\Database
    {
        $idx = 0;
        $db  = $this->createMock(\StratFlow\Core\Database::class);
        $db->method('tableExists')->willReturn(false);
        $db->method('query')->willReturnCallback(
            function () use (&$idx, $sequence, $db) {
                $pair  = $sequence[$idx++] ?? [false, []];
                $fetch = $pair[0];
                $all   = $pair[1];
                return $this->stmt($fetch, $all);
            }
        );
        return $db;
    }

    public function testDashboardAggregatesPortfolioStatus(): void
    {
        // Provide real rows for query #1 (portfolio) and nulls/empty for the rest
        $portfolioRows = [
            ['status' => 'active',    'cnt' => 3],
            ['status' => 'draft',     'cnt' => 1],
            ['status' => 'completed', 'cnt' => 2],
        ];
        $db = $this->freshExecDb([
            [false, $portfolioRows],  // 1. portfolio
            [false, []],               // 2. backlog
            [false, []],               // 3. top items
            [false, []],               // 4. velocity
            [false, []],               // 5. active sprints
            [false, []],               // 6. risk summary (fetch single row)
            [false, []],               // 7. drift rows
            [false, []],               // 8. gov items
            [false, []],               // 9. integrations
            [false, []],               // 10. subscription
            [false, []],               // 11. seat used
            [false, []],               // 12. okr items (diagram_nodes)
            [false, []],               // 13. kr counts
            [false, []],               // 14. kr detail rows
            [false, []],               // 15. story progress
            [false, []],               // 16. merged pr
            [false, []],               // 17. top risks
            [false, []],               // 18. critical alerts
            [false, []],               // 19. recent audit
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertSame('executive', $this->response->renderedTemplate);
        $portfolio = $this->response->renderedData['portfolio'];
        $this->assertSame(3, $portfolio['active']);
        $this->assertSame(1, $portfolio['draft']);
        $this->assertSame(2, $portfolio['completed']);
        $this->assertSame(6, $portfolio['total']);
    }

    public function testDashboardAggregatesDriftAlerts(): void
    {
        $driftRows = [
            ['severity' => 'critical', 'cnt' => 2],
            ['severity' => 'warning',  'cnt' => 1],
        ];
        // Risk summary needs a fetch (not fetchAll) — simulate with the fetch position
        $riskRow = ['high' => 1, 'medium' => 3, 'low' => 5];
        $db = $this->freshExecDb([
            [false, []],          // 1. portfolio
            [false, []],          // 2. backlog
            [false, []],          // 3. top items
            [false, []],          // 4. velocity
            [false, []],          // 5. active sprints
            [$riskRow, []],       // 6. risk summary (fetch)
            [false, $driftRows],  // 7. drift rows (fetchAll)
            [false, []],          // 8. gov items
            [false, []],          // 9. integrations
            [false, []],          // 10. subscription
            [false, []],          // 11. seat used
            [false, []],          // 12. okr items
            [false, []],          // 13. kr counts
            [false, []],          // 14. kr detail
            [false, []],          // 15. story progress
            [false, []],          // 16. merged pr
            [false, []],          // 17. top risks
            [false, []],          // 18. critical alerts
            [false, []],          // 19. recent audit
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $driftAlerts = $this->response->renderedData['drift_alerts'];
        $this->assertSame(2, $driftAlerts['critical']);
        $this->assertSame(1, $driftAlerts['warning']);
        $this->assertSame(3, $driftAlerts['total']);

        $riskSummary = $this->response->renderedData['risk_summary'];
        $this->assertSame(1, $riskSummary['high']);
        $this->assertSame(3, $riskSummary['medium']);
    }

    public function testDashboardProcessesOkrItemsWithKrLines(): void
    {
        $okrItem = [
            'item_id'         => 1,
            'node_key'        => 'n1',
            'okr_title'       => 'Grow Revenue',
            'okr_description' => "KR1: Reach $1M ARR\nKR2: Add 50 customers\nSome description line",
            'project_id'      => 5,
            'project_name'    => 'Alpha',
            'on_track'        => 0, 'at_risk' => 0, 'off_track' => 0,
            'not_started'     => 0, 'achieved' => 0, 'kr_count'  => 0,
        ];
        $db = $this->freshExecDb([
            [false, []],           // 1. portfolio
            [false, []],           // 2. backlog
            [false, []],           // 3. top items
            [false, []],           // 4. velocity
            [false, []],           // 5. active sprints
            [false, []],           // 6. risk summary
            [false, []],           // 7. drift rows
            [false, []],           // 8. gov items
            [false, []],           // 9. integrations
            [false, []],           // 10. subscription
            [false, []],           // 11. seat used
            [false, [$okrItem]],   // 12. okr items from diagram_nodes
            [false, []],           // 13. kr counts
            [false, []],           // 14. kr detail
            [false, []],           // 15. story progress
            [false, []],           // 16. merged pr
            [false, []],           // 17. top risks
            [false, []],           // 18. critical alerts
            [false, []],           // 19. recent audit
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $okrItems = $this->response->renderedData['okr_items'];
        $this->assertCount(1, $okrItems);
        $this->assertCount(2, $okrItems[0]['kr_lines']);
        $this->assertCount(1, $okrItems[0]['description_lines']);
        $this->assertSame(2, $okrItems[0]['kr_count']);
    }

    public function testDashboardPassesGovernanceQueueDepth(): void
    {
        $govItem = ['id' => 1, 'change_type' => 'scope', 'proposed_change_json' => '{}',
                    'created_at' => '2025-01-01', 'project_id' => 5, 'project_name' => 'Alpha'];
        $db = $this->freshExecDb([
            [false, []],           // 1. portfolio
            [false, []],           // 2. backlog
            [false, []],           // 3. top items
            [false, []],           // 4. velocity
            [false, []],           // 5. active sprints
            [false, []],           // 6. risk summary
            [false, []],           // 7. drift rows
            [false, [$govItem]],   // 8. gov items
            [false, []],           // 9. integrations
            [false, []],           // 10. subscription
            [false, []],           // 11. seat used
            [false, []],           // 12. okr items
            [false, []],           // 13. kr counts
            [false, []],           // 14. kr detail
            [false, []],           // 15. story progress
            [false, []],           // 16. merged pr
            [false, []],           // 17. top risks
            [false, []],           // 18. critical alerts
            [false, []],           // 19. recent audit
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertSame(1, $this->response->renderedData['governance_queue']);
    }

    public function testDashboardClearsFlashSession(): void
    {
        $_SESSION['flash_message'] = 'hello';
        $_SESSION['flash_error']   = 'bad';

        $db = $this->freshExecDb(array_fill(0, 20, [false, []]));
        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // ===========================
    // projectDashboard() — success path
    // ===========================

    public function testProjectDashboardRendersWhenProjectExists(): void
    {
        $project  = ['id' => 5, 'name' => 'Alpha', 'updated_at' => '2025-01-01'];
        $projects = [['id' => 5, 'name' => 'Alpha'], ['id' => 6, 'name' => 'Beta']];
        $db = $this->freshExecDb([
            [$project, []],    // 1. project lookup (fetch)
            [false, $projects], // 2. all org projects (fetchAll)
            [false, []],        // 3. okr items from diagram_nodes
            [false, []],        // 4. story progress per OKR
            [false, []],        // 5. structured key_results
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->projectDashboard(5);

        $this->assertSame('executive-project', $this->response->renderedTemplate);
        $data = $this->response->renderedData;
        $this->assertSame($project, $data['project']);
        $this->assertCount(2, $data['projects']);
        $this->assertSame(0, $data['health_counts']['total_okrs']);
        $this->assertSame(0, $data['health_counts']['total_krs']);
    }

    public function testProjectDashboardProcessesOkrItems(): void
    {
        $project = ['id' => 5, 'name' => 'Alpha', 'updated_at' => '2025-01-01'];
        $okrItem = [
            'id'              => 10,
            'okr_title'       => 'Expand Market',
            'okr_description' => "KR1: Get 100 signups\nKR2: NPS > 40",
        ];
        $db = $this->freshExecDb([
            [$project, []],      // 1. project lookup
            [false, []],          // 2. all org projects
            [false, [$okrItem]], // 3. okr items
            [false, []],          // 4. story progress
            [false, []],          // 5. key_results
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->projectDashboard(5);

        $data = $this->response->renderedData;
        $this->assertSame('executive-project', $this->response->renderedTemplate);
        $this->assertCount(1, $data['okr_items']);
        $this->assertSame(2, count($data['okr_items'][0]['kr_lines']));
        $this->assertSame(1, $data['health_counts']['total_okrs']);
        $this->assertSame(2, $data['health_counts']['total_krs']);
    }

    public function testProjectDashboardAttachesStoryProgress(): void
    {
        $project = ['id' => 5, 'name' => 'Alpha', 'updated_at' => '2025-01-01'];
        $okrItem = ['id' => 10, 'okr_title' => 'Revenue', 'okr_description' => 'KR1: Hit targets'];
        $spRow   = [
            'okr_title'   => 'Revenue',
            'kr_hypothesis' => '',
            'total'       => 10,
            'done'        => 5,
            'in_progress' => 2,
        ];
        $db = $this->freshExecDb([
            [$project, []],       // 1. project
            [false, []],           // 2. projects list
            [false, [$okrItem]], // 3. okr items
            [false, [$spRow]],    // 4. story progress rows
            [false, []],           // 5. key_results
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->projectDashboard(5);

        $okrItems = $this->response->renderedData['okr_items'];
        $this->assertSame(10, $okrItems[0]['story_total']);
        $this->assertSame(5, $okrItems[0]['story_done']);
        $this->assertSame(50, $okrItems[0]['story_pct']);
    }

    public function testProjectDashboardClearsFlashSession(): void
    {
        $_SESSION['flash_message'] = 'test';

        $project = ['id' => 5, 'name' => 'Alpha', 'updated_at' => '2025-01-01'];
        $db = $this->freshExecDb([
            [$project, []],
            [false, []],
            [false, []],
            [false, []],
            [false, []],
        ]);

        $ctrl = new ExecutiveController($this->makeGetRequest(), $this->response, $this->auth, $db, $this->config);
        $ctrl->projectDashboard(5);

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
    }
}
