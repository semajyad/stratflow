-- Smoke Test User Seed
--
-- Creates a read-only viewer account used by the ZAP authenticated scanner.
-- This user has minimal permissions: viewer role, no billing/executive access.
-- The org has no real data — only the user row exists.
--
-- IMPORTANT: This seed is for the TEST environment only. NEVER run on production.
--
-- Password hash: bcrypt of the ZAP_SMOKE_PASSWORD secret (generate with:
--   php -r "echo password_hash(getenv('ZAP_SMOKE_PASSWORD'), PASSWORD_DEFAULT);"
--
-- The password hash below is a placeholder. The actual hash must be generated
-- during the CI workflow using the ZAP_SMOKE_PASSWORD secret and inserted
-- via the workflow step, not committed here.
--
-- Org and user IDs use high values to avoid colliding with any fixture data.

-- Create smoke-test organisation (if not already present)
INSERT INTO organisations (id, name, slug, is_active, created_at)
VALUES (99999, 'ZAP Smoke Test Org', 'zap-smoke-test', 1, NOW())
ON DUPLICATE KEY UPDATE name = name;

-- Create smoke-test user (password is set by the CI workflow at runtime)
INSERT INTO users (
    id, org_id, email, full_name, role, is_active,
    has_billing_access, has_executive_access, is_project_admin,
    password_hash, created_at
)
VALUES (
    99999, 99999,
    'zap-smoke@stratflow-test.internal',
    'ZAP Scanner',
    'viewer',
    1, 0, 0, 0,
    -- placeholder; overwritten at runtime by CI workflow
    '$2y$10$placeholder.hash.will.be.replaced.by.ci.workflow.step',
    NOW()
)
ON DUPLICATE KEY UPDATE is_active = 1;
