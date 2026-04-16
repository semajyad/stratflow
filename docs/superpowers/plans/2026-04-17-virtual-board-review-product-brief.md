# Virtual Board Review — Product Brief

**For:** Product Owner / Product Manager  
**Status:** Awaiting approval  
**Linked to:** [Issue #44](https://github.com/semajyad/stratflow/issues/44) | [Technical Plan](2026-04-17-virtual-board-review.md)

---

## What Are We Building?

We're adding a **Virtual Board Review** button to four pages in the app. When clicked, it simulates a live boardroom discussion between AI-powered personas who critique the content on that page — then gives the user a single collective recommendation they can accept or reject.

Think of it as a fast, ruthless peer review from a room full of experts, available on demand.

---

## Why Are We Building It?

The existing **Sounding Board** feature gives independent opinions from each persona, one at a time. Users then have to read them all and decide what to do themselves.

The Virtual Board Review is different: the board **talks to each other**, reaches a **consensus**, and tells the user exactly what to change. It's faster, more decisive, and feels more like a real executive or product review session.

---

## Who Gets Access?

Only users on plans with the **Evaluation Board** feature enabled (the same gate as the existing Sounding Board). No changes to billing or subscription tiers are needed.

---

## The Two Boards

| Board | Used on | Personas |
|-------|---------|---------|
| **Virtual Executive Board** | Strategy summary, Roadmap diagram | CEO, CFO, COO, CMO, Enterprise Business Strategist |
| **Virtual Product Management Board** | Work Items, User Stories | Agile Product Manager, Product Owner, System Architect, Senior Developer |

The right board is selected automatically based on which page the user is on.

---

## User Journey (Step by Step)

### 1. Button appears on the page
A **"Virtual Executive Board"** or **"Virtual Product Management Board"** button appears alongside existing action buttons. It only shows if the user's subscription includes the Evaluation Board feature.

### 2. User opens the review
Clicking the button opens a panel. The user picks a **criticism level**:
- **Devil's Advocate** — constructive pushback
- **Red Teaming** — stress-test for failure modes
- **Gordon Ramsay** — brutally direct, no filter

### 3. The board deliberates
The user clicks **Start Review**. A loading message shows: *"The board is deliberating…"*

Behind the scenes, one AI call simulates 10–14 turns of back-and-forth conversation between all board members (cheaper and faster than running them separately).

### 4. Results appear
The panel displays:
- A **chat thread** showing the board conversation (named speakers, alternating bubbles)
- A **Recommendation card** with:
  - A one-sentence verdict
  - A 2–3 sentence rationale
  - The specific changes proposed

### 5. Accept or Reject
Two buttons at the bottom:

**Accept Recommendation** → the app immediately applies the proposed changes:
| Page | What changes |
|------|-------------|
| Strategy Summary | The summary text is rewritten |
| Roadmap Diagram | The Mermaid diagram is replaced with the revised version |
| Work Items | Items are added, updated, or removed as recommended |
| User Stories | Stories are added, updated, or removed as recommended |

**Reject** → nothing changes. The outcome is recorded for audit purposes.

---

## What Gets Stored?

Every board review is saved to the database with:
- A snapshot of the content at the time of review (for audit)
- The full conversation
- The recommendation and proposed changes
- Whether it was accepted or rejected, and by whom

This means users can always look back at past reviews.

---

## What This Feature Is NOT

- It does **not** replace the existing Sounding Board — both features coexist
- It does **not** change pricing or subscription tiers
- It does **not** run multiple AI calls per review (one call, simulated conversation — cost-controlled)
- It does **not** auto-apply changes without the user clicking Accept

---

## Pages Affected

| Page | Change |
|------|--------|
| Upload (Summary) | New "Virtual Executive Board" button added |
| Roadmap/Diagram | New "Virtual Executive Board" button added |
| Work Items | New "Virtual Product Management Board" button added |
| User Stories | New "Virtual Product Management Board" button added |

---

## Risks & Considerations

| Risk | Mitigation |
|------|-----------|
| AI proposes poor changes that user accidentally accepts | User must explicitly click Accept — no auto-apply. Rejected state is also stored. |
| AI returns garbled output | Existing retry logic handles this; error state shown to user if it fails |
| Work item / user story changes fail halfway | Changes are wrapped in a database transaction — either all apply or none do |

---

## Open Questions for PO/PM

1. Should the board review history be visible to all team members on a project, or only the person who ran it?
2. Should there be a character/length cap on the content sent for review (e.g. very long work item lists)?
3. Should users be able to re-run a review on the same content, or only after content has changed?

---

## Approval

To approve this feature for implementation, reply or comment: **"Approved"**.  
To request changes, note them below and the brief will be updated before implementation begins.
