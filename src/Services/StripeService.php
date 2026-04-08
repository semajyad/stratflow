<?php
/**
 * StripeService
 *
 * Thin wrapper around the Stripe PHP SDK. Handles checkout session creation,
 * webhook signature verification, and invoice retrieval. Reads API keys from
 * the application config array passed at construction time.
 *
 * Usage:
 *   $stripe = new StripeService($config['stripe']);
 *   $session = $stripe->createCheckoutSession($priceId, $successUrl, $cancelUrl, null, 'payment');
 *   $invoices = $stripe->listInvoices($customerId);
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
     * Create a Stripe Checkout Session.
     *
     * @param string      $priceId       Stripe price ID for the line item
     * @param string      $successUrl    URL Stripe redirects to on payment success
     * @param string      $cancelUrl     URL Stripe redirects to on cancellation
     * @param string|null $customerEmail Pre-fill the customer email field (optional)
     * @param string      $mode          Checkout mode: 'subscription' or 'payment' (default: 'subscription')
     * @return Session                   The created Checkout Session
     */
    public function createCheckoutSession(
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?string $customerEmail = null,
        string $mode = 'subscription'
    ): Session {
        $params = [
            'mode'        => $mode,
            'line_items'  => [
                [
                    'price'    => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            // Always collect email so we can create the user account
            'customer_creation' => ($mode === 'subscription') ? 'always' : 'if_required',
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
     * Return the configured valid price IDs as an array (all product types).
     *
     * @return string[]
     */
    public function validPriceIds(): array
    {
        return array_filter([
            $this->config['price_product'],
            $this->config['price_consultancy'],
            $this->config['price_user_pack'] ?? '',
            $this->config['price_evaluation_board'] ?? '',
        ]);
    }

    /**
     * Determine the plan type string for a given price ID.
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

        if ($priceId === ($this->config['price_user_pack'] ?? '')) {
            return 'user_pack';
        }

        if ($priceId === ($this->config['price_evaluation_board'] ?? '')) {
            return 'evaluation_board';
        }

        return 'unknown';
    }

    /**
     * Determine the checkout mode for a given product type.
     *
     * Subscription products use Stripe recurring billing; add-on purchases
     * are one-time payments.
     *
     * @param string $productType One of: 'subscription', 'user_pack', 'evaluation_board'
     * @return string             Stripe checkout mode: 'subscription' or 'payment'
     */
    public function modeForProductType(string $productType): string
    {
        return match ($productType) {
            'user_pack', 'evaluation_board' => 'payment',
            default                         => 'subscription',
        };
    }

    // ===========================
    // INVOICE MANAGEMENT
    // ===========================

    /**
     * List up to 50 invoices for a Stripe customer.
     *
     * @param string $customerId Stripe customer ID (cus_xxx)
     * @return \Stripe\Invoice[] Array of Stripe Invoice objects
     */
    public function listInvoices(string $customerId): array
    {
        \Stripe\Stripe::setApiKey($this->config['secret_key']);
        $invoices = \Stripe\Invoice::all(['customer' => $customerId, 'limit' => 50]);

        return $invoices->data;
    }

    /**
     * Retrieve the PDF download URL for a specific invoice.
     *
     * @param string $invoiceId Stripe invoice ID (in_xxx)
     * @return string|null      PDF URL, or null if not available
     */
    public function getInvoicePdfUrl(string $invoiceId): ?string
    {
        \Stripe\Stripe::setApiKey($this->config['secret_key']);
        $invoice = \Stripe\Invoice::retrieve($invoiceId);

        return $invoice->invoice_pdf;
    }
}
