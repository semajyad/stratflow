<?php

/**
 * SystemSettings Model
 *
 * Reads and writes the single-row system_settings table.
 * Settings are stored as a JSON object and surfaced as a flat PHP array.
 *
 * Usage:
 *   $settings = SystemSettings::get($db);       // returns flat array
 *   SystemSettings::save($db, $settings);        // merges and persists
 */

declare(strict_types=1);

namespace StratFlow\Models;

use StratFlow\Core\Database;

class SystemSettings
{
    /** Keys recognised by the settings form — prevents arbitrary injection. */
    private const ALLOWED_KEYS = [
        'ai_provider',
        'ai_model',
        'default_seat_limit',
        'default_plan_type',
        'default_billing_method',
        'feature_sounding_board',
        'feature_executive',
        'feature_xero',
        'feature_jira',
        'feature_github',
        'feature_gitlab',
        'feature_story_quality',
        'quality_threshold',
        'quality_enforcement',
        'support_email',
        'mail_from_name',
        // New org defaults
        'default_price_per_seat_cents',
        // Billing rates (price per seat in cents per period)
        'billing_currency',
        'billing_rate_monthly_cents',
        'billing_rate_annual_cents',
        // Workflow persona defaults
        'workflow_personas_json',
        // Critical review evaluation level prompts
        'evaluation_levels_json',
    ];
/** Safe fallback defaults returned when the table row is missing. */
    private const DEFAULTS = [
        'ai_provider'            => 'google',
        'ai_model'               => 'gemini-3-flash-preview',
        'default_seat_limit'     => 5,
        'default_plan_type'      => 'product',
        'default_billing_method' => 'invoiced',
        'feature_sounding_board' => true,
        'feature_executive'      => true,
        'feature_xero'           => true,
        'feature_jira'           => true,
        'feature_github'         => true,
        'feature_gitlab'         => true,
        'feature_story_quality'  => true,
        'quality_threshold'      => 70,
        'quality_enforcement'    => 'warn',
        'support_email'          => 'support@stratflow.io',
        'mail_from_name'         => 'StratFlow',
        // New org defaults
        'default_price_per_seat_cents'  => 0,
        // Billing rates
        'billing_currency'              => 'NZD',
        'billing_rate_monthly_cents'    => 0,
        'billing_rate_annual_cents'     => 0,
        // Workflow persona defaults
        'workflow_personas_json'        => '',
        // Critical review evaluation level prompts
        'evaluation_levels_json'        => '',
    ];
/**
     * Return current settings as a flat array, merged over hardcoded defaults.
     */
    public static function get(Database $db): array
    {
        try {
            $stmt = $db->query("SELECT settings_json FROM system_settings WHERE id = 1 LIMIT 1");
            $row  = $stmt->fetch();
        } catch (\Throwable) {
            return self::DEFAULTS;
        }

        if (!$row) {
            return self::DEFAULTS;
        }

        $stored = json_decode($row['settings_json'], true) ?: [];
        $merged = array_merge(self::DEFAULTS, $stored);
// Enforce gemini-3-flash-preview as the minimum model for Google provider
        if (($merged['ai_provider'] ?? '') === 'google') {
            $allowedGemini = ['gemini-3-flash-preview', 'gemini-1.5-pro', 'gemini-1.5-flash'];
            if (!in_array($merged['ai_model'], $allowedGemini)) {
                $merged['ai_model'] = 'gemini-3-flash-preview';
            }
        }

        return $merged;
    }

    /**
     * Persist settings. Only ALLOWED_KEYS are written.
     *
     * @param Database $db
     * @param array    $data Flat array of setting key → value
     */
    public static function save(Database $db, array $data): void
    {
        $current  = self::get($db);
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_KEYS));
        $merged   = array_merge($current, $filtered);
        $json = json_encode($merged);
        $db->query("INSERT INTO system_settings (id, settings_json) VALUES (1, :json_insert)
             ON DUPLICATE KEY UPDATE settings_json = :json_update, updated_at = NOW()", [':json_insert' => $json, ':json_update' => $json]);
    }
}
