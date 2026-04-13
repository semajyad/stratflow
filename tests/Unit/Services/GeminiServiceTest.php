<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\GeminiService;

/**
 * GeminiServiceTest
 *
 * Tests that GeminiService can be instantiated and that calling generate()
 * with an invalid API key throws a RuntimeException (API rejects the request).
 * Network tests are grouped 'external' so they can be skipped in offline environments.
 */
class GeminiServiceTest extends TestCase
{
    // ===========================
    // CONFIG HELPERS
    // ===========================

    private function makeConfig(string $apiKey = 'INVALID_KEY_FOR_TESTING'): array
    {
        return [
            'gemini' => [
                'api_key' => $apiKey,
                'model'   => 'gemini-3-flash-preview',
            ],
        ];
    }

    // ===========================
    // INSTANTIATION
    // ===========================

    #[Test]
    public function testServiceCanBeInstantiated(): void
    {
        $service = new GeminiService($this->makeConfig());
        $this->assertInstanceOf(GeminiService::class, $service);
    }

    #[Test]
    public function testServiceCanBeInstantiatedWithDifferentModels(): void
    {
        $config = [
            'gemini' => [
                'api_key' => 'test_key',
                'model'   => 'gemini-3-flash-preview',
            ],
        ];

        $service = new GeminiService($config);
        $this->assertInstanceOf(GeminiService::class, $service);
    }

    // ===========================
    // NETWORK / API ERRORS
    // ===========================

    #[Test]
    #[Group('external')]
    public function testGenerateThrowsOnInvalidApiKey(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = new GeminiService($this->makeConfig('INVALID_KEY_FOR_TESTING'));
        $service->generate('Summarise this text.', 'Hello world.');
    }

    #[Test]
    #[Group('external')]
    public function testGenerateJsonThrowsOnInvalidApiKey(): void
    {
        $this->expectException(\RuntimeException::class);

        $service = new GeminiService($this->makeConfig('INVALID_KEY_FOR_TESTING'));
        $service->generateJson('Return JSON with key "result".', 'Hello world.');
    }
}
