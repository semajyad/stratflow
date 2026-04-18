<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\SuperadminController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * SuperadminControllerTest
 *
 * Covers all public methods of SuperadminController.
 *
 * Uses a callback-queue approach for db->query() so that successive calls
 * within a single controller action can return different PDOStatement doubles
 * without mixing willReturn / willReturnOnConsecutiveCalls on the same mock.
 */
class SuperadminControllerTest extends ControllerTestCase
{
    private array $superadmin = [
        'id'        => 1,
        'org_id'    => 1,
        'role'      => 'superadmin',
        'email'     => 'super@test.invalid',
        'name'      => 'Super',
        'full_name' => 'Super Admin',
        'is_active' => 1,
    ];

    /** @var \PDOStatement[] FIFO queue consumed by the db->query() callback. */
    private array $stmtQueue = [];

    /** Fallback stmt returned when queue is empty. */
    private ?\PDOStatement $defaultStmt = null;

    // =========================================================================
    // SETUP / TEARDOWN
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $this->stmtQueue   = [];
        $this->defaultStmt = $this->makeStmt(false, []);

        $this->db->method('tableExists')->willReturn(false);
        $this->db->method('lastInsertId')->willReturn('1');

        // Single callback drives all db->query() calls.
        $this->db->method('query')->willReturnCallback(function (): \PDOStatement {
            if (!empty($this->stmtQueue)) {
                return array_shift($this->stmtQueue);
            }
            return $this->defaultStmt ?? $this->makeStmt(false, []);
        });

        $this->actingAs($this->superadmin);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Build a PDOStatement mock with predictable fetch / fetchAll results.
     */
    private function makeStmt(mixed $fetch = false, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    /**
     * Push PDOStatement mocks onto the queue consumed by db->query().
     */
    private function queueStmts(\PDOStatement ...$stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->stmtQueue[] = $stmt;
        }
    }

    /**
     * Set the default (fallback) stmt returned when queue is empty.
     */
    private function setDefault(mixed $fetch = false, array $all = []): void
    {
        $this->defaultStmt = $this->makeStmt($fetch, $all);
    }

    private function ctrl(?FakeRequest $r = null): SuperadminController
    {
        return new SuperadminController(
            $r ?? $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    private function postCtrl(array $body = [], string $uri = '/'): SuperadminController
    {
        return new SuperadminController(
            $this->makePostRequest($body, $uri),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    // =========================================================================
    // index()
    // =========================================================================

    #[Test]
    public function testIndexRendersTemplate(): void
    {
        // countAll() calls: organisations, users (is_active=1), subscriptions (active)
        $this->setDefault(['cnt' => 5], []);

        $this->ctrl()->index();

        $this->assertSame('superadmin/index', $this->response->renderedTemplate);
    }

    #[Test]
    public function testIndexPassesCountsToView(): void
    {
        $this->setDefault(['cnt' => 3], []);

        $this->ctrl()->index();

        $data = $this->response->renderedData;
        $this->assertArrayHasKey('org_count', $data);
        $this->assertArrayHasKey('user_count', $data);
        $this->assertArrayHasKey('subscription_count', $data);
    }

    #[Test]
    public function testIndexClearsFlashAfterRender(): void
    {
        $_SESSION['flash_message'] = 'hello';
        $_SESSION['flash_error']   = 'err';
        $this->setDefault(['cnt' => 0], []);

        $this->ctrl()->index();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // =========================================================================
    // organisations()
    // =========================================================================

    #[Test]
    public function testOrganisationsRendersTemplate(): void
    {
        // Organisation::findAll -> fetchAll returns []
        // getAllUsers -> fetchAll returns []
        $this->setDefault(false, []);

        $this->ctrl()->organisations();

        $this->assertSame('superadmin/organisations', $this->response->renderedTemplate);
    }

    #[Test]
    public function testOrganisationsPassesOrgsToView(): void
    {
        $this->setDefault(false, []);

        $this->ctrl()->organisations();

        $this->assertArrayHasKey('orgs', $this->response->renderedData);
    }

    #[Test]
    public function testOrganisationsLoadsSubscriptionForEachOrg(): void
    {
        // org row returned from findAll, then subscription lookup for that org
        $org     = ['id' => 7, 'name' => 'Acme', 'is_active' => 1, 'created_at' => '2025-01-01 00:00:00', 'user_count' => 2];
        $orgStmt = $this->makeStmt(false, [$org]);
        $subStmt = $this->makeStmt(false, []);   // Subscription::findByOrgId returns null
        $usersStmt = $this->makeStmt(false, []); // getAllUsers
        $this->queueStmts($orgStmt, $subStmt, $usersStmt);
        $this->setDefault(false, []);

        $this->ctrl()->organisations();

        $data = $this->response->renderedData;
        $this->assertArrayHasKey(7, $data['org_subs']);
    }

    // =========================================================================
    // users()
    // =========================================================================

    #[Test]
    public function testUsersRendersTemplate(): void
    {
        $this->setDefault(false, [
            ['id' => 1, 'email' => 'a@b.com', 'org_name' => 'Org A'],
        ]);

        $this->ctrl()->users();

        $this->assertSame('superadmin/users', $this->response->renderedTemplate);
    }

    #[Test]
    public function testUsersPassesAllUsersToView(): void
    {
        $this->setDefault(false, []);

        $this->ctrl()->users();

        $this->assertArrayHasKey('all_users', $this->response->renderedData);
    }

    // =========================================================================
    // subscriptions()
    // =========================================================================

    #[Test]
    public function testSubscriptionsRendersTemplate(): void
    {
        $this->setDefault(false, []);

        $this->ctrl()->subscriptions();

        $this->assertSame('superadmin/subscriptions', $this->response->renderedTemplate);
    }

    // =========================================================================
    // createOrg()
    // =========================================================================

    #[Test]
    public function testCreateOrgEmptyNameRedirects(): void
    {
        $this->postCtrl(['org_name' => ''])->createOrg();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Organisation name is required.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testCreateOrgSuccessRedirects(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl([
            'org_name'  => 'Acme Corp',
            'plan_type' => 'product',
        ])->createOrg();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('Acme Corp', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testCreateOrgWithConsultancyPlan(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl([
            'org_name'  => 'Consultancy Co',
            'plan_type' => 'consultancy',
        ])->createOrg();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    #[Test]
    public function testCreateOrgWithNoPlanTypeSkipsSubscription(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl([
            'org_name'  => 'No Sub Org',
            'plan_type' => 'none',
        ])->createOrg();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    // =========================================================================
    // updateOrg()
    // =========================================================================

    #[Test]
    public function testUpdateOrgNotFoundRedirects(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'suspend'])->updateOrg(99);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Organisation not found.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testUpdateOrgSuspend(): void
    {
        $org = ['id' => 1, 'name' => 'Test Org', 'is_active' => 1];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'suspend'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('suspended', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testUpdateOrgEnable(): void
    {
        $org = ['id' => 1, 'name' => 'Disabled Org', 'is_active' => 0];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'enable'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('enabled', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testUpdateOrgDelete(): void
    {
        $org = ['id' => 1, 'name' => 'Old Org', 'is_active' => 1];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'delete'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('deleted', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testUpdateOrgUnknownActionSetsError(): void
    {
        $org = ['id' => 1, 'name' => 'Org', 'is_active' => 1];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'bogus'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Unknown action.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testUpdateOrgEditWithExistingSubscription(): void
    {
        $org = ['id' => 1, 'name' => 'Org', 'is_active' => 1];
        $sub = ['id' => 10, 'org_id' => 1, 'plan_type' => 'product'];

        // Queue order: findById, Organisation::update, Subscription::findByOrgId
        // then UPDATE subscriptions + AuditLogger.log fall through to default
        $orgStmt    = $this->makeStmt($org, []);
        $updateStmt = $this->makeStmt(false, []);
        $subStmt    = $this->makeStmt($sub, []);
        $this->queueStmts($orgStmt, $updateStmt, $subStmt);
        $this->setDefault(false, []);

        $this->postCtrl([
            'action'    => 'edit',
            'org_name'  => 'Renamed',
            'plan_type' => 'product',
            'seat_limit' => '10',
            'billing_method' => 'invoiced',
        ])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    #[Test]
    public function testUpdateOrgEditNoSubscriptionCreatesOne(): void
    {
        $org = ['id' => 1, 'name' => 'Org', 'is_active' => 1];

        $orgStmt   = $this->makeStmt($org, []);
        $noSubStmt = $this->makeStmt(false, []);
        $this->queueStmts($orgStmt, $noSubStmt);
        $this->setDefault(false, []);

        $this->postCtrl([
            'action'    => 'edit',
            'org_name'  => 'New Name',
            'plan_type' => 'product',
        ])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    #[Test]
    public function testUpdateOrgUpdateSeats(): void
    {
        $org = ['id' => 1, 'name' => 'Org', 'is_active' => 1];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'update_seats', 'seat_limit' => '20'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('Seat limit', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testUpdateOrgRename(): void
    {
        $org = ['id' => 1, 'name' => 'Old', 'is_active' => 1];
        $this->setDefault($org, []);

        $this->postCtrl(['action' => 'rename', 'org_name' => 'New Name'])->updateOrg(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    // =========================================================================
    // toggleJira()
    // =========================================================================

    #[Test]
    public function testToggleJiraOrgNotFound(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'enable'])->toggleJira(99);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Organisation not found.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testToggleJiraEnableCreatesIntegration(): void
    {
        $org = ['id' => 1, 'name' => 'Jira Org'];
        $orgStmt   = $this->makeStmt($org, []);
        $noIntStmt = $this->makeStmt(false, []);
        $this->queueStmts($orgStmt, $noIntStmt);
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'enable'])->toggleJira(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    #[Test]
    public function testToggleJiraEnableUpdatesExistingIntegration(): void
    {
        $org         = ['id' => 1, 'name' => 'Jira Org'];
        $integration = ['id' => 3, 'org_id' => 1, 'provider' => 'jira', 'status' => 'active'];
        $orgStmt = $this->makeStmt($org, []);
        $intStmt = $this->makeStmt($integration, []);
        $this->queueStmts($orgStmt, $intStmt);
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'enable'])->toggleJira(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    #[Test]
    public function testToggleJiraDisable(): void
    {
        $org         = ['id' => 1, 'name' => 'Jira Org'];
        $integration = ['id' => 5, 'org_id' => 1, 'provider' => 'jira'];

        $orgStmt = $this->makeStmt($org, []);
        $intStmt = $this->makeStmt($integration, []);
        $this->queueStmts($orgStmt, $intStmt);
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'disable'])->toggleJira(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
    }

    // =========================================================================
    // exportOrg()
    // =========================================================================

    #[Test]
    public function testExportOrgNotFoundRedirects(): void
    {
        // Organisation::exportData -> fetch returns false
        $this->setDefault(false, []);

        $this->ctrl()->exportOrg(99);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Organisation not found.', $_SESSION['flash_error'] ?? '');
    }

    // =========================================================================
    // defaults()
    // =========================================================================

    #[Test]
    public function testDefaultsRendersTemplate(): void
    {
        $this->setDefault(['settings_json' => '{}'], []);

        $this->ctrl()->defaults();

        $this->assertSame('superadmin/defaults', $this->response->renderedTemplate);
    }

    #[Test]
    public function testDefaultsPassesSettingsToView(): void
    {
        $this->setDefault(['settings_json' => '{}'], []);

        $this->ctrl()->defaults();

        $data = $this->response->renderedData;
        $this->assertArrayHasKey('settings', $data);
        $this->assertArrayHasKey('api_keys', $data);
    }

    // =========================================================================
    // saveDefaults()
    // =========================================================================

    #[Test]
    public function testSaveDefaultsRedirects(): void
    {
        // SystemSettings::save calls get() first (SELECT), then upsert
        $this->setDefault(['settings_json' => '{}'], []);

        $this->postCtrl([
            'ai_provider'   => 'google',
            'ai_model'      => 'gemini-3-flash-preview',
            'support_email' => 'support@test.invalid',
        ])->saveDefaults();

        $this->assertSame('/superadmin/defaults', $this->response->redirectedTo);
    }

    #[Test]
    public function testSaveDefaultsSetsFlash(): void
    {
        $this->setDefault(['settings_json' => '{}'], []);

        $this->postCtrl(['ai_provider' => 'openai'])->saveDefaults();

        $this->assertSame('App-wide defaults saved.', $_SESSION['flash_message'] ?? '');
    }

    // =========================================================================
    // testAiConnection()
    // =========================================================================

    #[Test]
    public function testAiConnectionMissingModelReturnsError(): void
    {
        $this->postCtrl(['provider' => 'google', 'model' => ''])->testAiConnection();

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertFalse($this->response->jsonPayload['success']);
    }

    #[Test]
    public function testAiConnectionUnknownProviderReturnsError(): void
    {
        $this->postCtrl(['provider' => 'unknown_provider', 'model' => 'some-model'])->testAiConnection();

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertFalse($this->response->jsonPayload['success']);
    }

    #[Test]
    public function testAiConnectionGoogleNoApiKey(): void
    {
        $_ENV['GEMINI_API_KEY'] = '';

        $this->postCtrl(['provider' => 'google', 'model' => 'gemini-3-flash-preview'])->testAiConnection();

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertFalse($this->response->jsonPayload['success']);

        unset($_ENV['GEMINI_API_KEY']);
    }

    #[Test]
    public function testAiConnectionOpenAiNoApiKey(): void
    {
        $_ENV['OPENAI_API_KEY'] = '';

        $this->postCtrl(['provider' => 'openai', 'model' => 'gpt-4'])->testAiConnection();

        $this->assertFalse($this->response->jsonPayload['success']);

        unset($_ENV['OPENAI_API_KEY']);
    }

    #[Test]
    public function testAiConnectionAnthropicNoApiKey(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = '';

        $this->postCtrl(['provider' => 'anthropic', 'model' => 'claude-3-opus'])->testAiConnection();

        $this->assertFalse($this->response->jsonPayload['success']);

        unset($_ENV['ANTHROPIC_API_KEY']);
    }

    // =========================================================================
    // personas()
    // =========================================================================

    #[Test]
    public function testPersonasRendersTemplate(): void
    {
        $panel = ['id' => 1, 'name' => 'Executive Panel', 'panel_type' => 'executive'];
        // PersonaPanel::findDefaults -> fetchAll returns [$panel]
        $panelStmt    = $this->makeStmt(false, [$panel]);
        // SystemSettings::get -> fetch returns settings row
        $settingsStmt = $this->makeStmt(['settings_json' => '{}'], []);
        // PersonaMember::findByPanelId for panel 1 -> fetchAll []
        $memberStmt   = $this->makeStmt(false, []);
        $this->queueStmts($panelStmt, $settingsStmt, $memberStmt);
        $this->setDefault(false, []);

        $this->ctrl()->personas();

        $this->assertSame('superadmin/personas', $this->response->renderedTemplate);
    }

    #[Test]
    public function testPersonasSeedsWhenNoPanelsExist(): void
    {
        // First PersonaPanel::findDefaults -> empty -> triggers seed -> many queries -> second findDefaults -> empty
        $emptyStmt = $this->makeStmt(false, []);
        $this->queueStmts($emptyStmt);
        $this->setDefault(false, []);

        $this->ctrl()->personas();

        $this->assertSame('superadmin/personas', $this->response->renderedTemplate);
    }

    // =========================================================================
    // savePersona()
    // =========================================================================

    #[Test]
    public function testSavePersonaRedirects(): void
    {
        $this->setDefault(['settings_json' => '{}'], []);

        $_POST = [
            'member_1'                       => 'Updated CEO prompt',
            'workflow_agile_product_manager'  => 'Updated workflow prompt',
            'level_devils_advocate'           => 'Updated level prompt',
        ];

        $req  = new FakeRequest('POST', '/', $_POST);
        $ctrl = new SuperadminController($req, $this->response, $this->auth, $this->db, $this->config);
        $ctrl->savePersona();

        $_POST = [];

        $this->assertSame('/superadmin/personas', $this->response->redirectedTo);
    }

    #[Test]
    public function testSavePersonaSetsFlash(): void
    {
        $this->setDefault(['settings_json' => '{}'], []);

        $_POST = [];
        $req  = new FakeRequest('POST', '/', []);
        $ctrl = new SuperadminController($req, $this->response, $this->auth, $this->db, $this->config);
        $ctrl->savePersona();

        $this->assertStringContainsString('updated', $_SESSION['flash_message'] ?? '');
    }

    // =========================================================================
    // evaluatePersona()
    // =========================================================================

    #[Test]
    public function testEvaluatePersonaInvalidJsonReturns400(): void
    {
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], 'not-json');
        $ctrl = new SuperadminController($req, $this->response, $this->auth, $this->db, $this->config);
        $ctrl->evaluatePersona();

        $this->assertSame(400, $this->response->jsonStatus);
        $this->assertSame('error', $this->response->jsonPayload['status']);
    }

    #[Test]
    public function testEvaluatePersonaEmptyContentReturns400(): void
    {
        $body = json_encode(['panel_id' => 1, 'evaluation_level' => 'devils_advocate', 'content' => '']);
        $req  = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], $body);
        $ctrl = new SuperadminController($req, $this->response, $this->auth, $this->db, $this->config);
        $ctrl->evaluatePersona();

        $this->assertSame(400, $this->response->jsonStatus);
    }

    #[Test]
    public function testEvaluatePersonaNoMembersReturns404(): void
    {
        // PersonaMember::findByPanelId returns []
        $this->setDefault(false, []);

        $body = json_encode(['panel_id' => 1, 'evaluation_level' => 'devils_advocate', 'content' => 'test content']);
        $req  = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], $body);
        $ctrl = new SuperadminController($req, $this->response, $this->auth, $this->db, $this->config);
        $ctrl->evaluatePersona();

        $this->assertSame(404, $this->response->jsonStatus);
    }

    // =========================================================================
    // assignSuperadmin()
    // =========================================================================

    #[Test]
    public function testAssignSuperadminNoUserIdRedirects(): void
    {
        $this->postCtrl(['user_id' => '0'])->assignSuperadmin();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Please select a user.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testAssignSuperadminUserNotFound(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl(['user_id' => '42'])->assignSuperadmin();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('User not found.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testAssignSuperadminSuccess(): void
    {
        $targetUser = ['id' => 42, 'role' => 'user', 'full_name' => 'Alice'];
        $this->setDefault($targetUser, []);

        $this->postCtrl(['user_id' => '42'])->assignSuperadmin();

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('Alice', $_SESSION['flash_message'] ?? '');
    }

    // =========================================================================
    // auditLogs()
    // =========================================================================

    #[Test]
    public function testAuditLogsRendersTemplate(): void
    {
        $this->setDefault(false, []);

        $this->ctrl()->auditLogs();

        $this->assertSame('superadmin/audit-logs', $this->response->renderedTemplate);
    }

    #[Test]
    public function testAuditLogsWithTypeFilter(): void
    {
        $this->setDefault(false, []);

        $req = $this->makeGetRequest(['type' => 'login_success']);
        $this->ctrl($req)->auditLogs();

        $this->assertSame('superadmin/audit-logs', $this->response->renderedTemplate);
        $this->assertSame('login_success', $this->response->renderedData['filter_type']);
    }

    #[Test]
    public function testAuditLogsPassesEventTypesToView(): void
    {
        $this->setDefault(false, []);

        $this->ctrl()->auditLogs();

        $this->assertArrayHasKey('event_types', $this->response->renderedData);
        $this->assertIsArray($this->response->renderedData['event_types']);
    }

    // =========================================================================
    // exportAuditLogs()
    // =========================================================================

    #[Test]
    public function testExportAuditLogsReturnsDownload(): void
    {
        $logRow = [
            'created_at'   => '2025-01-01 00:00:00',
            'event_type'   => 'login_success',
            'full_name'    => 'Alice',
            'email'        => 'alice@test.invalid',
            'ip_address'   => '127.0.0.1',
            'details_json' => '{}',
        ];
        $this->setDefault(false, [$logRow]);

        $this->ctrl()->exportAuditLogs();

        $this->assertNotNull($this->response->downloadContent);
        $this->assertStringContainsString('.csv', $this->response->downloadFilename ?? '');
    }

    #[Test]
    public function testExportAuditLogsWithDateFilter(): void
    {
        $this->setDefault(false, []);

        $req = $this->makeGetRequest([
            'type' => 'admin_action',
            'from' => '2025-01-01',
            'to'   => '2025-01-31',
        ]);
        $this->ctrl($req)->exportAuditLogs();

        $this->assertNotNull($this->response->downloadContent);
    }

    // =========================================================================
    // toggleEvaluationBoard()
    // =========================================================================

    #[Test]
    public function testToggleEvaluationBoardOrgNotFound(): void
    {
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'enable'])->toggleEvaluationBoard(99);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertSame('Organisation not found.', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testToggleEvaluationBoardEnable(): void
    {
        $org = ['id' => 1, 'name' => 'Acme'];
        $this->queueStmts($this->makeStmt($org, []));
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'enable'])->toggleEvaluationBoard(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('enabled', $_SESSION['flash_message'] ?? '');
    }

    #[Test]
    public function testToggleEvaluationBoardDisable(): void
    {
        $org = ['id' => 1, 'name' => 'Acme'];
        $this->queueStmts($this->makeStmt($org, []));
        $this->setDefault(false, []);

        $this->postCtrl(['action' => 'disable'])->toggleEvaluationBoard(1);

        $this->assertSame('/superadmin/organisations', $this->response->redirectedTo);
        $this->assertStringContainsString('disabled', $_SESSION['flash_message'] ?? '');
    }
}
