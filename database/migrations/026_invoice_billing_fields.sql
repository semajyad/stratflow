-- Migration 026: Invoice billing fields
-- Adds fields to subscriptions to support invoice-billed customers:
--   billing_method         — 'stripe' or 'invoice'
--   billing_period_months  — invoice cycle length (1 = monthly, 3 = quarterly, 12 = annual)
--   price_per_seat_cents   — rate per seat in cents (e.g. 5000 = $50.00)
--   next_invoice_date      — next invoice due date for invoice billing

ALTER TABLE subscriptions
    ADD COLUMN billing_method         ENUM('stripe','invoice') NOT NULL DEFAULT 'invoice'   AFTER stripe_subscription_id,
    ADD COLUMN billing_period_months  TINYINT UNSIGNED NOT NULL DEFAULT 1                   AFTER billing_method,
    ADD COLUMN price_per_seat_cents   INT UNSIGNED     NOT NULL DEFAULT 0                   AFTER billing_period_months,
    ADD COLUMN next_invoice_date      DATE NULL                                             AFTER price_per_seat_cents;

-- Back-fill billing_method from existing stripe_subscription_id pattern
UPDATE subscriptions
SET billing_method = CASE
    WHEN stripe_subscription_id LIKE 'sub_%'    THEN 'stripe'
    WHEN stripe_subscription_id LIKE 'manual_%' THEN 'invoice'
    ELSE 'invoice'
END;
