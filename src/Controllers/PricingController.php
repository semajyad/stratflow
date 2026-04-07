<?php
/**
 * PricingController
 *
 * Handles the public pricing page, passing Stripe price IDs and
 * the publishable key into the template so the checkout forms
 * can reference the correct products.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;

class PricingController
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
     * Display the pricing page with both product tiers.
     *
     * Passes Stripe publishable key and price IDs to the template
     * so the checkout forms POST the correct price_id.
     */
    public function index(): void
    {
        $this->response->render('pricing', [
            'stripe_key'          => $this->config['stripe']['publishable_key'],
            'price_product'       => $this->config['stripe']['price_product'],
            'price_consultancy'   => $this->config['stripe']['price_consultancy'],
        ]);
    }
}
