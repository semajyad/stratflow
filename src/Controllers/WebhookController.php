<?php
/**
 * WebhookController
 *
 * Handles incoming Stripe webhook events. This endpoint bypasses CSRF
 * middleware because Stripe sends raw POST requests signed with HMAC-SHA256.
 *
 * Supported events:
 *   - checkout.session.completed: creates or finds the Organisation and upserts
 *     a Subscription record. Handles subscription, user_pack, and evaluation_board
 *     product types. User packs increment the org's seat limit by 5; evaluation
 *     board purchases set has_evaluation_board = 1 on the subscription.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Organisation;
use StratFlow\Models\PasswordToken;
use StratFlow\Models\Subscription;
use StratFlow\Models\User;
use StratFlow\Services\EmailService;
use StratFlow\Services\StripeService;

class WebhookController
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
     * Receive and process a Stripe webhook event.
     *
     * Reads the raw POST body, verifies the Stripe-Signature header, then
     * dispatches based on the event type. Returns JSON with status 200 on
     * success or 400 on signature failure.
     */
    public function handle(): void
    {
        $payload   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        $stripe = new StripeService($this->config['stripe']);

        try {
            $event = $stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->response->json(['error' => 'Invalid signature'], 400);
            return;
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event->data->object, $stripe);
        }

        $this->response->json(['status' => 'ok'], 200);
    }

    // ===== Private Handlers =====

    /**
     * Process a completed checkout session.
     *
     * For subscription products: creates the Organisation (if new) and a Subscription record.
     * For user_pack: increments the org's active subscription seat limit by 5.
     * For evaluation_board: sets has_evaluation_board = 1 on the active subscription.
     *
     * @param \Stripe\Checkout\Session $session The completed checkout session object
     * @param StripeService            $stripe  StripeService instance for plan lookup
     */
    private function handleCheckoutCompleted(\Stripe\Checkout\Session $session, StripeService $stripe): void
    {
        // Re-retrieve the session with expanded customer_details and line_items
        // The webhook payload doesn't always include these
        try {
            $session = \Stripe\Checkout\Session::retrieve([
                'id' => $session->id,
                'expand' => ['customer_details', 'line_items', 'customer'],
            ]);
        } catch (\Throwable $e) {
            \StratFlow\Services\Logger::warn("[StratFlow] Failed to expand checkout session: " . $e->getMessage());
        }

        $customerEmail    = $this->extractCustomerEmail($session);
        $stripeCustomerId = $this->extractStripeCustomerId($session->customer ?? null);
        $stripeSubId      = $session->subscription ?? '';

        \StratFlow\Services\Logger::warn("[StratFlow] Checkout completed: email={$customerEmail}, customer={$stripeCustomerId}, sub={$stripeSubId}");

        // Determine plan type from the first line item price ID
        $planType = 'product';
        if (!empty($session->line_items)) {
            $firstPrice = $session->line_items->data[0]->price->id ?? '';
            $resolved   = $stripe->planTypeForPrice($firstPrice);
            if ($resolved !== 'unknown') {
                $planType = $resolved;
            }
        }

        // Handle add-on purchases (one-time payments tied to an existing org)
        if (in_array($planType, ['user_pack', 'evaluation_board'], true)) {
            $org = Organisation::findByStripeCustomerId($this->db, $stripeCustomerId);
            if ($org !== null) {
                $this->applyAddonToSubscription($org['id'], $planType);
            }
            return;
        }

        // Subscription flow: find or create the organisation
        $org = Organisation::findByStripeCustomerId($this->db, $stripeCustomerId);

        if ($org === null) {
            $orgName = $customerEmail ?: ('org-' . $stripeCustomerId);
            $orgId   = Organisation::create($this->db, [
                'name'               => $orgName,
                'stripe_customer_id' => $stripeCustomerId,
                'is_active'          => 1,
            ]);
        } else {
            $orgId = (int) $org['id'];
        }

        // Create the subscription record
        if (!empty($stripeSubId) && Subscription::findByStripeId($this->db, $stripeSubId) === null) {
            Subscription::create($this->db, [
                'org_id'                 => $orgId,
                'stripe_subscription_id' => $stripeSubId,
                'plan_type'              => $planType,
                'status'                 => 'active',
            ]);
        }

        // Create the first user (org admin) with a welcome email
        if (!empty($customerEmail)) {
            $existingUser = User::findByEmail($this->db, $customerEmail);

            if ($existingUser === null) {
                $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $displayName    = explode('@', $customerEmail)[0];

                $newUserId = User::create($this->db, [
                    'org_id'        => $orgId,
                    'full_name'     => $displayName,
                    'email'         => $customerEmail,
                    'password_hash' => $randomPassword,
                    'role'          => 'org_admin',
                ]);

                $token          = PasswordToken::create($this->db, $newUserId, 'set_password');
                $setPasswordUrl = rtrim($this->config['app']['url'], '/') . '/set-password/' . $token;

                $emailService = new EmailService($this->config);
                $sent = $emailService->sendWelcome($customerEmail, $displayName, $setPasswordUrl);
                \StratFlow\Services\Logger::warn("[StratFlow] Welcome email to {$customerEmail}: " . ($sent ? 'SENT' : 'FAILED'));
            }
        }
    }

    /**
     * Stripe may return the customer as either a string ID or an expanded object.
     */
    private function extractStripeCustomerId(mixed $customer): string
    {
        if (is_string($customer)) {
            return $customer;
        }

        if (is_object($customer) && isset($customer->id) && is_string($customer->id)) {
            return $customer->id;
        }

        return '';
    }

    /**
     * Prefer the checkout session's customer details, with safe fallbacks.
     */
    private function extractCustomerEmail(object $session): string
    {
        if (isset($session->customer_details->email) && is_string($session->customer_details->email)) {
            return $session->customer_details->email;
        }

        if (isset($session->customer_email) && is_string($session->customer_email)) {
            return $session->customer_email;
        }

        if (is_object($session->customer ?? null) && isset($session->customer->email) && is_string($session->customer->email)) {
            return $session->customer->email;
        }

        return '';
    }

    /**
     * Apply an add-on purchase to the organisation's active subscription.
     *
     * user_pack increments user_seat_limit by 5.
     * evaluation_board sets has_evaluation_board = 1.
     *
     * @param int    $orgId    Organisation primary key
     * @param string $planType 'user_pack' or 'evaluation_board'
     */
    private function applyAddonToSubscription(int $orgId, string $planType): void
    {
        $sub = Subscription::findByOrgId($this->db, $orgId);
        if ($sub === null) {
            return;
        }

        if ($planType === 'user_pack') {
            $newLimit = (int) $sub['user_seat_limit'] + 5;
            $this->db->query(
                "UPDATE subscriptions SET user_seat_limit = :limit WHERE id = :id",
                [':limit' => $newLimit, ':id' => (int) $sub['id']]
            );
        } elseif ($planType === 'evaluation_board') {
            $this->db->query(
                "UPDATE subscriptions SET has_evaluation_board = 1 WHERE id = :id",
                [':id' => (int) $sub['id']]
            );
        }
    }
}
