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

<?php require __DIR__ . '/partials/workflow-stepper.php'; ?>

<div class="page-header flex justify-between items-center">
    <h1 class="page-title"><?= htmlspecialchars($project['name']) ?> &mdash; Document Upload</h1>
    <?php if ($hasSummary): ?>
        <a href="/app/diagram?project_id=<?= (int) $project['id'] ?>" class="btn btn-primary">
            Continue to Strategy Roadmap &rarr;
        </a>
    <?php endif; ?>
</div>

<div class="page-description">
    Upload your strategy documents (PDF, DOCX, PPTX, TXT) or paste text directly. StratFlow extracts the content and generates an AI summary — the foundation for your entire roadmap.
</div>

<?php if ($hasSummary): ?>
<!-- ===========================
     Summary Ready — Show summary + next step prominently
     =========================== -->
<section class="card mb-6" style="border-left: 4px solid var(--success, #28a745);">
    <div class="card-body">
        <div style="display:flex; align-items:start; gap:1rem;">
            <div style="flex:1;">
                <h3 style="margin:0 0 0.5rem; color:var(--success, #28a745);">Summary Ready</h3>
                <p style="font-size:0.9rem; color:var(--text-secondary); line-height:1.6; margin:0;">
                    <?= htmlspecialchars(mb_substr($latestDoc['ai_summary'], 0, 400)) ?>
                    <?= mb_strlen($latestDoc['ai_summary']) > 400 ? '...' : '' ?>
                </p>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($hasText && !$hasSummary): ?>
<!-- Text Extracted — Prompt to generate summary -->
<section class="card mb-6" style="border-left: 4px solid var(--primary);">
    <div class="card-body" style="display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem;">
        <div>
            <h3 style="margin:0 0 0.25rem;">Document uploaded successfully</h3>
            <p class="text-muted" style="margin:0; font-size:0.875rem;">
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
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Generate AI Summary</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($latestDoc && empty($latestDoc['extracted_text']) && !$hasSummary): ?>
<!-- Extraction Failure — Actionable recovery -->
<section class="card mb-6" style="border-left: 4px solid var(--danger, #dc2626);">
    <div class="card-body" style="padding:1.25rem 1.5rem;">
        <div style="display:flex; align-items:start; gap:1rem;">
            <div style="flex-shrink:0; width:40px; height:40px; border-radius:50%; background:#fee2e2; display:flex; align-items:center; justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div style="flex:1;">
                <h3 style="margin:0 0 0.35rem; color:#991b1b;">Could not extract text from this document</h3>
                <p class="text-muted" style="margin:0 0 1rem; font-size:0.875rem;">
                    We couldn't read <strong><?= htmlspecialchars($latestDoc['original_name']) ?></strong>. This usually happens with scanned PDFs, image-heavy documents, or encrypted files.
                </p>
                <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.875rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span style="color:var(--primary); font-weight:600;">1.</span>
                        <span>Upload a different format — <strong>DOCX works best</strong></span>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span style="color:var(--primary); font-weight:600;">2.</span>
                        <span>Paste the text directly into the form below</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span style="color:var(--primary); font-weight:600;">3.</span>
                        <span>Try a different version of the PDF (re-saved or re-exported)</span>
                    </div>
                </div>
                <div style="margin-top:1rem;">
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('paste-text').focus(); document.getElementById('paste-text').scrollIntoView({behavior:'smooth',block:'center'});">
                        Paste Text Instead
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===========================
     Upload Section
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
            <p class="drop-zone-hint">PDF, DOCX, PPTX, XLSX, TXT, CSV, RTF &mdash; up to 50 MB</p>
            <input type="file" name="document" id="file-input" accept=".txt,.csv,.md,.rtf,.pdf,.doc,.docx,.pptx,.xlsx" class="file-input-hidden">
        </div>
        <div class="selected-file" id="selected-file" style="display:none;">
            <span id="selected-file-name"></span>
            <button type="button" class="btn btn-sm btn-secondary" id="clear-file">Remove</button>
        </div>

        <div class="upload-divider"><span>or paste text directly</span></div>

        <div class="form-group">
            <textarea name="paste_text" id="paste-text" class="form-input" rows="4"
                      placeholder="Paste meeting notes, strategy briefs, or any strategic content here..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Upload & Extract</button>
    </form>
</section>

<?php if ($hasDocuments): ?>
<!-- ===========================
     Previous Documents (collapsed)
     =========================== -->
<section class="card">
    <div class="card-header flex justify-between items-center" style="cursor:pointer;"
         onclick="document.getElementById('doc-list').classList.toggle('hidden'); this.querySelector('.toggle-icon').textContent = document.getElementById('doc-list').classList.contains('hidden') ? '+' : '-';">
        <h2 class="card-title" style="margin:0;">
            Documents (<?= count($documents) ?>)
            <span class="toggle-icon" style="font-weight:400; margin-left:0.5rem;">+</span>
        </h2>
    </div>
    <div id="doc-list" class="hidden">
        <?php foreach ($documents as $doc): ?>
            <div style="padding:0.75rem 1.5rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <strong style="font-size:0.9rem;"><?= htmlspecialchars($doc['original_name']) ?></strong>
                    <span class="text-muted" style="font-size:0.8rem; margin-left:0.5rem;">
                        <?= formatFileSize((int) $doc['file_size']) ?> &middot; <?= date('j M Y', strtotime($doc['created_at'])) ?>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <?php if (!empty($doc['ai_summary'])): ?>
                        <span class="badge badge-success" style="font-size:0.7rem;">Summarised</span>
                    <?php elseif (!empty($doc['extracted_text'])): ?>
                        <form method="POST" action="/app/upload/summarise" class="inline-form"
                              data-loading="Summarising...">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-primary" style="font-size:0.75rem;">Summarise</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-secondary" style="font-size:0.7rem;">No text extracted</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>
