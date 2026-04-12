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
        <form method="GET" action="/app/admin/audit-logs" class="audit-log-filter">
            <label class="audit-log-filter-label">Filter:</label>
            <select name="type" class="form-control audit-log-filter-select">
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
            <span class="text-muted audit-log-count"><?= count($logs) ?> events</span>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-body audit-log-table-wrap audit-log-table-wrap--flush">
        <?php if (empty($logs)): ?>
            <p class="empty-state">No audit events found.</p>
        <?php else: ?>
            <table class="audit-log-table">
                <thead>
                    <tr>
                        <th class="audit-log-head">Time</th>
                        <th class="audit-log-head">Event</th>
                        <th class="audit-log-head">User</th>
                        <th class="audit-log-head">IP</th>
                        <th class="audit-log-head">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $isWarning = str_contains($log['event_type'], 'failure') || str_contains($log['event_type'], 'deleted');
                        ?>
                        <tr class="audit-log-row">
                            <td class="audit-log-cell audit-log-cell--time"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td class="audit-log-cell">
                                <span class="badge badge-<?= $isWarning ? 'warning' : 'info' ?> audit-log-badge">
                                    <?= htmlspecialchars($log['event_type']) ?>
                                </span>
                            </td>
                            <td class="audit-log-cell audit-log-cell--user">
                                <?= htmlspecialchars($log['full_name'] ?? 'System') ?>
                                <?php if (!empty($log['email'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($log['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="audit-log-cell audit-log-cell--mono"><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td class="audit-log-cell audit-log-cell--details">
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
                                        echo '<span class="text-muted audit-log-fallback">-</span>';
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
