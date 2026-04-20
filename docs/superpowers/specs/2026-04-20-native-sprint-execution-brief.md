# Native Sprint Execution — Product Brief

**For:** Martin Rajcok (Product Manager, StratFlow)
**Status:** Draft — awaiting PM review & sign-off
**Date:** 2026-04-20

---

## What Are We Building?

A lightweight sprint execution layer for teams that don't use Jira or Azure DevOps.

StratFlow already lets users create sprints with dates and assign stories to them. What's missing is the *execution* side: starting a sprint, running it day-to-day, and closing it cleanly. This brief covers the gap between "sprint planned" and "sprint done."

The feature is deliberately constrained — it is **not** a replacement for Jira. It provides just enough to run a sprint: activate it, track story progress on a daily scrum board, close it, and handle the leftovers. Traceability and dashboard data already in StratFlow is what makes it valuable on top of a plain kanban board.

---

## What Already Exists

| Capability | Status |
|---|---|
| Create sprint with name, dates, team capacity | ✅ Exists |
| Assign user stories to sprints (drag-and-drop allocation board) | ✅ Exists |
| Sprint status enum: `planning` → `active` → `completed` | ✅ Exists (schema only — no UI transitions) |
| Story statuses: `backlog`, `in_progress`, `in_review`, `done`, `closed` | ✅ Exists |
| Story size (points), quality scoring, OKR traceability | ✅ Exists |
| Per-sprint dashboards / Roadmap visibility | ✅ Exists |
| Daily scrum / kanban execution board | ❌ Missing |
| Sprint open/close mechanics (manual + automatic) | ❌ Missing |
| Incomplete-story resolution on sprint close | ❌ Missing |
| Org-level settings for auto open/close | ❌ Missing |

---

## Who Gets Access?

All users with project access. No new subscription gate proposed for v1 — this closes a gap in the core product for non-integrated teams.

**MR — Should native sprint execution be part of the base plan or gated?**
MR-This should be native functionality

---

## User Journey (Step by Step)

### 1. Sprint Start — manual or automatic

A sprint in `planning` state can be started in two ways:

**Manual:** A project owner or team lead clicks *Start Sprint* on the sprint card. A confirmation prompt shows the start date, end date, and story count. They confirm; the sprint moves to `active`.

**Automatic:** On the morning of `start_date` (via a scheduled job), if the sprint is still in `planning` state, it transitions to `active` automatically. A notification is posted to the project activity feed.

The active sprint is visually distinguished on the Roadmap and the sprint list — a green "Active" badge replaces "Planning."

### 2. Daily Scrum Board

A new page accessible from the active sprint: `/app/sprints/{id}/board`.

Three columns:
| Column | Maps to story status |
|---|---|
| Sprint Backlog | `backlog` (assigned to this sprint, not started) |
| In Progress | `in_progress` |
| Done | `done` |

Stories can be dragged between columns or advanced via a status dropdown. `in_review` stories sit in the *In Progress* column with a visual "In Review" chip — they don't get their own column to keep the board simple.

When a story is moved to **Done**:
- Its status becomes `done`
- It is marked as contributing to sprint progress
- Quality score + OKR contribution metrics update immediately (existing systems)
- A *Close story* button becomes available (moves status to `closed`)

The board shows per-column story counts and total points, so teams have a live velocity reading without a separate report.

**MR — should moving to Done auto-close the story, or should closing be a separate explicit action?**
We can copy functionality from Jira. By moving a story to the "done" we can open the story in the modal window, so the user can add comment. On the bottom of the modal window, we can have a button to cancel or close the story.


### 3. Sprint Close — manual or automatic

**Manual close:** A *Close Sprint* button is visible to project owners and team leads at any time once a sprint is active. Clicking it opens the Sprint Close dialogue (see step 4).

**Automatic close:** At the end of the day on `end_date`, if the sprint is still `active`, the system triggers the Sprint Close flow and sends an in-app notification to the project owner to complete it. Incomplete stories are held in a pending state until the owner resolves them.

**MR — for automatic close, should the system block the project (require resolution before proceeding) or just surface a notification and let work continue?**
Yes, the system should block the project and wait for a resolution. It should also postpone the next Sprint start until the current Sprint's closure is resolved.

### 4. Sprint Close Dialogue — handling incomplete stories

On close, any stories not in `done` or `closed` status trigger the resolution dialogue:

> *"3 stories are not done. What would you like to do?"*

Three options (selectable per-story or applied to all):

| Option | What happens |
|---|---|
| **Close** | Story status → `closed`. Sprint ends. |
| **Move to next sprint** | Story unlinked from closing sprint, linked to the next sprint in the project (if one exists in `planning` state). If no next sprint exists, treated as Backlog. |
| **Return to backlog** | Story status → `backlog`, sprint link removed. The story appears in the unassigned backlog. |

Once all incomplete stories are resolved, the sprint status → `completed`. A sprint summary card is generated showing: stories completed, points delivered, stories carried over, and stories closed incomplete.

**MR — should the sprint summary card be emailed/pushed to an in-app notification, or just shown inline?**
Just show it online, but it should be stored in the DB and available for future review if needed

### 5. Progress Visibility

While a sprint is active:
- The project Dashboard shows a sprint progress widget: X of Y stories done, Z points delivered vs. capacity.
- The Roadmap shows the active sprint as a bar with a fill indicator.
- Stories in `done`/`closed` state count toward measurable sprint progress.

This reuses existing dashboard and traceability infrastructure — no new data model for progress calculation.

---

## What Gets Stored?

**Schema changes required:**

| Change | Reason |
|---|---|
| `sprints.started_at DATETIME NULL` | Record actual start time (vs. planned start_date) |
| `sprints.closed_at DATETIME NULL` | Record actual close time |
| `sprint_stories.sprint_status ENUM('backlog','in_progress','done')` | Board column state per story per sprint (decoupled from story's own status if needed — see open question) |

**MR — Should the sprint board column be stored separately from the story's own status, or should they be the same field? Separate allows a story to show "Done in Sprint X" while being reopened for a bug in Sprint Y. Same is simpler.**
It should be stored separately, as that will be easier for the system users to manage and fix stories

**New settings:**

| Setting | Location |
|---|---|
| `organisations.sprint_auto_open TINYINT(1)` | Org admin — auto-start sprints on start_date |
| `organisations.sprint_auto_close TINYINT(1)` | Org admin — auto-close sprints on end_date |
| `organisations.sprint_default_incomplete_action ENUM('close','carry','backlog')` | Default resolution for incomplete stories (can be overridden per sprint close) |

---

## Pages Affected

| Page | Change |
|---|---|
| Sprint list (`/app/sprints`) | Add *Start Sprint* button on planning sprints; *Close Sprint* on active sprints |
| Sprint detail | Show active/completed state; link to Board |
| **Sprint Board** (new) `/app/sprints/{id}/board` | Daily scrum kanban — three columns, drag-and-drop, story point totals |
| Sprint Close dialog (new modal) | Incomplete story resolution UI |
| Project Dashboard | Sprint progress widget (X/Y stories, points delivered) |
| Roadmap | Active sprint fill indicator |
| Org Admin Settings | Auto open / auto close toggles; default incomplete action |

---

## What This Feature Is NOT

- Not a full Jira replacement. No issue linking, epic hierarchies beyond what StratFlow already has, or time tracking.
- Not a reporting suite. The sprint summary is a one-page card, not a burndown chart (v2 candidate).
- Not multi-sprint parallelism. One active sprint per project at a time in v1.

**MR — is one active sprint per project sufficient for v1, or do teams need concurrent sprint support?**
There should be one active sprint per project, and, if there are multiple teams working on the project, one active sprint per team. So there can be multiple active Sprints per project, but only one open Sprint per team.

---

## Open Questions for PM

1. **Subscription gate:** Base plan or gated feature?
   MR — Base plan

2. **Auto-close behaviour:** Block project until resolved, or notify and let work continue?
   MR — Block project until resolved

3. **Story "Done" handling:** Auto-close on Done, or separate explicit Close action?
   MR — Action described above

4. **Sprint board column vs. story status:** Separate field or same field?
   MR — Separate field

5. **Sprint summary:** Inline only, or also email/in-app notification?
   MR — Inline only that would be saved for future review

6. **Concurrent sprints:** One active sprint per project, or multiple?
   MR —

7. **Burndown chart:** V1 or v2? (Data is available; it's a render question.)
   MR — It will be in the V2

8. **Who can start/close a sprint:** Project owner only, team lead, any project member?
   MR — Project owner and team lead only

9. **Auto open/close defaults:** Default on or off for new orgs?
   MR — default is off 

---

## Out of Scope for v1

- Burndown/velocity charts
- Sprint retrospective workflow
- Cross-project sprint views
- Time tracking/hour logging
- Sub-tasks within stories
- Sprint templates

---

## Approval

- [ ] Product Manager (Martin Rajcok) — brief reviewed, questions answered
- [ ] Engineering lead — schema delta confirmed, implementation plan requested
- [ ] → Then: detailed technical implementation plan

