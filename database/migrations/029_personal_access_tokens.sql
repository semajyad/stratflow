-- Migration 029: Personal Access Tokens
--
-- Adds a personal_access_tokens table so users can mint long-lived bearer
-- tokens for use by external tooling (e.g. the stratflow-mcp MCP server).
--
-- Tokens are never stored in plaintext — only a sha256 hex digest is kept.
-- The token_prefix (first 8 raw chars prefixed by "sf_pat_") allows the UI
-- to display a recognisable hint without revealing the secret.
--
-- Scopes is JSON-nullable for future fine-grained permissions;
-- NULL means "full read + story status transitions" for now.

CREATE TABLE personal_access_tokens (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED     NOT NULL,
  org_id         INT UNSIGNED     NOT NULL COMMENT 'Denormalised for fast middleware lookup',
  name           VARCHAR(100)     NOT NULL,
  token_hash     CHAR(64)         NOT NULL COMMENT 'sha256 hex of raw token',
  token_prefix   CHAR(15)         NOT NULL COMMENT 'sf_pat_ + first 8 raw chars, for UI display',
  scopes         JSON             NULL,
  last_used_at   DATETIME         NULL,
  last_used_ip   VARCHAR(45)      NULL,
  expires_at     DATETIME         NULL,
  revoked_at     DATETIME         NULL,
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token_hash    (token_hash),
  KEY        ix_user_revoked  (user_id, revoked_at),
  KEY        ix_org           (org_id),
  CONSTRAINT fk_pat_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pat_org FOREIGN KEY (org_id)
    REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
