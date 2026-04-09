---
name: stripe-webhook-handler
description: Invoke when adding a new Stripe event handler, modifying WebhookController, or debugging a Stripe webhook issue. Enforces signature verification, idempotency, CSRF exemption, and the idempotency-guard pattern using the stripe_events table.
allowed-tools: Read, Grep, Glob, Edit, Write, Bash
---

# Stripe Webhook Handler Patterns

Getting webhook handling wrong causes double-charges, missed subscriptions, or revenue loss. These rules are non-negotiable.

## Non-Negotiable Rules

1. **Signature verification is mandatory.** Every webhook invocation MUST call `\Stripe\Webhook::constructEvent($payload, $signature, $secret)`. If the call throws, respond 400 and return. No exceptions.

2. **The webhook route has NO `csrf` middleware.** This is the only POST route exempt from CSRF — signature verification replaces CSRF for server-to-server callbacks.

3. **Handlers must be idempotent.** Stripe delivers the same event multiple times. Use `event.id` as a dedupe key: check `stripe_events` table before processing, insert the id after.

4. **Return 200 quickly.** Long-running work (email, provisioning) happens after the response is sent or in a queued job. Slow handlers cause Stripe retries → duplicate processing.

5. **Never hit the live Stripe API from tests.** Use `sk_test_...` and `stripe trigger` to emit test events. The PreToolUse hook blocks curl calls to `api.stripe.com` without `sk_test_`.

## The Canonical Handler Shape

```php
public function handle(Request $req, Response $res): Response
{
    $payload   = $req->rawBody();
    $signature = $req->header('Stripe-Signature') ?? '';
    $secret    = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
    } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
        $this->auditLogger->warn('stripe.webhook.invalid_signature', ['err' => $e->getMessage()]);
        return $res->json(['error' => 'invalid signature'], 400);
    }

    // Idempotency guard
    if ($this->stripeEvents->exists($event->id)) {
        return $res->json(['ok' => true, 'deduped' => true], 200);
    }

    try {
        match ($event->type) {
            'checkout.session.completed'    => $this->onCheckoutCompleted($event->data->object),
            'customer.subscription.updated' => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_failed'        => $this->onPaymentFailed($event->data->object),
            default                         => $this->auditLogger->info('stripe.webhook.unhandled', ['type' => $event->type]),
        };
        $this->stripeEvents->markProcessed($event->id, $event->type);
    } catch (\Throwable $e) {
        $this->auditLogger->error('stripe.webhook.handler_failed', [
            'event_id' => $event->id,
            'type'     => $event->type,
            'err'      => $e->getMessage(),
        ]);
        return $res->json(['error' => 'handler failed'], 500);  // Stripe will retry
    }

    return $res->json(['ok' => true], 200);
}
```

## Adding a New Event Type

1. Add a `match` arm in `handle()`.
2. Implement a private `onXxx()` method.
3. Handler must be idempotent even if called twice for the same object.
4. Add integration test covering: happy path, invalid signature (400), duplicate event id (200 + no side-effect), handler exception (500).
5. Update `docs/API.md` with the new event type.

## Testing Locally

```bash
stripe listen --forward-to http://localhost:8890/webhooks/stripe
stripe trigger checkout.session.completed
```

## Forbidden Patterns

- ❌ `$event = json_decode($payload)` without `constructEvent` verification
- ❌ `try { constructEvent(...) } catch (\Exception $e) { $event = json_decode($payload); }`
- ❌ Processing without checking `stripe_events` for the event id first
- ❌ Hardcoding `STRIPE_WEBHOOK_SECRET` anywhere outside `.env`
