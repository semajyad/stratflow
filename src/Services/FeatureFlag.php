<?php

/**
 * FeatureFlag
 *
 * Thin wrapper around the GrowthBook PHP SDK for feature flagging and A/B testing.
 *
 * Features are fetched from the self-hosted GrowthBook API once per request and
 * cached in a static array. If GrowthBook is unreachable the service falls back
 * gracefully — isOn() returns false, getValue() returns its default. Feature flags
 * must never break the application.
 *
 * Usage:
 *   // Check a boolean flag for the current user/org
 *   $flags = new FeatureFlag($config['growthbook'], ['org_id' => $orgId, 'user_id' => $userId]);
 *   if ($flags->isOn('new-dashboard')) { ... }
 *
 *   // Remote configuration value with fallback
 *   $limit = $flags->getValue('max-items-per-page', 25);
 *
 *   // Inject known features for testing (bypasses HTTP)
 *   FeatureFlag::setFeaturesForTesting(['new-dashboard' => ['defaultValue' => true]]);
 */

declare(strict_types=1);

namespace StratFlow\Services;

use Growthbook\Growthbook;

class FeatureFlag
{
    // ===========================
    // REQUEST-SCOPED FEATURE CACHE
    // ===========================

    /** @var array<string, array<string, mixed>> client_key → feature definitions */
    private static array $featureCache = [];

    /** @var array<string, mixed>|null Override for testing — bypasses all HTTP */
    private static ?array $testFeatures = null;

    // ===========================
    // INSTANCE STATE
    // ===========================

    private Growthbook $gb;
    private bool $enabled;

    // ===========================
    // CONSTRUCTION
    // ===========================

    /**
     * @param array{api_host: string, client_key: string, enabled?: bool} $config
     * @param array<string, mixed> $attributes  Targeting attributes (org_id, user_id, env, plan, …)
     */
    public function __construct(array $config, array $attributes = [])
    {
        $this->enabled = (!array_key_exists('enabled', $config) || (bool) $config['enabled'])
            && !empty($config['client_key']);

        $features = $this->enabled
            ? self::loadFeatures($config)
            : [];

        $this->gb = Growthbook::create()
            ->withFeatures($features)
            ->withAttributes($attributes);
    }

    // ===========================
    // EVALUATION
    // ===========================

    /**
     * Return true if the named feature flag is enabled for the current attributes.
     * Returns false if GrowthBook is disabled or the flag is absent.
     */
    public function isOn(string $flag): bool
    {
        if (!$this->enabled) {
            return false;
        }
        return $this->gb->isOn($flag);
    }

    /**
     * Return a remote configuration value, or $default if absent / GrowthBook disabled.
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }
        return $this->gb->getValue($key, $default);
    }

    // ===========================
    // FEATURE LOADING
    // ===========================

    /**
     * Fetch feature definitions from the GrowthBook API.
     * Results are cached per client_key for the lifetime of the PHP process / request.
     *
     * Falls back to an empty array (all flags off) if the API is unreachable.
     *
     * @param array{api_host: string, client_key: string} $config
     * @return array<string, mixed>
     */
    private static function loadFeatures(array $config): array
    {
        // Inject test fixtures without any HTTP
        if (self::$testFeatures !== null) {
            return self::$testFeatures;
        }

        $cacheKey = $config['client_key'];
        if (isset(self::$featureCache[$cacheKey])) {
            return self::$featureCache[$cacheKey];
        }

        $url     = rtrim($config['api_host'], '/') . '/api/features/' . urlencode($config['client_key']);
        $safeUrl = rtrim($config['api_host'], '/') . '/api/features/[redacted]';

        // @codeCoverageIgnoreStart
        $body  = null;
        $tries = 3;

        for ($i = 0; $i < $tries; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT        => 30,
                \CURLOPT_FAILONERROR    => false,
            ]);
            $response   = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError !== '') {
                Logger::warn('GrowthBook API unreachable — all flags defaulting off', ['url' => $safeUrl]);
                self::$featureCache[$cacheKey] = [];
                return [];
            }

            if ($httpStatus >= 500) {
                if ($i < $tries - 1) {
                    usleep(200_000 * (2 ** $i)); // 200ms, 400ms
                    continue;
                }
                Logger::warn('GrowthBook API returned 5xx after retries', ['url' => $safeUrl, 'status' => $httpStatus]);
                self::$featureCache[$cacheKey] = [];
                return [];
            }

            $body = $response;
            break;
        }

        $data = json_decode((string) $body, true);

        if (!is_array($data) || !isset($data['features'])) {
            Logger::warn('GrowthBook API returned unexpected response', [
                'url'    => $safeUrl,
                'status' => $httpStatus ?? 0,
            ]);
            self::$featureCache[$cacheKey] = [];
            return [];
        }

        self::$featureCache[$cacheKey] = $data['features'];
        return $data['features'];
        // @codeCoverageIgnoreEnd
    }

    // ===========================
    // TESTING HELPERS
    // ===========================

    /**
     * Inject feature definitions for unit tests, bypassing HTTP entirely.
     * Call clearTestFeatures() in tearDown to prevent bleed between tests.
     *
     * @param array<string, mixed> $features  e.g. ['my-flag' => ['defaultValue' => true]]
     */
    public static function setFeaturesForTesting(array $features): void
    {
        self::$testFeatures = $features;
        self::$featureCache = [];
    }

    /**
     * Remove test feature overrides and clear the request cache.
     */
    public static function clearTestFeatures(): void
    {
        self::$testFeatures = null;
        self::$featureCache = [];
    }

    /**
     * Clear the in-memory feature cache (useful between requests in long-running processes).
     */
    public static function clearCache(): void
    {
        self::$featureCache = [];
    }
}
