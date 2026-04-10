-- Migration 027: Project admin flag
-- Allows granting project management access (create/rename/delete/link Jira)
-- independently of the org_admin role. Org admins get it by default.

ALTER TABLE users
    ADD COLUMN is_project_admin TINYINT(1) NOT NULL DEFAULT 0
    AFTER has_billing_access;

-- Org admins are project admins by default
UPDATE users SET is_project_admin = 1 WHERE role = 'org_admin';
