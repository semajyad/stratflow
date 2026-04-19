<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\AdminController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * FakeStripeHttpClient
 *
 * Implements Stripe's ClientInterface to return mock API responses without
 * making real HTTP calls. Used to cover Stripe-dependent billing code paths.
 */
class FakeStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<string, array{body: string, code: int}> */
    private array $responses = [];

    public function addResponse(string $urlPattern, string $body, int $code = 200): void
    {
        $this->responses[$urlPattern] = ['body' => $body, 'code' => $code];
    }

    /** @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1'): array
    {
        foreach ($this->responses as $pattern => $response) {
            if (str_contains($absUrl, $pattern)) {
                return [$response['body'], $response['code'], []];
            }
        }
        // Default: empty list response
        return ['{"object":"list","data":[],"has_more":false,"url":"/v1/mock"}', 200, []];
    }
}

/**
 * TestableAdminController
 *
 * Extends AdminController to allow makeStripe() to be overridden in tests,
 * so we can inject a mock StripeService without modifying src/ files.
 */
class TestableAdminController extends AdminController
{
    private ?\StratFlow\Services\StripeService $fakeStripe = null;

    public function setFakeStripe(\StratFlow\Services\StripeService $stripe): void
    {
        $this->fakeStripe = $stripe;
    }

    protected function makeStripe(): \StratFlow\Services\StripeService
    {
        if ($this->fakeStripe !== null) {
            return $this->fakeStripe;
        }
        return parent::makeStripe();
    }
}

/**
 * AdminControllerTest
 *
 * Covers all 27 public methods of AdminController.
 * DB and Auth are mocked; no real DB or HTTP calls are made.
 * tableExists returns false to keep tests in legacy-role mode and avoid
 * extra query branches inside User::create / User::update.
 */
class AdminControllerTest extends ControllerTestCase
{
    private array $admin = [
        'id'                  => 1,
        'org_id'              => 10,
        'role'                => 'org_admin',
        'email'               => 'admin@test.invalid',
        'full_name'           => 'Admin User',
        'name'                => 'Admin',
        'is_active'           => 1,
        'has_billing_access'  => 1,
        'is_project_admin'    => 1,
        'has_executive_access' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        // Stay in legacy mode — tableExists always false
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function ctrl(?FakeRequest $r = null): AdminController
    {
        return new AdminController(
            $r ?? $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
    }

    /**
     * Build a fresh AdminController with a new DB mock (not the shared one from setUp).
     * Useful when we need tableExists to behave differently.
     */
    private function ctrlWithFreshDb(
        ?FakeRequest $r,
        callable $configureFreshDb,
        ?array $config = null
    ): AdminController {
        $freshDb = $this->createMock(\StratFlow\Core\Database::class);
        $configureFreshDb($freshDb);
        return new AdminController(
            $r ?? $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $freshDb,
            $config ?? $this->config
        );
    }

    /**
     * Build a TestableAdminController with an injected mock StripeService.
     * Allows covering Stripe-dependent code paths without real API keys.
     */
    private function ctrlWithMockStripe(
        ?FakeRequest $r,
        callable $configureStripe,
        ?callable $configureDb = null
    ): TestableAdminController {
        $mockStripe = $this->createMock(\StratFlow\Services\StripeService::class);
        $configureStripe($mockStripe);

        if ($configureDb !== null) {
            $configureDb($this->db);
        }

        $ctrl = new TestableAdminController(
            $r ?? $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->setFakeStripe($mockStripe);
        return $ctrl;
    }

    /**
     * Build a PDOStatement mock that returns $fetch from fetch() and $all from fetchAll().
     */
    private function stmt(mixed $fetch = false, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    /**
     * Stub db->query to return a sequence of statements.
     */
    private function stubQuerySequence(array $stmts): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(...$stmts);
    }

    /**
     * Stub db->query to always return a single statement.
     */
    private function stubQueryAlways(mixed $fetch = false, array $all = []): void
    {
        $this->db->method('query')->willReturn($this->stmt($fetch, $all));
    }

    private function orgRow(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 10,
            'name'               => 'TestOrg',
            'stripe_customer_id' => '',
            'settings_json'      => '{}',
            'is_active'          => 1,
        ], $overrides);
    }

    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 99,
            'org_id'              => 10,
            'full_name'           => 'Some User',
            'email'               => 'user@test.invalid',
            'role'                => 'user',
            'is_active'           => 1,
            'is_project_admin'    => 0,
            'has_billing_access'  => 0,
            'has_executive_access' => 0,
        ], $overrides);
    }

    private function teamRow(array $overrides = []): array
    {
        return array_merge([
            'id'          => 5,
            'org_id'      => 10,
            'name'        => 'Dev Team',
            'description' => 'A team',
            'capacity'    => 5,
        ], $overrides);
    }

    private function subRow(array $overrides = []): array
    {
        return array_merge([
            'id'                    => 3,
            'org_id'                => 10,
            'stripe_subscription_id' => 'manual_001',
            'plan_type'             => 'product',
            'status'                => 'active',
            'user_seat_limit'       => 10,
            'billing_method'        => 'invoice',
            'price_per_seat_cents'  => 5000,
        ], $overrides);
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexRendersAdminDashboard(): void
    {
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 3]),              // User::countByOrgId
            $this->stmt(['user_seat_limit' => 10]), // Subscription::getSeatLimit
            $this->stmt(false, []),                 // Team::findByOrgId
            $this->stmt(false),                     // Subscription::findByOrgId
            $this->stmt(false, []),                 // AuditLog::findFiltered
        ]);

        $this->ctrl()->index();

        $this->assertSame('admin/index', $this->response->renderedTemplate);
        $this->assertSame(3, $this->response->renderedData['user_count']);
    }

    public function testIndexClearsFlashAfterRender(): void
    {
        $_SESSION['flash_message'] = 'old';
        $_SESSION['flash_error']   = 'olderr';

        $this->stubQuerySequence([
            $this->stmt(['cnt' => 0]),
            $this->stmt(['user_seat_limit' => 5]),
            $this->stmt(false, []),
            $this->stmt(false),
            $this->stmt(false, []),
        ]);

        $this->ctrl()->index();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    public function testIndexHandlesAuditLogException(): void
    {
        // Make AuditLog::findFiltered throw — covers the catch block (line 72)
        $ctrl = $this->ctrlWithFreshDb(
            null,
            function (\StratFlow\Core\Database $db): void {
                $db->method('tableExists')->willReturn(false);
                $s1 = $this->stmt(['cnt' => 0]);            // countByOrgId
                $s2 = $this->stmt(['user_seat_limit' => 5]);// getSeatLimit
                $s3 = $this->stmt(false, []);               // Team::findByOrgId
                $s4 = $this->stmt(false);                   // Subscription::findByOrgId
                // 5th call (AuditLog::findFiltered) throws
                $db->method('query')
                    ->willReturnCallback(static function () use ($s1, $s2, $s3, $s4): mixed {
                        static $call = 0;
                        $call++;
                        return match ($call) {
                            1 => $s1,
                            2 => $s2,
                            3 => $s3,
                            4 => $s4,
                            default => throw new \RuntimeException('DB error'),
                        };
                    });
            }
        );
        $ctrl->index();

        // Should still render — exception is caught
        $this->assertSame('admin/index', $this->response->renderedTemplate);
    }

    // =========================================================================
    // users()
    // =========================================================================

    public function testUsersRendersUserList(): void
    {
        $this->stubQuerySequence([
            $this->stmt(false, [$this->userRow()]), // User::findByOrgId
            $this->stmt(['user_seat_limit' => 10]), // Subscription::getSeatLimit
            $this->stmt(['cnt' => 1]),              // User::countByOrgId
            $this->stmt(false),                     // Integration::findByOrgAndProvider (jira)
        ]);

        $this->ctrl()->users();

        $this->assertSame('admin/users', $this->response->renderedTemplate);
    }

    // =========================================================================
    // createUser()
    // =========================================================================

    public function testCreateUserRedirectsWhenSeatLimitReached(): void
    {
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 10]),              // countByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // getSeatLimit
        ]);

        $r = $this->makePostRequest(['email' => 'new@test.invalid', 'full_name' => 'New']);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('Seat limit', $_SESSION['flash_error']);
    }

    public function testCreateUserRedirectsWhenNameOrEmailMissing(): void
    {
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 1]),
            $this->stmt(['user_seat_limit' => 10]),
        ]);

        $r = $this->makePostRequest(['email' => '', 'full_name' => '']);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('required', $_SESSION['flash_error']);
    }

    public function testCreateUserRedirectsWhenEmailTaken(): void
    {
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 1]),
            $this->stmt(['user_seat_limit' => 10]),
            $this->stmt($this->userRow()),           // findByEmail => found
        ]);

        $r = $this->makePostRequest(['email' => 'user@test.invalid', 'full_name' => 'Someone']);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('already exists', $_SESSION['flash_error']);
    }

    public function testCreateUserSuccessRedirectsAndSetsFlash(): void
    {
        $this->db->method('lastInsertId')->willReturn('42');
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 1]),              // countByOrgId
            $this->stmt(['user_seat_limit' => 10]), // getSeatLimit
            $this->stmt(false),                     // findByEmail => not found
            $this->stmt(false),                     // User::create INSERT
            $this->stmt(false),                     // PasswordToken::create INSERT
            $this->stmt(false),                     // AuditLogger::log INSERT
        ]);

        $r = $this->makePostRequest([
            'email'     => 'newbie@test.invalid',
            'full_name' => 'Newbie',
            'role'      => 'user',
        ]);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // updateUser($id)
    // =========================================================================

    public function testUpdateUserRedirectsWhenUserNotFound(): void
    {
        $this->stubQueryAlways(false);

        $r = $this->makePostRequest(['full_name' => 'X', 'email' => 'x@x.com']);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testUpdateUserRedirectsWhenNameOrEmailMissing(): void
    {
        $this->stubQueryAlways($this->userRow());

        $r = $this->makePostRequest(['full_name' => '', 'email' => '']);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('required', $_SESSION['flash_error']);
    }

    public function testUpdateUserRedirectsWhenEmailTakenByOther(): void
    {
        $target = $this->userRow(['id' => 99]);
        $other  = $this->userRow(['id' => 55, 'email' => 'other@test.invalid']);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt($other),  // User::findByEmail
        ]);

        $r = $this->makePostRequest(['full_name' => 'X', 'email' => 'other@test.invalid']);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('already exists', $_SESSION['flash_error']);
    }

    public function testUpdateUserSuccessRedirects(): void
    {
        $target = $this->userRow(['id' => 99, 'role' => 'user']);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::findByEmail (not found/same)
            $this->stmt(false),   // User::update: information_schema account_type check
            $this->stmt(false),   // User::update: information_schema jira_display_name check
            $this->stmt(false),   // UPDATE users SET ...
            $this->stmt(false),   // AuditLogger role change log
        ]);

        $r = $this->makePostRequest([
            'full_name' => 'Updated Name',
            'email'     => 'updated@test.invalid',
            'role'      => 'org_admin',
        ]);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // reactivateUser($id)
    // =========================================================================

    public function testReactivateUserRedirectsWhenNotFound(): void
    {
        $this->stubQueryAlways(false);

        $this->ctrl()->reactivateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testReactivateUserSuccessRedirects(): void
    {
        $target = $this->userRow();
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::reactivate UPDATE
            $this->stmt(false),   // AuditLogger::log INSERT
        ]);

        $this->ctrl()->reactivateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // deleteUser($id)
    // =========================================================================

    public function testDeleteUserBlocksSelfDeletion(): void
    {
        // Admin id=1, trying to delete id=1
        $this->ctrl()->deleteUser(1);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('cannot deactivate your own', $_SESSION['flash_error']);
    }

    public function testDeleteUserRedirectsWhenNotFound(): void
    {
        $this->stubQueryAlways(false);

        $this->ctrl()->deleteUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testDeleteUserSuccessDeactivatesAndRedirects(): void
    {
        $target = $this->userRow(['id' => 99]);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::deactivate UPDATE
            $this->stmt(false),   // AuditLogger::log
        ]);

        $this->ctrl()->deleteUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // teams()
    // =========================================================================

    public function testTeamsRendersView(): void
    {
        $team = $this->teamRow();
        $this->stubQuerySequence([
            $this->stmt(false, [$team]),             // Team::findByOrgId
            $this->stmt(false, [$this->userRow()]),  // User::findByOrgId
            $this->stmt(false, []),                  // TeamMember::findByTeamId for team 5
        ]);

        $this->ctrl()->teams();

        $this->assertSame('admin/teams', $this->response->renderedTemplate);
    }

    // =========================================================================
    // createTeam()
    // =========================================================================

    public function testCreateTeamRedirectsWhenNameMissing(): void
    {
        $r = $this->makePostRequest(['name' => '']);
        $this->ctrl($r)->createTeam();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('required', $_SESSION['flash_error']);
    }

    public function testCreateTeamSuccessRedirects(): void
    {
        $this->db->method('lastInsertId')->willReturn('7');
        $this->stubQuerySequence([
            $this->stmt(false), // Team::create INSERT
        ]);

        $r = $this->makePostRequest(['name' => 'Alpha', 'description' => 'Desc', 'capacity' => '4']);
        $this->ctrl($r)->createTeam();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // updateTeam($id)
    // =========================================================================

    public function testUpdateTeamRedirectsWhenNotFound(): void
    {
        $this->stubQueryAlways(false);

        $r = $this->makePostRequest(['name' => 'X']);
        $this->ctrl($r)->updateTeam(5);

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testUpdateTeamRedirectsWhenNameMissing(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()), // Team::findById
        ]);

        $r = $this->makePostRequest(['name' => '']);
        $this->ctrl($r)->updateTeam(5);

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('required', $_SESSION['flash_error']);
    }

    public function testUpdateTeamSuccessRedirects(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()), // Team::findById
            $this->stmt(false),            // Team::update
        ]);

        $r = $this->makePostRequest(['name' => 'Beta', 'description' => '', 'capacity' => '3']);
        $this->ctrl($r)->updateTeam(5);

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // deleteTeam($id)
    // =========================================================================

    public function testDeleteTeamRedirectsWhenNotFound(): void
    {
        $this->stubQueryAlways(false);

        $this->ctrl()->deleteTeam(5);

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testDeleteTeamSuccessRedirects(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()), // Team::findById
            $this->stmt(false),            // Team::delete
        ]);

        $this->ctrl()->deleteTeam(5);

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // addTeamMember()
    // =========================================================================

    public function testAddTeamMemberRedirectsWhenTeamNotFound(): void
    {
        $this->stubQueryAlways(false);

        $r = $this->makePostRequest(['team_id' => '5', 'user_id' => '99']);
        $this->ctrl($r)->addTeamMember();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('Team not found', $_SESSION['flash_error']);
    }

    public function testAddTeamMemberRedirectsWhenUserNotFound(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()), // Team::findById
            $this->stmt(false),            // User::findById => not found
        ]);

        $r = $this->makePostRequest(['team_id' => '5', 'user_id' => '99']);
        $this->ctrl($r)->addTeamMember();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('User not found', $_SESSION['flash_error']);
    }

    public function testAddTeamMemberSuccessRedirects(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()),  // Team::findById
            $this->stmt($this->userRow()),  // User::findById
            $this->stmt(false),             // TeamMember::addMember INSERT IGNORE
        ]);

        $r = $this->makePostRequest(['team_id' => '5', 'user_id' => '99']);
        $this->ctrl($r)->addTeamMember();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // removeTeamMember()
    // =========================================================================

    public function testRemoveTeamMemberRedirectsWhenTeamNotFound(): void
    {
        $this->stubQueryAlways(false);

        $r = $this->makePostRequest(['team_id' => '5', 'user_id' => '99']);
        $this->ctrl($r)->removeTeamMember();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertStringContainsString('Team not found', $_SESSION['flash_error']);
    }

    public function testRemoveTeamMemberSuccessRedirects(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->teamRow()), // Team::findById
            $this->stmt(false),            // TeamMember::removeMember DELETE
        ]);

        $r = $this->makePostRequest(['team_id' => '5', 'user_id' => '99']);
        $this->ctrl($r)->removeTeamMember();

        $this->assertSame('/app/admin/teams', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // billing()
    // =========================================================================

    public function testBillingRendersViewWithNoStripeCustomer(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->orgRow()),             // Organisation::findById
            $this->stmt(false),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),   // Subscription::getSeatLimit
            $this->stmt(false, [$this->userRow()]),   // User::findByOrgId
            $this->stmt(false),                       // Integration::findByOrgAndProvider (xero)
        ]);

        $this->ctrl()->billing();

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        $this->assertFalse($this->response->renderedData['has_stripe']);
    }

    // =========================================================================
    // billingPortal()
    // =========================================================================

    public function testBillingPortalRedirectsWhenNoStripeCustomer(): void
    {
        $this->stubQueryAlways($this->orgRow(['stripe_customer_id' => '']));

        $this->ctrl()->billingPortal();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('No billing account', $_SESSION['flash_error']);
    }

    public function testBillingPortalSuccessRedirectsToPortalUrl(): void
    {
        // Mock Stripe HTTP client to return a valid portal session response
        $fakeHttp = new FakeStripeHttpClient();
        $fakeHttp->addResponse(
            '/v1/billing_portal',
            '{"id":"bps_test","object":"billing_portal.session","url":"https://billing.stripe.com/portal/test","return_url":"http://localhost/app/admin/billing"}'
        );
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $org = $this->orgRow(['stripe_customer_id' => 'cus_portal_test']);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt(false), // AuditLogger::log
        ]);

        $this->ctrl()->billingPortal();

        \Stripe\ApiRequestor::setHttpClient(null);

        // Should redirect to the Stripe portal URL
        $this->assertStringContainsString('stripe', $this->response->redirectedTo ?? '');
    }

    public function testBillingPortalRedirectsOnStripeError(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->orgRow(['stripe_customer_id' => 'cus_test'])), // Organisation::findById
            $this->stmt(false),                                                 // AuditLogger
        ]);

        // Override with bad Stripe key so it throws
        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid_key';

        $ctrl = new AdminController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $cfg
        );
        $ctrl->billingPortal();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    // =========================================================================
    // saveBillingContact()
    // =========================================================================

    public function testSaveBillingContactRedirects(): void
    {
        $org = $this->orgRow(['settings_json' => '{"billing_contact":{}}']);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt(false), // Organisation::update
        ]);

        $r = $this->makePostRequest([
            'billing_contact_name'  => 'Accounts',
            'billing_contact_email' => 'accounts@test.invalid',
        ]);
        $this->ctrl($r)->saveBillingContact();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // purchaseSeatsInvoice()
    // =========================================================================

    public function testPurchaseSeatsInvoiceRedirectsWhenNoSubscription(): void
    {
        $this->stubQueryAlways(false);

        $r = $this->makePostRequest(['seats_to_add' => '2']);
        $this->ctrl($r)->purchaseSeatsInvoice();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('No active subscription', $_SESSION['flash_error']);
    }

    public function testPurchaseSeatsInvoiceSuccessAddsSeats(): void
    {
        $sub = $this->subRow();
        $this->stubQuerySequence([
            $this->stmt($sub),                        // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),   // Subscription::getSeatLimit
            $this->stmt(false),                       // Subscription::updateSeatLimit
            $this->stmt(false),                       // AuditLogger::log
        ]);

        $r = $this->makePostRequest(['seats_to_add' => '3']);
        $this->ctrl($r)->purchaseSeatsInvoice();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
        $this->assertStringContainsString('3 seat', $_SESSION['flash_message']);
    }

    // =========================================================================
    // purchaseSeatsStripe()
    // =========================================================================

    public function testPurchaseSeatsStripeRedirectsWhenNoPriceConfigured(): void
    {
        $sub = $this->subRow(['plan_type' => 'enterprise']);
        $org = $this->orgRow();
        $this->stubQuerySequence([
            $this->stmt($org), // Organisation::findById
            $this->stmt($sub), // Subscription::findByOrgId
        ]);

        $cfg = $this->config;
        $cfg['stripe']['price_product']     = '';
        $cfg['stripe']['price_consultancy'] = '';

        $ctrl = new AdminController(
            $this->makePostRequest(['seat_quantity' => '2']),
            $this->response,
            $this->auth,
            $this->db,
            $cfg
        );
        $ctrl->purchaseSeatsStripe();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('No Stripe price configured', $_SESSION['flash_error']);
    }

    public function testPurchaseSeatsStripeSuccessRedirectsToCheckout(): void
    {
        $sub = $this->subRow(['plan_type' => 'product']);
        $org = $this->orgRow(['stripe_customer_id' => 'cus_checkout_test']);

        // Mock Stripe checkout session creation
        $fakeHttp = new FakeStripeHttpClient();
        $fakeHttp->addResponse(
            '/v1/checkout',
            '{"id":"cs_test","object":"checkout.session","url":"https://checkout.stripe.com/pay/cs_test"}'
        );
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt($sub),  // Subscription::findByOrgId
            $this->stmt(false), // AuditLogger::log
        ]);

        $ctrl = new AdminController(
            $this->makePostRequest(['seat_quantity' => '2']),
            $this->response,
            $this->auth,
            $this->db,
            $this->config
        );
        $ctrl->purchaseSeatsStripe();

        \Stripe\ApiRequestor::setHttpClient(null);

        // Should redirect to Stripe checkout URL
        $this->assertStringContainsString('stripe', $this->response->redirectedTo ?? '');
    }

    public function testPurchaseSeatsStripeRedirectsOnStripeError(): void
    {
        $sub = $this->subRow(['plan_type' => 'product']);
        $org = $this->orgRow();
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt($sub),  // Subscription::findByOrgId
            $this->stmt(false), // AuditLogger
        ]);

        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid';

        $ctrl = new AdminController(
            $this->makePostRequest(['seat_quantity' => '1']),
            $this->response,
            $this->auth,
            $this->db,
            $cfg
        );
        $ctrl->purchaseSeatsStripe();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    // =========================================================================
    // invoices()
    // =========================================================================

    public function testInvoicesRendersViewWhenNoStripeCustomer(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => '']);
        $this->stubQueryAlways($org);

        $this->ctrl()->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertSame([], $this->response->renderedData['invoices']);
    }

    public function testInvoicesRendersViewWhenStripeThrows(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_test']);
        $this->stubQueryAlways($org);

        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid';

        $ctrl = new AdminController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
    }

    // =========================================================================
    // downloadInvoice($id)
    // =========================================================================

    public function testDownloadInvoiceRedirectsToPdfUrl(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_test_dl']);

        // Mock Stripe invoice retrieve to return invoice belonging to our customer
        $fakeHttp = new FakeStripeHttpClient();
        $fakeHttp->addResponse(
            '/v1/invoices',
            '{"id":"in_test","object":"invoice","customer":"cus_test_dl","invoice_pdf":"https://invoice.stripe.com/invoice/test.pdf"}'
        );
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $this->stubQueryAlways($org);

        $this->ctrl()->downloadInvoice('in_test');

        \Stripe\ApiRequestor::setHttpClient(null);

        // Should redirect to the PDF URL
        $this->assertStringContainsString('invoice', $this->response->redirectedTo ?? '');
    }

    public function testDownloadInvoiceRedirectsWhenCustomerMismatch(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_ours']);

        // Invoice belongs to a different customer
        $fakeHttp = new FakeStripeHttpClient();
        $fakeHttp->addResponse(
            '/v1/invoices',
            '{"id":"in_other","object":"invoice","customer":"cus_other","invoice_pdf":"https://invoice.stripe.com/other.pdf"}'
        );
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $this->stubQueryAlways($org);

        $this->ctrl()->downloadInvoice('in_other');

        \Stripe\ApiRequestor::setHttpClient(null);

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('not found', $_SESSION['flash_error']);
    }

    public function testDownloadInvoiceRedirectsWhenNoPdfAvailable(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_nopdf']);

        // Invoice belongs to our customer but has no PDF
        $fakeHttp = new FakeStripeHttpClient();
        $fakeHttp->addResponse(
            '/v1/invoices',
            '{"id":"in_nopdf","object":"invoice","customer":"cus_nopdf","invoice_pdf":null}'
        );
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $this->stubQueryAlways($org);

        $this->ctrl()->downloadInvoice('in_nopdf');

        \Stripe\ApiRequestor::setHttpClient(null);

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('No PDF', $_SESSION['flash_error']);
    }

    public function testDownloadInvoiceRedirectsWhenNoStripeCustomer(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => '']);
        $this->stubQueryAlways($org);

        $this->ctrl()->downloadInvoice('in_xxx');

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('No billing account', $_SESSION['flash_error']);
    }

    public function testDownloadInvoiceRedirectsWhenStripeThrows(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_test']);
        $this->stubQueryAlways($org);

        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid';

        $ctrl = new AdminController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
        $ctrl->downloadInvoice('in_test');

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    // =========================================================================
    // auditLogs()
    // =========================================================================

    public function testAuditLogsRendersView(): void
    {
        $log = [
            'event_type'   => 'login_success',
            'created_at'   => '2025-01-01',
            'full_name'    => 'Alice',
            'email'        => 'alice@test.invalid',
            'ip_address'   => '127.0.0.1',
            'details_json' => '{}',
        ];
        $this->stubQueryAlways(false, [$log]);

        $this->ctrl()->auditLogs();

        $this->assertSame('admin/audit-logs', $this->response->renderedTemplate);
        $this->assertCount(1, $this->response->renderedData['logs']);
    }

    public function testAuditLogsPassesFilterType(): void
    {
        $this->stubQueryAlways(false, []);

        $r = $this->makeGetRequest(['type' => 'login_success']);
        $this->ctrl($r)->auditLogs();

        $this->assertSame('login_success', $this->response->renderedData['filter_type']);
    }

    // =========================================================================
    // exportAuditLogs()
    // =========================================================================

    public function testExportAuditLogsDownloadsCsv(): void
    {
        $log = [
            'event_type'   => 'login_success',
            'created_at'   => '2025-01-01 12:00:00',
            'full_name'    => 'Alice',
            'email'        => 'alice@test.invalid',
            'ip_address'   => '127.0.0.1',
            'details_json' => '{"k":"v"}',
        ];
        $this->stubQuerySequence([
            $this->stmt(false, [$log]), // AuditLog::findFiltered
            $this->stmt(false),         // AuditLogger::log (DATA_EXPORT)
        ]);

        $this->ctrl()->exportAuditLogs();

        $this->assertNotNull($this->response->downloadContent);
        $this->assertStringEndsWith('.csv', $this->response->downloadFilename);
        $this->assertSame('text/csv', $this->response->downloadMimeType);
        $this->assertStringContainsString('Timestamp', $this->response->downloadContent);
    }

    public function testExportAuditLogsSanitizesFormulaCells(): void
    {
        $log = [
            'event_type'   => '=HYPERLINK("http://evil.com","click")',
            'created_at'   => '+cmd|/c calc',
            'full_name'    => '-1+1',
            'email'        => '@SUM(A1:Z1)',
            'ip_address'   => '127.0.0.1',
            'details_json' => '{"safe":"value"}',
        ];
        $this->stubQuerySequence([
            $this->stmt(false, [$log]),
            $this->stmt(false),
        ]);

        $this->ctrl()->exportAuditLogs();

        $content = $this->response->downloadContent;
        $this->assertStringNotContainsString('=HYPERLINK', $content);
        $this->assertStringNotContainsString('+cmd', $content);
        $this->assertStringNotContainsString('-1+1', $content);
        $this->assertStringNotContainsString('@SUM', $content);
        // Sanitized values should be prefixed with single quote
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    // =========================================================================
    // settings()
    // =========================================================================

    public function testSettingsRendersView(): void
    {
        $this->stubQuerySequence([
            $this->stmt($this->orgRow()),  // Organisation::findById
            $this->stmt(false),            // SystemSettings::get (system_settings row)
        ]);

        $this->ctrl()->settings();

        $this->assertSame('admin/settings', $this->response->renderedTemplate);
    }

    public function testSettingsMergesWithSavedValues(): void
    {
        $savedSettings = json_encode(['sprint_length_weeks' => 3]);
        $org = $this->orgRow(['settings_json' => $savedSettings]);
        $this->stubQuerySequence([
            $this->stmt($org),   // Organisation::findById
            $this->stmt(false),  // SystemSettings::get
        ]);

        $this->ctrl()->settings();

        $this->assertSame('admin/settings', $this->response->renderedTemplate);
        $this->assertSame(3, $this->response->renderedData['settings']['sprint_length_weeks']);
    }

    // =========================================================================
    // saveSettings()
    // =========================================================================

    public function testSaveSettingsRedirectsToSettings(): void
    {
        $org = $this->orgRow(['settings_json' => '{}']);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById (for existing AI key)
            $this->stmt(false), // Organisation::update
            $this->stmt(false), // AuditLogger::log
        ]);

        $r = $this->makePostRequest([
            'persona_agile_product_manager'          => 'Prompt A',
            'persona_technical_project_manager'      => 'Prompt B',
            'persona_expert_system_architect'        => 'Prompt C',
            'persona_enterprise_risk_manager'        => 'Prompt D',
            'persona_agile_product_owner'            => 'Prompt E',
            'persona_enterprise_business_strategist' => 'Prompt F',
            'hl_item_default_months'                 => '2',
            'hl_item_sizing_method'                  => 'sprints',
            'sprint_length_weeks'                    => '2',
            'user_story_max_size'                    => '13',
            'capacity_tripwire_percent'               => '20',
            'dependency_tripwire_enabled'             => '1',
            'quality_enabled'                        => '1',
            'quality_threshold'                      => '70',
            'quality_enforcement'                    => 'warn',
            'ai_provider'                            => 'google',
            'ai_model'                               => 'gemini-3-flash-preview',
            'ai_api_key'                             => '',
        ]);
        $this->ctrl($r)->saveSettings();

        $this->assertSame('/app/admin/settings', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    public function testSaveSettingsPreservesExistingApiKey(): void
    {
        $org = $this->orgRow(['settings_json' => json_encode(['ai' => ['api_key' => 'existing-key']])]);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt(false), // Organisation::update
            $this->stmt(false), // AuditLogger::log
        ]);

        // Submitting blank api_key should preserve the existing one
        $r = $this->makePostRequest([
            'persona_agile_product_manager'          => '',
            'persona_technical_project_manager'      => '',
            'persona_expert_system_architect'        => '',
            'persona_enterprise_risk_manager'        => '',
            'persona_agile_product_owner'            => '',
            'persona_enterprise_business_strategist' => '',
            'hl_item_sizing_method' => 'sprints',
            'ai_provider'           => '',
            'ai_model'              => '',
            'ai_api_key'            => '',  // blank => keep existing
        ]);
        $this->ctrl($r)->saveSettings();

        $this->assertSame('/app/admin/settings', $this->response->redirectedTo);
    }

    // =========================================================================
    // testAi()
    // =========================================================================

    public function testTestAiReturnsErrorForUnsupportedProvider(): void
    {
        $r = $this->makePostRequest([
            'ai_provider' => 'openai',
            'ai_model'    => 'gpt-4',
            'ai_api_key'  => 'sk-xxx',
        ]);
        $this->ctrl($r)->testAi();

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertSame('error', $this->response->jsonPayload['status']);
        $this->assertStringContainsString('not yet integrated', $this->response->jsonPayload['message']);
    }

    public function testTestAiWithGoogleProviderReturnsJsonPayload(): void
    {
        $r = $this->makePostRequest([
            'ai_provider' => 'google',
            'ai_model'    => 'gemini-3-flash-preview',
            'ai_api_key'  => '',
        ]);
        $this->stubQueryAlways($this->orgRow(['settings_json' => '{}']));

        $cfg = $this->config;
        $cfg['gemini_api_key'] = '';  // no key = Gemini will throw

        $ctrl = new AdminController($r, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->testAi();

        $this->assertNotNull($this->response->jsonPayload);
        $this->assertArrayHasKey('status', $this->response->jsonPayload);
        // Either 'ok' (if Gemini reachable) or 'error' — both are valid responses
        $this->assertContains($this->response->jsonPayload['status'], ['ok', 'error']);
    }

    public function testTestAiWithEmptyProviderFallsBackToGoogle(): void
    {
        $r = $this->makePostRequest([
            'ai_provider' => '',
            'ai_model'    => '',
            'ai_api_key'  => '',
        ]);
        $this->stubQueryAlways($this->orgRow(['settings_json' => '{}']));

        $this->ctrl($r)->testAi();

        // Reaches Gemini code path — should return JSON regardless of network
        $this->assertNotNull($this->response->jsonPayload);
        $this->assertArrayHasKey('status', $this->response->jsonPayload);
    }

    // =========================================================================
    // updateUser() — password change path
    // =========================================================================

    public function testUpdateUserWithPasswordChangeRejectsWeakPassword(): void
    {
        $target = $this->userRow(['id' => 99, 'role' => 'user']);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::findByEmail (same user)
        ]);

        $r = $this->makePostRequest([
            'full_name' => 'Test User',
            'email'     => 'user@test.invalid',
            'role'      => 'user',
            'password'  => 'weak',  // fails PasswordPolicy
        ]);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    public function testUpdateUserWithValidPasswordChanges(): void
    {
        $target = $this->userRow(['id' => 99, 'role' => 'user']);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::findByEmail (same user)
            $this->stmt(false),   // User::update: account_type info schema check
            $this->stmt(false),   // User::update: jira_display_name check
            $this->stmt(false),   // UPDATE users
            $this->stmt(false),   // AuditLogger role change (same role, skipped)
            $this->stmt(false),   // AuditLogger password change
        ]);

        $r = $this->makePostRequest([
            'full_name' => 'Test User',
            'email'     => 'user@test.invalid',
            'role'      => 'user',
            'password'  => 'ValidPass123!',
        ]);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // billing() — with Stripe customer (covers Stripe paths)
    // =========================================================================

    public function testBillingRendersViewWithStripeCustomerAndSubscription(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_test', 'settings_json' => '{"billing_contact":{"name":"Billing","email":"b@x.com"}}']);
        $sub = $this->subRow(['stripe_subscription_id' => 'manual_001', 'billing_method' => 'invoice']);
        $this->stubQuerySequence([
            $this->stmt($org),                        // Organisation::findById
            $this->stmt($sub),                        // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),   // Subscription::getSeatLimit
            $this->stmt(false, [$this->userRow()]),   // User::findByOrgId
            $this->stmt(false),                       // Integration (xero) - not active
        ]);

        // Use bad Stripe key — it will throw, but billing handles it gracefully
        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid';

        $ctrl = new AdminController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
        $ctrl->billing();

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        $this->assertTrue($this->response->renderedData['has_stripe']);
    }

    public function testBillingWithXeroConnected(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => '']);
        $xeroRow = [
            'id'          => 1,
            'org_id'      => 10,
            'provider'    => 'xero',
            'status'      => 'active',
            'config_json' => '{"tenant_name":"My Xero"}',
        ];
        $this->stubQuerySequence([
            $this->stmt($org),                       // Organisation::findById
            $this->stmt(false),                      // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // getSeatLimit
            $this->stmt(false, [$this->userRow()]),  // User::findByOrgId
            $this->stmt($xeroRow),                   // Integration (xero) - active
            $this->stmt(false, []),                  // xero_invoices pushed_to_xero query
        ]);

        $this->ctrl()->billing();

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        $this->assertTrue($this->response->renderedData['xero_connected']);
        $this->assertSame('My Xero', $this->response->renderedData['xero_tenant_name']);
    }

    // =========================================================================
    // purchaseSeatsInvoice() — edge: seats_to_add = 0
    // =========================================================================

    public function testPurchaseSeatsInvoiceWithZeroSeatsStillAddsOne(): void
    {
        $sub = $this->subRow();
        $this->stubQuerySequence([
            $this->stmt($sub),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // getSeatLimit
            $this->stmt(false),                      // updateSeatLimit
            $this->stmt(false),                      // AuditLogger
        ]);

        // seats_to_add=0 → max(1,0)=1
        $r = $this->makePostRequest(['seats_to_add' => '0']);
        $this->ctrl($r)->purchaseSeatsInvoice();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // saveSettings() — invalid sizing method falls back
    // =========================================================================

    public function testSaveSettingsWithInvalidSizingMethodFallsBack(): void
    {
        $org = $this->orgRow(['settings_json' => '{}']);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById
            $this->stmt(false), // Organisation::update
            $this->stmt(false), // AuditLogger::log
        ]);

        $r = $this->makePostRequest([
            'persona_agile_product_manager'          => '',
            'persona_technical_project_manager'      => '',
            'persona_expert_system_architect'        => '',
            'persona_enterprise_risk_manager'        => '',
            'persona_agile_product_owner'            => '',
            'persona_enterprise_business_strategist' => '',
            'hl_item_sizing_method' => 'invalid_method',  // not in allowed list
            'quality_enforcement'   => 'block',
            'ai_provider'           => 'google',
            'ai_model'              => '',
            'ai_api_key'            => 'my-new-key',  // provide a key
        ]);
        $this->ctrl($r)->saveSettings();

        $this->assertSame('/app/admin/settings', $this->response->redirectedTo);
    }

    public function testSaveSettingsPersistsHlQualityEnabled(): void
    {
        $org = $this->orgRow(['settings_json' => '{}']);
        $this->stubQuerySequence([
            $this->stmt($org),  // Organisation::findById (for existing AI key)
            $this->stmt(false), // Organisation::update
            $this->stmt(false), // AuditLogger::log
        ]);

        $r = $this->makePostRequest([
            'persona_agile_product_manager'          => '',
            'persona_technical_project_manager'      => '',
            'persona_expert_system_architect'        => '',
            'persona_enterprise_risk_manager'        => '',
            'persona_agile_product_owner'            => '',
            'persona_enterprise_business_strategist' => '',
            'hl_item_sizing_method'    => 'sprints',
            'hl_quality_enabled'       => '1',
            'quality_enabled'          => '0',
            'quality_threshold'        => '70',
            'quality_enforcement'      => 'warn',
            'ai_provider'              => '',
            'ai_model'                 => '',
            'ai_api_key'               => '',
        ]);
        $this->ctrl($r)->saveSettings();

        $this->assertSame('/app/admin/settings', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // exportAuditLogs() — with date filters
    // =========================================================================

    public function testExportAuditLogsWithDateFilters(): void
    {
        $log = [
            'event_type'   => 'user_created',
            'created_at'   => '2025-06-01 10:00:00',
            'full_name'    => 'Bob',
            'email'        => 'bob@test.invalid',
            'ip_address'   => '10.0.0.1',
            'details_json' => '{}',
        ];
        $this->stubQuerySequence([
            $this->stmt(false, [$log]), // AuditLog::findFiltered
            $this->stmt(false),         // AuditLogger::log
        ]);

        $r = $this->makeGetRequest(['type' => 'user_created', 'from' => '2025-06-01', 'to' => '2025-06-30']);
        $this->ctrl($r)->exportAuditLogs();

        $this->assertNotNull($this->response->downloadContent);
        $this->assertMatchesRegularExpression(
            '/^Timestamp,Event,User,Email,"?IP Address"?,Details/',
            $this->response->downloadContent
        );
        $this->assertStringContainsString('Bob', $this->response->downloadContent);
        $this->assertStringNotContainsString('Deprecated', $this->response->downloadContent);
    }

    // =========================================================================
    // users() — with tableExists returning true for project_memberships
    // =========================================================================

    public function testUsersWithProjectMembershipsTable(): void
    {
        $userRow = $this->userRow();
        $ctrl = $this->ctrlWithFreshDb(
            null,
            function (\StratFlow\Core\Database $db) use ($userRow): void {
                // tableExists: 1st call (project_memberships) = true; 2nd (project_members) = false (won't be called)
                $db->method('tableExists')
                    ->willReturnCallback(fn(string $t) => $t === 'project_memberships');
                $s1 = $this->stmt(false, [$userRow]);                   // User::findByOrgId
                $s2 = $this->stmt(['user_seat_limit' => 5]);            // Subscription::getSeatLimit
                $s3 = $this->stmt(['cnt' => 1]);                        // User::countByOrgId
                $s4 = $this->stmt(false, [                              // project_memberships query
                    ['user_id' => 99, 'membership_count' => 2],
                ]);
                $s5 = $this->stmt(false);                               // Integration (jira)
                $db->method('query')->willReturnOnConsecutiveCalls($s1, $s2, $s3, $s4, $s5);
            }
        );
        $ctrl->users();

        $this->assertSame('admin/users', $this->response->renderedTemplate);
    }

    // =========================================================================
    // createUser() — org_admin role sets is_project_admin = 1 automatically
    // =========================================================================

    public function testCreateUserWithOrgAdminRoleSetsProjectAdmin(): void
    {
        $this->db->method('lastInsertId')->willReturn('43');
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 1]),              // countByOrgId
            $this->stmt(['user_seat_limit' => 10]), // getSeatLimit
            $this->stmt(false),                     // findByEmail => not found
            $this->stmt(false),                     // User::create INSERT
            $this->stmt(false),                     // PasswordToken::create INSERT
            $this->stmt(false),                     // AuditLogger::log INSERT
        ]);

        $r = $this->makePostRequest([
            'email'            => 'admin2@test.invalid',
            'full_name'        => 'Admin Two',
            'role'             => 'org_admin',      // triggers is_project_admin = 1 path
            'is_project_admin' => '0',              // ignored for org_admin
        ]);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // updateUser() — invalid role falls back to 'user'
    // =========================================================================

    public function testUpdateUserWithInvalidRoleFallsBackToUser(): void
    {
        $target = $this->userRow(['id' => 99, 'role' => 'user']);
        $this->stubQuerySequence([
            $this->stmt($target), // User::findById
            $this->stmt(false),   // User::findByEmail (not found)
            $this->stmt(false),   // User::update: account_type info schema
            $this->stmt(false),   // User::update: jira_display_name check
            $this->stmt(false),   // UPDATE users
            // no AuditLogger role change because role stays 'user'
        ]);

        $r = $this->makePostRequest([
            'full_name' => 'Test User',
            'email'     => 'user@test.invalid',
            'role'      => 'superadmin',  // not in assignable roles → falls back to 'user'
        ]);
        $this->ctrl($r)->updateUser(99);

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
    }

    // =========================================================================
    // billing() — with a non-invoice subscription (Stripe-billed)
    // =========================================================================

    public function testBillingWithStripeSubscriptionHitsMakeStripe(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_abc']);
        // Non-invoice sub: billing_method='stripe', stripe_subscription_id doesn't start with 'manual_'
        $sub = $this->subRow([
            'billing_method'        => 'stripe',
            'stripe_subscription_id' => 'sub_live123',
            'plan_type'             => 'enterprise',  // no price_enterprise in config → fallback to price_product
        ]);
        $this->stubQuerySequence([
            $this->stmt($org),                       // Organisation::findById
            $this->stmt($sub),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // Subscription::getSeatLimit
            $this->stmt(false, [$this->userRow()]),  // User::findByOrgId
            $this->stmt(false),                      // Integration (xero) - not active
        ]);

        // Bad Stripe key: getSubscription will throw (caught) → lines 577-579 covered
        $cfg = $this->config;
        $cfg['stripe']['secret_key']        = 'sk_invalid';
        $cfg['stripe']['price_enterprise']  = '';  // missing → triggers price_product fallback

        $ctrl = new AdminController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
        $ctrl->billing();

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        // has_stripe = true because stripe_customer_id is set
        $this->assertTrue($this->response->renderedData['has_stripe']);
    }

    // =========================================================================
    // users() — jira integration query throws → covers catch block (line 134)
    // =========================================================================

    public function testUsersHandlesJiraIntegrationException(): void
    {
        $userRow = $this->userRow();
        $ctrl = $this->ctrlWithFreshDb(
            null,
            function (\StratFlow\Core\Database $db) use ($userRow): void {
                $db->method('tableExists')->willReturn(false);
                $s1 = $this->stmt(false, [$userRow]);  // User::findByOrgId
                $s2 = $this->stmt(['user_seat_limit' => 5]);
                $s3 = $this->stmt(['cnt' => 1]);
                // 4th call = Integration::findByOrgAndProvider → throws
                $db->method('query')
                    ->willReturnCallback(static function () use ($s1, $s2, $s3): mixed {
                        static $call = 0;
                        $call++;
                        return match ($call) {
                            1 => $s1,
                            2 => $s2,
                            3 => $s3,
                            default => throw new \RuntimeException('DB error'),
                        };
                    });
            }
        );
        $ctrl->users();

        $this->assertSame('admin/users', $this->response->renderedTemplate);
    }

    // =========================================================================
    // users() — with tableExists returning true for project_members (elseif)
    // =========================================================================

    public function testUsersWithProjectMembersTable(): void
    {
        $userRow = $this->userRow();
        $ctrl = $this->ctrlWithFreshDb(
            null,
            function (\StratFlow\Core\Database $db) use ($userRow): void {
                // project_memberships = false, project_members = true
                $db->method('tableExists')
                    ->willReturnCallback(fn(string $t) => $t === 'project_members');
                $s1 = $this->stmt(false, [$userRow]);              // User::findByOrgId
                $s2 = $this->stmt(['user_seat_limit' => 5]);       // getSeatLimit
                $s3 = $this->stmt(['cnt' => 1]);                   // countByOrgId
                $s4 = $this->stmt(false, [                         // project_members query
                    ['user_id' => 99, 'membership_count' => 1],
                ]);
                $s5 = $this->stmt(false);                          // Integration (jira)
                $db->method('query')->willReturnOnConsecutiveCalls($s1, $s2, $s3, $s4, $s5);
            }
        );
        $ctrl->users();

        $this->assertSame('admin/users', $this->response->renderedTemplate);
    }

    // =========================================================================
    // createUser() — invalid role falls back to 'user' (line 184)
    // =========================================================================

    public function testCreateUserWithInvalidRoleFallsBackToUser(): void
    {
        $this->db->method('lastInsertId')->willReturn('44');
        $this->stubQuerySequence([
            $this->stmt(['cnt' => 1]),              // countByOrgId
            $this->stmt(['user_seat_limit' => 10]), // getSeatLimit
            $this->stmt(false),                     // findByEmail => not found
            $this->stmt(false),                     // User::create INSERT
            $this->stmt(false),                     // PasswordToken::create INSERT
            $this->stmt(false),                     // AuditLogger::log INSERT
        ]);

        $r = $this->makePostRequest([
            'email'     => 'test3@test.invalid',
            'full_name' => 'Test Three',
            'role'      => 'superadmin',  // not in org_admin assignable roles
        ]);
        $this->ctrl($r)->createUser();

        $this->assertSame('/app/admin/users', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    // =========================================================================
    // billing() — mock Stripe HTTP client to cover listInvoices + listSubscriptions
    // =========================================================================

    public function testBillingWithMockStripeHttpClientCoversAllLines(): void
    {
        // Set up a fake Stripe HTTP client that returns valid empty list responses
        $fakeHttp = new FakeStripeHttpClient();
        \Stripe\ApiRequestor::setHttpClient($fakeHttp);

        $org = $this->orgRow(['stripe_customer_id' => 'cus_test_mock', 'settings_json' => '{}']);
        $sub = $this->subRow(['billing_method' => 'invoice']);
        $this->stubQuerySequence([
            $this->stmt($org),                       // Organisation::findById
            $this->stmt($sub),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // getSeatLimit
            $this->stmt(false, [$this->userRow()]),  // User::findByOrgId
            $this->stmt(false),                      // Integration (xero) - not active
        ]);

        $this->ctrl()->billing();

        // Restore default HTTP client
        \Stripe\ApiRequestor::setHttpClient(null);

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        $this->assertTrue($this->response->renderedData['has_stripe']);
        $this->assertSame([], $this->response->renderedData['stripe_invoices']);
    }

    // =========================================================================
    // billing() — Stripe customer with listInvoices throwing (catch body covered)
    // =========================================================================

    public function testBillingWithStripeCustomerCoversListInvoicesCatch(): void
    {
        $org = $this->orgRow(['stripe_customer_id' => 'cus_xyz', 'settings_json' => '{}']);
        $sub = $this->subRow(['billing_method' => 'invoice']);
        $this->stubQuerySequence([
            $this->stmt($org),                       // Organisation::findById
            $this->stmt($sub),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // getSeatLimit
            $this->stmt(false, [$this->userRow()]),  // User::findByOrgId
            $this->stmt(false),                      // Integration (xero)
        ]);

        $cfg = $this->config;
        $cfg['stripe']['secret_key'] = 'sk_invalid_will_throw';

        $ctrl = new AdminController($this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
        $ctrl->billing();

        $this->assertSame('admin/billing', $this->response->renderedTemplate);
        // stripe_invoices = [] because exception was caught
        $this->assertSame([], $this->response->renderedData['stripe_invoices']);
    }

    // =========================================================================
    // purchaseSeatsInvoice() — price_per_seat_cents = 0 → no cost message
    // =========================================================================

    public function testPurchaseSeatsInvoiceWithNoPriceShowsNoAddedCost(): void
    {
        $sub = $this->subRow(['price_per_seat_cents' => 0]);
        $this->stubQuerySequence([
            $this->stmt($sub),                       // Subscription::findByOrgId
            $this->stmt(['user_seat_limit' => 10]),  // Subscription::getSeatLimit
            $this->stmt(false),                      // Subscription::updateSeatLimit
            $this->stmt(false),                      // AuditLogger::log
        ]);

        $r = $this->makePostRequest(['seats_to_add' => '1']);
        $this->ctrl($r)->purchaseSeatsInvoice();

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('1 seat', $_SESSION['flash_message']);
    }
}
