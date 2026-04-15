<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\SystemSettings;

/**
 * SystemSettingsTest
 *
 * Unit tests for the SystemSettings model — all DB calls mocked.
 */
class SystemSettingsTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeDb(?array $fetchRow = null): Database
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetchRow ?? false);
        $stmt->method('fetchAll')->willReturn($fetchRow ? [$fetchRow] : []);
        $stmt->method('rowCount')->willReturn($fetchRow ? 1 : 0);

        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn($stmt);
        $db->method('lastInsertId')->willReturn('1');
        return $db;
    }

    private function settingsRow(array $overrides = []): array
    {
        $defaults = [
            'id'            => 1,
            'settings_json' => json_encode([
                'ai_provider'            => 'google',
                'ai_model'               => 'gemini-3-flash-preview',
                'default_seat_limit'     => 5,
                'default_plan_type'      => 'product',
                'support_email'          => 'support@stratflow.io',
                'quality_threshold'      => 70,
            ]),
            'updated_at'    => '2024-01-15 10:30:00',
        ];
        return array_merge($defaults, $overrides);
    }

    // ===========================
    // GET - DEFAULTS
    // ===========================

    #[Test]
    public function getReturnsDefaultsWhenTableEmpty(): void
    {
        $db       = $this->makeDb(null);
        $settings = SystemSettings::get($db);

        $this->assertIsArray($settings);
        $this->assertSame('google', $settings['ai_provider']);
        $this->assertSame('gemini-3-flash-preview', $settings['ai_model']);
        $this->assertSame(5, $settings['default_seat_limit']);
        $this->assertSame('product', $settings['default_plan_type']);
    }

    #[Test]
    public function getReturnsDefaultsOnDatabaseException(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willThrowException(new \Exception('DB error'));

        $settings = SystemSettings::get($db);

        $this->assertIsArray($settings);
        $this->assertSame('google', $settings['ai_provider']);
    }

    #[Test]
    public function getReturnsMergedValuesFromDatabase(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => json_encode([
                'ai_provider' => 'custom-provider',
                'quality_threshold' => 85,
            ]),
        ]));

        $settings = SystemSettings::get($db);

        $this->assertSame('custom-provider', $settings['ai_provider']);
        $this->assertSame(85, $settings['quality_threshold']);
        $this->assertSame(5, $settings['default_seat_limit']); // from defaults
    }

    #[Test]
    public function getPreservesAllDefaultKeys(): void
    {
        $db = $this->makeDb(null);
        $settings = SystemSettings::get($db);

        $expectedKeys = [
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
            'default_price_per_seat_cents',
            'billing_currency',
            'billing_rate_monthly_cents',
            'billing_rate_annual_cents',
            'workflow_personas_json',
            'evaluation_levels_json',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $settings);
        }
    }

    // ===========================
    // GET - GEMINI MODEL ENFORCEMENT
    // ===========================

    #[Test]
    public function getEnforcesGeminiFlashPreviewForGoogleProvider(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => json_encode([
                'ai_provider' => 'google',
                'ai_model'    => 'invalid-model',
            ]),
        ]));

        $settings = SystemSettings::get($db);

        $this->assertSame('gemini-3-flash-preview', $settings['ai_model']);
    }

    #[Test]
    public function getAcceptsGemini15ProForGoogleProvider(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => json_encode([
                'ai_provider' => 'google',
                'ai_model'    => 'gemini-1.5-pro',
            ]),
        ]));

        $settings = SystemSettings::get($db);

        $this->assertSame('gemini-1.5-pro', $settings['ai_model']);
    }

    #[Test]
    public function getAcceptsGemini15FlashForGoogleProvider(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => json_encode([
                'ai_provider' => 'google',
                'ai_model'    => 'gemini-1.5-flash',
            ]),
        ]));

        $settings = SystemSettings::get($db);

        $this->assertSame('gemini-1.5-flash', $settings['ai_model']);
    }

    #[Test]
    public function getNonGoogleProviderSkipsModelValidation(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => json_encode([
                'ai_provider' => 'openai',
                'ai_model'    => 'gpt-4',
            ]),
        ]));

        $settings = SystemSettings::get($db);

        $this->assertSame('openai', $settings['ai_provider']);
        $this->assertSame('gpt-4', $settings['ai_model']);
    }

    // ===========================
    // GET - INVALID JSON
    // ===========================

    #[Test]
    public function getHandlesInvalidJsonGracefully(): void
    {
        $db = $this->makeDb($this->settingsRow([
            'settings_json' => 'invalid json {{{',
        ]));

        $settings = SystemSettings::get($db);

        // Should fall back to defaults when JSON decode fails
        $this->assertIsArray($settings);
        $this->assertSame('google', $settings['ai_provider']);
    }

    // ===========================
    // SAVE
    // ===========================

    #[Test]
    public function saveFiltersByAllowedKeys(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                // Capture last call (the INSERT ... ON DUPLICATE KEY UPDATE)
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        SystemSettings::save($db, [
            'ai_provider'  => 'custom',
            'disallowed_key' => 'should_be_filtered',
        ]);

        $this->assertNotNull($capturedParams);
        $decoded = json_decode($capturedParams[':json_insert'], true);
        $this->assertSame('custom', $decoded['ai_provider']);
        $this->assertArrayNotHasKey('disallowed_key', $decoded);
    }

    #[Test]
    public function saveMergesWithExistingSettings(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);

        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'SELECT')) {
                    // Return the settings row on SELECT
                    $settingsStmt = $this->createMock(\PDOStatement::class);
                    $settingsStmt->method('fetch')->willReturn($this->settingsRow([
                        'settings_json' => json_encode([
                            'ai_provider'       => 'google',
                            'quality_threshold' => 70,
                        ]),
                    ]));
                    return $settingsStmt;
                }
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        SystemSettings::save($db, [
            'quality_threshold' => 85,
        ]);

        $this->assertNotNull($capturedParams);
        $decoded = json_decode($capturedParams[':json_insert'], true);
        // New value
        $this->assertSame(85, $decoded['quality_threshold']);
        // Existing value preserved
        $this->assertSame('google', $decoded['ai_provider']);
    }

    #[Test]
    public function saveWritesAllAllowedKeys(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        $allowedData = [
            'ai_provider'              => 'custom',
            'ai_model'                 => 'custom-model',
            'default_seat_limit'       => 10,
            'quality_threshold'        => 80,
            'support_email'            => 'custom@example.com',
            'feature_sounding_board'   => false,
        ];

        SystemSettings::save($db, $allowedData);

        $this->assertNotNull($capturedParams);
        $decoded = json_decode($capturedParams[':json_insert'], true);

        foreach ($allowedData as $key => $value) {
            $this->assertArrayHasKey($key, $decoded);
            $this->assertSame($value, $decoded[$key]);
        }
    }

    #[Test]
    public function saveWithEmptyDataPreservesExisting(): void
    {
        $capturedParams = null;
        $selectCount = 0;

        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);

        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams, &$selectCount): \PDOStatement {
                if (str_contains($sql, 'SELECT')) {
                    $selectCount++;
                    // Return the settings row on SELECT
                    $settingsStmt = $this->createMock(\PDOStatement::class);
                    $settingsStmt->method('fetch')->willReturn($this->settingsRow([
                        'settings_json' => json_encode([
                            'ai_provider'       => 'custom-provider',
                            'quality_threshold' => 75,
                        ]),
                    ]));
                    return $settingsStmt;
                }
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        SystemSettings::save($db, []);

        $this->assertNotNull($capturedParams);
        $decoded = json_decode($capturedParams[':json_insert'], true);
        // Existing values preserved
        $this->assertSame('custom-provider', $decoded['ai_provider']);
        $this->assertSame(75, $decoded['quality_threshold']);
    }

    #[Test]
    public function saveSetsJsonForBothInsertAndUpdate(): void
    {
        $capturedParams = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->expects($this->atLeastOnce())->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$capturedParams): \PDOStatement {
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $capturedParams = $params;
                }
                return $stmt;
            }
        );

        SystemSettings::save($db, [
            'ai_provider' => 'test-provider',
        ]);

        $this->assertNotNull($capturedParams);
        // Both :json_insert and :json_update should have same value
        $this->assertSame($capturedParams[':json_insert'], $capturedParams[':json_update']);
    }

    // ===========================
    // INTEGRATION - GET + SAVE
    // ===========================

    #[Test]
    public function saveAndGetRoundTrip(): void
    {
        $saved = null;
        $stmt = $this->createMock(\PDOStatement::class);
        $db   = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($stmt, &$saved): \PDOStatement {
                if (str_contains($sql, 'INSERT INTO system_settings')) {
                    $saved = json_decode($params[':json_insert'], true);
                }
                // Mock empty read before save
                if (str_contains($sql, 'SELECT')) {
                    $getStmt = $this->createMock(\PDOStatement::class);
                    $getStmt->method('fetch')->willReturn(false);
                    return $getStmt;
                }
                return $stmt;
            }
        );

        SystemSettings::save($db, [
            'quality_threshold' => 90,
            'support_email'     => 'newmail@example.com',
        ]);

        $this->assertNotNull($saved);
        $this->assertSame(90, $saved['quality_threshold']);
        $this->assertSame('newmail@example.com', $saved['support_email']);
    }
}
