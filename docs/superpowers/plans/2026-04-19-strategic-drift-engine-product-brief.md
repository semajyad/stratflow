# Strategic Drift Engine — Product Brief

**For:** Martin Rajcok (Product Manager, StratFlow)
**Status:** Draft — awaiting PM review & sign-off
**Linked to:** existing Phase 4 scaffold (migration 004, `DriftDetectionService`), product concept from PM brain dump (2026-04-19)

---

## What Are We Building?

A three-layer governance system that watches what teams actually deliver against what leadership committed to when the strategy was approved — and quietly alerts when reality drifts away from that commitment.

Leadership today sets a strategy, Epics get broken into stories, and six weeks in nobody can easily answer "is the work we're doing still the work we signed up for?" The Strategic Drift Engine answers that question automatically.

It has three layers:

1. **Snapshot & Variance (the "receipts")** — the moment a project moves into Execution, we take a read-only photograph of the Epics and their estimated sizes. Thereafter we continuously compare reality to that photograph. The original strategy is never overwritten.

2. **Strategic Alignment Check (the AI reviewer)** — every time a new user story is added, Gemini silently evaluates it against the Epic's original OKR. If the story doesn't plausibly serve that goal, a "Strategic Misalignment Warning" is raised for the user to confirm or re-scope. *(Working name was "Scope Sentinel" — renamed for corporate fit; see Naming section below.)*

3. **Upward Impact Thresholds (the "tripwires")** — objective numeric rules that escalate an Epic to a *Requires Review* state when they fire. Two thresholds at v1:
   - **Capacity threshold:** Epic's total story points have grown > 20% from its AI-generated baseline
   - **Dependency threshold:** a new blocker spans 2+ teams, OR a blocker is likely to outlast one sprint

Crossing a threshold doesn't stop work — it locks the parent Epic to *Requires Review* status until a human (typically the PM or owner) acknowledges. Think of it as a circuit breaker for strategy.

---

## Why Are We Building It?

Three concrete pains:

- **Scope creep is invisible until it's too late.** By the time a leader notices an Epic has doubled in size, two sprints have already been spent on it.
- **"Strategy drift" is a conversation, not a signal.** Today the only way to catch a story that doesn't serve the OKR is for a human to notice it in grooming. Most slip through.
- **Blame-free accountability is hard.** When an Epic slips, there's no objective record of *when* it slipped or *what* changed. Snapshots give every retro a factual baseline.

The feature is designed to be quiet by default — it only surfaces itself when something meaningful changes, and even then it proposes review rather than blocks work.

---

## Important: Not a Greenfield Build

Migration `004_drift_engine.sql`, `src/Services/DriftDetectionService.php`, `src/Models/StrategicBaseline.php`, `src/Models/DriftAlert.php`, `src/Models/GovernanceItem.php`, `src/Services/Prompts/DriftPrompt.php`, and `src/Controllers/DriftController.php` **already exist** as a Phase 4 scaffold. The threshold types (scope_creep, capacity_tripwire, dependency_tripwire, alignment) are already encoded as `drift_alerts.alert_type` ENUM values. The `requires_review` flag already exists on both `hl_work_items` and `user_stories`.

This brief is therefore about **productising the existing scaffold** — turning an engineer-facing v0 into a PM-visible, subscription-gated, end-user feature — not building from zero. Implementation will largely be: wire it into UI, add a subscription gate, add missing columns (blocker duration), schedule baseline capture, and polish the alert/governance UX.

---

## Who Gets Access?

Two tiers to be decided by PM:

| Tier | Sees | Can Act |
|---|---|---|
| Any project member | Drift alerts on Epics they can view; Strategic Alignment Check warnings on stories they add | Dismiss their own Strategic Alignment Check warnings, add stories |
| Project owner / PM / Executive | Everything above + acknowledge tripwires and clear *Requires Review* status | Approve / reject drift, override thresholds |

Gated behind a new subscription flag `subscriptions.has_drift_engine` — mirrors the `has_evaluation_board` pattern already used by the Evaluation Board feature.

**MR — should the Drift Engine be part of the Evaluation Board bundle, a separate SKU, or included in the base plan?**

---

## Naming — Alternatives for PM to Choose

PM asked for names that work in a corporate environment. Current working names feel too technical/edgy. Options below; PM picks.

### For "Strategic Drift Engine" (umbrella feature name)

| Option | Feel | Notes |
|---|---|---|
| **Strategic Alignment Monitor** | Neutral, boardroom-safe | Clearly says what it does; pairs well with "monitor" as a feature category |
| **Strategic Commitment Tracker** | Accountability framing | Emphasises the "we signed up for X" angle |
| **Plan Integrity Monitor** | Audit / compliance feel | Good if selling to regulated industries |
| **Strategic Variance Watch** | Finance / FP&A tone | "Variance" is familiar to execs reading P&L reports |
| **Strategic Guardrails** | Informal, friendly | Less boardroom, more product-team-friendly |
| Strategic Drift Engine | Current working name | Arguably too technical — "engine" suggests plumbing, "drift" sounds negative |

*Recommendation: **Strategic Alignment Monitor*** — neutral, self-explanatory, the noun "monitor" correctly implies "watches and reports, doesn't block."

### For "Scope Sentinel" (the AI alignment checker)

"Sentinel" reads as military/security — not boardroom. Alternatives:

| Option | Feel | Notes |
|---|---|---|
| **Strategic Alignment Check** | Neutral, boardroom-safe | Matches the existing codebase — `checkAlignment` method and `ALIGNMENT_PROMPT` constant already use this language |
| **OKR Alignment Check** | Direct | Names the specific thing being compared |
| **Goal Fit Check** | Friendly, plain English | Short; reads well in UI strings ("Goal fit: low") |
| **Story Alignment Review** | Descriptive | Explicit about what's reviewed |
| **Alignment Advisor** | Consultative tone | Implies suggestion, not verdict |
| Scope Sentinel | Current working name | Security connotation; "sentinel" appears in Sentinel-branded products (confusable), not corporate-plain |

*Recommendation: **Strategic Alignment Check*** — consistent with existing code (`checkAlignment`), clear, and corporate-neutral.

### For "Tripwire" (the threshold mechanism)

| Option | Feel | Notes |
|---|---|---|
| **Review Threshold** | Boardroom-safe | Dry, clear, matches the existing `requires_review` flag verbatim |
| **Escalation Threshold** | Ops / incident-mgmt tone | Implies something up-stack is notified |
| **Governance Gate** | Compliance feel | Strong pair with existing `governance_queue` table |
| **Checkpoint** | Neutral, friendly | Short, non-alarming, works in UI copy |
| **Review Trigger** | Action-oriented | Reads naturally in sentences ("A review trigger fired on Epic X") |
| Tripwire | Current working name | Military/security connotation, possibly too alarming for exec audiences |

*Recommendation: **Review Threshold*** for formal copy, **Checkpoint** for inline UI. "Capacity Checkpoint" and "Dependency Checkpoint" read well in the product surface.

**MR — pick one name for each, and we'll use those consistently through implementation, UI copy, and all docs.**

---

## User Journey (Step by Step)

### 1. Baseline capture (automatic, invisible)

The moment a project transitions from Planning to Execution (an existing `projects.status` transition), the system snapshots:
- Every Epic with its current title, OKR, estimated sprints, and calculated final_score
- The total story count and total story points for each Epic
- A timestamp

Stored in `strategic_baselines.snapshot_json`. Read-only thereafter.

*UI touchpoint:* a small banner appears on the project dashboard — *"Strategic baseline captured on [date]. Reality will be compared against this commitment."*

**Open: should there be a manual "re-baseline" action for legitimate strategic pivots? See Open Questions.**

### 2. Story added → Strategic Alignment Check runs

A user adds a new user story under an Epic. The story is saved normally. In the background, `DriftDetectionService::checkAlignment` sends the story + the Epic's OKR to Gemini with the existing `ALIGNMENT_PROMPT`.

- **Aligned** → nothing visible happens. Story is flagged internally as alignment-checked. *Quiet by default.*
- **Not aligned** → a yellow banner appears on the story: *"Strategic Alignment Warning — this story may not serve the Epic's OKR: [short AI reason]. Confirm, re-scope, or move to a different Epic."*

The warning never blocks the save. The user can dismiss it with a one-click *"Confirm anyway"*.

### 3. Threshold fires → Epic goes to *Requires Review*

Every time a story is added, resized, or blocked, `DriftDetectionService::detectDrift` runs. If either threshold crosses:

- **Capacity:** `SUM(child stories.size) > baseline_size × 1.20`
- **Dependency:** a new blocker crosses team boundaries, OR blocker is estimated > 1 sprint

→ Epic's `requires_review` flag flips to 1, a `drift_alerts` row is created, and `governance_queue` gets a pending item. The Epic tile on the Roadmap gains a red "Requires Review" badge.

### 4. Reviewer acknowledges

The PM / project owner opens the Epic, sees a new *Drift* panel showing:
- The original snapshot (Epic title, OKR, estimated size, baseline story count)
- Current reality (size, story count, recent additions, active blockers)
- The specific threshold(s) that fired
- Buttons: *Approve drift (clear review state)*, *Roll back to baseline (revert recent additions)*, *Escalate (add note + notify team lead)*

Acknowledgement closes the alert, clears `requires_review`, and writes the decision to `governance_queue`.

### 5. Retro view (read-only history)

Anyone with project view access can open *Strategic History* on the project → see every baseline ever taken, every alert fired, and every governance decision recorded. This is the blame-free audit trail.

---

## What Gets Stored?

Existing tables (reused, no migration needed):
- `strategic_baselines` — baseline snapshots (already exists)
- `drift_alerts` — alert_type, severity, status (already exists)
- `governance_queue` — change_type, status, decided_by (already exists)
- `hl_work_items.requires_review` / `user_stories.requires_review` — locked flags (already exist)

New columns / tables (proposed):
- `subscriptions.has_drift_engine TINYINT(1)` — subscription gate
- `user_stories.blocked_since DATETIME`, `user_stories.blocker_resolved_at DATETIME` — so the Dependency threshold can tell if a blocker has lasted more than a sprint
- Optional: `strategic_baselines.org_id` — today inferred via project → denormalise for performance if needed

No raw Gemini prompt/response text is logged beyond the short "reason" string shown to the user — same privacy posture as Sounding Board.

---

## What This Feature Is NOT

- It is **not** a Jira replacement. It doesn't plan, estimate, or assign — it only watches.
- It does **not** block work. Every threshold and every Strategic Alignment Check warning can be acknowledged and work continues. The only thing locked is the *Requires Review* status, and even that is a flag, not a veto.
- It is **not** a governance approval workflow. We are not building multi-step approvals. One person clicks acknowledge; it's done.
- It does **not** modify strategy. The baseline is forever read-only. If leadership legitimately pivots, they must explicitly re-baseline (see Open Questions).
- It does **not** replace the existing Virtual Board Review or Sounding Board — those are on-demand decision tools, this is an always-on monitor. They complement each other.

---

## Pages Affected

| Page | Addition |
|---|---|
| Project Dashboard | New banner on Execution-phase projects showing baseline date + active alert count |
| Roadmap | Red *Requires Review* badge on affected Epic tiles |
| Work Items (Epic detail) | New *Drift* panel showing baseline vs current, with acknowledge buttons |
| User Stories | Yellow Strategic Alignment warning on flagged rows |
| Account / Subscription | New feature flag toggle (if separate SKU) |
| Strategic History (new page) | Read-only audit trail of baselines, alerts, decisions |

---

## Risks & Considerations

| Risk | Mitigation |
|---|---|
| **Alert fatigue** — Strategic Alignment Check fires too often on legitimate new stories | Tunable Gemini prompt; start strict, relax based on real-world false-positive rate. Warnings are dismissible, never blocking. |
| **Gemini cost at scale** — every story add = 1 Gemini call | Debounce (don't re-run on mere edits); same-cost order as Sounding Board (budget known). Cache per story hash. |
| **Gaming the baseline** — teams might push "pre-baseline" stories to avoid drift detection | Baseline capture is automatic on status change, so there's a clear before/after boundary. Governance queue keeps the audit trail. |
| **The 20% capacity number is arbitrary** | Make it configurable per project (default 20%). PM can raise/lower. |
| **Legitimate strategic pivots** look like drift | Explicit *Re-baseline* action (owner-only, logged) — see Open Questions. |
| **Existing scaffold assumes Phase 4 constraints** | Implementation plan will audit what the scaffold currently does vs. what this brief specifies and list the delta. |

---

## Open Questions for PO/PM

1. **Naming:** which option do you want for the feature umbrella name, and which for the threshold mechanism? (See Naming table above.)
 MR — 

2. **Access tier:** is the Drift Engine included in the base plan, bundled with Evaluation Board, or a separate SKU?
 MR — 

3. **Re-baseline:** when a legitimate strategic pivot happens, who can take a new baseline, and does it *replace* or *supplement* the old one? (Replace = simpler but destroys history; supplement = truthful but slightly more complex UX.)
 MR — 

4. **Default threshold values:** is 20% capacity growth the right default, or do you want something tighter (10%) / looser (33%)? And is "one sprint" the right duration for the dependency threshold, given sprint length varies by team?
 MR — 

5. **Strategic Alignment Check confidence:** should we only raise the warning when Gemini is highly confident (reducing noise but missing edge cases), or always raise and let the user dismiss (catches more, more noise)?
 MR — 

6. **Strategic Alignment Check applies to:** new stories only, or also to edits that materially change a story's description / acceptance criteria?
 MR — 

7. **Threshold firing behaviour:** when an Epic goes to *Requires Review*, should its child stories still be workable? I've assumed yes (non-blocking). Confirm.
 MR — 

8. **Who owns acknowledgement:** project owner, PM, executive, any project admin, or configurable per project?
 MR — 

9. **Retro / history visibility:** all project members, or owners/executives only? (Virtual Board Review chose "all project members" — consistent?)
 MR — 

10. **Integration with Virtual Board Review / Sounding Board:** should a *Requires Review* Epic optionally auto-trigger a Sounding Board session, or stay manual?
 MR — 

11. **Notification surface:** email, in-app, Slack, none? What's the v1 bar?
 MR — 

12. **First project to roll out to:** do you want a pilot project to validate the thresholds and alignment prompt before enabling for all orgs?
 MR — 

---

## Out-of-Scope for v1 (candidates for v2)

- Multi-threshold tuning per-Epic (only project-level at v1)
- Configurable alignment prompts per org
- ML-learned thresholds (v1 is deterministic)
- Slack / Teams notifications (v1 is in-app only — unless PM says otherwise in Q11)
- Strategic pivot wizard (v1 is a simple "re-baseline" button)
- Cross-project drift (v1 scopes to a single project at a time)
- API / webhook surface for drift alerts

---

## Approval

- [ ] Product Manager (Martin Rajcok) — brief reviewed, questions answered, names chosen
- [ ] Engineering lead — existing scaffold delta assessed, implementation plan requested
- [ ] → Then: detailed technical implementation plan is written using this brief + MR's answers as the source of truth.
