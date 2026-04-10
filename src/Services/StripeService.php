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
        ];

        // For one-time payments, ensure a customer is always created
        // (subscriptions always create a customer automatically)
        if ($mode === 'payment') {
            $params['customer_creation'] = 'always';
        }

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
     * Create a Stripe Checkout Session for purchasing seats.
     *
     * Passes quantity directly so the customer can buy N seats in one go.
     * Attaches to an existing Stripe customer when available so payment
     * methods are pre-filled.
     *
     * @param string      $priceId     Stripe price ID for the plan
     * @param int         $quantity    Number of seats to subscribe to
     * @param string      $successUrl  Redirect URL on success (?session_id={CHECKOUT_SESSION_ID} appended)
     * @param string      $cancelUrl   Redirect URL on cancellation
     * @param string|null $customerId  Existing Stripe customer ID to pre-fill (optional)
     * @return Session                 The created Checkout Session
     */
    public function createSeatCheckout(
        string $priceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        ?string $customerId = null
    ): Session {
        $params = [
            'mode'        => 'subscription',
            'line_items'  => [['price' => $priceId, 'quantity' => max(1, $quantity)]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ];
        if ($customerId !== null) {
            $params['customer'] = $customerId;
        }
        return Session::create($params);
    }

    /**
     * List all subscriptions for a Stripe customer.
     *
     * Returns active, trialing, and past_due subscriptions so the billing
     * page can show a full picture of what the org is subscribed to.
     *
     * @param string $customerId Stripe customer ID (cus_xxx)
     * @return array[]           Normalised subscription data arrays
     */
    public function listSubscriptions(string $customerId): array
    {
        \Stripe\Stripe::setApiKey($this->config['secret_key']);
        $result = \Stripe\Subscription::all([
            'customer' => $customerId,
            'limit'    => 20,
            'expand'   => ['data.items.data.price'],
        ]);

        $out = [];
        foreach ($result->data as $sub) {
            $item = $sub->items->data[0] ?? null;
            $out[] = [
                'id'                   => $sub->id,
                'status'               => $sub->status,
                'current_period_start' => date('j M Y', $sub->current_period_start),
                'current_period_end'   => date('j M Y', $sub->current_period_end),
                'cancel_at_period_end' => $sub->cancel_at_period_end,
                'quantity'             => $item?->quantity ?? 1,
                'unit_amount'          => $item?->price->unit_amount ?? 0,
                'currency'             => strtoupper($item?->price->currency ?? 'USD'),
                'interval'             => $item?->price->recurring?->interval ?? 'month',
                'product_name'         => is_string($item?->price->product)
                                            ? $item?->price->product
                                            : ($item?->price->product?->name ?? 'StratFlow'),
            ];
        }
        return $out;
    }

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

    /**
     * Create a Stripe Customer Portal session for self-service billing.
     *
     * Allows customers to update payment methods, view invoices,
     * cancel subscriptions, and change seat quantities.
     *
     * @param string $customerId Stripe customer ID
     * @param string $returnUrl  URL to return to after portal session
     * @return string            Portal session URL to redirect to
     */
    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        \Stripe\Stripe::setApiKey($this->config['secret_key']);

        $session = \Stripe\BillingPortal\Session::create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Retrieve subscription details including current quantity (seats).
     *
     * @param string $subscriptionId Stripe subscription ID
     * @return array Subscription data
     */
    public function getSubscription(string $subscriptionId): array
    {
        \Stripe\Stripe::setApiKey($this->config['secret_key']);
        $sub = \Stripe\Subscription::retrieve($subscriptionId);

        return [
            'id'              => $sub->id,
            'status'          => $sub->status,
            'current_period_start' => date('Y-m-d', $sub->current_period_start),
            'current_period_end'   => date('Y-m-d', $sub->current_period_end),
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'quantity'        => $sub->items->data[0]->quantity ?? 1,
            'price_id'        => $sub->items->data[0]->price->id ?? '',
            'unit_amount'     => $sub->items->data[0]->price->unit_amount ?? 0,
            'currency'        => $sub->items->data[0]->price->currency ?? 'usd',
            'interval'        => $sub->items->data[0]->price->recurring->interval ?? 'month',
        ];
    }
}
