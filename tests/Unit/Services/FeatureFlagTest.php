<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\FeatureFlag;

/**
 * FeatureFlagTest
 *
 * All tests use setFeaturesForTesting() to bypass HTTP — no GrowthBook
 * instance or network required. clearTestFeatures() in tearDown prevents
 * bleed between tests.
 */
#[Group('unit')]
class FeatureFlagTest extends TestCase
{
    // ===========================
    // FIXTURES
    // ===========================

    private function config(bool $enabled = true): array
    {
        return [
            'api_host'   => 'http://localhost:3101',
            'client_key' => 'sdk-test-key',
            'enabled'    => $enabled,
        ];
    }

    protected function tearDown(): void
    {
        FeatureFlag::clearTestFeatures();
    }

    // ===========================
    // DISABLED SERVICE (no client key)
    // ===========================

    #[Test]
    public function testIsOnReturnsFalseWhenServiceDisabled(): void
    {
        $flags = new FeatureFlag($this->config(false));

        $this->assertFalse($flags->isOn('any-flag'));
    }

    #[Test]
    public function testGetValueReturnsDefaultWhenServiceDisabled(): void
    {
        $flags = new FeatureFlag($this->config(false));

        $this->assertSame('fallback', $flags->getValue('config-key', 'fallback'));
        $this->assertNull($flags->getValue('config-key'));
    }

    // ===========================
    // BOOLEAN FLAGS
    // ===========================

    #[Test]
    public function testIsOnReturnsTrueForEnabledFlag(): void
    {
        FeatureFlag::setFeaturesForTesting([
            'new-dashboard' => ['defaultValue' => true],
        ]);

        $flags = new FeatureFlag($this->config());

        $this->assertTrue($flags->isOn('new-dashboard'));
    }

    #[Test]
    public function testIsOnReturnsFalseForDisabledFlag(): void
    {
        FeatureFlag::setFeaturesForTesting([
            'new-dashboard' => ['defaultValue' => false],
        ]);

        $flags = new FeatureFlag($this->config());

        $this->assertFalse($flags->isOn('new-dashboard'));
    }

    #[Test]
    public function testIsOnReturnsFalseForAbsentFlag(): void
    {
        FeatureFlag::setFeaturesForTesting([]);

        $flags = new FeatureFlag($this->config());

        $this->assertFalse($flags->isOn('non-existent-flag'));
    }

    // ===========================
    // REMOTE CONFIGURATION VALUES
    // ===========================

    #[Test]
    public function testGetValueReturnsConfiguredString(): void
    {
        FeatureFlag::setFeaturesForTesting([
            'max-items-per-page' => ['defaultValue' => 50],
        ]);

        $flags = new FeatureFlag($this->config());

        $this->assertSame(50, $flags->getValue('max-items-per-page', 25));
    }

    #[Test]
    public function testGetValueReturnsDefaultForAbsentKey(): void
    {
        FeatureFlag::setFeaturesForTesting([]);

        $flags = new FeatureFlag($this->config());

        $this->assertSame('blue', $flags->getValue('button-color', 'blue'));
    }

    #[Test]
    public function testGetValueReturnsNullDefaultWhenUnset(): void
    {
        FeatureFlag::setFeaturesForTesting([]);

        $flags = new FeatureFlag($this->config());

        $this->assertNull($flags->getValue('missing-key'));
    }

    // ===========================
    // TARGETING ATTRIBUTES
    // ===========================

    #[Test]
    public function testFlagRespectOrgIdTargeting(): void
    {
        // Rule: only org 42 gets this flag
        FeatureFlag::setFeaturesForTesting([
            'beta-reporting' => [
                'defaultValue' => false,
                'rules'        => [
                    [
                        'condition'    => ['org_id' => 42],
                        'force'        => true,
                    ],
                ],
            ],
        ]);

        $flagsForOrg42 = new FeatureFlag($this->config(), ['org_id' => 42]);
        $flagsForOrg99 = new FeatureFlag($this->config(), ['org_id' => 99]);

        $this->assertTrue($flagsForOrg42->isOn('beta-reporting'));
        $this->assertFalse($flagsForOrg99->isOn('beta-reporting'));
    }

    // ===========================
    // TEST ISOLATION
    // ===========================

    #[Test]
    public function testSetFeaturesForTestingDoesNotBleedAcrossInstances(): void
    {
        FeatureFlag::setFeaturesForTesting(['flag-a' => ['defaultValue' => true]]);
        $first = new FeatureFlag($this->config());

        FeatureFlag::clearTestFeatures();

        FeatureFlag::setFeaturesForTesting(['flag-b' => ['defaultValue' => true]]);
        $second = new FeatureFlag($this->config());

        // flag-a was in first instance's features but second was created after clear
        $this->assertFalse($second->isOn('flag-a'));
        $this->assertTrue($second->isOn('flag-b'));
    }

    #[Test]
    public function testClearCacheResetsStaticFeatureCache(): void
    {
        FeatureFlag::setFeaturesForTesting(['my-flag' => ['defaultValue' => true]]);
        new FeatureFlag($this->config()); // populate internal cache

        FeatureFlag::clearTestFeatures();
        FeatureFlag::clearCache();

        // With no test features and empty cache, service is enabled but has no flags
        FeatureFlag::setFeaturesForTesting([]);
        $flags = new FeatureFlag($this->config());

        $this->assertFalse($flags->isOn('my-flag'));
    }
}
