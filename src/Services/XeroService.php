<?php
/**
 * XeroService
 *
 * Xero OAuth 2.0 client for invoice management.
 * Handles the authorisation code flow, token refresh, and invoice CRUD.
 *
 * Tokens are stored encrypted in integrations.config_json:
 *   { "access_token": "...", "refresh_token": "...", "expires_at": 1234567890,
 *     "tenant_id": "..." }
 *
 * Usage:
 *   $xero = new XeroService($config);
 *   $url  = $xero->authUrl($state);                          // Step 1 — redirect user
 *   $tok  = $xero->exchangeCode($code);                      // Step 2 — after callback
 *   $xero->setTokens($tok);                                  // load tokens
 *   $inv  = $xero->createInvoice($tenantId, $invoiceData);   // create invoice
 *   $list = $xero->listInvoices($tenantId);                  // list invoices
 */

declare(strict_types=1);

namespace StratFlow\Services;

class XeroService
{
    // ===========================
    // CONFIG
    // ===========================

    private const AUTH_URL    = 'https://login.xero.com/identity/connect/authorize';
    private const TOKEN_URL   = 'https://identity.xero.com/connect/token';
    private const API_BASE    = 'https://api.xero.com/api.xro/2.0';
    private const TENANTS_URL = 'https://api.xero.com/connections';

    private const SCOPES = [
        'openid',
        'profile',
        'email',
        'accounting.invoices',
        'accounting.contacts',
        'offline_access',
    ];

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    private ?string $accessToken   = null;
    private ?string $refreshToken  = null;
    private int     $expiresAt     = 0;
    private ?string $tenantId      = null;

    public function __construct(array $config)
    {
        $this->clientId     = $config['xero']['client_id']     ?? '';
        $this->clientSecret = $config['xero']['client_secret'] ?? '';
        $this->redirectUri  = $config['xero']['redirect_uri']  ?? '';
    }

    // ===========================
    // OAUTH
    // ===========================

    /**
     * Build the Xero OAuth 2.0 authorisation URL.
     *
     * @param string $state Random CSRF state string
     * @return string       URL to redirect the user to
     */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => implode(' ', self::SCOPES),
            'state'         => $state,
        ]);
    }

    /**
     * Exchange an authorisation code for access + refresh tokens.
     *
     * @param string $code Code from Xero callback query string
     * @return array       Token payload: access_token, refresh_token, expires_in
     * @throws \RuntimeException On HTTP or JSON error
     */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
    }

    /**
     * Refresh an expired access token using the stored refresh token.
     *
     * @return array Updated token payload
     * @throws \RuntimeException On HTTP or JSON error
     */
    public function refreshAccessToken(): array
    {
        if ($this->refreshToken === null) {
            throw new \RuntimeException('No refresh token available.');
        }

        $tokens = $this->tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $this->setTokens($tokens);
        return $tokens;
    }

    /**
     * Load stored tokens into the service instance.
     *
     * @param array $tokens Keys: access_token, refresh_token, expires_at, tenant_id
     */
    public function setTokens(array $tokens): void
    {
        $this->accessToken  = $tokens['access_token']  ?? null;
        $this->refreshToken = $tokens['refresh_token'] ?? null;
        $this->expiresAt    = (int) ($tokens['expires_at'] ?? 0);
        $this->tenantId     = $tokens['tenant_id']     ?? null;
    }

    /**
     * Return the stored tokens as a serialisable array for persistence.
     *
     * @return array Keys: access_token, refresh_token, expires_at, tenant_id
     */
    public function getTokens(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at'    => $this->expiresAt,
            'tenant_id'     => $this->tenantId,
        ];
    }

    /**
     * Return true if the current access token appears to be valid (not expired).
     */
    public function isTokenValid(): bool
    {
        return $this->accessToken !== null && time() < ($this->expiresAt - 60);
    }

    /**
     * Ensure we have a valid access token, refreshing if needed.
     *
     * @throws \RuntimeException If no tokens are loaded or refresh fails
     */
    public function ensureValidToken(): void
    {
        if (!$this->isTokenValid()) {
            $this->refreshAccessToken();
        }
    }

    // ===========================
    // TENANTS
    // ===========================

    /**
     * Fetch the list of Xero tenants (organisations) the user has authorised.
     *
     * @return array Array of tenant objects with id, name, tenantType
     * @throws \RuntimeException On API error
     */
    public function getTenants(): array
    {
        $this->ensureValidToken();
        $response = $this->apiGet(self::TENANTS_URL);
        return $response ?? [];
    }

    // ===========================
    // INVOICES
    // ===========================

    /**
     * Create a new invoice in Xero.
     *
     * @param string $tenantId   Xero tenant (organisation) UUID
     * @param array  $invoice    Invoice data (see Xero Accounting API docs)
     * @return array             Created invoice from Xero API
     * @throws \RuntimeException On API error
     */
    public function createInvoice(string $tenantId, array $invoice): array
    {
        $this->ensureValidToken();

        $url  = self::API_BASE . '/Invoices';
        $body = json_encode(['Invoices' => [$invoice]]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Xero-Tenant-Id: ' . $tenantId,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException("Xero create invoice failed (HTTP {$code}): " . ($raw ?: 'no response'));
        }

        $data = json_decode($raw, true);
        return $data['Invoices'][0] ?? [];
    }

    /**
     * Retrieve invoices from Xero, optionally filtered by status.
     *
     * @param string      $tenantId Xero tenant UUID
     * @param string|null $status   Optional status filter (e.g. 'AUTHORISED')
     * @param int         $page     Page number (100 per page)
     * @return array                Array of invoice objects
     * @throws \RuntimeException On API error
     */
    public function listInvoices(string $tenantId, ?string $status = null, int $page = 1): array
    {
        $this->ensureValidToken();

        $params = ['page' => $page, 'order' => 'Date DESC'];
        if ($status !== null) {
            $params['Statuses'] = $status;
        }

        $url      = self::API_BASE . '/Invoices?' . http_build_query($params);
        $response = $this->apiGet($url, $tenantId);
        return $response['Invoices'] ?? [];
    }

    /**
     * Get a single invoice by Xero invoice ID.
     *
     * @param string $tenantId  Xero tenant UUID
     * @param string $invoiceId Xero invoice UUID
     * @return array|null       Invoice object or null if not found
     */
    public function getInvoice(string $tenantId, string $invoiceId): ?array
    {
        $this->ensureValidToken();

        $url      = self::API_BASE . '/Invoices/' . urlencode($invoiceId);
        $response = $this->apiGet($url, $tenantId);
        $invoices = $response['Invoices'] ?? [];
        return !empty($invoices) ? $invoices[0] : null;
    }

    /**
     * Build a standard StratFlow invoice payload for Xero.
     *
     * @param string $contactName  Billing contact / organisation name
     * @param string $description  Line item description (e.g. "StratFlow Product — Annual")
     * @param float  $amount       Total invoice amount (excl. tax)
     * @param string $currency     ISO 4217 currency code (default NZD)
     * @param string $reference    Optional reference (e.g. subscription ID)
     * @return array               Xero invoice payload array
     */
    public static function buildInvoicePayload(
        string $contactName,
        string $description,
        float  $amount,
        string $currency  = 'NZD',
        string $reference = ''
    ): array {
        return [
            'Type'         => 'ACCREC',
            'Contact'      => ['Name' => $contactName],
            'LineItems'    => [
                [
                    'Description' => $description,
                    'Quantity'    => 1.0,
                    'UnitAmount'  => $amount,
                    'TaxType'     => 'OUTPUT2',  // Standard NZ GST
                ],
            ],
            'Date'         => date('Y-m-d'),
            'DueDate'      => date('Y-m-d', strtotime('+30 days')),
            'CurrencyCode' => $currency,
            'Reference'    => $reference,
            'Status'       => 'AUTHORISED',
        ];
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Perform a GET request to the Xero API.
     *
     * @param string      $url      Full URL
     * @param string|null $tenantId Xero-Tenant-Id header value (omit for /connections)
     * @return array                Decoded JSON response
     * @throws \RuntimeException On HTTP or JSON error
     */
    private function apiGet(string $url, ?string $tenantId = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
        ];
        if ($tenantId !== null) {
            $headers[] = 'Xero-Tenant-Id: ' . $tenantId;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException("Xero API GET failed (HTTP {$code}): " . ($raw ?: 'no response'));
        }

        return json_decode($raw, true) ?? [];
    }

    /**
     * POST to the Xero token endpoint with Basic auth.
     *
     * @param array $params POST parameters
     * @return array        Decoded token response
     * @throws \RuntimeException On HTTP or JSON error
     */
    private function tokenRequest(array $params): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            throw new \RuntimeException("Xero token request failed (HTTP {$code}): " . ($raw ?: 'no response'));
        }

        $data = json_decode($raw, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Xero token response missing access_token: ' . $raw);
        }

        // Compute absolute expiry timestamp
        $data['expires_at'] = time() + (int) ($data['expires_in'] ?? 1800);

        return $data;
    }
}
