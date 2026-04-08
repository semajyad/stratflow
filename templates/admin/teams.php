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
<div class="page-header">
    <h1 class="page-title">Team Management</h1>
    <p class="page-subtitle">
        <a href="/app/admin">&larr; Back to Administration</a>
    </p>
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
            <div class="form-group" style="align-self: flex-end;">
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
                    <h3 class="card-title" style="margin: 0;">
                        <?= htmlspecialchars($team['name']) ?>
                        <span class="badge badge-secondary" style="font-weight: normal; margin-left: 0.5rem;">
                            <?= (int) $team['member_count'] ?> member<?= (int) $team['member_count'] !== 1 ? 's' : '' ?>
                        </span>
                    </h3>
                    <?php if (!empty($team['description'])): ?>
                        <p class="text-muted mt-1" style="margin-bottom: 0; font-size: 0.875rem;">
                            <?= htmlspecialchars($team['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <button class="btn btn-sm btn-secondary"
                            onclick="toggleTeamEdit(<?= $teamId ?>)">Edit</button>
                    <form method="POST" action="/app/admin/teams/<?= $teamId ?>/delete" class="inline-form"
                          onsubmit="return confirm('Delete this team? Members will be unlinked.')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>

            <!-- Inline edit form (hidden) -->
            <div id="team-edit-<?= $teamId ?>" class="hidden" style="padding: 1rem; background: #fefce8; border-bottom: 1px solid var(--border);">
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
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    onclick="toggleTeamEdit(<?= $teamId ?>)">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Team body: members + add member -->
            <div class="card-body">
                <div class="flex gap-4" style="flex-wrap: wrap;">
                    <!-- Current members -->
                    <div style="flex: 2; min-width: 250px;">
                        <h4 style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 0.5rem;">Members</h4>
                        <?php if (empty($members)): ?>
                            <p class="text-muted" style="font-size: 0.875rem;">No members assigned.</p>
                        <?php else: ?>
                            <div class="team-member-list">
                                <?php foreach ($members as $member): ?>
                                    <div class="team-member-row">
                                        <span>
                                            <?= htmlspecialchars($member['full_name']) ?>
                                            <span class="text-muted" style="font-size: 0.8rem;">
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
                    <div style="flex: 1; min-width: 200px;">
                        <h4 style="font-size: 0.875rem; color: var(--text-light); margin-bottom: 0.5rem;">Add Member</h4>
                        <?php
                            $available = array_filter($org_users, function ($u) use ($memberIds) {
                                return $u['is_active'] && !in_array((int) $u['id'], $memberIds, true);
                            });
                        ?>
                        <?php if (empty($available)): ?>
                            <p class="text-muted" style="font-size: 0.875rem;">All users assigned.</p>
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
                    <div class="mt-3 text-muted" style="font-size: 0.8125rem;">
                        Capacity: <strong><?= (int) $team['capacity'] ?></strong> points per sprint
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<script>
function toggleTeamEdit(id) {
    const el = document.getElementById('team-edit-' + id);
    if (el) el.classList.toggle('hidden');
}
</script>
