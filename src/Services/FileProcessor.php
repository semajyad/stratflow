<?php
/**
 * FileProcessor Service
 *
 * Handles file validation, storage, and text extraction for uploaded documents.
 * Supports TXT, PDF, DOCX formats. PDF parsing uses smalot/pdfparser.
 * DOCX parsing uses PHP's built-in ZipArchive to read word/document.xml.
 *
 * Security hardening:
 * - File content scanning for embedded scripts/PHP tags
 * - MIME type verification via finfo (double-check)
 * - EXIF data stripping from images
 * - Read-only file permissions on stored uploads
 * - Server-side file size enforcement
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
    // CONSTANTS
    // ===========================

    /** @var int Maximum file size enforced at PHP level (10 MB) */
    private const MAX_FILE_SIZE = 10485760;

    /** @var array Dangerous byte signatures to scan for in uploads */
    private const DANGEROUS_SIGNATURES = [
        '<?php',
        '<?=',
        '<script',
        '<%',
        '#!/',
    ];

    /** @var array Map of allowed extensions to expected MIME types */
    private const EXTENSION_MIME_MAP = [
        'txt'  => ['text/plain'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    // ===========================
    // VALIDATION
    // ===========================

    /**
     * Validate an uploaded file against configuration constraints.
     *
     * Checks PHP upload error code, file size (both config and hard limit),
     * extension, MIME type (both header and finfo), and scans content for
     * dangerous payloads.
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

        // Hard file size limit at PHP level
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['valid' => false, 'error' => 'File exceeds the maximum allowed size of 10 MB.'];
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

        // Verify MIME type using finfo (server-side double-check)
        // Only run on actual uploaded files (tmp_name must exist)
        if (is_file($file['tmp_name'])) {
            $verifyResult = $this->verifyMimeType($file['tmp_name'], $ext);
            if ($verifyResult !== null) {
                return ['valid' => false, 'error' => $verifyResult];
            }

            // Scan file content for dangerous payloads
            $scanResult = $this->scanFileContent($file['tmp_name']);
            if ($scanResult !== null) {
                return ['valid' => false, 'error' => $scanResult];
            }
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
     * Sets the stored file to read-only permissions.
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

        // Set file to read-only (prevents modification after upload)
        @chmod($dest, 0444);

        // Strip EXIF data from images if applicable
        $this->stripExifData($dest, $ext);

        return $filename;
    }

    // ===========================
    // TEXT EXTRACTION
    // ===========================

    /**
     * Extract plain text content from a stored file based on its MIME type.
     *
     * - text/plain  -> direct file_get_contents
     * - application/pdf -> smalot/pdfparser
     * - DOCX        -> ZipArchive reads word/document.xml, strip_tags()
     * - DOC (binary)-> returns a friendly unsupported message
     * - other       -> returns empty string
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
    // SECURITY HELPERS
    // ===========================

    /**
     * Verify the MIME type of a file using finfo, independent of the
     * client-supplied Content-Type header.
     *
     * @param string $tmpPath  Path to the temporary upload file
     * @param string $ext      File extension (lowercase)
     * @return string|null     Error message if mismatch, null if OK
     */
    private function verifyMimeType(string $tmpPath, string $ext): ?string
    {
        if (!function_exists('finfo_open')) {
            return null; // Graceful degradation if finfo unavailable
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $detectedMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if ($detectedMime === false) {
            return null;
        }

        // Check if detected MIME matches any expected MIME for this extension
        $expectedMimes = self::EXTENSION_MIME_MAP[$ext] ?? [];
        if (!empty($expectedMimes) && !in_array($detectedMime, $expectedMimes, true)) {
            // Allow application/octet-stream as some systems report this for DOCX/DOC
            if ($detectedMime !== 'application/octet-stream' && $detectedMime !== 'application/zip') {
                return 'File content does not match the declared file type. Detected: ' . $detectedMime;
            }
        }

        return null;
    }

    /**
     * Scan the first bytes of a file for dangerous embedded content.
     *
     * Detects PHP opening tags, script tags, and other server-side
     * execution markers that could indicate a disguised webshell.
     *
     * @param string $tmpPath Path to the temporary upload file
     * @return string|null    Error message if dangerous content found, null if clean
     */
    private function scanFileContent(string $tmpPath): ?string
    {
        // Read first 8KB for signature scanning
        $content = file_get_contents($tmpPath, false, null, 0, 8192);
        if ($content === false) {
            return 'Unable to read uploaded file for security scanning.';
        }

        $contentLower = strtolower($content);
        foreach (self::DANGEROUS_SIGNATURES as $sig) {
            if (str_contains($contentLower, strtolower($sig))) {
                return 'File contains potentially dangerous content and has been rejected.';
            }
        }

        return null;
    }

    /**
     * Strip EXIF metadata from image files.
     *
     * EXIF data can contain GPS coordinates, device info, and other
     * sensitive metadata that should not be stored.
     *
     * @param string $filePath Absolute path to the stored file
     * @param string $ext      File extension (lowercase)
     */
    private function stripExifData(string $filePath, string $ext): void
    {
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return;
        }

        if (!function_exists('imagecreatefromjpeg')) {
            return; // GD library not available
        }

        try {
            if ($ext === 'png') {
                $img = @imagecreatefrompng($filePath);
                if ($img !== false) {
                    // Make writable temporarily to overwrite
                    @chmod($filePath, 0644);
                    imagepng($img, $filePath);
                    imagedestroy($img);
                    @chmod($filePath, 0444);
                }
            } else {
                $img = @imagecreatefromjpeg($filePath);
                if ($img !== false) {
                    @chmod($filePath, 0644);
                    imagejpeg($img, $filePath, 95);
                    imagedestroy($img);
                    @chmod($filePath, 0444);
                }
            }
        } catch (\Throwable $e) {
            // Non-critical — log and continue
            error_log('[FileProcessor] EXIF strip failed: ' . $e->getMessage());
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
