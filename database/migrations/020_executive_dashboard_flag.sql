-- Grant per-user access to the Executive Dashboard.
-- Default 0: no user gets access until an admin explicitly toggles it.
-- Superadmin always has access via ExecutiveMiddleware (no column check needed for them).
ALTER TABLE users ADD COLUMN has_executive_access TINYINT(1) NOT NULL DEFAULT 0;
