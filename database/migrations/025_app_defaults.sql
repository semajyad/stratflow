-- Migration 025: App-wide system defaults table
-- Stores a single JSON row of system-wide configuration managed by superadmin.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS system_settings (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settings_json JSON NOT NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the single row with safe defaults
INSERT IGNORE INTO system_settings (id, settings_json) VALUES (1, JSON_OBJECT(
  'ai_provider',                  'google',
  'ai_model',                     'gemini-2.0-flash',
  'default_seat_limit',           5,
  'default_plan_type',            'product',
  'default_billing_method',       'invoiced',
  'feature_sounding_board',       true,
  'feature_executive',            true,
  'feature_xero',                 true,
  'feature_jira',                 true,
  'feature_github',               true,
  'feature_gitlab',               true,
  'feature_story_quality',        true,
  'quality_threshold',            70,
  'quality_enforcement',          'warn',
  'support_email',                'support@stratflow.io',
  'mail_from_name',               'StratFlow',
  'billing_currency',             'NZD',
  'billing_rate_monthly_cents',   0,
  'billing_rate_quarterly_cents', 0,
  'billing_rate_6monthly_cents',  0,
  'billing_rate_annual_cents',    0
));

SET FOREIGN_KEY_CHECKS = 1;
