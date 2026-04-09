<?php
/**
 * XeroController
 *
 * Handles the Xero OAuth 2.0 flow and invoice management for StratFlow admins.
 *
 * Routes (all require 'auth' + 'admin'):
 *   GET  /app/admin/xero/connect              — redirect to Xero OAuth
 *   GET  /app/admin/xero/callback             — handle OAuth callback
 *   POST /app/admin/xero/disconnect           — remove integration
 *   GET  /app/admin/invoices                  — list invoices (Xero + Stripe)
 *   POST /app/admin/invoices/create           — create invoice in Xero
 *   POST /app/admin/invoices/sync             — refresh local invoice cache
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;
use StratFlow\Services\XeroService;

class XeroController
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
    // OAUTH
    // ===========================

    /**
     * Redirect the admin to Xero's OAuth authorisation page.
     *
     * Stores a random state token in session for CSRF protection.
     * GET /app/admin/xero/connect
     */
    public function connect(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['xero_oauth_state'] = $state;

        $xero = new XeroService($this->config);
        $this->response->redirect($xero->authUrl($state));
    }

    /**
     * Handle the OAuth callback from Xero.
     *
     * Exchanges the authorisation code for tokens, fetches the first tenant,
     * and stores everything in the integrations table.
     * GET /app/admin/xero/callback
     */
    public function callback(): void
    {
        $error = $this->request->get('error', '');
        if ($error !== '') {
            $_SESSION['flash_error'] = 'Xero connection refused: ' . htmlspecialchars((string) $error);
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $state    = (string) $this->request->get('state', '');
        $expected = $_SESSION['xero_oauth_state'] ?? '';
        unset($_SESSION['xero_oauth_state']);

        if (!hash_equals($expected, $state) || $expected === '') {
            $_SESSION['flash_error'] = 'Xero OAuth state mismatch. Please try again.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        $code = (string) $this->request->get('code', '');
        if ($code === '') {
            $_SESSION['flash_error'] = 'No authorisation code returned by Xero.';
            $this->response->redirect('/app/admin/integrations');
            return;
        }

        try {
            $xero   = new XeroService($this->config);
            $tokens = $xero->exchangeCode($code);
            $xero->setTokens($tokens);

            // Fetch the first authorised tenant
            $tenants  = $xero->getTenants();
            $tenantId = $tenants[0]['tenantId'] ?? null;
            if ($tenantId === null) {
                throw new \RuntimeException('No Xero organisations found in your account.');
            }

            $config = [
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at'    => $tokens['expires_at'],
                'tenant_id'     => $tenantId,
                'tenant_name'   => $tenants[0]['tenantName'] ?? 'Unknown',
            ];

            $user  = $this->auth->user();
            $orgId = (int) $user['org_id'];

            $existing = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
            if ($existing) {
                Integration::update($this->db, (int) $existing['id'], [
                    'status'      => 'active',
                    'config_json' => json_encode($config),
                ]);
            } else {
                Integration::create($this->db, [
                    'org_id'      => $orgId,
                    'provider'    => 'xero',
                    'status'      => 'active',
                    'config_json' => json_encode($config),
                ]);
            }

            $_SESSION['flash_message'] = 'Xero connected successfully to ' . htmlspecialchars($tenants[0]['tenantName'] ?? 'your organisation') . '.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Xero connection failed: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/integrations');
    }

    /**
     * Remove the Xero integration for this organisation.
     *
     * POST /app/admin/xero/disconnect
     */
    public function disconnect(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $integration = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
        if ($integration) {
            Integration::update($this->db, (int) $integration['id'], ['status' => 'disconnected']);
        }

        // Clear cached invoices
        $this->db->query("DELETE FROM xero_invoices WHERE org_id = :org_id", [':org_id' => $orgId]);

        $_SESSION['flash_message'] = 'Xero disconnected.';
        $this->response->redirect('/app/admin/integrations');
    }

    // ===========================
    // INVOICES
    // ===========================

    /**
     * Display all invoices — Xero (from local cache) + Stripe fallback.
     *
     * GET /app/admin/invoices
     */
    public function invoices(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        // Load Xero integration
        $xeroIntegration = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
        $xeroConnected   = $xeroIntegration && $xeroIntegration['status'] === 'active';

        // Load cached Xero invoices
        $xeroInvoices = [];
        if ($xeroConnected) {
            $stmt = $this->db->query(
                "SELECT * FROM xero_invoices WHERE org_id = :org_id ORDER BY invoice_date DESC, created_at DESC",
                [':org_id' => $orgId]
            );
            $xeroInvoices = $stmt->fetchAll();
        }

        // Load Stripe invoices as fallback / supplement
        $stripeInvoices = $this->loadStripeInvoices($orgId);

        $this->response->render('admin/invoices', [
            'user'             => $user,
            'xero_invoices'    => $xeroInvoices,
            'xero_connected'   => $xeroConnected,
            'xero_tenant_name' => $xeroConnected
                ? (json_decode($xeroIntegration['config_json'] ?? '{}', true)['tenant_name'] ?? 'Xero')
                : null,
            'stripe_invoices'  => $stripeInvoices,
            'active_page'      => 'admin',
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create an invoice in Xero for this organisation.
     *
     * POST /app/admin/invoices/create
     */
    public function createInvoice(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $xeroIntegration = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
        if (!$xeroIntegration || $xeroIntegration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Xero is not connected. Connect it under Integrations first.';
            $this->response->redirect('/app/admin/invoices');
            return;
        }

        try {
            $config   = json_decode($xeroIntegration['config_json'] ?? '{}', true) ?: [];
            $xero     = new XeroService($this->config);
            $xero->setTokens($config);

            $contactName = trim((string) $this->request->post('contact_name', ''));
            $description = trim((string) $this->request->post('description', 'StratFlow Subscription'));
            $amount      = (float) $this->request->post('amount', '0');
            $currency    = strtoupper(trim((string) $this->request->post('currency', 'NZD')));
            $reference   = trim((string) $this->request->post('reference', ''));

            if ($contactName === '' || $amount <= 0) {
                $_SESSION['flash_error'] = 'Contact name and a positive amount are required.';
                $this->response->redirect('/app/admin/invoices');
                return;
            }

            $payload = XeroService::buildInvoicePayload($contactName, $description, $amount, $currency, $reference);
            $invoice = $xero->createInvoice($config['tenant_id'], $payload);

            // Cache locally
            $this->cacheXeroInvoice($orgId, $invoice);

            // Persist refreshed tokens
            $this->persistTokens($xeroIntegration, $xero);

            $_SESSION['flash_message'] = 'Invoice created in Xero: ' . htmlspecialchars($invoice['InvoiceNumber'] ?? '');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create Xero invoice: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/invoices');
    }

    /**
     * Refresh the local Xero invoice cache from the live API.
     *
     * POST /app/admin/invoices/sync
     */
    public function syncInvoices(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];

        $xeroIntegration = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
        if (!$xeroIntegration || $xeroIntegration['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Xero is not connected.';
            $this->response->redirect('/app/admin/invoices');
            return;
        }

        try {
            $config   = json_decode($xeroIntegration['config_json'] ?? '{}', true) ?: [];
            $xero     = new XeroService($this->config);
            $xero->setTokens($config);

            $invoices = $xero->listInvoices($config['tenant_id']);

            foreach ($invoices as $invoice) {
                $this->cacheXeroInvoice($orgId, $invoice);
            }

            $this->persistTokens($xeroIntegration, $xero);

            $_SESSION['flash_message'] = 'Synced ' . count($invoices) . ' invoice' . (count($invoices) !== 1 ? 's' : '') . ' from Xero.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Xero sync failed: ' . $e->getMessage();
        }

        $this->response->redirect('/app/admin/invoices');
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Upsert a single Xero invoice into the local cache table.
     *
     * @param int   $orgId   Organisation ID
     * @param array $invoice Invoice data from Xero API
     */
    private function cacheXeroInvoice(int $orgId, array $invoice): void
    {
        $xeroId = $invoice['InvoiceID'] ?? '';
        if ($xeroId === '') {
            return;
        }

        $this->db->query(
            "INSERT INTO xero_invoices
             (org_id, xero_invoice_id, invoice_number, contact_name, status,
              currency_code, amount_due, amount_paid, total, invoice_date, due_date, reference, xero_url)
             VALUES (:org_id, :xero_id, :number, :contact, :status,
                     :currency, :amount_due, :amount_paid, :total, :inv_date, :due_date, :ref, :url)
             ON DUPLICATE KEY UPDATE
               invoice_number = VALUES(invoice_number),
               contact_name   = VALUES(contact_name),
               status         = VALUES(status),
               currency_code  = VALUES(currency_code),
               amount_due     = VALUES(amount_due),
               amount_paid    = VALUES(amount_paid),
               total          = VALUES(total),
               invoice_date   = VALUES(invoice_date),
               due_date       = VALUES(due_date),
               reference      = VALUES(reference),
               xero_url       = VALUES(xero_url),
               synced_at      = NOW()",
            [
                ':org_id'      => $orgId,
                ':xero_id'     => $xeroId,
                ':number'      => $invoice['InvoiceNumber'] ?? null,
                ':contact'     => $invoice['Contact']['Name'] ?? null,
                ':status'      => $invoice['Status'] ?? 'DRAFT',
                ':currency'    => $invoice['CurrencyCode'] ?? 'NZD',
                ':amount_due'  => (float) ($invoice['AmountDue']  ?? 0),
                ':amount_paid' => (float) ($invoice['AmountPaid'] ?? 0),
                ':total'       => (float) ($invoice['Total']      ?? 0),
                ':inv_date'    => !empty($invoice['Date'])    ? substr($invoice['Date'], 0, 10)    : null,
                ':due_date'    => !empty($invoice['DueDate']) ? substr($invoice['DueDate'], 0, 10) : null,
                ':ref'         => $invoice['Reference'] ?? null,
                ':url'         => $invoice['Url'] ?? null,
            ]
        );
    }

    /**
     * Persist refreshed tokens back to the integrations row (if they changed).
     *
     * @param array        $integration Integration row
     * @param XeroService  $xero        Service instance with current tokens
     */
    private function persistTokens(array $integration, XeroService $xero): void
    {
        $fresh      = $xero->getTokens();
        $existing   = json_decode($integration['config_json'] ?? '{}', true) ?: [];
        $merged     = array_merge($existing, $fresh);
        Integration::update($this->db, (int) $integration['id'], [
            'config_json' => json_encode($merged),
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Load Stripe invoices for the org as a fallback when Xero is not connected.
     *
     * @param int $orgId Organisation ID
     * @return array     Array of Stripe Invoice objects (empty if Stripe unavailable)
     */
    private function loadStripeInvoices(int $orgId): array
    {
        try {
            $stripeKey = $this->config['stripe']['secret_key'] ?? '';
            if ($stripeKey === '') {
                return [];
            }

            $stmt = $this->db->query(
                "SELECT stripe_customer_id FROM organisations WHERE id = :id LIMIT 1",
                [':id' => $orgId]
            );
            $org = $stmt->fetch();
            if (!$org || empty($org['stripe_customer_id'])) {
                return [];
            }

            \Stripe\Stripe::setApiKey($stripeKey);
            $invoices = \Stripe\Invoice::all([
                'customer' => $org['stripe_customer_id'],
                'limit'    => 24,
            ]);

            return $invoices->data ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
