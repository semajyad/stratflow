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
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Document;
use StratFlow\Models\Project;
use StratFlow\Services\FileProcessor;

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
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->get('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
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
        $orgId     = (int) $user['org_id'];
        $projectId = (int) $this->request->post('project_id', 0);

        $project = Project::findById($this->db, $projectId, $orgId);
        if ($project === null) {
            $this->response->redirect('/app/home');
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
        $uploadDir     = dirname(__DIR__, 3) . '/public/uploads/';
        $extractedText = '';
        $filename      = 'pasted-text';
        $originalName  = 'Pasted Text';
        $mimeType      = 'text/plain';
        $fileSize      = 0;

        if ($hasFile) {
            $validation = $processor->validateFile($uploadedFile, $this->config);
            if (!$validation['valid']) {
                $_SESSION['flash_error'] = $validation['error'];
                $this->response->redirect('/app/upload?project_id=' . $projectId);
                return;
            }

            $filename      = $processor->storeFile($uploadedFile, $uploadDir);
            $originalName  = $uploadedFile['name'];
            $mimeType      = $uploadedFile['type'];
            $fileSize      = (int) $uploadedFile['size'];
            $extractedText = $processor->extractText($uploadDir . $filename, $mimeType);
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

        $_SESSION['flash_message'] = 'Document uploaded and text extracted successfully.';
        $this->response->redirect('/app/upload?project_id=' . $projectId);
    }
}
