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
        <form method="GET" action="/superadmin/audit-logs" class="inline-form" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
            <label for="type" style="font-weight:600;">Filter by event:</label>
            <select name="type" id="type" class="form-control" style="max-width:250px;">
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
    <div class="card-body" style="overflow-x:auto;">
        <?php if (empty($logs)): ?>
            <p style="color:#666;padding:1rem;">No audit events found.</p>
        <?php else: ?>
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:0.5rem;border-bottom:2px solid #dee2e6;">Time</th>
                        <th style="text-align:left;padding:0.5rem;border-bottom:2px solid #dee2e6;">Event</th>
                        <th style="text-align:left;padding:0.5rem;border-bottom:2px solid #dee2e6;">User</th>
                        <th style="text-align:left;padding:0.5rem;border-bottom:2px solid #dee2e6;">IP Address</th>
                        <th style="text-align:left;padding:0.5rem;border-bottom:2px solid #dee2e6;">Details</th>
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
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:0.5rem;white-space:nowrap;font-size:0.85rem;">
                                <?= htmlspecialchars($log['created_at']) ?>
                            </td>
                            <td style="padding:0.5rem;">
                                <span class="badge badge-<?= $severity ?>" style="display:inline-block;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.8rem;font-weight:600;background:<?= $severity === 'warning' ? '#fff3cd' : '#d1ecf1' ?>;color:<?= $severity === 'warning' ? '#856404' : '#0c5460' ?>;">
                                    <?= htmlspecialchars($log['event_type']) ?>
                                </span>
                            </td>
                            <td style="padding:0.5rem;font-size:0.9rem;">
                                <?php if (!empty($log['full_name'])): ?>
                                    <?= htmlspecialchars($log['full_name']) ?>
                                    <br><small style="color:#888;"><?= htmlspecialchars($log['email'] ?? '') ?></small>
                                <?php else: ?>
                                    <span style="color:#999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.5rem;font-family:monospace;font-size:0.85rem;">
                                <?= htmlspecialchars($log['ip_address']) ?>
                            </td>
                            <td style="padding:0.5rem;font-size:0.85rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;">
                                <?php
                                    $details = json_decode($log['details_json'] ?? '{}', true);
                                    if (!empty($details)) {
                                        $parts = [];
                                        foreach ($details as $k => $v) {
                                            $parts[] = htmlspecialchars($k) . ': ' . htmlspecialchars((string) $v);
                                        }
                                        echo implode(', ', $parts);
                                    } else {
                                        echo '<span style="color:#999;">-</span>';
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
