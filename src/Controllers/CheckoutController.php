<?php
/**
 * CheckoutController
 *
 * Handles creation of Stripe Checkout Sessions. Accepts either a direct
 * price_id or a product_type ('subscription', 'user_pack', 'evaluation_board')
 * and resolves the correct price ID and checkout mode from config. Validates
 * the price_id against configured values before creating the session.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Services\StripeService;

class CheckoutController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    /**
     * Create a Stripe Checkout Session and redirect to the hosted checkout URL.
     *
     * Accepts a product_type ('subscription', 'user_pack', 'evaluation_board') to
     * determine the price ID and checkout mode automatically, or a direct price_id.
     * Validates the resolved price against configured values before creating the session.
     * On Stripe API error, renders the pricing page with an error message.
     */
    public function create(): void
    {
        $stripe      = new StripeService($this->config['stripe']);
        $productType = (string) $this->request->post('product_type', '');
        $priceId     = (string) $this->request->post('price_id', '');

        // Resolve price_id and mode from product_type when provided
        $mode = 'subscription';
        if ($productType !== '') {
            [$priceId, $mode] = $this->resolveProductType($productType);
        } else {
            $mode = 'subscription';
        }

        $validPriceIds = $stripe->validPriceIds();

        if ($priceId === '' || !in_array($priceId, $validPriceIds, true)) {
            $this->response->render('pricing', [
                'stripe_key'        => $this->config['stripe']['publishable_key'],
                'price_product'     => $this->config['stripe']['price_product'],
                'price_consultancy' => $this->config['stripe']['price_consultancy'],
                'flash_message'     => 'Invalid plan selected. Please try again.',
            ]);
            return;
        }

        // Dev mode: skip Stripe when keys are placeholders
        if ($this->config['app']['debug'] && str_contains($this->config['stripe']['secret_key'], '_xxx')) {
            $this->handleDevCheckout($priceId);
            return;
        }

        try {
            $appUrl     = rtrim($this->config['app']['url'], '/');
            $successUrl = $appUrl . '/success';
            $cancelUrl  = $appUrl . '/pricing';

            $session = $stripe->createCheckoutSession($priceId, $successUrl, $cancelUrl, null, $mode);
            $this->response->redirect($session->url);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->response->render('pricing', [
                'stripe_key'        => $this->config['stripe']['publishable_key'],
                'price_product'     => $this->config['stripe']['price_product'],
                'price_consultancy' => $this->config['stripe']['price_consultancy'],
                'flash_message'     => 'Payment service error. Please try again later.',
            ]);
        }
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Dev mode: simulate a successful checkout without Stripe.
     * Creates an org subscription and redirects to success page.
     */
    private function handleDevCheckout(string $priceId): void
    {
        $planType = ($priceId === ($this->config['stripe']['price_consultancy'] ?? '')) ? 'consultancy' : 'product';

        // If user is logged in, use their org. Otherwise create a dev subscription for the seed org.
        $orgId = $this->auth->check() ? $this->auth->orgId() : 1;

        // Create or update subscription
        $existing = \StratFlow\Models\Subscription::findByOrgId($this->db, $orgId);
        if (!$existing) {
            \StratFlow\Models\Subscription::create($this->db, [
                'org_id' => $orgId,
                'stripe_subscription_id' => 'dev_sub_' . time(),
                'plan_type' => $planType,
                'status' => 'active',
                'started_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->response->redirect('/success');
    }

    /**
     * Map a product_type string to [priceId, checkoutMode].
     *
     * @param string $productType One of: 'subscription', 'user_pack', 'evaluation_board'
     * @return array{0: string, 1: string} Tuple of [price_id, mode]
     */
    private function resolveProductType(string $productType): array
    {
        return match ($productType) {
            'user_pack'        => [$this->config['stripe']['price_user_pack'] ?? '', 'payment'],
            'evaluation_board' => [$this->config['stripe']['price_evaluation_board'] ?? '', 'payment'],
            default            => [$this->config['stripe']['price_product'] ?? '', 'subscription'],
        };
    }
}
