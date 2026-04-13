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

    /** @var int Maximum file size enforced at PHP level (50 MB) */
    private const MAX_FILE_SIZE = 52428800;

    /** @var int Maximum file size for video/audio uploads (200 MB) */
    private const MAX_MEDIA_FILE_SIZE = 209715200;

    /** @var array Extensions treated as media (skip text scan, use media size limit) */
    private const MEDIA_EXTENSIONS = ['mp4', 'mov', 'avi', 'webm', 'mkv', 'mp3', 'm4a', 'wav', 'ogg', 'aac'];

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
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'md'   => ['text/plain', 'text/markdown'],
        'rtf'  => ['text/rtf', 'application/rtf'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        // Video
        'mp4'  => ['video/mp4'],
        'mov'  => ['video/quicktime'],
        'avi'  => ['video/x-msvideo'],
        'webm' => ['video/webm'],
        'mkv'  => ['video/x-matroska', 'application/x-matroska'],
        // Audio
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'm4a'  => ['audio/mp4', 'audio/x-m4a'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'ogg'  => ['audio/ogg'],
        'aac'  => ['audio/aac', 'audio/x-aac'],
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

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $isMedia   = in_array($ext, self::MEDIA_EXTENSIONS, true);
        $hardLimit = $isMedia ? self::MAX_MEDIA_FILE_SIZE : self::MAX_FILE_SIZE;
        $hardLabel = $isMedia ? '200 MB' : '50 MB';

        if ($file['size'] > $hardLimit) {
            return ['valid' => false, 'error' => "File exceeds the maximum allowed size of {$hardLabel}."];
        }

        if (!$isMedia && $file['size'] > $uploadConfig['max_size']) {
            $maxMb = round($uploadConfig['max_size'] / 1048576, 1);
            return ['valid' => false, 'error' => "File exceeds maximum size of {$maxMb} MB."];
        }
        if (!in_array($ext, $uploadConfig['allowed_extensions'], true)) {
            $allowed = implode(', ', $uploadConfig['allowed_extensions']);
            return ['valid' => false, 'error' => "File type not allowed. Accepted: {$allowed}."];
        }

        if (!in_array($file['type'], $uploadConfig['allowed_types'], true)) {
            return ['valid' => false, 'error' => 'MIME type not permitted. Upload a TXT, PDF, DOC, or DOCX file.'];
        }

        // Verify MIME type using finfo (server-side double-check)
        if (is_file($file['tmp_name'])) {
            $verifyResult = $this->verifyMimeType($file['tmp_name'], $ext);
            if ($verifyResult !== null) {
                return ['valid' => false, 'error' => $verifyResult];
            }

            // Only scan plain text files for dangerous payloads
            // PDF and DOCX are binary formats that legitimately contain these signatures
            if ($ext === 'txt' && !$isMedia) {
                $scanResult = $this->scanFileContent($file['tmp_name']);
                if ($scanResult !== null) {
                    return ['valid' => false, 'error' => $scanResult];
                }
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
    /** @var array App config, set via setConfig() for AI-powered extraction */
    private array $appConfig = [];

    public function setConfig(array $config): void
    {
        $this->appConfig = $config;
    }

    public function extractText(string $filePath, string $mimeType): string
    {
        // Also check by file extension as a fallback
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // MIME type takes strict priority. Extension fallbacks only apply when no
        // specific MIME handler matched (i.e. MIME is empty or genuinely unknown).
        // This prevents a .txt extension from overriding an explicit non-text MIME
        // type such as application/octet-stream or application/msword.
        return match (true) {
            $mimeType === 'text/plain'
                => (string) file_get_contents($filePath),

            $mimeType === 'application/pdf'
                => $this->extractPdfText($filePath),

            str_contains($mimeType, 'wordprocessingml')
                => $this->extractDocxText($filePath),

            str_contains($mimeType, 'presentationml')
                => $this->extractPptxText($filePath),

            str_contains($mimeType, 'spreadsheetml')
                => $this->extractXlsxText($filePath),

            $mimeType === 'text/rtf' || $mimeType === 'application/rtf'
                => $this->extractRtfText($filePath),

            $mimeType === 'application/msword'
                => 'Binary .doc format is not supported. Please save as .docx or paste text.',

            // Extension-only fallbacks — only reached when MIME type is empty (absent).
            // An explicit MIME type (even application/octet-stream) takes precedence;
            // if the MIME matched nothing above, that IS the answer (return '').
            $mimeType === '' && in_array($ext, ['txt', 'csv', 'md'])
                => (string) file_get_contents($filePath),

            $mimeType === '' && $ext === 'pdf'
                => $this->extractPdfText($filePath),

            $mimeType === '' && $ext === 'docx'
                => $this->extractDocxText($filePath),

            $mimeType === '' && $ext === 'pptx'
                => $this->extractPptxText($filePath),

            $mimeType === '' && $ext === 'xlsx'
                => $this->extractXlsxText($filePath),

            $mimeType === '' && $ext === 'rtf'
                => $this->extractRtfText($filePath),

            $mimeType === '' && $ext === 'doc'
                => 'Binary .doc format is not supported. Please save as .docx or paste text.',

            str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/')
                => $this->extractMediaViaGemini($filePath, $mimeType),

            default => '',
        };
    }

    private function externalAiProcessingEnabled(): bool
    {
        return (bool) ($this->appConfig['upload']['allow_external_ai_processing'] ?? false);
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

        // Check if detected MIME matches expected for this extension
        // Allow common alternatives that finfo reports on different platforms
        $expectedMimes = self::EXTENSION_MIME_MAP[$ext] ?? [];
        $alwaysAllowed = ['application/octet-stream', 'application/zip', 'application/x-empty', 'text/plain'];
        if (!empty($expectedMimes) && !in_array($detectedMime, $expectedMimes, true) && !in_array($detectedMime, $alwaysAllowed, true)) {
            return 'File content does not match the declared file type. Detected: ' . $detectedMime;
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
        // Method 1: smalot/pdfparser (fast, local)
        $text = $this->extractPdfViaSmalot($filePath);
        if (trim($text) !== '') {
            return $text;
        }

        // Method 2: Gemini AI vision (handles scanned/complex PDFs)
        $text = $this->extractPdfViaGemini($filePath);
        if (trim($text) !== '') {
            return $text;
        }

        error_log("[FileProcessor] All PDF extraction methods failed for: " . basename($filePath));
        return '';
    }

    private function extractPdfViaSmalot(string $filePath): string
    {
        $prevMemory = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($filePath);

            $text = '';
            $pages = $pdf->getPages();
            foreach ($pages as $page) {
                try {
                    $pageText = $page->getText();
                    if (trim($pageText) !== '') {
                        $text .= $pageText . "\n\n";
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (trim($text) === '') {
                $text = $pdf->getText();
            }

            ini_set('memory_limit', $prevMemory);
            return trim($text);
        } catch (\Throwable $e) {
            ini_set('memory_limit', $prevMemory);
            error_log("[FileProcessor] smalot failed: " . $e->getMessage());
            return '';
        }
    }

    private function extractPdfViaGemini(string $filePath): string
    {
        if (!$this->externalAiProcessingEnabled()) {
            error_log('[FileProcessor] External AI PDF extraction disabled by policy');
            return '';
        }

        // Try multiple sources for the API key
        $apiKey = $this->appConfig['gemini']['api_key']
               ?? $_ENV['GEMINI_API_KEY']
               ?? getenv('GEMINI_API_KEY')
               ?: '';

        if ($apiKey === '') {
            error_log("[FileProcessor] No Gemini API key available for PDF fallback");
            return '';
        }

        try {
            $fileSize = filesize($filePath);
            error_log("[FileProcessor] Attempting Gemini PDF extraction for " . basename($filePath) . " ({$fileSize} bytes)");

            $pdfData = file_get_contents($filePath);
            if ($pdfData === false) return '';

            $base64 = base64_encode($pdfData);
            $model  = $this->appConfig['gemini']['model']
                   ?? $_ENV['GEMINI_MODEL']
                   ?? getenv('GEMINI_MODEL')
                   ?: 'gemini-3.0-preview';

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $body = json_encode([
                'contents' => [[
                    'parts' => [
                        ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $base64]],
                        ['text' => 'Extract ALL text content from this PDF document. Output ONLY the extracted text, preserving the original structure, headings, and paragraphs. Do not add commentary or summarise — just extract the raw text exactly as it appears.'],
                    ],
                ]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 65536],
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 120,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                error_log("[FileProcessor] Gemini PDF extraction: curl failed - " . $curlError);
                return '';
            }

            if ($httpCode !== 200) {
                error_log("[FileProcessor] Gemini PDF extraction HTTP {$httpCode}: " . substr($response, 0, 500));
                return '';
            }

            $data = json_decode($response, true);

            if (!empty($data['error'])) {
                error_log("[FileProcessor] Gemini API error: " . ($data['error']['message'] ?? json_encode($data['error'])));
                return '';
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (trim($text) !== '') {
                error_log("[FileProcessor] Gemini extracted " . strlen($text) . " chars from PDF");
                return trim($text);
            }

            error_log("[FileProcessor] Gemini returned empty text. Response: " . substr($response, 0, 300));
        } catch (\Throwable $e) {
            error_log("[FileProcessor] Gemini PDF extraction failed: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract text from PPTX (PowerPoint) via ZipArchive.
     */
    private function extractPptxText(string $filePath): string
    {
        if (!class_exists('ZipArchive')) return '';

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) return '';

        $text = '';
        $i = 1;
        while (($content = $zip->getFromName("ppt/slides/slide{$i}.xml")) !== false) {
            $text .= strip_tags($content) . "\n\n";
            $i++;
        }

        // Also try notes
        $i = 1;
        while (($content = $zip->getFromName("ppt/notesSlides/notesSlide{$i}.xml")) !== false) {
            $noteText = strip_tags($content);
            if (trim($noteText) !== '') {
                $text .= "Notes: " . $noteText . "\n";
            }
            $i++;
        }

        $zip->close();
        return preg_replace('/\s+/', ' ', trim($text));
    }

    /**
     * Extract text from XLSX (Excel) via ZipArchive.
     */
    private function extractXlsxText(string $filePath): string
    {
        if (!class_exists('ZipArchive')) return '';

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) return '';

        // Read shared strings
        $strings = [];
        $sharedStrings = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStrings) {
            $xml = @simplexml_load_string($sharedStrings);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $strings[] = (string) $si->t ?: (string) $si;
                }
            }
        }

        // Read sheet data
        $text = '';
        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet1) {
            $xml = @simplexml_load_string($sheet1);
            if ($xml) {
                foreach ($xml->sheetData->row ?? [] as $row) {
                    $rowTexts = [];
                    foreach ($row->c ?? [] as $cell) {
                        $val = (string) $cell->v;
                        if ((string) ($cell['t'] ?? '') === 's' && isset($strings[(int) $val])) {
                            $rowTexts[] = $strings[(int) $val];
                        } else {
                            $rowTexts[] = $val;
                        }
                    }
                    $text .= implode(' | ', $rowTexts) . "\n";
                }
            }
        }

        $zip->close();
        return trim($text);
    }

    /**
     * Extract text from RTF.
     */
    private function extractRtfText(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) return '';

        // Strip RTF control words and groups
        $text = preg_replace('/\{[^}]*\}/', '', $content);
        $text = preg_replace('/\\\\[a-z]+\d*\s?/i', '', $text ?? '');
        $text = preg_replace('/[{}]/', '', $text ?? '');
        $text = preg_replace('/\s+/', ' ', trim($text ?? ''));

        return $text;
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
     * Transcribe video or audio via the Gemini Files API.
     *
     * Three-step flow:
     *   1. Upload file to Gemini Files API (resumable multipart)
     *   2. Poll until state === ACTIVE (up to 120 seconds)
     *   3. Call generateContent with the file URI to get transcript
     *   4. Delete the file from Gemini (best-effort)
     *
     * Returns the transcript string, or '' on any failure (non-throwing).
     */
    private function extractMediaViaGemini(string $filePath, string $mimeType): string
    {
        if (!$this->externalAiProcessingEnabled()) {
            error_log('[FileProcessor] External AI media transcription disabled by policy');
            return '';
        }

        $apiKey = $this->appConfig['gemini']['api_key']
               ?? $_ENV['GEMINI_API_KEY']
               ?? getenv('GEMINI_API_KEY')
               ?: '';

        if ($apiKey === '') {
            error_log('[FileProcessor] No Gemini API key for media transcription');
            return '';
        }

        $model = $this->appConfig['gemini']['model']
              ?? $_ENV['GEMINI_MODEL']
              ?? getenv('GEMINI_MODEL')
              ?: 'gemini-3.0-preview';

        try {
            $fileUri  = $this->geminiFilesUpload($filePath, $mimeType, $apiKey);
            $fileName = $this->geminiFileUriToName($fileUri);

            if (!$this->geminiFilesPollActive($fileName, $apiKey)) {
                error_log('[FileProcessor] Gemini file did not become ACTIVE within timeout');
                $this->geminiFilesDelete($fileName, $apiKey);
                return '';
            }

            $transcript = $this->geminiTranscribe($fileUri, $mimeType, $model, $apiKey);
            $this->geminiFilesDelete($fileName, $apiKey);

            return $transcript;
        } catch (\Throwable $e) {
            error_log('[FileProcessor] Media transcription failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Upload a file to the Gemini Files API via multipart POST.
     * Returns the uploaded file's URI.
     *
     * @throws \RuntimeException on HTTP error or unexpected response shape
     */
    private function geminiFilesUpload(string $filePath, string $mimeType, string $apiKey): string
    {
        $displayName = basename($filePath);
        $fileSize    = filesize($filePath);

        $boundary = bin2hex(random_bytes(8));
        $metadata = json_encode(['file' => ['display_name' => $displayName, 'mime_type' => $mimeType]]);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n";
        $body .= "Content-Length: {$fileSize}\r\n\r\n";
        $body .= file_get_contents($filePath);
        $body .= "\r\n--{$boundary}--\r\n";

        $url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: multipart/related; boundary={$boundary}",
                "Content-Length: " . mb_strlen($body, '8bit'),
            ],
            CURLOPT_TIMEOUT        => 300,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException("Gemini Files upload cURL error: {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Gemini Files upload HTTP {$httpCode}: " . substr((string)$response, 0, 300));
        }

        $data = json_decode((string)$response, true);
        $uri  = $data['file']['uri'] ?? '';
        if ($uri === '') {
            throw new \RuntimeException('Gemini Files upload returned no URI');
        }

        return $uri;
    }

    /**
     * Extract the short file name (e.g. "files/abc123") from a full URI.
     */
    private function geminiFileUriToName(string $uri): string
    {
        $parts = explode('/v1beta/', $uri);
        return $parts[1] ?? '';
    }

    /**
     * Poll the Gemini Files API until the file reaches ACTIVE state.
     * Returns true on ACTIVE, false on timeout or FAILED.
     */
    private function geminiFilesPollActive(string $fileName, string $apiKey): bool
    {
        $url      = "https://generativelanguage.googleapis.com/v1beta/{$fileName}?key={$apiKey}";
        $deadline = time() + 120;

        while (time() < $deadline) {
            sleep(2);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $data  = json_decode((string)$response, true);
            $state = $data['state'] ?? '';

            if ($state === 'ACTIVE') {
                return true;
            }
            if ($state === 'FAILED') {
                error_log('[FileProcessor] Gemini file processing FAILED: ' . json_encode($data['error'] ?? []));
                return false;
            }
        }

        return false;
    }

    /**
     * Call generateContent with a Gemini Files API file URI to get a transcript.
     *
     * @throws \RuntimeException on HTTP error or unexpected response
     */
    private function geminiTranscribe(string $fileUri, string $mimeType, string $model, string $apiKey): string
    {
        $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $body = json_encode([
            'contents' => [[
                'parts' => [
                    ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileUri]],
                    ['text' => 'Transcribe all spoken content from this recording. Output the full verbatim transcript only, preserving speaker turns where identifiable (e.g. "Speaker 1:", "Speaker 2:"). Do not summarise, add headings, or add any commentary — output only the transcript text.'],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 65536],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException("Gemini transcribe cURL error: {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Gemini transcribe HTTP {$httpCode}: " . substr((string)$response, 0, 300));
        }

        $data = json_decode((string)$response, true);
        return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    }

    /**
     * Delete a file from Gemini Files API. Non-throwing — failure is logged only.
     */
    private function geminiFilesDelete(string $fileName, string $apiKey): void
    {
        if ($fileName === '') {
            return;
        }
        $url = "https://generativelanguage.googleapis.com/v1beta/{$fileName}?key={$apiKey}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        curl_close($ch);
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
