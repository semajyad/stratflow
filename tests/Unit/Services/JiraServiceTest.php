<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\JiraService;

/**
 * JiraServiceTest
 *
 * Unit tests for JiraService — tests pure logic methods that do not
 * require network access (OAuth URL building, ADF conversion, helper
 * computations). HTTP-touching methods are tested via integration tests.
 */
class JiraServiceTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeService(array $configOverride = [], ?array $integration = null): JiraService
    {
        $config = array_merge([
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri'  => 'https://example.com/callback',
        ], $configOverride);
        return new JiraService($config, $integration);
    }

    // ===========================
    // AUTHORIZATION URL
    // ===========================

    #[Test]
    public function getAuthorizationUrlContainsClientId(): void
    {
        $svc = $this->makeService();
        $url = $svc->getAuthorizationUrl('state123');
        $this->assertStringContainsString('client_id=test-client-id', $url);
    }

    #[Test]
    public function getAuthorizationUrlContainsState(): void
    {
        $svc = $this->makeService();
        $url = $svc->getAuthorizationUrl('csrf_state_xyz');
        $this->assertStringContainsString('state=csrf_state_xyz', $url);
    }

    #[Test]
    public function getAuthorizationUrlContainsConsentPrompt(): void
    {
        $svc = $this->makeService();
        $url = $svc->getAuthorizationUrl('s');
        $this->assertStringContainsString('prompt=consent', $url);
    }

    #[Test]
    public function getAuthorizationUrlContainsRedirectUri(): void
    {
        $svc = $this->makeService(['redirect_uri' => 'https://app.example.com/oauth']);
        $url = $svc->getAuthorizationUrl('s');
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('app.example.com', $url);
    }

    #[Test]
    public function getAuthorizationUrlPointsToAtlassian(): void
    {
        $svc = $this->makeService();
        $url = $svc->getAuthorizationUrl('s');
        $this->assertStringStartsWith('https://auth.atlassian.com/authorize', $url);
    }

    #[Test]
    public function getAuthorizationUrlContainsResponseTypeCode(): void
    {
        $svc = $this->makeService();
        $url = $svc->getAuthorizationUrl('s');
        $this->assertStringContainsString('response_type=code', $url);
    }

    // ===========================
    // ADF CONVERSION — textToAdf
    // ===========================

    #[Test]
    public function textToAdfReturnsDocType(): void
    {
        $svc = $this->makeService();
        $adf = $svc->textToAdf('Hello world');
        $this->assertSame('doc', $adf['type']);
        $this->assertSame(1, $adf['version']);
    }

    #[Test]
    public function textToAdfWrapsTextInParagraph(): void
    {
        $svc  = $this->makeService();
        $adf  = $svc->textToAdf('Hello world');
        $para = $adf['content'][0];
        $this->assertSame('paragraph', $para['type']);
        $this->assertSame('text', $para['content'][0]['type']);
        $this->assertSame('Hello world', $para['content'][0]['text']);
    }

    #[Test]
    public function textToAdfCreatesMultipleParagraphsForMultilineInput(): void
    {
        $svc = $this->makeService();
        $adf = $svc->textToAdf("Line 1\nLine 2\nLine 3");
        $this->assertCount(3, $adf['content']);
    }

    #[Test]
    public function textToAdfCreatesEmptyParagraphForBlankLine(): void
    {
        $svc      = $this->makeService();
        $adf      = $svc->textToAdf("Line 1\n\nLine 3");
        $emptyPara = $adf['content'][1];
        $this->assertSame('paragraph', $emptyPara['type']);
        $this->assertEmpty($emptyPara['content']);
    }

    // ===========================
    // ADF CONVERSION — adfToText
    // ===========================

    #[Test]
    public function adfToTextExtractsPlainText(): void
    {
        $svc = $this->makeService();
        $adf = [
            'version' => 1,
            'type'    => 'doc',
            'content' => [
                [
                    'type'    => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello'],
                    ],
                ],
            ],
        ];
        $text = $svc->adfToText($adf);
        $this->assertStringContainsString('Hello', $text);
    }

    #[Test]
    public function adfToTextAddsNewlineAfterParagraph(): void
    {
        $svc = $this->makeService();
        $adf = [
            'version' => 1,
            'type'    => 'doc',
            'content' => [
                [
                    'type'    => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Paragraph 1']],
                ],
                [
                    'type'    => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Paragraph 2']],
                ],
            ],
        ];
        $text = $svc->adfToText($adf);
        $this->assertStringContainsString("Paragraph 1\n", $text);
    }

    #[Test]
    public function adfToTextHandlesEmptyDoc(): void
    {
        $svc  = $this->makeService();
        $text = $svc->adfToText(['version' => 1, 'type' => 'doc', 'content' => []]);
        $this->assertSame('', $text);
    }

    // ===========================
    // ADF ROUND-TRIP
    // ===========================

    #[Test]
    public function adfRoundTripPreservesTextContent(): void
    {
        $svc      = $this->makeService();
        $original = 'First line';
        $adf      = $svc->textToAdf($original);
        $text     = trim($svc->adfToText($adf));
        $this->assertSame($original, $text);
    }

    // ===========================
    // CONSTRUCTOR HANDLES MISSING KEYS
    // ===========================

    #[Test]
    public function constructorHandlesMissingConfigKeysGracefully(): void
    {
        // Should not throw even with an empty config
        $svc = new JiraService([]);
        $url = $svc->getAuthorizationUrl('s');
        $this->assertStringContainsString('authorize', $url);
    }

    // ===========================
    // makeAuthenticatedRequest without integration
    // ===========================

    #[Test]
    public function getProjectsThrowsWithoutIntegration(): void
    {
        $this->expectException(\RuntimeException::class);
        $svc = $this->makeService();
        $svc->getProjects();
    }
}
