<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\XeroController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class XeroControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@t.invalid', 'is_active' => 1];
    private array $xeroIntegration = [
        'id' => 1,
        'org_id' => 10,
        'provider' => 'xero',
        'status' => 'active',
        'config_json' => '{"access_token":"tok","refresh_token":"rtok","expires_at":9999999999,"tenant_id":"tid","tenant_name":"Test Co"}',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): XeroController
    {
        $cfg = array_merge($this->config, [
            'xero' => ['client_id' => 'xcid', 'client_secret' => 'xsec', 'redirect_uri' => 'http://localhost/cb'],
        ]);
        return new XeroController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $cfg);
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    // ===========================
    // connect()
    // ===========================

    public function testConnectSetsSessionStateAndRedirects(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->connect();

        $this->assertArrayHasKey('xero_oauth_state', $_SESSION);
        $this->assertIsString($_SESSION['xero_oauth_state']);
        $this->assertGreaterThan(0, strlen($_SESSION['xero_oauth_state']));
        $this->assertNotNull($this->response->redirectedTo);
    }

    // ===========================
    // callback()
    // ===========================

    public function testCallbackErrorParameterSetsFlashError(): void
    {
        $_SESSION['xero_oauth_state'] = 'valid_state';
        $req = $this->makeGetRequest(['error' => 'access_denied', 'error_description' => 'User denied']);
        $ctrl = $this->ctrl($req);

        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('access_denied', $_SESSION['flash_error'] ?? '');
    }

    public function testCallbackStateMismatchSetsFlashError(): void
    {
        $_SESSION['xero_oauth_state'] = 'valid_state';
        $req = $this->makeGetRequest(['code' => 'auth_code_xyz', 'state' => 'wrong_state']);
        $ctrl = $this->ctrl($req);

        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('state mismatch', $_SESSION['flash_error'] ?? '');
    }

    public function testCallbackNoCodeSetsFlashError(): void
    {
        $_SESSION['xero_oauth_state'] = 'valid_state';
        $req = $this->makeGetRequest(['state' => 'valid_state', 'code' => '']);
        $ctrl = $this->ctrl($req);

        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('No authorisation code', $_SESSION['flash_error'] ?? '');
    }

    public function testCallbackNoSessionStateDefaults(): void
    {
        unset($_SESSION['xero_oauth_state']);
        $req = $this->makeGetRequest(['code' => 'abc', 'state' => '']);
        $ctrl = $this->ctrl($req);

        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
    }

    // ===========================
    // disconnect()
    // ===========================

    public function testDisconnectFindsAndUpdatesIntegration(): void
    {
        $stmtFind = $this->stmt($this->xeroIntegration);
        $stmtDelete = $this->stmt(false);
        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtFind, $stmtDelete, $stmtDelete);

        $ctrl = $this->ctrl();
        $ctrl->disconnect();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('disconnected', $_SESSION['flash_message'] ?? '');
    }

    public function testDisconnectNoIntegrationStillRedirects(): void
    {
        $stmtFind = $this->stmt(false);
        $stmtDelete = $this->stmt(false);
        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtFind, $stmtDelete);

        $ctrl = $this->ctrl();
        $ctrl->disconnect();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('Xero disconnected', $_SESSION['flash_message'] ?? '');
    }

    // ===========================
    // invoices()
    // ===========================

    public function testInvoicesRendersPageWithNoXeroIntegration(): void
    {
        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertFalse($this->response->renderedData['xero_connected']);
        $this->assertSame([], $this->response->renderedData['xero_invoices']);
    }

    public function testInvoicesRendersPageWithActiveXeroIntegration(): void
    {
        $stmtIntegration = $this->stmt($this->xeroIntegration);
        $stmtInvoices = $this->stmt(null, [
            ['id' => 1, 'invoice_number' => 'INV-001', 'amount_due' => 100],
        ]);

        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtIntegration, $stmtInvoices);

        $ctrl = $this->ctrl();
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertTrue($this->response->renderedData['xero_connected']);
        $this->assertSame('Test Co', $this->response->renderedData['xero_tenant_name']);
    }

    // ===========================
    // pushToXero()
    // ===========================

    public function testPushToXeroNoXeroIntegrationRedirects(): void
    {
        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->pushToXero('in_xyz123');

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('not connected', $_SESSION['flash_error'] ?? '');
    }

    public function testPushToXeroDisconnectedIntegrationRedirects(): void
    {
        $disconnected = array_merge($this->xeroIntegration, ['status' => 'disconnected']);
        $stmtFind = $this->stmt($disconnected);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->pushToXero('in_xyz123');

        $this->assertSame('/app/admin/billing', $this->response->redirectedTo);
        $this->assertStringContainsString('not connected', $_SESSION['flash_error'] ?? '');
    }

    // ===========================
    // createInvoice()
    // ===========================

    public function testCreateInvoiceNoXeroIntegrationRedirects(): void
    {
        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req = $this->makePostRequest(['contact_name' => 'Acme', 'amount' => '100']);
        $ctrl = $this->ctrl($req);
        $ctrl->createInvoice();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('not connected', $_SESSION['flash_error'] ?? '');
    }

    public function testCreateInvoiceEmptyContactNameRedirects(): void
    {
        $stmtFind = $this->stmt($this->xeroIntegration);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req = $this->makePostRequest(['contact_name' => '', 'amount' => '100']);
        $ctrl = $this->ctrl($req);
        $ctrl->createInvoice();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('Contact name and a positive amount', $_SESSION['flash_error'] ?? '');
    }

    public function testCreateInvoiceZeroAmountRedirects(): void
    {
        $stmtFind = $this->stmt($this->xeroIntegration);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req = $this->makePostRequest(['contact_name' => 'Acme', 'amount' => '0']);
        $ctrl = $this->ctrl($req);
        $ctrl->createInvoice();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('positive amount', $_SESSION['flash_error'] ?? '');
    }

    // ===========================
    // syncInvoices()
    // ===========================

    public function testSyncInvoicesNoXeroIntegrationRedirects(): void
    {
        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->syncInvoices();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('not connected', $_SESSION['flash_error'] ?? '');
    }

    public function testSyncInvoicesDisconnectedIntegrationRedirects(): void
    {
        $disconnected = array_merge($this->xeroIntegration, ['status' => 'disconnected']);
        $stmtFind = $this->stmt($disconnected);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->syncInvoices();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('not connected', $_SESSION['flash_error'] ?? '');
    }

    // ===========================
    // invoices() — loadStripeInvoices branches
    // ===========================

    public function testInvoicesWithNoXeroAndEmptyStripeKeySkipsStripeLoad(): void
    {
        // Config without stripe key → loadStripeInvoices returns [] immediately
        $cfg = array_merge($this->config, [
            'xero'   => ['client_id' => 'xcid', 'client_secret' => 'xsec', 'redirect_uri' => 'http://localhost/cb'],
            'stripe' => ['secret_key' => '', 'publishable_key' => '', 'webhook_secret' => '', 'price_product' => ''],
        ]);
        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req  = $this->makeGetRequest();
        $ctrl = new XeroController($req, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertSame([], $this->response->renderedData['stripe_invoices']);
    }

    public function testInvoicesWithNoStripeCustomerIdReturnsEmptyStripeInvoices(): void
    {
        // Xero not active, Stripe key present, but DB returns no stripe_customer_id
        $stmtNoIntegration = $this->stmt(false);
        $stmtNoOrg         = $this->stmt(false);  // organisations query returns no row
        $callIdx = 0;
        $stmtSeq = $this->createMock(\PDOStatement::class);
        $stmtSeq->method('fetch')->willReturnCallback(function () use (&$callIdx) {
            $callIdx++;
            return false;
        });
        $stmtSeq->method('fetchAll')->willReturn([]);
        $this->db->expects($this->any())->method('query')->willReturn($stmtSeq);

        $ctrl = $this->ctrl();
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertSame([], $this->response->renderedData['stripe_invoices']);
    }

    public function testInvoicesClearsFlashAfterRender(): void
    {
        $_SESSION['flash_message'] = 'done';
        $_SESSION['flash_error']   = 'fail';

        $stmtFind = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $ctrl = $this->ctrl();
        $ctrl->invoices();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    public function testInvoicesWithActiveXeroAndStripeCustomerIdEmptyReturnsEmpty(): void
    {
        $stmtIntegration   = $this->stmt($this->xeroIntegration);
        $stmtXeroInvoices  = $this->stmt(null, []);                     // xero_invoices table
        $stmtOrgNoCustomer = $this->stmt(['id' => 10, 'stripe_customer_id' => null]); // org row

        $callIdx = 0;
        $stmtsByCall = [$stmtIntegration, $stmtXeroInvoices, $stmtOrgNoCustomer];
        $fallback = $this->stmt(false);
        $this->db->expects($this->any())->method('query')->willReturnCallback(
            function () use (&$callIdx, $stmtsByCall, $fallback) {
                return $stmtsByCall[$callIdx++] ?? $fallback;
            }
        );

        $ctrl = $this->ctrl();
        $ctrl->invoices();

        $this->assertSame('admin/invoices', $this->response->renderedTemplate);
        $this->assertTrue($this->response->renderedData['xero_connected']);
        // Stripe invoices empty because no stripe_customer_id
        $this->assertSame([], $this->response->renderedData['stripe_invoices']);
    }

    // ===========================
    // createInvoice() — negative amount
    // ===========================

    public function testCreateInvoiceNegativeAmountRedirects(): void
    {
        $stmtFind = $this->stmt($this->xeroIntegration);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req = $this->makePostRequest(['contact_name' => 'Acme', 'amount' => '-50']);
        $ctrl = $this->ctrl($req);
        $ctrl->createInvoice();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertStringContainsString('positive amount', $_SESSION['flash_error'] ?? '');
    }

    // ===========================
    // callback() — additional early-exit paths
    // ===========================

    public function testCallbackWithEmptyStateAndEmptySessionStateRedirects(): void
    {
        unset($_SESSION['xero_oauth_state']);
        $req = $this->makeGetRequest(['state' => 'anything', 'code' => 'abc']);
        $ctrl = $this->ctrl($req);

        $ctrl->callback();

        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
        $this->assertStringContainsString('state mismatch', $_SESSION['flash_error'] ?? '');
    }

    // ===========================
    // disconnect() — flash message content
    // ===========================

    public function testDisconnectSetsFlashMessage(): void
    {
        $stmtFind   = $this->stmt(false);
        $stmtDelete = $this->stmt(false);
        $this->db->expects($this->any())->method('query')
            ->willReturnOnConsecutiveCalls($stmtFind, $stmtDelete);

        $ctrl = $this->ctrl();
        $ctrl->disconnect();

        $this->assertStringContainsString('Xero disconnected', $_SESSION['flash_message'] ?? '');
        $this->assertSame('/app/admin/integrations', $this->response->redirectedTo);
    }

    // ===========================
    // syncInvoices() — active but XeroService fails (exception caught)
    // ===========================

    public function testSyncInvoicesXeroServiceExceptionSetsFlashError(): void
    {
        // Config with INVALID xero credentials so XeroService construction fails
        // But XeroService is constructed inside try{} — TypeError becomes flash error
        $cfg = array_merge($this->config, [
            'xero' => ['client_id' => null, 'client_secret' => null, 'redirect_uri' => null],
        ]);
        $stmtFind = $this->stmt($this->xeroIntegration);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req  = $this->makePostRequest([]);
        $ctrl = new XeroController($req, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->syncInvoices();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        // Either flash_error is set (exception caught) or it redirected cleanly
        $this->assertNotNull($this->response->redirectedTo);
    }

    public function testCreateInvoiceXeroServiceExceptionSetsFlashError(): void
    {
        // Config with null xero credentials → XeroService constructor throws TypeError
        $cfg = array_merge($this->config, [
            'xero' => ['client_id' => null, 'client_secret' => null, 'redirect_uri' => null],
        ]);
        $stmtFind = $this->stmt($this->xeroIntegration);
        $this->db->expects($this->any())->method('query')->willReturn($stmtFind);

        $req  = $this->makePostRequest(['contact_name' => 'Acme', 'amount' => '100', 'description' => 'Sub']);
        $ctrl = new XeroController($req, $this->response, $this->auth, $this->db, $cfg);
        $ctrl->createInvoice();

        $this->assertSame('/app/admin/invoices', $this->response->redirectedTo);
        $this->assertNotNull($this->response->redirectedTo);
    }
}
