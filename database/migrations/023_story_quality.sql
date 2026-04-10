-- Migration 023: Story Quality Phase A
-- Adds acceptance_criteria + kr_hypothesis to work items and stories,
-- and creates the org-configurable story_quality_config table.

ALTER TABLE hl_work_items
  ADD COLUMN acceptance_criteria TEXT          NULL AFTER okr_description,
  ADD COLUMN kr_hypothesis        VARCHAR(500)  NULL AFTER acceptance_criteria;

ALTER TABLE user_stories
  ADD COLUMN acceptance_criteria TEXT          NULL AFTER description,
  ADD COLUMN kr_hypothesis        VARCHAR(500)  NULL AFTER acceptance_criteria;

CREATE TABLE IF NOT EXISTS story_quality_config (
  id            INT UNSIGNED                                     PRIMARY KEY AUTO_INCREMENT,
  org_id        INT UNSIGNED                                     NOT NULL,
  rule_type     ENUM('splitting_pattern','mandatory_condition')  NOT NULL,
  label         VARCHAR(255)                                     NOT NULL,
  is_default    TINYINT(1)                                       NOT NULL DEFAULT 0,
  is_active     TINYINT(1)                                       NOT NULL DEFAULT 1,
  display_order SMALLINT UNSIGNED                                NOT NULL DEFAULT 0,
  created_at    DATETIME                                         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_org_type (org_id, rule_type, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO story_quality_config (org_id, rule_type, label, is_default, display_order)
SELECT o.id, 'splitting_pattern', p.label, 1, p.ord
FROM organisations o
CROSS JOIN (
  SELECT 'SPIDR'               AS label, 1 AS ord UNION ALL
  SELECT 'Happy/Unhappy Path'  AS label, 2 AS ord UNION ALL
  SELECT 'User Role'           AS label, 3 AS ord UNION ALL
  SELECT 'Performance Tier'    AS label, 4 AS ord UNION ALL
  SELECT 'CRUD Operations'     AS label, 5 AS ord
) p
WHERE NOT EXISTS (
  SELECT 1 FROM story_quality_config sq
  WHERE sq.org_id = o.id AND sq.rule_type = 'splitting_pattern' AND sq.is_default = 1
);
