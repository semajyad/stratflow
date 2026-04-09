-- Expand user roles — idempotent (schema.sql already has latest ENUM)
-- This migration is a no-op if schema.sql was applied first
ALTER TABLE users MODIFY COLUMN role ENUM('viewer','user','project_manager','org_admin','superadmin') NOT NULL DEFAULT 'user';
