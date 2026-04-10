<?php
// stratflow/templates/executive-project.php
// Variables: $user, $project, $projects, $okr_items, $health_counts,
//            $flash_message, $flash_error
// $okr_items[]: id, okr_title, okr_description, kr_lines[]
// $health_counts: total_okrs, total_krs
?>

<?php if (!empty($flash_message)): ?>
    <div class="flash-success"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
    <div class="flash-error"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header flex justify-between items-center" style="flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?> &mdash; OKR Progress</h1>
        <p class="page-subtitle" style="color: #64748b; font-size: 0.875rem;">
            <?= (int) $health_counts['total_okrs'] ?> objective<?= $health_counts['total_okrs'] !== 1 ? 's' : '' ?>
            &middot;
            <?= (int) $health_counts['total_krs'] ?> key result<?= $health_counts['total_krs'] !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div style="display:flex; align-items:center; gap: 0.75rem;">
        <select onchange="window.location='/app/projects/' + this.value + '/executive'"
                style="border:1px solid #d1d5db; border-radius:6px; padding: 6px 10px; font-size: 0.875rem;">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $project['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size: 12px; color: #94a3b8;">
            Updated <?= htmlspecialchars($project['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if (empty($okr_items)): ?>
    <div class="card mt-6" style="text-align:center; padding:2rem; color:#6b7280;">
        <p>No OKRs defined for this project yet.</p>
        <p style="font-size:0.875rem; margin-top:0.5rem;">Add OKR titles to roadmap nodes on the
            <a href="/app/diagram" style="color:#6366f1;">Strategy Roadmap</a> page.
        </p>
    </div>
<?php endif; ?>

<!-- OKR Cards -->
<?php foreach ($okr_items as $i => $okr):
    $krLines = $okr['kr_lines'] ?? [];
    $krCount = count($krLines);
    $borderColour = $krCount > 0 ? '#6366f1' : '#9ca3af';
?>
<div class="card mb-4" style="border-top: 3px solid <?= htmlspecialchars($borderColour, ENT_QUOTES, 'UTF-8') ?>;">
    <div class="card-body" style="padding: 1rem 1.25rem;">

        <!-- OKR header -->
        <div class="flex justify-between items-start" style="gap: 0.75rem;">
            <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.25rem;">
                    <span style="display:inline-block; background:#6366f1; color:#fff; border-radius:999px; padding:2px 10px; font-size:0.65rem; text-transform:uppercase; font-weight:700; white-space:nowrap;">
                        Objective
                    </span>
                    <strong style="font-size: 0.95rem; color:#1e293b;">
                        <?= htmlspecialchars($okr['okr_title'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                </div>
                <?php if (!empty($okr['description_lines'])): ?>
                    <p style="font-size:0.8rem; color:#64748b; margin:0.25rem 0 0; line-height:1.4;">
                        <?= htmlspecialchars(implode(' ', $okr['description_lines']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
            </div>
            <span style="font-size:0.75rem; color:#94a3b8; white-space:nowrap; padding-top:3px;">
                <?= $krCount ?> KR<?= $krCount !== 1 ? 's' : '' ?>
            </span>
        </div>

        <?php if (!empty($krLines)): ?>
        <!-- KR lines -->
        <div style="margin-top: 0.875rem; border-top:1px solid #f1f5f9; padding-top:0.75rem;">
            <div style="font-size:0.68rem; text-transform:uppercase; font-weight:700; letter-spacing:.05em; color:#94a3b8; margin-bottom:0.5rem;">Key Results</div>
            <?php foreach ($krLines as $j => $krLine): ?>
            <div style="display:flex; align-items:flex-start; gap:0.5rem; padding:0.4rem 0.6rem; margin-bottom:0.3rem; background:#f8fafc; border-radius:5px; border-left:3px solid #a5b4fc;">
                <span style="font-size:0.75rem; font-weight:700; color:#6366f1; white-space:nowrap; min-width:1.5rem;">
                    <?= (int) ($j + 1) ?>.
                </span>
                <span style="font-size:0.8rem; color:#374151; line-height:1.4;">
                    <?php
                    // Strip leading "KR1:" or "KR:" prefix for cleaner display
                    $displayLine = preg_replace('/^\s*KR\d*\s*[:.\-]\s*/i', '', $krLine);
                    echo htmlspecialchars($displayLine, ENT_QUOTES, 'UTF-8');
                    ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif (!empty($okr['okr_description'])): ?>
        <!-- Description only (no KR lines detected) -->
        <div style="margin-top:0.75rem; border-top:1px solid #f1f5f9; padding-top:0.625rem;">
            <p style="font-size:0.8rem; color:#6b7280; white-space:pre-wrap; margin:0;">
                <?= htmlspecialchars(trim($okr['okr_description']), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div><!-- /.card -->
<?php endforeach; ?>

<style>
.mt-6 { margin-top: 1.5rem; }
.mb-4 { margin-bottom: 1rem; }
</style>
