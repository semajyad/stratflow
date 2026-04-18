<?php
/**
 * Document Upload Template
 *
 * Clean, guided flow: upload → extract → summarise → next step.
 * Shows the right action at each stage.
 */
$hasDocuments = !empty($documents);
$latestDoc    = $hasDocuments ? $documents[0] : null;
$hasSummary   = $latestDoc && !empty($latestDoc['ai_summary']);
$hasText      = $latestDoc && !empty($latestDoc['extracted_text']);

function formatFileSize(int $bytes): string {
    if ($bytes === 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
?>

<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; Document Upload
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">Upload your strategy documents (PDF, DOCX, PPTX, TXT) or paste text directly. StratFlow extracts the content and generates an AI summary — the foundation for your entire roadmap.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php if ($hasSummary): ?>
            <a href="/app/diagram?project_id=<?= (int) $project['id'] ?>" class="btn btn-primary btn-sm">
                Continue to Strategy Roadmap &rarr;
            </a>
        <?php endif; ?>
        <?php $board_review_screen = 'summary'; include __DIR__ . '/partials/board-review-button.php'; ?>
    </div>
</div>

<!-- ===========================
     1. Upload Section (always first)
     =========================== -->
<section class="card mb-6">
    <div class="card-header">
        <h2 class="card-title"><?= $hasDocuments ? 'Upload Another Document' : 'Upload Strategy Document' ?></h2>
    </div>
    <form method="POST" action="/app/upload" enctype="multipart/form-data" id="upload-form"
          data-loading="Uploading..." data-overlay="Uploading and extracting text from your document.">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="52428800">
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

        <div class="drop-zone" id="drop-zone">
            <div class="drop-zone-icon">&#128196;</div>
            <p class="drop-zone-label">Drop file here or click to browse</p>
            <p class="drop-zone-hint">PDF, DOCX, PPTX, XLSX, TXT, CSV, RTF, MP4, MOV, AVI, WEBM, MKV, MP3, M4A, WAV, OGG, AAC &mdash; Max 50 MB for documents &middot; 200 MB for video/audio</p>
            <input type="file" name="document" id="file-input" accept=".pdf,.doc,.docx,.txt,.csv,.md,.rtf,.pptx,.xlsx,.mp4,.mov,.avi,.webm,.mkv,.mp3,.m4a,.wav,.ogg,.aac" class="file-input-hidden">
        </div>
        <div class="selected-file hidden" id="selected-file">
            <span id="selected-file-name"></span>
            <button type="button" class="btn btn-sm btn-secondary" id="clear-file">Remove</button>
        </div>

        <div class="upload-divider"><span>or paste text directly</span></div>

        <div class="form-group">
            <textarea name="paste_text" id="paste-text" class="form-input" rows="4"
                      placeholder="Paste meeting notes, strategy briefs, or any strategic content here..."></textarea>
        </div>

        <div class="upload-form-actions">
            <button type="submit" class="btn btn-primary">Upload &amp; Extract</button>
        </div>
    </form>
</section>

<!-- ===========================
     2. Extraction Failure (contextual — shown below upload)
     =========================== -->
<?php if ($latestDoc && empty($latestDoc['extracted_text']) && !$hasSummary): ?>
<section class="card mb-6 upload-callout upload-callout--danger">
    <div class="card-body upload-callout-body">
        <div class="upload-callout-row">
            <div class="upload-callout-icon upload-callout-icon--danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div class="upload-callout-copy">
                <h3 class="upload-callout-title upload-callout-title--danger">Could not extract text from this document</h3>
                <p class="text-muted upload-callout-text">
                    We couldn't read <strong><?= htmlspecialchars($latestDoc['original_name']) ?></strong>. This usually happens with scanned PDFs, image-heavy documents, or encrypted files.
                </p>
                <div class="upload-callout-steps">
                    <div class="upload-callout-step">
                        <span class="upload-callout-step-number">1.</span>
                        <span>Upload a different format — <strong>DOCX works best</strong></span>
                    </div>
                    <div class="upload-callout-step">
                        <span class="upload-callout-step-number">2.</span>
                        <span>Paste the text directly into the form above</span>
                    </div>
                    <div class="upload-callout-step">
                        <span class="upload-callout-step-number">3.</span>
                        <span>Try a different version of the PDF (re-saved or re-exported)</span>
                    </div>
                </div>
                <div class="upload-callout-actions">
                    <button type="button" class="btn btn-primary btn-sm js-focus-paste-text">
                        Paste Text Instead
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     3. Generate AI Summary (below upload — shown when text extracted, no summary yet)
     =========================== -->
<?php if ($hasText && !$hasSummary): ?>
<section class="card mb-6 upload-callout upload-callout--primary">
    <div class="card-body upload-callout-body upload-callout-body--between">
        <div>
            <h3 class="upload-callout-title">Document uploaded successfully</h3>
            <p class="text-muted upload-callout-text upload-callout-text--compact">
                Text extracted from <strong><?= htmlspecialchars($latestDoc['original_name']) ?></strong>.
                Generate an AI summary to proceed to the strategy roadmap.
            </p>
        </div>
        <form method="POST" action="/app/upload/summarise"
              data-loading="Generating summary..."
              data-overlay="AI is analysing your document and generating a strategic summary. This typically takes 15-30 seconds.">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="document_id" value="<?= (int) $latestDoc['id'] ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-ai">Generate AI Summary</button>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     4. Summary Ready (below generate prompt)
     =========================== -->
<?php if ($hasSummary): ?>
<section class="card mb-6 upload-callout upload-callout--success">
    <div class="card-body">
        <div class="upload-callout-row">
            <div class="upload-callout-copy">
                <h3 class="upload-callout-title upload-callout-title--success">Summary Ready</h3>
                <p class="upload-summary-text">
                    <?= htmlspecialchars(mb_substr($latestDoc['ai_summary'], 0, 400)) ?>
                    <?= mb_strlen($latestDoc['ai_summary']) > 400 ? '...' : '' ?>
                </p>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     5. Previous Documents (collapsed, always last)
     =========================== -->
<?php if ($hasDocuments): ?>
<section class="card">
    <div class="card-header flex justify-between items-center js-toggle-doc-list upload-doc-toggle"
         data-target-id="doc-list">
        <h2 class="card-title upload-doc-title">
            Documents (<?= count($documents) ?>)
            <span class="toggle-icon upload-doc-toggle-icon">+</span>
        </h2>
    </div>
    <div id="doc-list" class="hidden">
        <?php foreach ($documents as $doc): ?>
            <div class="upload-doc-item">
                <?php if (!empty($doc['ai_summary'])): ?>
                    <details class="upload-doc-details">
                        <summary class="upload-doc-summary">
                            <div>
                                <strong class="upload-doc-name"><?= htmlspecialchars($doc['original_name']) ?></strong>
                                <span class="text-muted upload-doc-meta">
                                    <?= formatFileSize((int) $doc['file_size']) ?> &middot; <?= date('j M Y', strtotime($doc['created_at'])) ?>
                                </span>
                            </div>
                            <span class="badge badge-success upload-doc-badge">
                                AI Summary &#9660;
                            </span>
                        </summary>
                        <div class="upload-doc-summary-copy">
                            <?= htmlspecialchars($doc['ai_summary']) ?>
                        </div>
                    </details>
                <?php else: ?>
                    <div class="upload-doc-row">
                        <div>
                            <strong class="upload-doc-name"><?= htmlspecialchars($doc['original_name']) ?></strong>
                            <span class="text-muted upload-doc-meta">
                                <?= formatFileSize((int) $doc['file_size']) ?> &middot; <?= date('j M Y', strtotime($doc['created_at'])) ?>
                            </span>
                        </div>
                        <div>
                            <?php if (!empty($doc['extracted_text'])): ?>
                                <form method="POST" action="/app/upload/summarise" class="inline-form"
                                      data-loading="Summarising...">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ai upload-doc-action">Summarise</button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-secondary upload-doc-badge">No text extracted</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
