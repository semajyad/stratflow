<?php
/**
 * Admin Audit Logs Template
 *
 * Org-scoped audit log viewer with filtering and CSV export.
 * For compliance review and incident investigation.
 */
?>

<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">Audit Logs</h1>
        <p class="page-subtitle"><a href="/app/admin">&larr; Back to Administration</a></p>
    </div>
    <a href="/app/admin/audit-logs/export?<?= http_build_query(array_filter(['type' => $filter_type ?? ''])) ?>"
       class="btn btn-secondary btn-sm">Export CSV</a>
</div>

<section class="card mb-4">
    <div class="card-body">
        <form method="GET" action="/app/admin/audit-logs" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
            <label style="font-weight:600;">Filter:</label>
            <select name="type" class="form-control" style="max-width:220px;">
                <option value="">All events</option>
                <?php foreach ($event_types as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= ($filter_type ?? '') === $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars(str_replace('_', ' ', ucfirst($type))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($filter_type ?? null): ?>
                <a href="/app/admin/audit-logs" class="btn btn-secondary btn-sm">Clear</a>
            <?php endif; ?>
            <span class="text-muted" style="margin-left:auto; font-size:0.85rem;"><?= count($logs) ?> events</span>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-body" style="overflow-x:auto; padding:0;">
        <?php if (empty($logs)): ?>
            <p class="empty-state">No audit events found.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Time</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Event</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">User</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">IP</th>
                        <th style="text-align:left;padding:0.6rem 0.75rem;border-bottom:2px solid var(--border);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $isWarning = str_contains($log['event_type'], 'failure') || str_contains($log['event_type'], 'deleted');
                        ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:0.5rem 0.75rem;white-space:nowrap;font-size:0.85rem;"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td style="padding:0.5rem 0.75rem;">
                                <span class="badge badge-<?= $isWarning ? 'warning' : 'info' ?>" style="font-size:0.75rem;">
                                    <?= htmlspecialchars($log['event_type']) ?>
                                </span>
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-size:0.85rem;">
                                <?= htmlspecialchars($log['full_name'] ?? 'System') ?>
                                <?php if (!empty($log['email'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($log['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.5rem 0.75rem;font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td style="padding:0.5rem 0.75rem;font-size:0.8rem;max-width:350px;overflow:hidden;text-overflow:ellipsis;">
                                <?php
                                    $details = json_decode($log['details_json'] ?? '{}', true);
                                    if (!empty($details)) {
                                        $parts = [];
                                        foreach ($details as $k => $v) {
                                            if (is_array($v)) $v = json_encode($v);
                                            $parts[] = htmlspecialchars($k) . ': ' . htmlspecialchars((string) $v);
                                        }
                                        echo implode(', ', $parts);
                                    } else {
                                        echo '<span class="text-muted">-</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
