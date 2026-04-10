# User Stories — Grouped Layout Design Spec

**Date:** 2026-04-11
**Scope:** Epic-grouped story layout, per-epic generate button, wider edit modal

---

## Overview

Three improvements to the User Stories screen:

1. **Grouped layout** — stories displayed under their parent work item (epic), not in a flat list
2. **Per-epic generate button** — inline "✨ Generate User Stories" button per epic, no new routes needed
3. **Wider edit modal** — `max-width: 760px` matching the work-item modal

No controller changes. No DB migrations. No new routes.

---

## 1. Page Structure

New top-to-bottom order:

| # | Section | Condition |
|---|---------|-----------|
| 1 | Page header | Always |
| 2 | Batch generate card (checkbox selector + "Split to User Stories") | `!empty($work_items)` |
| 3 | Per-epic sections (one per work item) | Always (empty state shown if no stories) |
| 4 | Unlinked stories section | Only if stories exist with no `parent_hl_item_id` |
| 5 | Export + Regenerate Sizing card | `!empty($stories)` |

The flat "User Stories List" card is removed and replaced by sections 3–4.

---

## 2. PHP Grouping

At the top of `templates/user-stories.php`, after the existing variable declarations, add:

```php
// Group stories by parent work item
$storiesByItem = [];
foreach ($stories as $story) {
    $pid = (int) ($story['parent_hl_item_id'] ?? 0);
    $storiesByItem[$pid][] = $story;
}
$unlinkedStories = $storiesByItem[0] ?? [];
```

Then iterate `$work_items` to render each epic section. Stories for an epic: `$storiesByItem[(int) $wi['id']] ?? []`.

---

## 3. Epic Section Structure

Each work item renders as:

```
┌─────────────────────────────────────────────────────┐
│  [N]  Epic Title                    [✨ Generate...] │
│       Node: X | Owner: Y                            │
├─────────────────────────────────────────────────────┤
│  story-row  1.1  ...                                │
│  story-row  1.2  ...                                │
│  (or: italic empty state if no stories)             │
└─────────────────────────────────────────────────────┘
```

**Section header:**
- Numbered badge (`$epicIndex` starting at 1, matching work item priority display)
- Epic title (`$wi['title']`)
- Owner subline (`Owner: {$wi['owner'] ?? 'Unassigned'}`) — `node_id` is not in the `HLWorkItem` schema; only `owner` is available
- Inline generate button — right-aligned

**Generate button label:**
- No stories for this epic: `"✨ Generate User Stories"`
- Has stories: `"✨ Generate More Stories"`

**Generate button form:**
```php
<form method="POST" action="/app/user-stories/generate"
      data-loading="Generating user stories...">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
    <input type="hidden" name="hl_item_ids[]" value="<?= (int) $wi['id'] ?>">
    <button type="submit" class="btn btn-ai btn-sm">
        <?= $epicHasStories ? '✨ Generate More Stories' : '✨ Generate User Stories' ?>
    </button>
</form>
```

Posts to the existing `/app/user-stories/generate` controller — no changes needed there.

**Empty state (no stories for this epic):**
```html
<p style="color: var(--text-secondary); font-style: italic; padding: 1rem 1.5rem; margin: 0;">
    No User Stories generated yet. Click the button to decompose this epic.
</p>
```

---

## 4. Story Rows

Reuse the existing `user-story-row.php` partial unchanged. Stories within each epic section are wrapped in a `<div class="user-stories-list">` — SortableJS is initialised on each of these divs using the same existing reorder logic, scoping drag-drop within the epic.

Because SortableJS currently initialises on `#user-stories-list` (single ID), change the selector to `.user-stories-list` (class) and initialise on each instance.

---

## 5. Unlinked Stories Section

Below all epic sections, if `$unlinkedStories` is non-empty:

```
┌────────────────────────────┐
│  Unlinked Stories          │
├────────────────────────────┤
│  story-row ...             │
└────────────────────────────┘
```

Same story-row partial. Its own `.user-stories-list` div.

---

## 6. Delete All Button

Moved from the flat list card header to the **page header** (alongside "Add Story"), visible only when `count($stories) > 0`.

---

## 7. Edit Modal Width

In `templates/partials/user-story-modal.php`, find `<div class="modal">` and change to:

```php
<div class="modal" style="max-width: 760px;">
```

---

## 8. Batch Generate Card

Unchanged. Stays at top of page. Checkbox selector + "Split to User Stories" button as today.

---

## Files Changed

| File | Change |
|------|--------|
| `templates/user-stories.php` | Full rewrite: PHP grouping + epic sections + page header Delete All |
| `templates/partials/user-story-modal.php` | Add `max-width: 760px` to modal div |

No controller changes. No CSS changes (all existing classes reused).

---

## Out of Scope

- Drag-drop across epic groups (stories stay within their epic's sortable list)
- Collapsible epic sections
- Per-epic story count badges in the header
- Any changes to the generate controller or data model
