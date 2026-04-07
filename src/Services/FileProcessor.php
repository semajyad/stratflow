<?php
/**
 * FileProcessor Service
 *
 * Handles file validation, storage, and text extraction for uploaded documents.
 * Supports TXT, PDF, DOCX formats. PDF parsing uses smalot/pdfparser.
 * DOCX parsing uses PHP's built-in ZipArchive to read word/document.xml.
 *
 * Usage:
 *   $processor = new FileProcessor();
 *   $result    = $processor->validateFile($_FILES['document'], $config);
 *   $filename  = $processor->storeFile($_FILES['document'], '/var/www/html/public/uploads/');
 *   $text      = $processor->extractText($filePath, $mimeType);
 */

declare(strict_types=1);

namespace StratFlow\Services;

class FileProcessor
{
    // ===========================
    // VALIDATION
    // ===========================

    /**
     * Validate an uploaded file against configuration constraints.
     *
     * Checks PHP upload error code, file size, extension, and MIME type.
     *
     * @param array $file   Entry from $_FILES (name, type, tmp_name, error, size)
     * @param array $config Full app config array; uses $config['upload'] sub-key
     * @return array        ['valid' => bool, 'error' => string]
     */
    public function validateFile(array $file, array $config): array
    {
        $uploadConfig = $config['upload'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => $this->uploadErrorMessage($file['error'])];
        }

        if ($file['size'] > $uploadConfig['max_size']) {
            $maxMb = round($uploadConfig['max_size'] / 1048576, 1);
            return ['valid' => false, 'error' => "File exceeds maximum size of {$maxMb} MB."];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $uploadConfig['allowed_extensions'], true)) {
            $allowed = implode(', ', $uploadConfig['allowed_extensions']);
            return ['valid' => false, 'error' => "File type not allowed. Accepted: {$allowed}."];
        }

        if (!in_array($file['type'], $uploadConfig['allowed_types'], true)) {
            return ['valid' => false, 'error' => 'MIME type not permitted. Upload a TXT, PDF, DOC, or DOCX file.'];
        }

        return ['valid' => true, 'error' => ''];
    }

    // ===========================
    // STORAGE
    // ===========================

    /**
     * Move an uploaded file to the upload directory with a UUID-style filename.
     *
     * Generates a collision-resistant filename using random bytes + original extension.
     * Returns the stored filename (not the full path).
     *
     * @param array  $file      Entry from $_FILES
     * @param string $uploadDir Absolute path to the target upload directory (trailing slash optional)
     * @return string           The stored filename (e.g. "a3f9...2c.pdf")
     * @throws \RuntimeException If the file cannot be moved
     */
    public function storeFile(array $file, string $uploadDir): string
    {
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException("Failed to move uploaded file to storage.");
        }

        return $filename;
    }

    // ===========================
    // TEXT EXTRACTION
    // ===========================

    /**
     * Extract plain text content from a stored file based on its MIME type.
     *
     * - text/plain  → direct file_get_contents
     * - application/pdf → smalot/pdfparser
     * - DOCX        → ZipArchive reads word/document.xml, strip_tags()
     * - DOC (binary)→ returns a friendly unsupported message
     * - other       → returns empty string
     *
     * @param string $filePath Absolute path to the stored file
     * @param string $mimeType MIME type string
     * @return string          Extracted plain text
     */
    public function extractText(string $filePath, string $mimeType): string
    {
        switch ($mimeType) {
            case 'text/plain':
                return (string) file_get_contents($filePath);

            case 'application/pdf':
                return $this->extractPdfText($filePath);

            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return $this->extractDocxText($filePath);

            case 'application/msword':
                return 'Binary .doc format is not supported. Please convert to .docx or paste text manually.';

            default:
                return '';
        }
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Extract text from a PDF file using smalot/pdfparser.
     *
     * @param string $filePath Absolute path to the PDF
     * @return string          Extracted text, or empty string on failure
     */
    private function extractPdfText(string $filePath): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Extract text from a DOCX file via ZipArchive + XML tag stripping.
     *
     * DOCX files are ZIP archives; word/document.xml holds the body text.
     *
     * @param string $filePath Absolute path to the DOCX file
     * @return string          Extracted plain text, or empty string on failure
     */
    private function extractDocxText(string $filePath): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($filePath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        // Replace paragraph/break tags with spaces before stripping to preserve word boundaries
        $xml = preg_replace('/<w:p[ >]/', ' ', $xml) ?? $xml;
        $xml = preg_replace('/<w:br[ >]/', ' ', $xml) ?? $xml;

        return trim(strip_tags($xml));
    }

    /**
     * Convert a PHP upload error code to a human-readable message.
     *
     * @param int $code PHP UPLOAD_ERR_* constant value
     * @return string   Human-readable error description
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the allowed size limit.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server configuration error: cannot write to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default               => 'An unknown upload error occurred.',
        };
    }
}
