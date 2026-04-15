<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\CheckoutController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * CheckoutControllerTest
 *
 * Tests for CheckoutController::create() and helper methods.
 * - Dev mode checkout (skips Stripe when keys are placeholders)
 * - Price ID validation against configured values
 * - Product type resolution (subscription, user_pack, evaluation_board)
 * - Stripe API error handling
 */
final class CheckoutControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@test.invalid', 'is_active' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): CheckoutController
    {
        return new CheckoutController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    // ===========================
    // Product Type Resolution
    // ===========================

    #[Test]
    public function testResolveProductTypeSubscription(): void
    {
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_product'] = 'price_product_xxx';

        $req = $this->makePostRequest(['product_type' => 'subscription']);
        $this->ctrl($req)->create();

        // In dev mode, should redirect to /success
        $this->assertSame('/success', $this->response->redirectedTo);
    }

    #[Test]
    public function testResolveProductTypeUserPack(): void
    {
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_user_pack'] = 'price_user_pack_xxx';

        $req = $this->makePostRequest(['product_type' => 'user_pack']);
        $this->ctrl($req)->create();

        // In dev mode, should redirect to /success
        $this->assertSame('/success', $this->response->redirectedTo);
    }

    #[Test]
    public function testResolveProductTypeEvaluationBoard(): void
    {
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_evaluation_board'] = 'price_eval_xxx';

        $req = $this->makePostRequest(['product_type' => 'evaluation_board']);
        $this->ctrl($req)->create();

        // In dev mode, should redirect to /success
        $this->assertSame('/success', $this->response->redirectedTo);
    }

    // ===========================
    // Price ID Validation
    // ===========================

    #[Test]
    public function testMissingPriceIdRendersErrorPage(): void
    {
        $this->config['app']['debug'] = false;
        $this->config['stripe']['secret_key'] = 'sk_live_real';
        $this->config['stripe']['price_product'] = '';
        $this->config['stripe']['publishable_key'] = 'pk_live_real';
        $this->config['stripe']['price_consultancy'] = 'price_consult_xxx';

        $req = $this->makePostRequest(['product_type' => 'invalid_type']);
        $this->ctrl($req)->create();

        $this->assertSame('pricing', $this->response->renderedTemplate);
        $this->assertStringContainsString('Invalid plan', $this->response->renderedData['flash_error'] ?? '');
    }

    #[Test]
    public function testInvalidPriceIdRendersErrorPage(): void
    {
        $this->config['app']['debug'] = false;
        $this->config['stripe']['secret_key'] = 'sk_live_real';
        $this->config['stripe']['price_product'] = 'price_product_xxx';
        $this->config['stripe']['publishable_key'] = 'pk_live_real';
        $this->config['stripe']['price_consultancy'] = 'price_consult_xxx';

        $req = $this->makePostRequest(['price_id' => 'invalid_price_id']);
        $this->ctrl($req)->create();

        $this->assertSame('pricing', $this->response->renderedTemplate);
        $this->assertStringContainsString('Invalid plan', $this->response->renderedData['flash_error'] ?? '');
    }

    // ===========================
    // Dev Mode Checkout
    // ===========================

    #[Test]
    public function testDevModeSkipsStripeWhenSecretKeyHasPlaceholder(): void
    {
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_product'] = 'price_product_xxx';
        $this->config['stripe']['publishable_key'] = 'pk_test_xxx';

        $req = $this->makePostRequest(['product_type' => 'subscription']);
        $this->ctrl($req)->create();

        // Should redirect to /success without calling Stripe
        $this->assertSame('/success', $this->response->redirectedTo);
    }

    #[Test]
    public function testDevModeCreateSubscriptionForOrgOneWhenNoAuth(): void
    {
        $this->auth->method('check')->willReturn(false);
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_product'] = 'price_product_xxx';

        $req = $this->makePostRequest(['product_type' => 'subscription']);
        $this->ctrl($req)->create();

        $this->assertSame('/success', $this->response->redirectedTo);
    }

    #[Test]
    public function testDevModeCreateSubscriptionForCurrentOrgWhenAuthenticated(): void
    {
        $this->auth->method('check')->willReturn(true);
        $this->auth->method('user')->willReturn($this->user);
        $this->auth->method('orgId')->willReturn(10);
        $this->config['app']['debug'] = true;
        $this->config['stripe']['secret_key'] = 'sk_test_xxx';
        $this->config['stripe']['price_consultancy'] = 'price_consult_xxx';

        $req = $this->makePostRequest(['product_type' => 'subscription']);
        $this->ctrl($req)->create();

        $this->assertSame('/success', $this->response->redirectedTo);
    }

    // ===========================
    // Stripe API Error Handling
    // ===========================

    #[Test]
    public function testStripeApiErrorRendersErrorPage(): void
    {
        $this->config['app']['debug'] = false;
        $this->config['stripe']['secret_key'] = 'sk_live_real';
        $this->config['stripe']['price_product'] = 'price_product_xxx';
        $this->config['stripe']['publishable_key'] = 'pk_live_real';
        $this->config['stripe']['price_consultancy'] = 'price_consult_xxx';
        $this->config['app']['url'] = 'http://localhost';

        $req = $this->makePostRequest(['price_id' => 'price_product_xxx']);
        $this->ctrl($req)->create();

        // Mock will fail, so should render error page
        $this->assertSame('pricing', $this->response->renderedTemplate);
        $this->assertArrayHasKey('flash_error', $this->response->renderedData);
    }
}
