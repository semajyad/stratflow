-- Migration 042: Enable Evaluation Board for all existing subscriptions
-- Changes the column default to 1 (on for all new subscriptions) and
-- backfills all existing rows so current orgs gain access immediately.

ALTER TABLE subscriptions
    MODIFY COLUMN has_evaluation_board TINYINT(1) NOT NULL DEFAULT 1;

UPDATE subscriptions SET has_evaluation_board = 1;
