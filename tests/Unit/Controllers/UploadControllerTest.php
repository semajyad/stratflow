<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\UploadController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

/**
 * UploadControllerTest
 *
 * Tests for UploadController::index(), store(), and generateSummary().
 * - Project authorization (findViewableProject / findEditableProject)
 * - Missing project_id redirects home
 * - Document list rendering
 * - Flash messages for success/error
 */
final class UploadControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@test.invalid', 'is_active' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): UploadController
    {
        return new UploadController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        $s->method('fetch')->willReturn($fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    // ===========================
    // GET /app/upload (index)
    // ===========================

    #[Test]
    public function testIndexRedirectsHomeWhenProjectNotFound(): void
    {
        $req = $this->makeGetRequest(['project_id' => '999']);
        $this->ctrl($req)->index();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testIndexRendersUploadPageWhenProjectFound(): void
    {
        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                    $stmt->method('fetchAll')->willReturn([]);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        $req = $this->makeGetRequest(['project_id' => '1']);
        $this->ctrl($req)->index();

        $this->assertSame('upload', $this->response->renderedTemplate);
        $this->assertArrayHasKey('project', $this->response->renderedData);
        $this->assertArrayHasKey('documents', $this->response->renderedData);
    }

    #[Test]
    public function testIndexClearsFlashMessagesAfterRender(): void
    {
        $_SESSION['flash_message'] = 'Document uploaded successfully.';
        $_SESSION['flash_error'] = 'An error occurred.';

        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                    $stmt->method('fetchAll')->willReturn([]);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        $req = $this->makeGetRequest(['project_id' => '1']);
        $this->ctrl($req)->index();

        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // ===========================
    // POST /app/upload (store)
    // ===========================

    #[Test]
    public function testStoreRedirectsHomeWhenProjectNotFound(): void
    {
        $req = $this->makePostRequest(['project_id' => '999']);
        $this->ctrl($req)->store();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testStoreRequiresFileOrTextPaste(): void
    {
        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        // No file, no text — should redirect with error
        $req = $this->makePostRequest(['project_id' => '1', 'paste_text' => '']);
        $this->ctrl($req)->store();

        $this->assertStringContainsString('/app/upload', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('A file or text is required', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testStoreAcceptsTextPaste(): void
    {
        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        $req = $this->makePostRequest([
            'project_id' => '1',
            'paste_text' => 'This is pasted text content.',
        ]);
        $this->ctrl($req)->store();

        $this->assertStringContainsString('/app/upload', $this->response->redirectedTo ?? '');
    }

    // ===========================
    // POST /app/upload/generate-summary
    // ===========================

    #[Test]
    public function testGenerateSummaryRedirectsHomeWhenProjectNotFound(): void
    {
        $req = $this->makePostRequest([
            'document_id' => '1',
            'project_id' => '999',
        ]);
        $this->ctrl($req)->generateSummary();

        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    #[Test]
    public function testGenerateSummaryHandlesDocumentNotFound(): void
    {
        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        $req = $this->makePostRequest([
            'document_id' => '999',
            'project_id' => '1',
        ]);
        $this->ctrl($req)->generateSummary();

        $this->assertStringContainsString('/app/upload', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('Document not found', $_SESSION['flash_error'] ?? '');
    }

    #[Test]
    public function testGenerateSummaryHandlesEmptyExtractedText(): void
    {
        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 10];
        $document = [
            'id' => 1,
            'project_id' => 1,
            'filename' => 'test.pdf',
            'extracted_text' => null,
        ];

        $this->db->expects($this->any())
            ->method('query')
            ->willReturnCallback(function ($sql) use ($project, $document) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (strpos($sql, 'projects') !== false) {
                    $stmt->method('fetch')->willReturn($project);
                } elseif (strpos($sql, 'documents') !== false) {
                    $stmt->method('fetch')->willReturn($document);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                }
                return $stmt;
            });

        $req = $this->makePostRequest([
            'document_id' => '1',
            'project_id' => '1',
        ]);
        $this->ctrl($req)->generateSummary();

        $this->assertStringContainsString('/app/upload', $this->response->redirectedTo ?? '');
        $this->assertStringContainsString('no extracted text', $_SESSION['flash_error'] ?? '');
    }
}
