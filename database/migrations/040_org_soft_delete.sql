-- Migration 040: Organisation soft-delete
-- Adds deleted_at timestamp to organisations. When set, the org and all its
-- data are invisible to the application. After 30 days, bin/purge_deleted_orgs.php
-- permanently removes the data (audit logs retained per SOC2 7-year retention).

ALTER TABLE organisations
    ADD COLUMN deleted_at    DATETIME     NULL AFTER is_active,
    ADD COLUMN deletion_reason VARCHAR(255) NULL AFTER deleted_at,
    ADD INDEX  idx_deleted_at (deleted_at);
