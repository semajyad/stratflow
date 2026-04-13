<?php
/**
 * UploadController
 *
 * Handles the document upload page (GET /app/upload) and file/text
 * submission (POST /app/upload). Supports both file uploads (TXT, PDF,
 * DOCX) and manual text paste. Text is extracted immediately on upload
 * via FileProcessor and stored alongside the document record.
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\RateLimiter;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Document;
use StratFlow\Models\Project;
use StratFlow\Security\ProjectPolicy;
use StratFlow\Services\AuditLogger;
use StratFlow\Services\FileProcessor;
use StratFlow\Services\GeminiService;
use StratFlow\Services\Prompts\SummaryPrompt;

class UploadController
{
    protected Request       $request;
    protected Response      $response;
    protected Auth          $auth;
    protected Database      $db;
    protected array         $config;

    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    // ===========================
    // ACTIONS
    // ===========================

    /**
     * Render the upload page for a specific project.
     *
     * Loads the project by query-string project_id, enforces org-level
     * multi-tenancy, then renders the upload template with any existing
     * documents for that project.
     */
    public function index(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->get('project_id', 0);

        $project = ProjectPolicy::findViewableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $documents = Document::findByProjectId($this->db, $projectId);

        $this->response->render('upload', [
            'user'          => $user,
            'project'       => $project,
            'documents'     => $documents,
            'active_page'   => 'upload',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Handle document upload or text paste submission.
     *
     * Verifies org access, then either:
     *   - processes an uploaded file (validate → store → extract text), or
     *   - uses pasted text directly as extracted_text.
     * Creates a document record and redirects back with a success flash.
     */
    public function store(): void
    {
        $user      = $this->auth->user();
        $projectId = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        // Rate limit file uploads: 10 per hour per user
        $userId = (string) $user['id'];
        if (!RateLimiter::check($this->db, RateLimiter::FILE_UPLOAD, $userId, 50, 3600)) {
            $_SESSION['flash_error'] = 'Upload rate limit reached. Please try again later.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        $pasteText = trim((string) $this->request->post('paste_text', ''));
        $uploadedFile = $this->request->file('document');

        // Determine if we have a file upload or a text paste
        $hasFile = $uploadedFile !== null && $uploadedFile['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$hasFile && $pasteText === '') {
            $_SESSION['flash_error'] = 'Please upload a file or paste text before submitting.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        $processor     = new FileProcessor();
        $processor->setConfig($this->config);
        $uploadDir     = dirname(__DIR__, 3) . '/public/uploads/';
        $extractedText = '';
        $filename      = 'pasted-text';
        $originalName  = 'Pasted Text';
        $mimeType      = 'text/plain';
        $fileSize      = 0;

        // Ensure uploads directory exists
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        if ($hasFile) {
            $validation = $processor->validateFile($uploadedFile, $this->config);
            if (!$validation['valid']) {
                $_SESSION['flash_error'] = $validation['error'];
                $this->response->redirect('/app/upload?project_id=' . $projectId);
                return;
            }

            try {
                $filename      = $processor->storeFile($uploadedFile, $uploadDir);
                $originalName  = $uploadedFile['name'];
                $mimeType      = $uploadedFile['type'];
                $fileSize      = (int) $uploadedFile['size'];
                $extractedText = $processor->extractText($uploadDir . $filename, $mimeType);
            } catch (\Throwable $e) {
                \StratFlow\Services\Logger::warn("[StratFlow] Upload processing error: " . $e->getMessage());
                $_SESSION['flash_error'] = 'Failed to process uploaded file. Please try a different file or paste the text directly.';
                $this->response->redirect('/app/upload?project_id=' . $projectId);
                return;
            }
        } else {
            // Text paste — treat as a virtual plain-text document
            $extractedText = $pasteText;
            $fileSize      = strlen($pasteText);
        }

        Document::create($this->db, [
            'project_id'     => $projectId,
            'filename'       => $filename,
            'original_name'  => $originalName,
            'mime_type'      => $mimeType,
            'file_size'      => $fileSize,
            'extracted_text' => $extractedText !== '' ? $extractedText : null,
            'uploaded_by'    => (int) $user['id'],
        ]);

        RateLimiter::record($this->db, RateLimiter::FILE_UPLOAD, $userId);

        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::DOCUMENT_UPLOADED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'project_id'    => $projectId,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'file_size'     => $fileSize,
        ]);

        if ($extractedText !== '') {
            $_SESSION['flash_message'] = 'Document uploaded and text extracted. You can now generate an AI summary.';
        } else {
            $_SESSION['flash_error'] = 'Document uploaded but text could not be extracted from this PDF. Try a different format (DOCX or TXT) or paste the text directly.';
        }
        $this->response->redirect('/app/upload?project_id=' . $projectId);
    }

    /**
     * Generate an AI summary for an uploaded document via Gemini.
     *
     * Loads the document by POST document_id, verifies org access through its
     * project, then calls GeminiService with the extracted text. On success the
     * ai_summary column is updated and the user is redirected back to the upload
     * page with a success flash. Redirects with an error flash if the document is
     * missing, inaccessible, or has no extracted text to summarise.
     */
    public function generateSummary(): void
    {
        $user       = $this->auth->user();
        $documentId = (int) $this->request->post('document_id', 0);
        $projectId  = (int) $this->request->post('project_id', 0);

        $project = ProjectPolicy::findEditableProject($this->db, $user, $projectId);
        if ($project === null) {
            $this->response->redirect('/app/home');
            return;
        }

        $document = Document::findById($this->db, $documentId);
        if ($document === null || (int) $document['project_id'] !== $projectId) {
            $_SESSION['flash_error'] = 'Document not found.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        if (empty($document['extracted_text'])) {
            $_SESSION['flash_error'] = 'Cannot summarise a document with no extracted text.';
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        try {
            $gemini    = new GeminiService($this->config);
            $aiSummary = $gemini->generate(SummaryPrompt::PROMPT, $document['extracted_text']);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'AI summary generation failed: ' . $e->getMessage();
            $this->response->redirect('/app/upload?project_id=' . $projectId);
            return;
        }

        Document::update($this->db, $documentId, ['ai_summary' => $aiSummary]);

        $_SESSION['flash_message'] = 'AI summary generated successfully.';
        $this->response->redirect('/app/upload?project_id=' . $projectId);
    }
}
