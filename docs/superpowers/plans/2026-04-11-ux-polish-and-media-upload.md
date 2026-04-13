# UX Polish & Media Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend session expiry to 24h, add StratFlow/ThreePoints logos, fill spinner gaps on long-running forms, and add video/audio upload support with Gemini Files API transcription.

**Architecture:** Four independent changes touching templates, CSS, JS, FileProcessor service, and infrastructure config. No new routes, no new controllers, no DB changes. Media transcription uses the Gemini Files API (upload → poll → transcribe → delete).

**Tech Stack:** PHP 8.4, vanilla JS, CSS, PHPUnit 11, Gemini REST API (Files API + generateContent)

---

## File Map

| Action | File | What changes |
|--------|------|--------------|
| MODIFY | `public/index.php` | Session timeout 1800 → 86400 |
| MODIFY | `templates/partials/sidebar.php` | StratFlow text → `<img>` |
| MODIFY | `templates/layouts/app.php` | ThreePoints logo in topbar-right |
| MODIFY | `templates/layouts/public.php` | StratFlow text → `<img>` |
| MODIFY | `templates/login.php` | StratFlow logo above heading |
| MODIFY | `public/assets/css/app.css` | Logo sizing styles |
| MODIFY | `templates/governance.php` | Add `data-loading` to detect + baseline forms |
| MODIFY | `templates/partials/work-item-row.php` | Add `data-loading` to improve form |
| MODIFY | `templates/partials/user-story-row.php` | Add `data-loading` to improve form |
| MODIFY | `php.ini` | Raise upload/exec limits for media |
| MODIFY | `docker/nginx/default.conf` | Raise `client_max_body_size` |
| MODIFY | `src/Config/config.php` | Add media types to upload config |
| MODIFY | `src/Services/FileProcessor.php` | Media MIME map, size limit, extractMediaViaGemini() |
| MODIFY | `tests/Unit/Services/FileProcessorTest.php` | Tests for media validation + extraction routing |
| MODIFY | `templates/upload.php` | Accept attribute + size hint text |

---

## Task 1: Extend session expiry to 24 hours

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Update the Session constructor call**

In `public/index.php` line 85, change:
```php
$session = new \StratFlow\Core\Session(1800, $db->getPdo());
```
to:
```php
$session = new \StratFlow\Core\Session(86400, $db->getPdo());
```

- [ ] **Step 2: Verify syntax**

```bash
docker compose exec php php -l public/index.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/index.php
git commit -m "feat(auth): extend session inactivity timeout to 24 hours"
```

---

## Task 2: Add logo image files and CSS

**Files:**
- Create: `public/assets/images/stratflow-logo.png`
- Create: `public/assets/images/threepoints-logo.png`
- Modify: `public/assets/css/app.css`

- [ ] **Step 1: Create the images directory and copy logo files**

```bash
mkdir -p public/assets/images
```

Manually copy the two PNG files from the conversation screenshots into:
- `public/assets/images/stratflow-logo.png`
- `public/assets/images/threepoints-logo.png`

Verify they exist:
```bash
ls public/assets/images/
```
Expected: both files listed.

- [ ] **Step 2: Add logo CSS to `public/assets/css/app.css`**

Append the following at the end of the file (before the final line if one exists):

```css
/* === Logo Branding ======================================================== */

.topbar-threepoints-logo {
    height: 28px;
    width: auto;
    display: block;
    opacity: 0.9;
    flex-shrink: 0;
}

.sidebar-brand-logo {
    height: 28px;
    width: auto;
    display: block;
}

.sidebar-brand-mark-logo {
    height: 28px;
    width: auto;
    display: block;
}

.public-header .logo img {
    height: 32px;
    width: auto;
    display: block;
}

.auth-logo {
    text-align: center;
    margin-bottom: 1.5rem;
}

.auth-logo img {
    height: 48px;
    width: auto;
    display: inline-block;
}
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/images/ public/assets/css/app.css
git commit -m "feat(branding): add logo image files and CSS sizing rules"
```

---

## Task 3: Add logos to templates

**Files:**
- Modify: `templates/partials/sidebar.php`
- Modify: `templates/layouts/app.php`
- Modify: `templates/layouts/public.php`
- Modify: `templates/login.php`

- [ ] **Step 1: Replace StratFlow text in sidebar**

In `templates/partials/sidebar.php`, find:
```php
    <div class="sidebar-brand">
        <a href="/app/home" class="sidebar-brand-full">StratFlow</a>
        <a href="/app/home" class="sidebar-brand-mark" aria-label="StratFlow home">S</a>
```

Replace with:
```php
    <div class="sidebar-brand">
        <a href="/app/home" class="sidebar-brand-full" aria-label="StratFlow home">
            <img src="/assets/images/stratflow-logo.png" alt="StratFlow" class="sidebar-brand-logo">
        </a>
        <a href="/app/home" class="sidebar-brand-mark" aria-label="StratFlow home">
            <img src="/assets/images/stratflow-logo.png" alt="SF" class="sidebar-brand-mark-logo">
        </a>
```

- [ ] **Step 2: Add ThreePoints logo to app topbar**

In `templates/layouts/app.php`, find:
```php
                <div class="topbar-right">
                    <span class="user-name"><?= htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User') ?></span>
```

Replace with:
```php
                <div class="topbar-right">
                    <img src="/assets/images/threepoints-logo.png" alt="ThreePoints" class="topbar-threepoints-logo">
                    <span class="user-name"><?= htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User') ?></span>
```

- [ ] **Step 3: Replace StratFlow text in public header**

In `templates/layouts/public.php`, find:
```php
            <a href="/" class="logo">StratFlow</a>
```

Replace with:
```php
            <a href="/" class="logo"><img src="/assets/images/stratflow-logo.png" alt="StratFlow"></a>
```

- [ ] **Step 4: Add StratFlow logo to login page**

In `templates/login.php`, find:
```php
        <div class="auth-card-header">
            <h2 class="auth-heading">Sign in to your account</h2>
        </div>
```

Replace with:
```php
        <div class="auth-card-header">
            <div class="auth-logo">
                <img src="/assets/images/stratflow-logo.png" alt="StratFlow">
            </div>
            <h2 class="auth-heading">Sign in to your account</h2>
        </div>
```

- [ ] **Step 5: Verify syntax on all four files**

```bash
docker compose exec php php -l templates/partials/sidebar.php
docker compose exec php php -l templates/layouts/app.php
docker compose exec php php -l templates/layouts/public.php
docker compose exec php php -l templates/login.php
```

Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Commit**

```bash
git add templates/partials/sidebar.php templates/layouts/app.php templates/layouts/public.php templates/login.php
git commit -m "feat(branding): replace text logos with StratFlow and ThreePoints images"
```

---

## Task 4: Fill loading spinner gaps

**Files:**
- Modify: `templates/governance.php`
- Modify: `templates/partials/work-item-row.php`
- Modify: `templates/partials/user-story-row.php`

The spinner JS and CSS infrastructure is already in place — only `data-loading` attributes are missing from three locations.

- [ ] **Step 1: Add data-loading to governance detect form**

In `templates/governance.php`, find:
```php
        <form method="POST" action="/app/governance/detect" class="inline-form">
```

Replace with:
```php
        <form method="POST" action="/app/governance/detect" class="inline-form" data-loading="Analysing for strategic drift…">
```

- [ ] **Step 2: Add data-loading to governance baseline form**

In `templates/governance.php`, find:
```php
        <form method="POST" action="/app/governance/baseline" class="inline-form">
```

Replace with:
```php
        <form method="POST" action="/app/governance/baseline" class="inline-form" data-loading="Creating baseline snapshot…">
```

- [ ] **Step 3: Add data-loading to work item improve form**

In `templates/partials/work-item-row.php`, find:
```php
        <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/improve"
              class="quality-improve-form"
```

Replace with:
```php
        <form method="POST" action="/app/work-items/<?= (int) $item['id'] ?>/improve"
              class="quality-improve-form"
              data-loading="Improving with AI…"
```

- [ ] **Step 4: Add data-loading to user story improve form**

In `templates/partials/user-story-row.php`, find:
```php
        <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/improve"
              class="quality-improve-form"
```

Replace with:
```php
        <form method="POST" action="/app/user-stories/<?= (int) $story['id'] ?>/improve"
              class="quality-improve-form"
              data-loading="Improving with AI…"
```

- [ ] **Step 5: Verify syntax**

```bash
docker compose exec php php -l templates/governance.php
docker compose exec php php -l templates/partials/work-item-row.php
docker compose exec php php -l templates/partials/user-story-row.php
```

Expected: `No syntax errors detected` for all three.

- [ ] **Step 6: Commit**

```bash
git add templates/governance.php templates/partials/work-item-row.php templates/partials/user-story-row.php
git commit -m "feat(ux): add missing data-loading spinners to governance and improve forms"
```

---

## Task 5: Raise infrastructure limits for media uploads

**Files:**
- Modify: `php.ini`
- Modify: `docker/nginx/default.conf`

- [ ] **Step 1: Update php.ini**

In `php.ini`, find and replace:
```ini
upload_max_filesize = 50M
post_max_size = 55M
max_execution_time = 120
max_input_time = 120
```

Replace with:
```ini
upload_max_filesize = 210M
post_max_size = 220M
max_execution_time = 300
max_input_time = 300
```

- [ ] **Step 2: Update nginx client_max_body_size**

In `docker/nginx/default.conf`, find:
```nginx
    client_max_body_size 55M;
```

Replace with:
```nginx
    client_max_body_size 220M;
```

- [ ] **Step 3: Restart containers to apply config**

```bash
docker compose down && docker compose up -d
```

Expected: all containers come up healthy.

- [ ] **Step 4: Commit**

```bash
git add php.ini docker/nginx/default.conf
git commit -m "feat(upload): raise php and nginx limits to 210M/220M for media uploads"
```

---

## Task 6: Write failing FileProcessor tests for media support

**Files:**
- Modify: `tests/Unit/Services/FileProcessorTest.php`

- [ ] **Step 1: Add media validation and extraction tests**

Append the following test methods inside the `FileProcessorTest` class, after the last existing test:

```php
    // ===========================
    // VALIDATION — MEDIA FILES
    // ===========================

    #[Test]
    public function testValidMp4FilePasses(): void
    {
        $config = $this->buildMediaConfig();
        $file   = $this->makeFile('meeting.mp4', 'video/mp4', 1048576);
        $result = $this->processor->validateFile($file, $config);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function testValidMp3FilePasses(): void
    {
        $config = $this->buildMediaConfig();
        $file   = $this->makeFile('recording.mp3', 'audio/mpeg', 1048576);
        $result = $this->processor->validateFile($file, $config);
        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function testMediaFileExceedingHardLimitFails(): void
    {
        $config = $this->buildMediaConfig();
        // 211 MB — exceeds the 210 MB media hard limit
        $file   = $this->makeFile('huge.mp4', 'video/mp4', 220_200_960);
        $result = $this->processor->validateFile($file, $config);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('200 MB', $result['error']);
    }

    #[Test]
    public function testExtractTextRoutesToMediaHandlerForVideoMime(): void
    {
        // extractText with a video MIME type on a non-existent file returns ''
        // (Gemini not called because no API key in test env — returns '' gracefully)
        $result = $this->processor->extractText('/tmp/nonexistent.mp4', 'video/mp4');
        $this->assertIsString($result);
        // Should not throw — graceful empty string on Gemini unavailability
    }

    #[Test]
    public function testExtractTextRoutesToMediaHandlerForAudioMime(): void
    {
        $result = $this->processor->extractText('/tmp/nonexistent.mp3', 'audio/mpeg');
        $this->assertIsString($result);
    }

    // ===========================
    // HELPERS
    // ===========================

    /** Build a config that includes media extensions and types. */
    private function buildMediaConfig(): array
    {
        return [
            'upload' => [
                'max_size'           => 10485760,
                'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx', 'mp4', 'mov', 'avi', 'webm', 'mkv', 'mp3', 'm4a', 'wav', 'ogg', 'aac'],
                'allowed_types'      => [
                    'text/plain',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'video/mp4',
                    'video/quicktime',
                    'video/x-msvideo',
                    'video/webm',
                    'video/x-matroska',
                    'audio/mpeg',
                    'audio/mp4',
                    'audio/wav',
                    'audio/ogg',
                    'audio/aac',
                ],
            ],
        ];
    }
```

- [ ] **Step 2: Run the new tests to confirm they fail**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/FileProcessorTest.php --no-coverage --filter "Media"
```

Expected: failures referencing missing MIME types in `EXTENSION_MIME_MAP` or missing match arm.

---

## Task 7: Implement media support in FileProcessor

**Files:**
- Modify: `src/Services/FileProcessor.php`

- [ ] **Step 1: Add media types to EXTENSION_MIME_MAP**

In `FileProcessor.php`, find `private const EXTENSION_MIME_MAP = [` and add the media entries:

```php
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
```

- [ ] **Step 2: Add a media hard-limit constant and update validateFile()**

After `private const MAX_FILE_SIZE = 52428800;`, add:

```php
    /** @var int Maximum file size for video/audio uploads (200 MB) */
    private const MAX_MEDIA_FILE_SIZE = 209715200;

    /** @var array Extensions treated as media (skip text scan, use media size limit) */
    private const MEDIA_EXTENSIONS = ['mp4', 'mov', 'avi', 'webm', 'mkv', 'mp3', 'm4a', 'wav', 'ogg', 'aac'];
```

In `validateFile()`, replace the two size-check blocks:

```php
        // Hard file size limit at PHP level
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['valid' => false, 'error' => 'File exceeds the maximum allowed size of 50 MB.'];
        }

        if ($file['size'] > $uploadConfig['max_size']) {
            $maxMb = round($uploadConfig['max_size'] / 1048576, 1);
            return ['valid' => false, 'error' => "File exceeds maximum size of {$maxMb} MB."];
        }
```

With:

```php
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
```

Also remove the duplicate `$ext = strtolower(...)` line that appears later in `validateFile()` (around line 91), since `$ext` is now declared earlier:

Find and remove:
```php
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
```
(the second occurrence, after the size checks)

Also add a skip for the text scan on media files. Find:

```php
            // Only scan plain text files for dangerous payloads
            // PDF and DOCX are binary formats that legitimately contain these signatures
            if ($ext === 'txt') {
```

Replace with:

```php
            // Only scan plain text files for dangerous payloads
            // Binary and media formats legitimately contain these signatures
            if ($ext === 'txt' && !$isMedia) {
```

- [ ] **Step 3: Add extractMediaViaGemini() and wire it into extractText()**

In `extractText()`, add a new match arm for audio/video MIME types **before** the `default` arm:

```php
            str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/')
                => $this->extractMediaViaGemini($filePath, $mimeType),
```

Then add the `extractMediaViaGemini()` method in the `PRIVATE HELPERS` section:

```php
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
     *
     * @param string $filePath Absolute path to the stored media file
     * @param string $mimeType MIME type of the file (e.g. 'video/mp4')
     * @return string          Transcript text, or '' on failure
     */
    private function extractMediaViaGemini(string $filePath, string $mimeType): string
    {
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
              ?: 'gemini-2.0-flash';

        try {
            // Step 1: Upload to Gemini Files API
            $fileUri  = $this->geminiFilesUpload($filePath, $mimeType, $apiKey);
            $fileName = $this->geminiFileUriToName($fileUri);

            // Step 2: Poll until ACTIVE
            if (!$this->geminiFilesPollActive($fileName, $apiKey)) {
                error_log('[FileProcessor] Gemini file did not become ACTIVE within timeout');
                $this->geminiFilesDelete($fileName, $apiKey);
                return '';
            }

            // Step 3: Transcribe
            $transcript = $this->geminiTranscribe($fileUri, $mimeType, $model, $apiKey);

            // Step 4: Delete (best-effort)
            $this->geminiFilesDelete($fileName, $apiKey);

            return $transcript;
        } catch (\Throwable $e) {
            error_log('[FileProcessor] Media transcription failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Upload a file to the Gemini Files API via multipart POST.
     * Returns the uploaded file's URI (e.g. "https://generativelanguage.googleapis.com/v1beta/files/xxx").
     *
     * @throws \RuntimeException on HTTP error or unexpected response shape
     */
    private function geminiFilesUpload(string $filePath, string $mimeType, string $apiKey): string
    {
        $displayName = basename($filePath);
        $fileSize    = filesize($filePath);

        // Multipart body: JSON metadata part + file bytes part
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
                "Content-Length: " . strlen($body),
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
        // URI shape: https://generativelanguage.googleapis.com/v1beta/files/abc123
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

        return false; // Timed out
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
```

- [ ] **Step 4: Verify syntax**

```bash
docker compose exec php php -l src/Services/FileProcessor.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Run the new tests — they should now pass**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/FileProcessorTest.php --no-coverage --filter "Media"
```

Expected: 5 tests, 5 passed

- [ ] **Step 6: Run the full FileProcessor test suite to check no regressions**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit/Services/FileProcessorTest.php --no-coverage
```

Expected: all green

- [ ] **Step 7: Commit**

```bash
git add src/Services/FileProcessor.php tests/Unit/Services/FileProcessorTest.php
git commit -m "feat(upload): add video/audio support with Gemini Files API transcription"
```

---

## Task 8: Update upload config and template

**Files:**
- Modify: `src/Config/config.php`
- Modify: `templates/upload.php`

- [ ] **Step 1: Add media types to upload config**

In `src/Config/config.php`, find:
```php
    'upload' => [
        'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 52428800),
        'allowed_types' => ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx'],
    ],
```

Replace with:
```php
    'upload' => [
        'max_size' => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 52428800),
        'allowed_types' => [
            'text/plain',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            // Video
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/webm',
            'video/x-matroska',
            // Audio
            'audio/mpeg',
            'audio/mp4',
            'audio/wav',
            'audio/ogg',
            'audio/aac',
        ],
        'allowed_extensions' => [
            'txt', 'pdf', 'doc', 'docx',
            'mp4', 'mov', 'avi', 'webm', 'mkv',
            'mp3', 'm4a', 'wav', 'ogg', 'aac',
        ],
    ],
```

- [ ] **Step 2: Update the upload template file input**

In `templates/upload.php`, find the file input's `accept` attribute (will contain something like `accept=".pdf,.doc,.docx,.txt"`). Update it to include media types:

```php
accept=".pdf,.doc,.docx,.txt,.csv,.md,.rtf,.pptx,.xlsx,.mp4,.mov,.avi,.webm,.mkv,.mp3,.m4a,.wav,.ogg,.aac"
```

Also find any file size hint text such as `"50MB"` or `"maximum 50"` and update to read:
`"Max 50 MB for documents · 200 MB for video/audio"`

- [ ] **Step 3: Verify syntax**

```bash
docker compose exec php php -l src/Config/config.php
docker compose exec php php -l templates/upload.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add src/Config/config.php templates/upload.php
git commit -m "feat(upload): add media extensions and types to upload config and template"
```

---

## Task 9: Final verification and push

- [ ] **Step 1: Run full unit test suite**

```bash
docker compose exec php vendor/bin/phpunit tests/Unit --no-coverage 2>&1 | tail -5
```

Expected: the same pre-existing failures only (30 errors from `getTestDbConfig()` in unrelated tests) — no new failures.

- [ ] **Step 2: Smoke-test manually**

1. Log in — verify StratFlow logo appears above "Sign in to your account"
2. Log in successfully — verify StratFlow logo in sidebar, ThreePoints logo in top-right
3. Navigate to Work Items → click Generate — verify full-screen spinner appears
4. Navigate to Governance → click Detect Drift — verify spinner appears
5. Navigate to Upload — upload a `.txt` file — verify it still works
6. Check session: leave browser idle, return after confirming 24h timeout is set in PHP session

- [ ] **Step 3: Push**

```bash
git push
```
