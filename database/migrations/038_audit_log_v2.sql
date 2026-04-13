-- Migration 038: AuditLogger v2
-- Adds org_id, resource_type, resource_id, prev_hash, row_hash to audit_logs.
-- Enables tamper-evident HMAC hash chain (keyed by AUDIT_HMAC_KEY env var)
-- and org-scoped filtering for SOC 2 / HIPAA audit report exports.

ALTER TABLE audit_logs
    ADD COLUMN org_id        INT UNSIGNED     NULL          AFTER user_id,
    ADD COLUMN resource_type VARCHAR(50)      NULL          AFTER details_json,
    ADD COLUMN resource_id   INT UNSIGNED     NULL          AFTER resource_type,
    ADD COLUMN prev_hash     VARCHAR(64)      NULL          AFTER resource_id,
    ADD COLUMN row_hash      VARCHAR(64)      NOT NULL DEFAULT '' AFTER prev_hash,
    ADD INDEX  idx_org_id    (org_id),
    ADD INDEX  idx_resource  (resource_type, resource_id),
    ADD FOREIGN KEY fk_audit_org (org_id) REFERENCES organisations(id) ON DELETE SET NULL;
