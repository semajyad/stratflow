-- Billing access as a flag, not a role. Any role can have billing access.
ALTER TABLE users ADD COLUMN has_billing_access TINYINT(1) NOT NULL DEFAULT 0;
-- Remove billing_admin from role ENUM since it's now a flag
ALTER TABLE users MODIFY COLUMN role ENUM('viewer','user','project_manager','org_admin','superadmin') NOT NULL DEFAULT 'user';
