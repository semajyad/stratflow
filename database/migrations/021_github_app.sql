-- Migration 021: GitHub App integration
--
-- Adds GitHub App installation tracking to the integrations table and two
-- new tables: integration_repos (repos visible to an install) and
-- project_repo_links (many-to-many: stratflow projects <-> repos).
--
-- An org can have many GitHub App installations (one per GitHub account).
-- (provider, installation_id) is globally unique; (provider, org_id) is not.

ALTER TABLE integrations
  ADD COLUMN installation_id BIGINT UNSIGNED NULL AFTER config_json,
  ADD COLUMN account_login   VARCHAR(255)    NULL AFTER installation_id,
  ADD UNIQUE KEY uk_provider_installation (provider, installation_id),
  ADD KEY ix_org_provider_status (org_id, provider, status),
  MODIFY COLUMN status ENUM('active','paused','error','disconnected','inactive','revoked')
    NOT NULL DEFAULT 'disconnected';

-- Repos available to a GitHub App installation.
-- Populated on install callback and kept live via installation_repositories
-- webhook events.
CREATE TABLE integration_repos (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  integration_id  INT UNSIGNED     NOT NULL,
  org_id          INT UNSIGNED     NOT NULL,
  repo_github_id  BIGINT UNSIGNED  NOT NULL,
  repo_full_name  VARCHAR(255)     NOT NULL COMMENT 'e.g. acme-corp/hello-world',
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_integration_repo (integration_id, repo_github_id),
  UNIQUE KEY uk_org_repo_github  (org_id, repo_github_id),
  KEY        ix_org_repo_name    (org_id, repo_full_name),
  CONSTRAINT fk_ir_integration FOREIGN KEY (integration_id)
    REFERENCES integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: stratflow projects subscribe to specific repos.
-- A project can pull repos from multiple GitHub accounts.
-- A repo can serve multiple projects independently.
CREATE TABLE project_repo_links (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  project_id          INT UNSIGNED  NOT NULL,
  integration_repo_id INT UNSIGNED  NOT NULL,
  org_id              INT UNSIGNED  NOT NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by          INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_project_repo  (project_id, integration_repo_id),
  KEY        ix_org_project   (org_id, project_id),
  KEY        ix_repo          (integration_repo_id),
  CONSTRAINT fk_prl_project FOREIGN KEY (project_id)
    REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_prl_repo    FOREIGN KEY (integration_repo_id)
    REFERENCES integration_repos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
