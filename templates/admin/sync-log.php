<?php
/**
 * Sync Log Template
 *
 * Shows the last 50 sync operations for the Jira integration.
 *
 * Variables: $user (array), $logs (array), $integration (array|null),
 *            $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header">
    <h1 class="page-title">Sync History</h1>
    <p class="page-subtitle">
        <a href="/app/admin/integrations">&larr; Back to Integrations</a>
    </p>
</div>

<!-- ===========================
     Sync Log Table
     =========================== -->
<section class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">Recent Sync Operations</h2>
    </div>
    <div class="card-body" style="overflow-x: auto;">
        <?php if (empty($logs)): ?>
            <p class="text-muted">No sync operations recorded yet. Push or pull items to see activity here.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Direction</th>
                        <th>Action</th>
                        <th>Item Type</th>
                        <th>Item ID</th>
                        <th>External</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php $details = json_decode($log['details_json'] ?? '{}', true) ?: []; ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.85rem;">
                                <?= htmlspecialchars($log['created_at'] ?? '') ?>
                            </td>
                            <td>
                                <?php if ($log['direction'] === 'push'): ?>
                                    <span class="badge badge-primary">Push</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Pull</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $actionBadge = match ($log['action'] ?? '') {
                                    'create' => 'badge-success',
                                    'update' => 'badge-warning',
                                    'delete' => 'badge-danger',
                                    'skip'   => 'badge-secondary',
                                    default  => 'badge-secondary',
                                };
                                ?>
                                <span class="badge <?= $actionBadge ?>"><?= htmlspecialchars(ucfirst($log['action'] ?? '')) ?></span>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst($log['local_type'] ?? '-'))) ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?= $log['local_id'] ? (int) $log['local_id'] : '-' ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?php if (!empty($log['external_id']) && !empty($integration['site_url'])): ?>
                                    <a href="<?= htmlspecialchars(rtrim($integration['site_url'], '/') . '/browse/' . $log['external_id']) ?>"
                                       target="_blank" rel="noopener">
                                        <?= htmlspecialchars($log['external_id']) ?>
                                    </a>
                                <?php elseif (!empty($log['external_id'])): ?>
                                    <?= htmlspecialchars($log['external_id']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge badge-success">OK</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Error</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                title="<?= htmlspecialchars(json_encode($details)) ?>">
                                <?php if (!empty($details['error'])): ?>
                                    <?= htmlspecialchars(substr($details['error'], 0, 80)) ?>
                                <?php elseif (!empty($details['title'])): ?>
                                    <?= htmlspecialchars(substr($details['title'], 0, 60)) ?>
                                <?php elseif (!empty($details['reason'])): ?>
                                    <?= htmlspecialchars($details['reason']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
