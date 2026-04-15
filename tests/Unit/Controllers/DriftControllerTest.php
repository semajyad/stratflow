<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\DriftController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * DriftControllerTest
 *
 * Unit tests for DriftController (governance/drift detection).
 * Tests all five public methods: dashboard(), createBaseline(), runDetection(),
 * acknowledgeAlert(), reviewChange().
 * Coverage target: ≥80% method coverage.
 */
final class DriftControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $this->actingAs([
            'id'        => 1,
            'org_id'    => 10,
            'role'      => 'org_admin',
            'email'     => 'admin@test.invalid',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function testDashboardRedirectsWhenProjectNotFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest(['project_id' => '999']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->dashboard();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testDashboardRedirectsWithoutProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->dashboard();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testDashboardWithZeroProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest(['project_id' => '0']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->dashboard();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testCreateBaselineRedirectsWhenProjectNotEditable(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->createBaseline();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testCreateBaselineWithZeroProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['project_id' => '0']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->createBaseline();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testRunDetectionRedirectsWhenProjectNotEditable(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->runDetection();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testRunDetectionWithZeroProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['project_id' => '0']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->runDetection();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testAcknowledgeAlertRedirectsWhenAlertNotFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->acknowledgeAlert(999);

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testAcknowledgeAlertRedirectsWhenProjectNotEditable(): void
    {
        $alert = ['id' => 1, 'project_id' => 5, 'status' => 'active'];
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum, $alert) {
            $callNum++;
            if ($callNum === 1) {
                return $alert;
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->acknowledgeAlert(1);

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testAcknowledgeAlertSetsSessionAction(): void
    {
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum) {
            $callNum++;
            if ($callNum === 1) {
                return ['id' => 1, 'project_id' => 1, 'status' => 'active'];
            }
            if ($callNum === 2) {
                return ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['action' => 'acknowledge', 'redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->acknowledgeAlert(1);

        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function testAcknowledgeAlertDefaultsActionToAcknowledge(): void
    {
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum) {
            $callNum++;
            if ($callNum === 1) {
                return ['id' => 1, 'project_id' => 1, 'status' => 'active'];
            }
            if ($callNum === 2) {
                return ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->acknowledgeAlert(1);

        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function testReviewChangeRedirectsWhenItemNotFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->reviewChange(999);

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testReviewChangeRedirectsWhenProjectNotEditable(): void
    {
        $item = ['id' => 1, 'project_id' => 5, 'status' => 'pending'];
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum, $item) {
            $callNum++;
            if ($callNum === 1) {
                return $item;
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->reviewChange(1);

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testReviewChangeAcceptsApproveAction(): void
    {
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum) {
            $callNum++;
            if ($callNum === 1) {
                return [
                    'id'                   => 1,
                    'project_id'           => 1,
                    'status'               => 'pending',
                    'proposed_change_json' => '{"work_item_id": 5}',
                ];
            }
            if ($callNum === 2) {
                return ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['action' => 'approve', 'redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->reviewChange(1);

        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function testReviewChangeAcceptsRejectAction(): void
    {
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum) {
            $callNum++;
            if ($callNum === 1) {
                return [
                    'id'                   => 1,
                    'project_id'           => 1,
                    'status'               => 'pending',
                    'proposed_change_json' => '{"work_item_id": 5}',
                ];
            }
            if ($callNum === 2) {
                return ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['action' => 'reject', 'redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->reviewChange(1);

        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function testReviewChangeDefaultsActionToApprove(): void
    {
        $callNum = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(function () use (&$callNum) {
            $callNum++;
            if ($callNum === 1) {
                return [
                    'id'                   => 1,
                    'project_id'           => 1,
                    'status'               => 'pending',
                    'proposed_change_json' => '{"work_item_id": 5}',
                ];
            }
            if ($callNum === 2) {
                return ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
            }
            return false;
        });
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest(['redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->reviewChange(1);

        $this->assertNotNull($this->response->redirectedTo);
    }

    #[Test]
    public function testCreateBaselineWithoutProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->createBaseline();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testRunDetectionWithoutProjectId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new DriftController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->runDetection();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    // ===========================
    // dashboard() — success path
    // ===========================

    /**
     * Build a fresh Database mock with sequenced fetch/fetchAll returns.
     * Required because setUp() already locks $this->db->method('query').
     */
    private function freshDb(array $fetchReturns, array $fetchAllReturns = []): \StratFlow\Core\Database
    {
        $fetchIdx    = 0;
        $fetchAllIdx = 0;
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturnCallback(
            function () use (&$fetchIdx, $fetchReturns) {
                return $fetchReturns[$fetchIdx++] ?? false;
            }
        );
        $stmt->method('fetchAll')->willReturnCallback(
            function () use (&$fetchAllIdx, $fetchAllReturns) {
                return $fetchAllReturns[$fetchAllIdx++] ?? [];
            }
        );
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('tableExists')->willReturn(false);
        return $db;
    }

    #[Test]
    public function testDashboardRendersWhenProjectFound(): void
    {
        $project = ['id' => 1, 'name' => 'P1', 'org_id' => 10, 'visibility' => 'everyone'];
        $subRow  = ['has_evaluation_board' => 1];
        $db = $this->freshDb([$project, $subRow], [[], [], []]);

        $request = $this->makeGetRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertSame('governance', $this->response->renderedTemplate);
        $this->assertArrayHasKey('project', $this->response->renderedData);
    }

    #[Test]
    public function testDashboardDecodesBaselineSnapshots(): void
    {
        $project  = ['id' => 1, 'name' => 'P1', 'org_id' => 10, 'visibility' => 'everyone'];
        $baseline = [
            'id'            => 42,
            'created_at'    => '2025-01-01',
            'snapshot_json' => json_encode([
                'work_items' => [1, 2, 3],
                'stories'    => ['total_size' => 20, 'total_count' => 5],
            ]),
        ];
        $subRow = ['has_evaluation_board' => 0];
        // fetchAll order: alerts=[], governance=[], baselines=[baseline]
        $db = $this->freshDb([$project, $subRow], [[], [], [$baseline]]);

        $request = $this->makeGetRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertSame('governance', $this->response->renderedTemplate);
        $baselines = $this->response->renderedData['baselines'];
        $this->assertCount(1, $baselines);
        $this->assertSame(3, $baselines[0]['work_item_count']);
        $this->assertSame(20, $baselines[0]['total_story_size']);
        $this->assertSame(5, $baselines[0]['story_count']);
    }

    #[Test]
    public function testDashboardClearsFlashFromSession(): void
    {
        $_SESSION['flash_message'] = 'done';
        $_SESSION['flash_error']   = 'err';

        $project = ['id' => 1, 'name' => 'P1', 'org_id' => 10, 'visibility' => 'everyone'];
        $subRow  = ['has_evaluation_board' => 0];
        $db = $this->freshDb([$project, $subRow], [[], [], []]);

        $request = $this->makeGetRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->dashboard();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // ===========================
    // runDetection() — success paths
    // ===========================

    #[Test]
    public function testRunDetectionNoDriftSetsFlashMessage(): void
    {
        $project = ['id' => 1, 'name' => 'P1', 'org_id' => 10, 'visibility' => 'everyone'];
        $org     = ['id' => 10, 'settings_json' => json_encode(['capacity_tripwire_percent' => 15])];
        // fetchAll returns empty => no baselines => detectDrift returns []
        $db = $this->freshDb([$project, $org], [[]]);

        $config  = array_merge($this->config, ['gemini' => ['api_key' => 'fake-key', 'model' => 'gemini-pro']]);
        $request = $this->makePostRequest(['project_id' => '1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $config);
        $ctrl->runDetection();

        $this->assertNotNull($this->response->redirectedTo);
        $this->assertStringContainsString('project_id=1', $this->response->redirectedTo);
        $this->assertStringContainsString('No drift detected', $_SESSION['flash_message'] ?? '');
    }

    // ===========================
    // acknowledgeAlert() — resolve + redirect fallback
    // ===========================

    #[Test]
    public function testAcknowledgeAlertResolveActionSetsResolvedStatus(): void
    {
        $db = $this->freshDb(
            [['id' => 1, 'project_id' => 1, 'status' => 'active'], ['id' => 1, 'name' => 'Test Project', 'org_id' => 10]],
            []
        );

        $request = $this->makePostRequest(['action' => 'resolve']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->acknowledgeAlert(1);

        $this->assertStringContainsString('resolved', $_SESSION['flash_message'] ?? '');
        $this->assertStringContainsString('/app/governance', $this->response->redirectedTo);
    }

    #[Test]
    public function testAcknowledgeAlertFallbackRedirectWhenRedirectToInvalid(): void
    {
        $db = $this->freshDb(
            [['id' => 1, 'project_id' => 7, 'status' => 'active'], ['id' => 1, 'name' => 'Test Project', 'org_id' => 10]],
            []
        );

        // redirect_to does not start with /app/ → should fall back to /app/governance?project_id=7
        $request = $this->makePostRequest(['action' => 'acknowledge', 'redirect_to' => 'https://evil.com']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->acknowledgeAlert(1);

        $this->assertStringContainsString('project_id=7', $this->response->redirectedTo);
    }

    // ===========================
    // reviewChange() — reject + redirect fallback + parent_item_id branch
    // ===========================

    #[Test]
    public function testReviewChangeApproveWithParentItemId(): void
    {
        $db = $this->freshDb(
            [
                ['id' => 1, 'project_id' => 1, 'status' => 'pending', 'proposed_change_json' => '{"parent_item_id": 10}'],
                ['id' => 1, 'name' => 'Test Project', 'org_id' => 10],
            ],
            []
        );

        $request = $this->makePostRequest(['action' => 'approve', 'redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reviewChange(1);

        $this->assertStringContainsString('approved', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testReviewChangeFallbackRedirectWhenRedirectToInvalid(): void
    {
        $db = $this->freshDb(
            [
                ['id' => 1, 'project_id' => 9, 'status' => 'pending', 'proposed_change_json' => '{}'],
                ['id' => 1, 'name' => 'Test Project', 'org_id' => 10],
            ],
            []
        );

        $request = $this->makePostRequest(['action' => 'reject', 'redirect_to' => 'http://external.com']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reviewChange(1);

        $this->assertStringContainsString('project_id=9', $this->response->redirectedTo);
        $this->assertStringContainsString('rejected', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testReviewChangeApproveWithNoRelatedItemId(): void
    {
        $db = $this->freshDb(
            [
                ['id' => 1, 'project_id' => 1, 'status' => 'pending', 'proposed_change_json' => '{"other_field": "value"}'],
                ['id' => 1, 'name' => 'Test Project', 'org_id' => 10],
            ],
            []
        );

        $request = $this->makePostRequest(['action' => 'approve', 'redirect_to' => '/app/governance?project_id=1']);
        $ctrl = new DriftController($request, $this->response, $this->auth, $db, $this->config);
        $ctrl->reviewChange(1);

        // When no related item ID, skip HLWorkItem update — should still redirect
        $this->assertNotNull($this->response->redirectedTo);
        $this->assertStringContainsString('approved', $_SESSION['flash_message'] ?? '');
    }
}
