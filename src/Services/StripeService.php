<?php
/**
 * StripeService
 *
 * Thin wrapper around the Stripe PHP SDK. Handles checkout session creation
 * and webhook signature verification. Reads API keys from the application
 * config array passed at construction time.
 *
 * Usage:
 *   $stripe = new StripeService($config['stripe']);
 *   $session = $stripe->createCheckoutSession($priceId, $successUrl, $cancelUrl);
 */

declare(strict_types=1);

namespace StratFlow\Services;

use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeService
{
    private array $config;

    /**
     * @param array $config Stripe config slice: secret_key, webhook_secret, price_product, price_consultancy
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        Stripe::setApiKey($config['secret_key']);
    }

    /**
     * Create a Stripe Checkout Session in subscription mode.
     *
     * @param string      $priceId       Stripe price ID for the line item
     * @param string      $successUrl    URL Stripe redirects to on payment success
     * @param string      $cancelUrl     URL Stripe redirects to on cancellation
     * @param string|null $customerEmail Pre-fill the customer email field (optional)
     * @return Session                   The created Checkout Session
     */
    public function createCheckoutSession(
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?string $customerEmail = null
    ): Session {
        $params = [
            'mode'        => 'subscription',
            'line_items'  => [
                [
                    'price'    => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ];

        if ($customerEmail !== null) {
            $params['customer_email'] = $customerEmail;
        }

        return Session::create($params);
    }

    /**
     * Verify and construct a Stripe webhook Event from raw payload.
     *
     * @param string $payload   Raw POST body from the Stripe webhook request
     * @param string $sigHeader Value of the Stripe-Signature HTTP header
     * @return Event            The verified Stripe Event object
     * @throws SignatureVerificationException If signature does not match
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->config['webhook_secret']);
    }

    /**
     * Return the configured valid price IDs as an array.
     *
     * @return string[]
     */
    public function validPriceIds(): array
    {
        return array_filter([
            $this->config['price_product'],
            $this->config['price_consultancy'],
        ]);
    }

    /**
     * Determine the plan type string ('product' or 'consultancy') for a given price ID.
     *
     * @param string $priceId Stripe price ID
     * @return string         Plan type label, or 'unknown' if no match
     */
    public function planTypeForPrice(string $priceId): string
    {
        if ($priceId === $this->config['price_product']) {
            return 'product';
        }

        if ($priceId === $this->config['price_consultancy']) {
            return 'consultancy';
        }

        return 'unknown';
    }
}
