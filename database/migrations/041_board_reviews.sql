-- database/migrations/041_board_reviews.sql
-- Per-review audit store for the Virtual Board Review feature.
-- content_snapshot captures the exact page content sent to Gemini for this review.
-- Project-wide governance baselines live separately in strategic_baselines (drift engine, migration 004).
CREATE TABLE board_reviews (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id       INT UNSIGNED NOT NULL,
    panel_id         INT UNSIGNED NOT NULL,
    board_type       ENUM('executive','product_management') NOT NULL,
    evaluation_level ENUM('devils_advocate','red_teaming','gordon_ramsay') NOT NULL,
    screen_context   VARCHAR(100) NOT NULL,
    content_snapshot MEDIUMTEXT NOT NULL,
    conversation_json   JSON NOT NULL,
    recommendation_json JSON NOT NULL,
    proposed_changes    JSON NOT NULL,
    status           ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    responded_by     INT UNSIGNED NULL,
    responded_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_screen    (project_id, screen_context),
    INDEX idx_project_board_type (project_id, board_type),
    FOREIGN KEY (project_id)   REFERENCES projects(id)        ON DELETE CASCADE,
    FOREIGN KEY (panel_id)     REFERENCES persona_panels(id)  ON DELETE CASCADE,
    FOREIGN KEY (responded_by) REFERENCES users(id)           ON DELETE SET NULL
);
