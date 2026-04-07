<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\FileProcessor;

/**
 * FileProcessorTest
 *
 * Tests file validation (good file passes, bad extension fails, oversized fails)
 * and plain-text extraction from the fixture file.
 */
class FileProcessorTest extends TestCase
{
    // ===========================
    // CONFIG
    // ===========================

    private FileProcessor $processor;

    /** Upload config that mirrors the real application defaults. */
    private array $config = [
        'upload' => [
            'max_size'           => 10485760, // 10 MB
            'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx'],
            'allowed_types'      => [
                'text/plain',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ],
    ];

    protected function setUp(): void
    {
        $this->processor = new FileProcessor();
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build a fake $_FILES entry.
     */
    private function makeFile(
        string $name,
        string $type,
        int    $size,
        int    $error = UPLOAD_ERR_OK
    ): array {
        return [
            'name'     => $name,
            'type'     => $type,
            'tmp_name' => '/tmp/fake',
            'error'    => $error,
            'size'     => $size,
        ];
    }

    // ===========================
    // VALIDATION — HAPPY PATH
    // ===========================

    #[Test]
    public function testValidTxtFilePasses(): void
    {
        $file   = $this->makeFile('strategy.txt', 'text/plain', 1024);
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['error']);
    }

    #[Test]
    public function testValidPdfFilePasses(): void
    {
        $file   = $this->makeFile('report.pdf', 'application/pdf', 2048);
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function testValidDocxFilePasses(): void
    {
        $file = $this->makeFile(
            'plan.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            4096
        );
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertTrue($result['valid']);
    }

    // ===========================
    // VALIDATION — ERROR CASES
    // ===========================

    #[Test]
    public function testDisallowedExtensionFails(): void
    {
        $file   = $this->makeFile('malware.exe', 'application/octet-stream', 100);
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not allowed', $result['error']);
    }

    #[Test]
    public function testOversizedFileFails(): void
    {
        $file   = $this->makeFile('huge.txt', 'text/plain', 20971520); // 20 MB
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds', $result['error']);
    }

    #[Test]
    public function testUploadErrorCodeFails(): void
    {
        $file   = $this->makeFile('file.txt', 'text/plain', 100, UPLOAD_ERR_NO_FILE);
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['error']);
    }

    #[Test]
    public function testDisallowedMimeTypeFails(): void
    {
        // Extension is fine but MIME is not permitted
        $file   = $this->makeFile('trick.txt', 'application/x-php', 512);
        $result = $this->processor->validateFile($file, $this->config);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('MIME', $result['error']);
    }

    // ===========================
    // TEXT EXTRACTION
    // ===========================

    #[Test]
    public function testExtractTextFromTxtFixture(): void
    {
        $fixturePath = __DIR__ . '/../../fixtures/sample.txt';
        $text        = $this->processor->extractText($fixturePath, 'text/plain');

        $this->assertStringContainsString('Q3 Strategy Planning Session', $text);
        $this->assertStringContainsString('microservices', $text);
    }

    #[Test]
    public function testExtractTextReturnsEmptyForUnknownMime(): void
    {
        $fixturePath = __DIR__ . '/../../fixtures/sample.txt';
        $text        = $this->processor->extractText($fixturePath, 'application/octet-stream');

        $this->assertSame('', $text);
    }

    #[Test]
    public function testExtractTextForBinaryDocReturnsUnsupportedMessage(): void
    {
        $fixturePath = __DIR__ . '/../../fixtures/sample.txt';
        $text        = $this->processor->extractText($fixturePath, 'application/msword');

        $this->assertStringContainsString('not supported', $text);
    }
}
