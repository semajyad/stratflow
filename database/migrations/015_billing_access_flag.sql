-- Billing access as a flag (any role can have it)
ALTER TABLE users ADD COLUMN has_billing_access TINYINT(1) NOT NULL DEFAULT 0;
