# UX Polish Round 2 — Design Spec

**Date:** 2026-04-11
**Scope:** AI button styling, upload layout reorder, work items alignment + modal width, prioritisation score visibility + Fibonacci WSJF, risks RPN position + heatmap reorder + deselection fix

---

## Overview

Six independent screen improvements driven by enterprise user feedback. All changes are template, CSS, or JS only — no new routes, no DB migrations.

1. **Global AI button style** — `.btn-ai` class for all AI-driven actions
2. **Upload screen** — layout reordered to upload → generate → summary flow
3. **Work Items** — button right-alignment, wider edit modal
4. **Prioritisation** — sticky Score column, Fibonacci values for WSJF
5. **Risks** — RPN before title, heatmap above risk list, deselection scroll fix

---

## 1. Global AI Button Style

### CSS

Add to `public/assets/css/app.css`:

```css
/* === AI Generation Buttons ================================================ */

.btn-ai {
    background-color: #3b82f6;
    color: #ffffff;
    border: none;
    cursor: pointer;
    font-weight: 600;
}

.btn-ai:hover,
.btn-ai:focus {
    background-color: #2563eb;
    color: #ffffff;
}
```

No size or padding rules — `.btn-ai` is always used alongside `.btn` or `.btn-sm` which carry the sizing.

### Buttons that receive `.btn-ai` (replacing existing `.btn-primary` or `.btn-secondary`)

| Template | Button label |
|---|---|
| `templates/upload.php` | "Generate AI Summary", "Summarise" (in document list) |
| `templates/diagram.php` | "Generate Roadmap", "Regenerate" (header), "Generate OKRs (AI)" |
| `templates/work-items.php` | "Generate Work Items", "Regenerate Work Items", "Regenerate Sizing" |
| `templates/user-stories.php` | "Split to User Stories", "Regenerate Sizing" |
| `templates/prioritisation.php` | "AI Suggest Scores" |
| `templates/risks.php` | "Generate Risks (AI)" |
| `templates/partials/risk-row.php` | "Generate Mitigation (AI)" |
| `templates/partials/work-item-row.php` | "Improve with AI" |
| `templates/partials/user-story-row.php` | "Improve with AI" |
| `templates/governance.php` | "Run Detection" |
| `templates/sprints.php` | "Generate Sprints", "Auto-Fill Sprints" |

Buttons that are NOT changed: Save, Add, Edit, Delete, Export, Cancel, Re-rank, Create Baseline, Add Risk, Add Work Item, Add Story, Upload & Extract, Continue to next screen.

---

## 2. Upload Screen Layout Reorder

### Current order
1. Summary Ready card (green) — if summary exists
2. Generate AI Summary prompt card — if text extracted but no summary
3. Extraction Failure card — if extraction failed
4. Upload section (drop zone + paste)
5. Previous documents

### New order
1. **Upload section** — always first (primary action)
2. **Extraction Failure card** — immediately below upload (contextual feedback on last upload)
3. **Generate AI Summary prompt** — below upload, shown when text extracted but no summary; "Generate AI Summary" button uses `.btn-ai`
4. **Summary Ready** — below generate prompt area, shown when summary exists
5. **Previous documents** — at bottom

The "Continue to Strategy Roadmap →" button in the page header stays unchanged.

The "Summarise" button in the previous documents list uses `.btn-ai`.

---

## 3. Work Items Screen

### Button right-alignment

In the "AI Work Item Generation" card (`templates/work-items.php`), the button group div:
```php
<div class="flex items-center gap-2" style="flex-wrap: wrap;">
```
Add `margin-left: auto` to this div:
```php
<div class="flex items-center gap-2" style="flex-wrap: wrap; margin-left: auto;">
```
This forces the button group right even when the flex container wraps.

### Wider edit modal

In `templates/partials/work-item-modal.php` line 12, find `<div class="modal">` and add `max-width: 760px`:
```php
<div class="modal" style="max-width: 760px;">
```
Other modals (risk, sprint, framework-info) are unchanged.

---

## 4. Prioritisation Screen

### Sticky Score column

The Score column (last `<th>` and `<td>`) becomes sticky-right so it's always visible regardless of table scroll position.

In `templates/prioritisation.php`:
- `<th>` Score: add `style="position: sticky; right: 0; background: var(--bg, #fff); z-index: 1;"`
- `<td class="final-score">` in each row: add `style="position: sticky; right: 0; background: var(--bg, #fff);"`

### Fibonacci WSJF dropdowns

The scoring dropdown is currently `for ($n = 1; $n <= 10; $n++)` for both RICE and WSJF.

For WSJF, replace with SAFe Fibonacci sequence `[1, 2, 3, 5, 8, 13, 20]`.

In `templates/prioritisation.php`, change the dropdown generation inside the `foreach ($fields as $i => $field)` loop:

```php
<?php if ($isRice): ?>
    <option value="0" <?= $vals[$i] === 0 ? 'selected' : '' ?>>-</option>
    <?php for ($n = 1; $n <= 10; $n++): ?>
        <option value="<?= $n ?>" <?= $vals[$i] === $n ? 'selected' : '' ?>><?= $n ?></option>
    <?php endfor; ?>
<?php else: ?>
    <option value="0" <?= $vals[$i] === 0 ? 'selected' : '' ?>>-</option>
    <?php foreach ([1, 2, 3, 5, 8, 13, 20] as $n): ?>
        <option value="<?= $n ?>" <?= $vals[$i] === $n ? 'selected' : '' ?>><?= $n ?></option>
    <?php endforeach; ?>
<?php endif; ?>
```

Existing stored scores that fall outside the Fibonacci sequence are preserved in the DB and still displayed correctly — the dropdown just won't show them as "selected" until they're updated.

---

## 5. Risks Screen

### RPN in front of title

In `templates/partials/risk-row.php`, move the `risk-rpn` badge from `.risk-scores` to inline before the title in `.risk-info`:

Current:
```php
<div class="risk-info">
    <strong><?= htmlspecialchars($risk['title']) ?></strong>
    ...
</div>
<div class="risk-scores">
    <span class="risk-badge ...">L: ...</span>
    <span class="risk-badge ...">I: ...</span>
    <span class="risk-rpn">RPN: <?= $rpn ?></span>
</div>
```

New:
```php
<div class="risk-info">
    <div style="display: flex; align-items: center; gap: 0.5rem;">
        <span class="risk-rpn">RPN: <?= $rpn ?></span>
        <strong><?= htmlspecialchars($risk['title']) ?></strong>
    </div>
    ...
</div>
<div class="risk-scores">
    <span class="risk-badge ...">L: ...</span>
    <span class="risk-badge ...">I: ...</span>
</div>
```

### Heatmap above risk list

In `templates/risks.php`, move the heatmap `<div class="card mb-6">` (currently last, lines ~141–186) to immediately after the page header and modals section — before the Risk List card. Risk List renders below the heatmap.

### Heatmap deselection scroll fix

In `templates/risks.php`, the `clearHeatmapFilter()` JS function currently removes the selected-cell class and shows all rows but leaves the viewport scrolled to wherever `filterHeatmapRisks()` scrolled it. The previously-filtered risk row appears "still selected" because it's sitting at the top of the viewport.

Fix: after re-showing all rows, scroll the risk list container back to its top:

```js
function clearHeatmapFilter() {
    heatmapFilter = { likelihood: null, impact: null };
    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.classList.remove('heatmap-cell--selected');
    });
    document.querySelectorAll('.risk-row').forEach(function(row) { row.style.display = ''; });
    var banner = document.getElementById('heatmap-filter-banner');
    if (banner) banner.remove();
    // Scroll risk list back to top so no row appears pinned/selected
    var riskList = document.querySelector('.risk-list');
    if (riskList) riskList.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
```

---

## Out of Scope

- Roadmap clickable nodes → OKR modal (separate Plan B spec)
- Any layout changes to screens not listed above
- CSS variable changes or theme overhaul
- Mobile-specific responsive changes
