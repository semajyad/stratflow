# UX Polish & Media Upload — Design Spec

**Date:** 2026-04-11
**Scope:** Session expiry, logo branding, consistent loading spinner, video/audio upload support

---

## Overview

Four independent improvements driven by enterprise user feedback:

1. **Session expiry** — extend from 30 minutes to 24 hours of inactivity
2. **Branding** — StratFlow logo (top-left + login) and ThreePoints logo (top-right)
3. **Loading spinner** — consistent full-screen overlay on all long-running operations
4. **Media upload** — accept video and audio files up to 200MB, transcribed via Gemini Files API

---

## 1. Session Expiry

**Change:** `public/index.php` line 85 — `new Session(1800, ...)` → `new Session(86400, ...)`

The existing inactivity-based timer resets on every page load, so active users are never logged out mid-day. 86400 seconds = 24 hours.

No other changes required. Session fingerprinting, ID regeneration, and secure cookie parameters are unchanged.

---

## 2. Branding

### Image files

Two PNG files must be placed in `public/assets/images/` before deployment:

| Filename | Source |
|---|---|
| `stratflow-logo.png` | StratFlow logo (blue bars + red arrow, "Strategy-to-Code Accelerator" tagline) |
| `threepoints-logo.png` | ThreePoints logo (three dots + wordmark) |

These files are not committed to the repo — they must be manually copied to the path above.

### Template changes

| File | Change |
|---|---|
| `templates/layouts/app.php` | In `.topbar-right`, add `<img>` for ThreePoints logo before the user name |
| `templates/partials/sidebar.php` | Replace text "StratFlow" in sidebar header with StratFlow logo `<img>` |
| `templates/layouts/public.php` | Replace `<a class="logo">StratFlow</a>` text with StratFlow logo `<img>` |
| `templates/login.php` | Add StratFlow logo `<img>` above the "Sign in to your account" `<h2>` |

### CSS additions (`public/assets/css/app.css`)

```css
.topbar-logo-threepoints {
    height: 28px;
    width: auto;
    opacity: 0.85;
}

.sidebar-logo img,
.public-logo img,
.auth-logo img {
    height: 32px;
    width: auto;
    display: block;
}

.auth-logo {
    text-align: center;
    margin-bottom: 1.25rem;
}
```

---

## 3. Loading Spinner

### Existing infrastructure (no new CSS needed)

The following classes already exist in `app.css`:
- `.processing-overlay` — full-screen dimmed backdrop, fixed position, z-index 9999
- `.processing-card` — centered white card with spinner and message text
- `.loading-spinner` — CSS-animated spinning circle
- `.btn-loading` — cursor-wait + opacity on the triggering button

### JS implementation

Add a single delegated listener in `app.js` that intercepts `submit` events on forms with `data-loading`:

```js
// Global loading overlay for long-running form submissions
document.addEventListener('submit', function(e) {
    const form = e.target.closest('form[data-loading]');
    if (!form) return;
    const message = form.dataset.loading || 'Processing...';
    showProcessingOverlay(message);
});

function showProcessingOverlay(message) {
    const overlay = document.createElement('div');
    overlay.className = 'processing-overlay';
    overlay.innerHTML = `
        <div class="processing-card">
            <div class="loading-spinner"></div>
            <p>${message}</p>
        </div>`;
    document.body.appendChild(overlay);
}
```

The overlay is never programmatically removed — the page reloads on response, which destroys it naturally.

### Forms that receive `data-loading`

| Template | Form / Button | Message |
|---|---|---|
| `templates/work-items.php` | Generate work items form | `"Generating work items…"` |
| `templates/user-stories.php` | Decompose into stories form | `"Decomposing into user stories…"` |
| `templates/upload.php` | Upload + summarise forms | `"Uploading…"` / `"Generating summary…"` |
| `templates/diagram.php` | Generate diagram form | `"Generating diagram…"` |
| `templates/diagram.php` | Generate OKRs form | `"Generating OKRs…"` |
| `templates/prioritisation.php` | AI baseline form | `"Running AI baseline…"` |
| `templates/sprints.php` | Auto-generate / auto-fill / AI allocate | `"Allocating sprints…"` |
| `templates/work-items.php` | Regenerate sizing form | `"Regenerating sizing…"` |
| `templates/user-stories.php` | Regenerate sizing form | `"Regenerating sizing…"` |
| `templates/partials/work-item-row.php` | Improve with AI form | `"Improving with AI…"` |
| `templates/partials/user-story-row.php` | Improve with AI form | `"Improving with AI…"` |
| `templates/governance.php` | Detect drift / create baseline | `"Analysing…"` |

**Not tagged:** Save/update forms, reorder, delete, logout, CSRF-only posts — these are instant.

The existing per-button AJAX inline spinners (generate description, suggest size, risk mitigation) are left unchanged — they update in-place and don't do a full page reload.

---

## 4. Video/Audio Upload

### Supported file types

| Extension | MIME type |
|---|---|
| `mp4` | `video/mp4` |
| `mov` | `video/quicktime` |
| `avi` | `video/x-msvideo` |
| `webm` | `video/webm` |
| `mkv` | `video/x-matroska` |
| `mp3` | `audio/mpeg` |
| `m4a` | `audio/mp4` |
| `wav` | `audio/wav` |
| `ogg` | `audio/ogg` |
| `aac` | `audio/aac` |

### FileProcessor changes

**`EXTENSION_MIME_MAP`** — add all 10 extensions above.

**`MAX_FILE_SIZE`** — introduce a per-type limit:
- Documents (existing types): 50MB hard limit (unchanged)
- Media files (audio/video extensions): 210MB hard limit

**`validateFile()`** — media files skip the dangerous-content text scan (binary formats).

**`extractText()`** — add a `match` arm for audio/video MIME types that calls `extractMediaViaGemini()`.

### `extractMediaViaGemini()` — Gemini Files API flow

```
1. Upload file to Gemini Files API
   POST /upload/v1beta/files?key={API_KEY}
   Content-Type: multipart/related
   Body: metadata (display_name, mime_type) + file bytes

2. Poll until state === ACTIVE
   GET /v1beta/files/{name}?key={API_KEY}
   Poll every 2 seconds, timeout after 120 seconds

3. Transcribe
   POST /v1beta/models/{model}:generateContent?key={API_KEY}
   Body: { contents: [{ parts: [
     { fileData: { mimeType, fileUri } },
     { text: "Transcribe all spoken content from this recording. Output the full transcript only, preserving speaker turns where identifiable. Do not summarise." }
   ]}] }

4. Cleanup (best-effort)
   DELETE /v1beta/files/{name}?key={API_KEY}
   Failure is non-fatal — Gemini auto-deletes after 48h.

5. Return transcript string (or '' on any failure)
```

On failure: `error_log(...)` and return `''`. The controller's existing empty-text flash message handles this gracefully.

### Infrastructure changes

**`php.ini`** (in repo root):
```ini
upload_max_filesize = 210M
post_max_size = 220M
max_execution_time = 300
max_input_time = 300
```

**`docker/nginx.conf`** (or equivalent):
```nginx
client_max_body_size 220M;
```

**`templates/upload.php`** — update file input `accept` attribute to include media extensions, update the size hint text from "50MB" to "50MB for documents, 200MB for video/audio".

### Upload config (`src/` or app config)

The `$config['upload']` array (used in `validateFile`) must be updated:
- `allowed_extensions`: add the 10 media extensions
- `allowed_types`: add the 10 MIME types
- `max_size`: keep at document default; media size checked via separate hard limit in `validateFile`

---

## Out of Scope

- Streaming transcription / progress updates for large uploads
- Speaker diarisation beyond what Gemini returns naturally
- Video thumbnail generation
- Audio waveform display
- Automatic transcription on upload (user still clicks "Generate Summary" / the existing summarise flow)
