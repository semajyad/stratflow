<?php
/**
 * Document Upload Template
 *
 * Upload page for a specific project. Supports drag-and-drop file upload
 * (TXT, PDF, DOCX) and manual text paste. Lists previously uploaded documents
 * with extracted text previews and AI summary status.
 *
 * Variables: $project (array), $documents (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?></h1>
</div>

<!-- ===========================
     Page Description
     =========================== -->
<div class="page-description">
    Upload your strategy documents, meeting notes, or transcripts. StratFlow will extract the text and generate an AI summary to prepare for strategic mapping.
</div>

<!-- ===========================
     Instructions
     =========================== -->
<div class="info-box">
    <p>Upload your meeting notes, strategy documents, or other files. Supported formats: TXT, PDF, DOCX. You can also paste text directly.</p>
</div>

<!-- ===========================
     Upload Form
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Upload Document</h2>
    </div>

    <form
        method="POST"
        action="/app/upload"
        enctype="multipart/form-data"
        class="upload-form"
        id="upload-form"
    >
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="52428800">
        <input type="hidden" name="project_id"  value="<?= (int) $project['id'] ?>">

        <!-- Drag-and-drop zone -->
        <div class="drop-zone" id="drop-zone">
            <div class="drop-zone-icon">&#128196;</div>
            <p class="drop-zone-label">Drop files here or click to browse</p>
            <p class="drop-zone-hint">TXT, PDF, DOCX &mdash; max 50 MB</p>
            <input
                type="file"
                name="document"
                id="file-input"
                accept=".txt,.pdf,.doc,.docx"
                class="file-input-hidden"
            >
        </div>
        <div class="selected-file" id="selected-file" style="display:none;">
            <span id="selected-file-name"></span>
            <button type="button" class="btn btn-sm btn-secondary" id="clear-file">Remove</button>
        </div>

        <!-- Divider -->
        <div class="upload-divider">
            <span>or paste text below</span>
        </div>

        <!-- Text paste area -->
        <div class="form-group">
            <textarea
                name="paste_text"
                id="paste-text"
                class="form-input"
                rows="6"
                placeholder="Paste your document text here..."
            ></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Upload &amp; Extract Text</button>
    </form>
</section>

<!-- ===========================
     Uploaded Documents List
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Uploaded Documents</h2>
    </div>

    <?php if (empty($documents)): ?>
        <p class="empty-state">No documents uploaded yet.</p>
    <?php else: ?>
        <div class="document-list">
            <?php foreach ($documents as $doc): ?>
                <div class="document-item">
                    <div class="document-meta">
                        <span class="document-name"><?= htmlspecialchars($doc['original_name']) ?></span>
                        <span class="document-size"><?= formatFileSize((int) $doc['file_size']) ?></span>
                        <span class="document-date">
                            <?= date('j M Y, g:ia', strtotime($doc['created_at'])) ?>
                        </span>
                    </div>

                    <?php if (!empty($doc['ai_summary'])): ?>
                        <!-- AI summary available -->
                        <div class="ai-summary-box">
                            <strong>AI Summary:</strong>
                            <p><?= htmlspecialchars($doc['ai_summary']) ?></p>
                            <a
                                href="/app/diagram?project_id=<?= (int) $project['id'] ?>"
                                class="btn btn-primary btn-sm"
                            >Proceed to Strategy Diagram</a>
                        </div>
                    <?php else: ?>
                        <!-- No summary yet — show generate button when extracted text is available -->
                        <div class="document-actions">
                            <?php if (!empty($doc['extracted_text'])): ?>
                                <form method="POST" action="/app/upload/summarise">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                                    <input type="hidden" name="project_id"  value="<?= (int) $project['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Generate AI Summary</button>
                                </form>
                            <?php else: ?>
                                <button
                                    class="btn btn-secondary btn-sm"
                                    disabled
                                    title="No extracted text available to summarise"
                                >Generate AI Summary</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($doc['extracted_text'])): ?>
                        <!-- Extracted text preview with toggle -->
                        <div class="extracted-text-preview">
                            <p class="text-preview" id="preview-<?= (int) $doc['id'] ?>">
                                <?= htmlspecialchars(mb_substr($doc['extracted_text'], 0, 200)) ?>
                                <?= mb_strlen($doc['extracted_text']) > 200 ? '...' : '' ?>
                            </p>
                            <?php if (mb_strlen($doc['extracted_text']) > 200): ?>
                                <div class="text-full" id="full-<?= (int) $doc['id'] ?>" style="display:none;">
                                    <pre class="text-full-content"><?= htmlspecialchars($doc['extracted_text']) ?></pre>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-secondary toggle-text"
                                    data-doc-id="<?= (int) $doc['id'] ?>"
                                >View Full Text</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<?php
/**
 * Format a file size in bytes to a human-readable string.
 *
 * @param int $bytes File size in bytes
 * @return string    Formatted string (e.g. "1.2 MB", "340 KB")
 */
function formatFileSize(int $bytes): string
{
    if ($bytes === 0) {
        return '—';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}
?>
