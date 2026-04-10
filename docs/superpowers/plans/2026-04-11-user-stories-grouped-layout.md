# User Stories Grouped Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the User Stories screen to group stories under their parent epic, add a per-epic "Generate User Stories" button, and widen the edit modal.

**Architecture:** Pure template + JS changes. `user-stories.php` is rewritten to iterate `$work_items` and render each as a visual section with its stories grouped beneath it. PHP groups `$stories` by `parent_hl_item_id` before rendering. The per-epic generate button posts to the existing `/app/user-stories/generate` endpoint with a single `hl_item_ids[]` value — no controller changes. SortableJS is updated from a single `#id` to multiple `.user-stories-list` class instances; `onEnd` collects all story rows across all lists in DOM order to maintain global priority numbering.

**Tech Stack:** PHP 8.4 templates, vanilla JS, SortableJS 1.15.6

---

## File Map

| Action | File | What changes |
|--------|------|--------------|
| MODIFY | `templates/user-stories.php` | Full rewrite: PHP grouping, epic sections, per-epic generate, Delete All to header |
| MODIFY | `templates/partials/user-story-modal.php` | Add `max-width: 760px` to modal div |
| MODIFY | `public/assets/js/app.js` | SortableJS: `#user-stories-list` → `.user-stories-list` (multiple instances), global reorder |

---

## Task 1: Widen the edit modal

**Files:**
- Modify: `templates/partials/user-story-modal.php:14`

- [ ] **Step 1: Read `templates/partials/user-story-modal.php` line 14** to confirm the exact `<div class="modal">` tag.

Expected line 14: `    <div class="modal">`

- [ ] **Step 2: Add `max-width: 760px` to the modal div**

Find:
```php
    <div class="modal">
```
Replace with:
```php
    <div class="modal" style="max-width: 760px;">
```

- [ ] **Step 3: Commit**

```bash
cd c:/Users/James/Scripts/stratflow
git add templates/partials/user-story-modal.php
git commit -m "feat(user-stories): widen edit modal to 760px"
```

---

## Task 2: Rewrite user-stories.php with epic-grouped layout

**Files:**
- Modify: `templates/user-stories.php`

This task rewrites the entire template. Read it first to confirm current content, then replace completely.

- [ ] **Step 1: Read `templates/user-stories.php`** in full to confirm current structure.

- [ ] **Step 2: Write the complete new file**

Replace the entire contents of `templates/user-stories.php` with:

```php
<?php
/**
 * User Stories Template
 *
 * Displays user stories grouped under their parent work item (epic),
 * with per-epic AI generation, drag-and-drop reordering, edit modal,
 * dependency tracking, and CSV/JSON/Jira export.
 *
 * Variables: $project (array), $stories (array), $work_items (array),
 *            $csrf_token (string)
 */

// Group stories by parent work item id (0 = unlinked)
$storiesByItem = [];
foreach ($stories as $story) {
    $pid = (int) ($story['parent_hl_item_id'] ?? 0);
    $storiesByItem[$pid][] = $story;
}
$unlinkedStories = $storiesByItem[0] ?? [];
?>

<!-- ===========================
     Page Header
     =========================== -->
<div class="page-header flex justify-between items-center">
    <h1 class="page-title">
        <?= htmlspecialchars($project['name']) ?> &mdash; User Stories
        <span class="page-title-count"><?= count($stories) ?></span>
        <span class="page-info" tabindex="0" role="button" aria-label="About this page">
            <span class="page-info-btn" aria-hidden="true">i</span>
            <span class="page-info-popover" role="tooltip">User stories break down high-level work items into developer-ready tasks of approximately 3 days each. Select work items to decompose, manage dependencies, and export to your project management tool.</span>
        </span>
    </h1>
    <div class="flex items-center gap-2">
        <?php $sync_type = 'user_stories'; include __DIR__ . '/partials/jira-sync-button.php'; ?>
        <?php include __DIR__ . '/partials/sounding-board-button.php'; ?>
        <?php if (!empty($stories)): ?>
        <form method="POST" action="/app/user-stories/delete-all" class="inline-form"
              onsubmit="return confirm('Delete all <?= count($stories) ?> stories for this project? This cannot be undone.')">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Delete All</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="toggleStoryModal()">Add Story</button>
    </div>
</div>

<!-- ===========================
     HL Item Selector — Batch AI Decomposition
     =========================== -->
<?php if (!empty($work_items)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3>Split Work Items to User Stories (AI)</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/app/user-stories/generate"
              data-loading="Decomposing into user stories..."
              data-overlay="Decomposing work items into user stories. This may take 15-30 seconds.">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <div class="hl-selector">
                <label class="checkbox-label" style="font-weight:600; border-bottom:1px solid var(--border); padding-bottom:0.5rem; margin-bottom:0.5rem;">
                    <input type="checkbox" id="select-all-hl" onchange="document.querySelectorAll('input[name=\'hl_item_ids[]\']').forEach(cb => cb.checked = this.checked)">
                    Select All
                </label>
                <?php foreach ($work_items as $wi): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
                        <?= htmlspecialchars($wi['title']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-ai btn-sm mt-2" onclick="return document.querySelectorAll('input[name=\'hl_item_ids[]\']:checked').length > 0 || (alert('Select at least one work item.'), false)">
                Split to User Stories
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Per-Epic Story Sections
     =========================== -->
<?php if (!empty($work_items)): ?>
    <?php foreach ($work_items as $epicIndex => $wi): ?>
        <?php
        $epicStories    = $storiesByItem[(int) $wi['id']] ?? [];
        $epicHasStories = !empty($epicStories);
        ?>
        <div class="card mb-4">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span class="priority-number" style="flex-shrink:0;"><?= $epicIndex + 1 ?></span>
                    <div>
                        <strong style="font-size:0.9375rem;"><?= htmlspecialchars($wi['title']) ?></strong>
                        <div class="text-muted" style="font-size:0.8rem; margin-top:0.1rem;">
                            Owner: <?= htmlspecialchars($wi['owner'] ?? 'Unassigned') ?>
                        </div>
                    </div>
                </div>
                <form method="POST" action="/app/user-stories/generate"
                      data-loading="Generating user stories...">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <input type="hidden" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
                    <button type="submit" class="btn btn-ai btn-sm">
                        <?= $epicHasStories ? '✨ Generate More Stories' : '✨ Generate User Stories' ?>
                    </button>
                </form>
            </div>
            <?php if ($epicHasStories): ?>
            <div class="card-body" style="padding:0;">
                <div class="user-stories-list">
                    <?php foreach ($epicStories as $story): ?>
                        <?php require __DIR__ . '/partials/user-story-row.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body">
                <p style="color:var(--text-secondary); font-style:italic; margin:0; font-size:0.875rem;">
                    No User Stories generated yet. Click the button to decompose this epic.
                </p>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ===========================
     Unlinked Stories (no parent work item)
     =========================== -->
<?php if (!empty($unlinkedStories)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3>Unlinked Stories</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="user-stories-list">
            <?php foreach ($unlinkedStories as $story): ?>
                <?php require __DIR__ . '/partials/user-story-row.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Empty state (no work items defined yet)
     =========================== -->
<?php if (empty($work_items) && empty($stories)): ?>
<div class="card mb-6">
    <div class="card-body text-center" style="padding:3rem;">
        <p class="text-muted" style="font-size:1.125rem;">
            No user stories yet. Select work items above to decompose with AI, or add stories manually.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Export + Regenerate Sizing Section
     =========================== -->
<?php if (!empty($stories)): ?>
<div class="card mb-6">
    <div class="card-body export-section">
        <div class="flex items-center justify-between" style="flex-wrap: wrap; gap: 1rem;">
            <div>
                <strong>Export User Stories</strong>
                <div class="flex items-center gap-2 mt-2">
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=csv" class="btn btn-secondary btn-sm">
                        Export CSV
                    </a>
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=json" class="btn btn-secondary btn-sm">
                        Export JSON
                    </a>
                    <a href="/app/user-stories/export?project_id=<?= (int) $project['id'] ?>&format=jira" class="btn btn-secondary btn-sm">
                        Export Jira CSV
                    </a>
                </div>
            </div>
            <form method="POST" action="/app/user-stories/regenerate-sizing"
                  data-loading="Re-estimating story sizes..."
                  data-overlay="Re-estimating story point sizes for all user stories using AI.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                <button type="submit" class="btn btn-ai btn-sm"
                        onclick="return confirm('Re-estimate story point sizes for all user stories using AI?')">
                    Regenerate Sizing
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===========================
     Add/Edit Story Modal
     =========================== -->
<?php require __DIR__ . '/partials/user-story-modal.php'; ?>

<?php require __DIR__ . '/partials/workflow-nav.php'; ?>

<!-- SortableJS CDN -->
<script defer src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
```

- [ ] **Step 3: Commit**

```bash
cd c:/Users/James/Scripts/stratflow
git add templates/user-stories.php
git commit -m "feat(user-stories): group stories under epics, per-epic generate button, Delete All to header"
```

---

## Task 3: Update SortableJS to handle multiple epic lists

**Files:**
- Modify: `public/assets/js/app.js` (around lines 500–530)

The current code initialises SortableJS on a single `#user-stories-list` element. The new template has one `.user-stories-list` per epic. This task updates JS to initialise on each, while keeping global priority numbering across all lists.

- [ ] **Step 1: Read `public/assets/js/app.js` lines 498–531** to confirm the exact current block.

Expected content:
```js
    // ===========================
    // User Stories: SortableJS Drag & Drop
    // ===========================
    var storyList = document.getElementById('user-stories-list');
    if (storyList && typeof Sortable !== 'undefined') {
        Sortable.create(storyList, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                var items = storyList.querySelectorAll('.story-row');
                var order = [];
                items.forEach(function(el, index) {
                    order.push({ id: parseInt(el.dataset.id), position: index + 1 });
                    el.querySelector('.priority-number').textContent = index + 1;
                });

                var csrfToken = document.querySelector('input[name="_csrf_token"]');
                fetch('/app/user-stories/reorder', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        order: order,
                        _csrf_token: csrfToken ? csrfToken.value : ''
                    })
                });
            }
        });
    }
```

- [ ] **Step 2: Replace the SortableJS block**

Find the exact block above and replace with:

```js
    // ===========================
    // User Stories: SortableJS Drag & Drop
    // Supports multiple .user-stories-list containers (one per epic).
    // onEnd collects all story rows across all lists in DOM order
    // to maintain a single global priority numbering.
    // ===========================
    var storyLists = document.querySelectorAll('.user-stories-list');
    if (storyLists.length > 0 && typeof Sortable !== 'undefined') {
        storyLists.forEach(function(list) {
            Sortable.create(list, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() {
                    // Collect ALL story rows across ALL epic lists in DOM order
                    var allRows = document.querySelectorAll('.user-stories-list .story-row');
                    var order = [];
                    allRows.forEach(function(el, index) {
                        order.push({ id: parseInt(el.dataset.id), position: index + 1 });
                        el.querySelector('.priority-number').textContent = index + 1;
                    });

                    var csrfToken = document.querySelector('input[name="_csrf_token"]');
                    fetch('/app/user-stories/reorder', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            order: order,
                            _csrf_token: csrfToken ? csrfToken.value : ''
                        })
                    });
                }
            });
        });
    }
```

- [ ] **Step 3: Commit**

```bash
cd c:/Users/James/Scripts/stratflow
git add public/assets/js/app.js
git commit -m "feat(user-stories): update SortableJS for multi-list grouped layout"
```

---

## Task 4: Verification and push

- [ ] **Step 1: Run PHP unit tests** (if Docker is available)

```bash
cd c:/Users/James/Scripts/stratflow
docker compose exec php vendor/bin/phpunit tests/Unit --no-coverage 2>&1 | tail -5
```

Expected: same pre-existing failures only, no new failures.

- [ ] **Step 2: Smoke-test the screen**

1. Navigate to a project's User Stories screen
2. Verify each work item renders as a separate card with epic number badge, title, Owner subline, and "✨ Generate User Stories" / "✨ Generate More Stories" button
3. Verify the batch generate card (checkbox selector) is still visible at the top
4. Verify stories appear under their correct epic
5. Verify unlinked stories (if any) appear in a final "Unlinked Stories" section
6. Verify the Delete All button is now in the page header (not in a card)
7. Click "Add Story" → verify modal opens and is noticeably wider (~760px)
8. Verify drag handles on stories still work (drag a story to reorder within its epic group)

- [ ] **Step 3: Push**

```bash
cd c:/Users/James/Scripts/stratflow
git push
```
