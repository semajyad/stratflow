-- Migration 022: Key Results & KR Contributions
-- Adds key_results (one per KR, owned by an hl_work_item),
-- key_result_contributions (one per merged-PR × KR, AI-scored),
-- and ai_matched flag on story_git_links.

-- Step 1: Extend story_git_links with ai_matched flag.
-- Skipped silently on re-run (error 1060).
ALTER TABLE story_git_links
  ADD COLUMN ai_matched TINYINT(1) NOT NULL DEFAULT 0;

-- Step 2: Key results table.
CREATE TABLE IF NOT EXISTS key_results (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  org_id             INT UNSIGNED  NOT NULL,        -- denormalised, tenant scope enforced via hl_work_items cascade
  hl_work_item_id    INT UNSIGNED  NOT NULL,
  title              VARCHAR(500)  NOT NULL,
  metric_description TEXT          NULL,
  baseline_value     DECIMAL(12,4) NULL,
  target_value       DECIMAL(12,4) NULL,
  current_value      DECIMAL(12,4) NULL,
  unit               VARCHAR(50)   NULL,
  status             ENUM('not_started','on_track','at_risk','off_track','achieved')
                                   NOT NULL DEFAULT 'not_started',
  jira_goal_id       VARCHAR(255)  NULL,
  jira_goal_url      VARCHAR(500)  NULL,
  ai_momentum        TEXT          NULL,
  display_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_org (org_id),
  KEY ix_work_item (hl_work_item_id),
  CONSTRAINT fk_kr_work_item FOREIGN KEY (hl_work_item_id)
    REFERENCES hl_work_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: KR contributions table (one row per merged PR × KR).
CREATE TABLE IF NOT EXISTS key_result_contributions (
  id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  key_result_id       INT UNSIGNED     NOT NULL,
  story_git_link_id   INT UNSIGNED     NOT NULL,
  org_id              INT UNSIGNED     NOT NULL,        -- denormalised, tenant scope enforced via hl_work_items cascade
  ai_relevance_score  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ai_rationale        TEXT             NULL,
  scored_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_kr_link (key_result_id, story_git_link_id),
  KEY ix_org (org_id),
  KEY ix_story_git_link (story_git_link_id),
  CONSTRAINT fk_krc_kr   FOREIGN KEY (key_result_id)   REFERENCES key_results(id)    ON DELETE CASCADE,
  CONSTRAINT fk_krc_link FOREIGN KEY (story_git_link_id) REFERENCES story_git_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
