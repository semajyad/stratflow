<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\XeroService;

final class XeroServiceTest extends TestCase
{
    private function makeService(): XeroService
    {
        return new XeroService([
            'xero' => [
                'client_id' => 'client-123',
                'client_secret' => 'secret-456',
                'redirect_uri' => 'https://app.test/xero/callback',
            ],
        ]);
    }

    #[Test]
    public function authUrlIncludesExpectedOauthParameters(): void
    {
        $url = $this->makeService()->authUrl('csrf-state');
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.xero.com/identity/connect/authorize?', $url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('https://app.test/xero/callback', $query['redirect_uri']);
        $this->assertSame('csrf-state', $query['state']);
        $this->assertStringContainsString('offline_access', $query['scope']);
    }

    #[Test]
    public function setTokensAndGetTokensRoundTrip(): void
    {
        $service = $this->makeService();
        $tokens = [
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => time() + 3600,
            'tenant_id' => 'tenant',
        ];

        $service->setTokens($tokens);

        $this->assertSame($tokens, $service->getTokens());
        $this->assertTrue($service->isTokenValid());
    }

    #[Test]
    public function expiredTokenIsNotValid(): void
    {
        $service = $this->makeService();
        $service->setTokens([
            'access_token' => 'access',
            'expires_at' => time() - 1,
        ]);

        $this->assertFalse($service->isTokenValid());
    }

    #[Test]
    public function ensureValidTokenRequiresRefreshTokenWhenExpired(): void
    {
        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No refresh token available.');

        $service->ensureValidToken();
    }

    #[Test]
    public function buildInvoicePayloadUsesStratflowDefaults(): void
    {
        $payload = XeroService::buildInvoicePayload('Acme Ltd', 'Annual plan', 1200.50, 'NZD', 'sub_123');

        $this->assertSame('ACCREC', $payload['Type']);
        $this->assertSame(['Name' => 'Acme Ltd'], $payload['Contact']);
        $this->assertSame('Annual plan', $payload['LineItems'][0]['Description']);
        $this->assertSame(1.0, $payload['LineItems'][0]['Quantity']);
        $this->assertSame(1200.50, $payload['LineItems'][0]['UnitAmount']);
        $this->assertSame('OUTPUT2', $payload['LineItems'][0]['TaxType']);
        $this->assertSame('NZD', $payload['CurrencyCode']);
        $this->assertSame('sub_123', $payload['Reference']);
        $this->assertSame('AUTHORISED', $payload['Status']);
    }
}
