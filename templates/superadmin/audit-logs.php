<?php
/**
 * Superadmin Audit Logs Template
 *
 * Displays security audit events with filtering by event type.
 * Required for HIPAA audit trail review and SOC 2 monitoring.
 *
 * Variables: $user (array), $logs (array), $event_types (array),
 *            $filter_type (string|null), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Audit Logs</h1>
    <p class="page-subtitle">Security event log for compliance review (HIPAA / SOC 2 / PCI-DSS).</p>
</div>

<!-- ===========================
     Filter Controls
     =========================== -->
<section class="card mb-4">
    <div class="card-body">
        <form method="GET" action="/superadmin/audit-logs" class="inline-form audit-log-filter">
            <label for="type" class="audit-log-filter-label">Filter by event:</label>
            <select name="type" id="type" class="form-control audit-log-filter-select audit-log-filter-select--wide">
                <option value="">All events</option>
                <?php foreach ($event_types as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= ($filter_type === $type) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($filter_type): ?>
                <a href="/superadmin/audit-logs" class="btn btn-secondary btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</section>

<!-- ===========================
     Audit Log Table
     =========================== -->
<section class="card">
    <div class="card-body audit-log-table-wrap">
        <?php if (empty($logs)): ?>
            <p class="audit-log-empty">No audit events found.</p>
        <?php else: ?>
            <table class="data-table audit-log-table">
                <thead>
                    <tr>
                        <th class="audit-log-head">Time</th>
                        <th class="audit-log-head">Event</th>
                        <th class="audit-log-head">User</th>
                        <th class="audit-log-head">IP Address</th>
                        <th class="audit-log-head">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $severity = 'info';
                            if (str_contains($log['event_type'], 'failure') || str_contains($log['event_type'], 'deleted')) {
                                $severity = 'warning';
                            }
                        ?>
                        <tr class="audit-log-row">
                            <td class="audit-log-cell audit-log-cell--time">
                                <?= htmlspecialchars($log['created_at']) ?>
                            </td>
                            <td class="audit-log-cell">
                                <span class="badge badge-<?= $severity ?> audit-log-badge">
                                    <?= htmlspecialchars($log['event_type']) ?>
                                </span>
                            </td>
                            <td class="audit-log-cell audit-log-cell--user audit-log-cell--user-large">
                                <?php if (!empty($log['full_name'])): ?>
                                    <?= htmlspecialchars($log['full_name']) ?>
                                    <br><small class="audit-log-email"><?= htmlspecialchars($log['email'] ?? '') ?></small>
                                <?php else: ?>
                                    <span class="audit-log-fallback">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="audit-log-cell audit-log-cell--mono audit-log-cell--mono-large">
                                <?= htmlspecialchars($log['ip_address']) ?>
                            </td>
                            <td class="audit-log-cell audit-log-cell--details audit-log-cell--details-sm">
                                <?php
                                    $details = json_decode($log['details_json'] ?? '{}', true);
                                    if (!empty($details)) {
                                        $parts = [];
                                        foreach ($details as $k => $v) {
                                            $parts[] = htmlspecialchars($k) . ': ' . htmlspecialchars((string) $v);
                                        }
                                        echo implode(', ', $parts);
                                    } else {
                                        echo '<span class="audit-log-fallback">-</span>';
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
