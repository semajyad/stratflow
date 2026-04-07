<?php
/**
 * CheckoutController
 *
 * Handles creation of Stripe Checkout Sessions. Validates the submitted
 * price_id against configured values, then redirects to the Stripe-hosted
 * checkout page. On error, falls back to the pricing page with a message.
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
     * Validates that the POSTed price_id matches one of the two configured Stripe
     * price IDs before creating the session. On Stripe API error, renders the
     * pricing page with an error message.
     */
    public function create(): void
    {
        $priceId = $this->request->post('price_id', '');

        $validPriceIds = array_filter([
            $this->config['stripe']['price_product'],
            $this->config['stripe']['price_consultancy'],
        ]);

        if (!in_array($priceId, $validPriceIds, true)) {
            $this->response->render('pricing', [
                'stripe_key'        => $this->config['stripe']['publishable_key'],
                'price_product'     => $this->config['stripe']['price_product'],
                'price_consultancy' => $this->config['stripe']['price_consultancy'],
                'flash_message'     => 'Invalid plan selected. Please try again.',
            ]);
            return;
        }

        try {
            $stripe     = new StripeService($this->config['stripe']);
            $appUrl     = rtrim($this->config['app']['url'], '/');
            $successUrl = $appUrl . '/success';
            $cancelUrl  = $appUrl . '/pricing';

            $session = $stripe->createCheckoutSession($priceId, $successUrl, $cancelUrl);
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
}
