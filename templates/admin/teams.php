<?php
/**
 * Admin Team Management Template
 *
 * Lists all teams with member counts, expandable member lists,
 * and forms for creating, editing, and deleting teams.
 *
 * Variables: $user (array), $teams (array), $team_members (array),
 *            $org_users (array), $csrf_token (string)
 */
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <div>
        <h1 class="page-title">Team Management</h1>
        <p class="page-subtitle">
            <a href="/app/admin">&larr; Back to Administration</a>
        </p>
    </div>
    <form method="POST" action="/app/admin/integrations/jira/import-teams" class="inline-form"
          data-loading="Importing teams..."
          data-overlay="Importing teams from Jira project roles and team fields.">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <button type="submit" class="btn btn-secondary btn-sm"
                data-confirm="Import boards from Jira as teams? Each Jira board will create a team with its board ID linked for sprint sync.">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.66 0 3-4.03 3-9s-1.34-9-3-9m0 18c-1.66 0-3-4.03-3-9s1.34-9 3-9"/></svg>
            Import Boards from Jira
        </button>
    </form>
</div>

<!-- ===========================
     Create Team Form
     =========================== -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Create Team</h2>
    </div>
    <form method="POST" action="/app/admin/teams" class="card-body">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-row gap-4">
            <div class="form-group">
                <label class="form-label">Team Name</label>
                <input type="text" name="name" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-input" placeholder="Optional">
            </div>
            <div class="form-group">
                <label class="form-label">Capacity (points/sprint)</label>
                <input type="number" name="capacity" class="form-input" value="0" min="0">
            </div>
            <div class="form-group team-admin-form-group-end">
                <button type="submit" class="btn btn-primary">Create Team</button>
            </div>
        </div>
    </form>
</section>

<!-- ===========================
     Team List
     =========================== -->
<?php if (empty($teams)): ?>
    <section class="card mt-4">
        <p class="empty-state">No teams yet. Create one above to get started.</p>
    </section>
<?php else: ?>
    <?php foreach ($teams as $team): ?>
        <?php
            $teamId = (int) $team['id'];
            $members = $team_members[$teamId] ?? [];
            $memberIds = array_column($members, 'id');
        ?>
        <section class="card mt-4 team-card">
            <!-- Team header -->
            <div class="card-header flex justify-between items-center">
                <div>
                    <h3 class="card-title team-admin-title">
                        <?= htmlspecialchars($team['name']) ?>
                        <span class="badge badge-secondary team-admin-badge team-admin-badge--first">
                            <?= (int) $team['member_count'] ?> member<?= (int) $team['member_count'] !== 1 ? 's' : '' ?>
                        </span>
                        <?php if (!empty($team['jira_board_id'])): ?>
                            <span class="badge badge-info team-admin-badge">
                                Jira Board #<?= (int) $team['jira_board_id'] ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <?php if (!empty($team['description'])): ?>
                        <p class="text-muted mt-1 team-admin-description">
                            <?= htmlspecialchars($team['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <button class="btn btn-sm btn-secondary js-toggle-target"
                            type="button"
                            data-target-id="team-edit-<?= $teamId ?>">Edit</button>
                    <form method="POST" action="/app/admin/teams/<?= $teamId ?>/delete" class="inline-form"
                          data-confirm="Delete this team? Members will be unlinked.">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>

            <!-- Inline edit form (hidden) -->
            <div id="team-edit-<?= $teamId ?>" class="hidden team-admin-edit">
                <form method="POST" action="/app/admin/teams/<?= $teamId ?>">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="form-row gap-4">
                        <div class="form-group">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-input"
                                   value="<?= htmlspecialchars($team['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-input"
                                   value="<?= htmlspecialchars($team['description'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity" class="form-input"
                                   value="<?= (int) $team['capacity'] ?>" min="0">
                        </div>
                        <div class="form-group team-admin-form-group-end">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <button type="button" class="btn btn-secondary btn-sm js-toggle-target"
                                    data-target-id="team-edit-<?= $teamId ?>">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Team body: members + add member -->
            <div class="card-body">
                <div class="flex gap-4 team-admin-body">
                    <!-- Current members -->
                    <div class="team-admin-members">
                        <h4 class="team-admin-section-title">Members</h4>
                        <?php if (empty($members)): ?>
                            <p class="text-muted team-admin-empty-copy">No members assigned.</p>
                        <?php else: ?>
                            <div class="team-member-list">
                                <?php foreach ($members as $member): ?>
                                    <div class="team-member-row">
                                        <span>
                                            <?= htmlspecialchars($member['full_name']) ?>
                                            <span class="text-muted team-admin-member-email">
                                                (<?= htmlspecialchars($member['email']) ?>)
                                            </span>
                                        </span>
                                        <form method="POST" action="/app/admin/teams/remove-member" class="inline-form">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    title="Remove from team">&#10005;</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Add member -->
                    <div class="team-admin-add-member">
                        <h4 class="team-admin-section-title">Add Member</h4>
                        <?php
                            $available = array_filter($org_users, function ($u) use ($memberIds) {
                                return $u['is_active'] && !in_array((int) $u['id'], $memberIds, true);
                            });
                        ?>
                        <?php if (empty($available)): ?>
                            <p class="text-muted team-admin-empty-copy">All users assigned.</p>
                        <?php else: ?>
                            <form method="POST" action="/app/admin/teams/add-member">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                <div class="flex gap-2">
                                    <select name="user_id" class="form-input" required>
                                        <option value="">Select user...</option>
                                        <?php foreach ($available as $au): ?>
                                            <option value="<?= (int) $au['id'] ?>">
                                                <?= htmlspecialchars($au['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm">Add</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ((int) $team['capacity'] > 0): ?>
                    <div class="mt-3 text-muted team-admin-capacity">
                        Capacity: <strong><?= (int) $team['capacity'] ?></strong> points per sprint
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
