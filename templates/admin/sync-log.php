<?php
/**
 * Sync Log Template
 *
 * Shows paginated, filterable sync operations for the Jira integration
 * with CSV export support.
 *
 * Variables: $user (array), $logs (array), $integration (array|null),
 *            $page (int), $totalPages (int), $total (int),
 *            $direction (string|null), $status (string|null),
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
     Filters & Export
     =========================== -->
<section class="card mt-4">
    <div class="card-body">
        <form method="GET" action="/app/admin/integrations/sync-log" class="d-flex align-items-end gap-3 flex-wrap">
            <div>
                <label for="filter-direction" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Direction</label>
                <select id="filter-direction" name="direction" class="form-select form-select-sm" style="min-width: 120px;">
                    <option value="">All</option>
                    <option value="push" <?= ($direction ?? '') === 'push' ? 'selected' : '' ?>>Push</option>
                    <option value="pull" <?= ($direction ?? '') === 'pull' ? 'selected' : '' ?>>Pull</option>
                </select>
            </div>
            <div>
                <label for="filter-status" class="form-label" style="font-size: 0.85rem; font-weight: 600;">Status</label>
                <select id="filter-status" name="status" class="form-select form-select-sm" style="min-width: 120px;">
                    <option value="">All</option>
                    <option value="success" <?= ($status ?? '') === 'success' ? 'selected' : '' ?>>Success</option>
                    <option value="error" <?= ($status ?? '') === 'error' ? 'selected' : '' ?>>Error</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            </div>
            <div class="ms-auto">
                <?php
                    $exportParams = [];
                    if (!empty($direction)) $exportParams['direction'] = $direction;
                    if (!empty($status))    $exportParams['status']    = $status;
                    $exportQuery = $exportParams ? '?' . http_build_query($exportParams) : '';
                ?>
                <a href="/app/admin/integrations/sync-log/export<?= $exportQuery ?>" class="btn btn-sm btn-outline-secondary">
                    Export CSV
                </a>
            </div>
        </form>
    </div>
</section>

<!-- ===========================
     Sync Log Table
     =========================== -->
<section class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Sync Operations</h2>
        <span class="text-muted" style="font-size: 0.85rem;">
            <?= number_format($total ?? 0) ?> total <?= ($total ?? 0) === 1 ? 'entry' : 'entries' ?>
        </span>
    </div>
    <div class="card-body" style="overflow-x: auto;">
        <?php if (empty($logs)): ?>
            <p class="text-muted">No sync operations match the current filters.</p>
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

            <!-- ===========================
                 Pagination Controls
                 =========================== -->
            <?php if (($totalPages ?? 1) > 1): ?>
                <?php
                    $queryParams = [];
                    if (!empty($direction)) $queryParams['direction'] = $direction;
                    if (!empty($status))    $queryParams['status']    = $status;
                ?>
                <nav class="d-flex justify-content-between align-items-center mt-3">
                    <div>
                        <?php if ($page > 1): ?>
                            <?php $prevParams = array_merge($queryParams, ['page' => $page - 1]); ?>
                            <a href="/app/admin/integrations/sync-log?<?= http_build_query($prevParams) ?>"
                               class="btn btn-sm btn-outline-primary">&laquo; Previous</a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-outline-secondary disabled">&laquo; Previous</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.85rem;">
                        Page <?= $page ?> of <?= $totalPages ?>
                    </div>
                    <div>
                        <?php if ($page < $totalPages): ?>
                            <?php $nextParams = array_merge($queryParams, ['page' => $page + 1]); ?>
                            <a href="/app/admin/integrations/sync-log?<?= http_build_query($nextParams) ?>"
                               class="btn btn-sm btn-outline-primary">Next &raquo;</a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-outline-secondary disabled">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
