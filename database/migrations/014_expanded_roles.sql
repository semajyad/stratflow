-- Expand user roles for enterprise RBAC
ALTER TABLE users MODIFY COLUMN role ENUM('viewer','user','project_manager','org_admin','billing_admin','superadmin') NOT NULL DEFAULT 'user';
