# Database Reference

StratFlow uses **MySQL 8.4** with a single database named `stratflow`. All tables use the `InnoDB` engine and `utf8mb4_unicode_ci` collation.

The schema file is at `database/schema.sql`. It is loaded automatically on first container start via the Docker entrypoint.

---

## Entity Relationships (Overview)

```
organisations
  ├── users (org_id → organisations.id)
  ├── subscriptions (org_id → organisations.id)
  ├── teams (org_id → organisations.id)
  │     └── team_members (team_id → teams.id, user_id → users.id)
  ├── persona_panels (org_id → organisations.id [nullable — NULL = system default])
  │     ├── persona_members (panel_id → persona_panels.id)
  │     └── evaluation_results (panel_id → persona_panels.id)
  └── projects (org_id → organisations.id)
        ├── documents (project_id → projects.id)
        ├── strategy_diagrams (project_id → projects.id)
        │     └── diagram_nodes (diagram_id → strategy_diagrams.id)
        ├── hl_work_items (project_id → projects.id,
        │                  diagram_id → strategy_diagrams.id [nullable])
        │     ├── hl_item_dependencies (item_id → hl_work_items.id,         ← Phase 5
        │     │                         depends_on_id → hl_work_items.id)
        │     └── risk_item_links (work_item_id → hl_work_items.id)
        ├── risks (project_id → projects.id)
        │     └── risk_item_links (risk_id → risks.id)
        ├── user_stories (project_id → projects.id,
        │                 parent_hl_item_id → hl_work_items.id [nullable],
        │                 blocked_by → user_stories.id [nullable])
        ├── sprints (project_id → projects.id)
        │     └── sprint_stories (sprint_id → sprints.id,
        │                         user_story_id → user_stories.id)
        ├── evaluation_results (project_id → projects.id)
        ├── strategic_baselines (project_id → projects.id)    ← Phase 4
        ├── drift_alerts (project_id → projects.id)           ← Phase 4
        └── governance_queue (project_id → projects.id,       ← Phase 4
                              reviewed_by → users.id [nullable])

login_attempts (standalone — tracks IP-based brute force attempts)
```

All project data is scoped to an organisation via `org_id`. Users belong to exactly one organisation.

---

## Tables

### `organisations`

Top-level tenant. Each paying customer is one organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `name` | VARCHAR(255) | NOT NULL | Organisation display name |
| `stripe_customer_id` | VARCHAR(255) | NULL | Stripe customer object ID |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 0 = suspended |
| `settings_json` | JSON | NULL | Org-level workflow settings: AI persona prompts, High Level item defaults, capacity tripwires |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

---

### `users`

Application users. Each user belongs to exactly one organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | |
| `email` | VARCHAR(255) | NOT NULL, UNIQUE | Login identifier |
| `password_hash` | VARCHAR(255) | NOT NULL | bcrypt hash |
| `full_name` | VARCHAR(255) | NOT NULL | |
| `role` | ENUM | NOT NULL, DEFAULT `user` | `user`, `org_admin`, `superadmin` |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE RESTRICT

---

### `subscriptions`

Tracks Stripe subscription state per organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | |
| `stripe_subscription_id` | VARCHAR(255) | NOT NULL | Stripe subscription object ID |
| `plan_type` | ENUM | NOT NULL | `product`, `consultancy` |
| `status` | ENUM | NOT NULL, DEFAULT `active` | `active`, `cancelled`, `expired` |
| `started_at` | DATETIME | NOT NULL | |
| `expires_at` | DATETIME | NULL | NULL = ongoing subscription |
| `user_seat_limit` | INT UNSIGNED | NOT NULL, DEFAULT 5 | Maximum number of user accounts allowed for this subscription tier |
| `has_evaluation_board` | TINYINT(1) | NOT NULL, DEFAULT 1 | 1 = org has access to Sounding Board and Virtual Board Review (on by default; toggled per-org via superadmin) |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE

---

### `login_attempts`

Rate-limiting table for brute force protection. Stores one row per login attempt, indexed by IP address and time.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `ip_address` | VARCHAR(45) | NOT NULL | Supports IPv6 |
| `attempted_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Indexes:** `idx_ip_time (ip_address, attempted_at)`

---

### `projects`

A project is the top-level work unit within an organisation. It owns documents, diagrams, work items, risks, user stories, and sprints.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | Multi-tenancy scope key |
| `name` | VARCHAR(255) | NOT NULL | |
| `status` | ENUM | NOT NULL, DEFAULT `draft` | `draft`, `active`, `completed` |
| `selected_framework` | ENUM | NULL | `rice`, `wsjf` — chosen prioritisation framework; NULL until set |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE; `created_by` → `users.id` ON DELETE RESTRICT

**Added in:** migration `001_v1_completion.sql` (`selected_framework` column)

---

### `documents`

Files uploaded to a project. Text is extracted at upload time; AI summary is generated on demand.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `filename` | VARCHAR(255) | NOT NULL | Storage filename (UUID-based) |
| `original_name` | VARCHAR(255) | NOT NULL | Original user filename |
| `mime_type` | VARCHAR(100) | NOT NULL | e.g. `application/pdf` |
| `file_size` | INT UNSIGNED | NOT NULL | Bytes |
| `extracted_text` | LONGTEXT | NULL | Raw text extracted from file |
| `ai_summary` | TEXT | NULL | Gemini-generated summary (populated on demand) |
| `uploaded_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `uploaded_by` → `users.id` ON DELETE RESTRICT

---

### `strategy_diagrams`

A versioned Mermaid.js diagram for a project. Each save creates or updates a record; `version` increments with each save.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `mermaid_code` | TEXT | NOT NULL | Raw Mermaid.js source |
| `version` | INT UNSIGNED | NOT NULL, DEFAULT 1 | Incremented on each save |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `created_by` → `users.id` ON DELETE RESTRICT

---

### `diagram_nodes`

Individual nodes extracted from a diagram, with optional OKR metadata attached by the user.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `diagram_id` | INT UNSIGNED | NOT NULL, FK → strategy_diagrams | |
| `node_key` | VARCHAR(100) | NOT NULL | Mermaid node ID (e.g. `A`, `STR1`) |
| `label` | VARCHAR(255) | NOT NULL | Node display label |
| `okr_title` | VARCHAR(255) | NULL | OKR title set by user |
| `okr_description` | TEXT | NULL | OKR description set by user |

**Foreign keys:** `diagram_id` → `strategy_diagrams.id` ON DELETE CASCADE

---

### `hl_work_items`

High-Level Work Items (High Level Work Items) generated from the strategy diagram. Each item represents approximately one month of team effort. Phase 1 added RICE/WSJF scoring columns and a computed final score.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `diagram_id` | INT UNSIGNED | NULL, FK → strategy_diagrams | NULL if diagram was deleted |
| `priority_number` | INT UNSIGNED | NOT NULL | Drag-reorder priority (1 = highest) |
| `title` | VARCHAR(255) | NOT NULL | |
| `description` | TEXT | NULL | AI-generated or manually edited scope |
| `strategic_context` | TEXT | NULL | Which diagram nodes this maps to |
| `okr_title` | VARCHAR(255) | NULL | Inherited from diagram node |
| `okr_description` | TEXT | NULL | Inherited from diagram node |
| `owner` | VARCHAR(255) | NULL | Assigned team or person |
| `estimated_sprints` | INT UNSIGNED | NOT NULL, DEFAULT 2 | Default = 2 sprints (~1 month) |
| `rice_reach` | INT UNSIGNED | NULL | RICE: users/stakeholders impacted (1–10) |
| `rice_impact` | INT UNSIGNED | NULL | RICE: significance of impact per user (1–10) |
| `rice_confidence` | INT UNSIGNED | NULL | RICE: confidence in estimates (1–10) |
| `rice_effort` | INT UNSIGNED | NULL | RICE: effort required (1–10) |
| `wsjf_business_value` | INT UNSIGNED | NULL | WSJF: business value delivered (1–10) |
| `wsjf_time_criticality` | INT UNSIGNED | NULL | WSJF: urgency (1–10) |
| `wsjf_risk_reduction` | INT UNSIGNED | NULL | WSJF: risk/opportunity addressed (1–10) |
| `wsjf_job_size` | INT UNSIGNED | NULL | WSJF: size of work (1–10) |
| `final_score` | DECIMAL(10,2) | NULL | Computed RICE or WSJF score; NULL until scored |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |
| `requires_review` | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = flagged by Drift Engine; cleared when governance item is approved |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `diagram_id` → `strategy_diagrams.id` ON DELETE SET NULL

**Added in:** migration `001_v1_completion.sql` (all `rice_*`, `wsjf_*`, and `final_score` columns); `requires_review` added in Phase 4 (Strategic Drift Engine)

---

### `risks`

Project risks identified manually or via AI. Each risk has a likelihood and impact score (1–5 scale). The computed `priority` field is `likelihood × impact`.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `title` | VARCHAR(255) | NOT NULL | Concise risk title |
| `description` | TEXT | NULL | 2–3 sentence description |
| `likelihood` | TINYINT UNSIGNED | NOT NULL, DEFAULT 3 | 1 (rare) to 5 (almost certain) |
| `impact` | TINYINT UNSIGNED | NOT NULL, DEFAULT 3 | 1 (negligible) to 5 (catastrophic) |
| `mitigation` | TEXT | NULL | AI-generated or manually entered mitigation strategy |
| `priority` | DECIMAL(5,2) | NULL | Computed: likelihood × impact; NULL until calculated |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE

---

### `risk_item_links`

Many-to-many join table linking risks to the work items they relate to.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `risk_id` | INT UNSIGNED | NOT NULL, FK → risks | |
| `work_item_id` | INT UNSIGNED | NOT NULL, FK → hl_work_items | |

**Foreign keys:** `risk_id` → `risks.id` ON DELETE CASCADE; `work_item_id` → `hl_work_items.id` ON DELETE CASCADE

**Unique constraint:** `uq_risk_item (risk_id, work_item_id)` — one link per risk–item pair

---

### `user_stories`

Granular user stories decomposed from High-Level Work Items. Each story follows the "As a [role], I want [action], so that [value]" format and represents approximately 3 days of development work.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `parent_hl_item_id` | INT UNSIGNED | NULL, FK → hl_work_items | NULL if parent item was deleted |
| `priority_number` | INT UNSIGNED | NOT NULL | Order within the backlog (1 = highest) |
| `title` | VARCHAR(255) | NOT NULL | Full "As a..." user story statement |
| `description` | TEXT | NULL | Technical description of what needs to be built |
| `parent_link` | VARCHAR(255) | NULL | Optional external Jira/Linear ticket URL |
| `team_assigned` | VARCHAR(255) | NULL | Team or person responsible |
| `size` | INT UNSIGNED | NULL | Story points (Fibonacci: 1, 2, 3, 5, 8, 13, 20) |
| `blocked_by` | INT UNSIGNED | NULL, FK → user_stories | Self-referential dependency |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |
| `requires_review` | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = flagged by Drift Engine for governance review |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `parent_hl_item_id` → `hl_work_items.id` ON DELETE SET NULL; `blocked_by` → `user_stories.id` ON DELETE SET NULL

**Added in:** Phase 4 (`requires_review` column)

---

### `sprints`

Sprint containers for sprint planning and allocation. Stores date range and team capacity in story points.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `name` | VARCHAR(255) | NOT NULL | Sprint name (e.g. "Sprint 1") |
| `start_date` | DATE | NULL | Sprint start date |
| `end_date` | DATE | NULL | Sprint end date |
| `team_capacity` | INT UNSIGNED | NULL | Total story points the team can deliver |
| `status` | ENUM | NOT NULL, DEFAULT `planning` | `planning`, `active`, `completed` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE

---

### `sprint_stories`

Many-to-many join table assigning user stories to sprints.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `sprint_id` | INT UNSIGNED | NOT NULL, FK → sprints | |
| `user_story_id` | INT UNSIGNED | NOT NULL, FK → user_stories | |

**Foreign keys:** `sprint_id` → `sprints.id` ON DELETE CASCADE; `user_story_id` → `user_stories.id` ON DELETE CASCADE

**Unique constraint:** `uq_sprint_story (sprint_id, user_story_id)` — a story can only appear in one sprint at a time

---

### `teams`

Groups of users within an organisation. Used for sprint capacity planning and member assignment.
Added in Phase 2 (Admin Features).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | Multi-tenancy scope key |
| `name` | VARCHAR(255) | NOT NULL | Team display name |
| `description` | TEXT | NULL | Optional description of the team's purpose |
| `capacity` | INT UNSIGNED | NULL | Sprint capacity in story points |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE

---

### `team_members`

Many-to-many junction table linking users to teams. A user may belong to multiple teams.
Added in Phase 2 (Admin Features).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `team_id` | INT UNSIGNED | NOT NULL, FK → teams | |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users | |

**Foreign keys:** `team_id` → `teams.id` ON DELETE CASCADE; `user_id` → `users.id` ON DELETE CASCADE

**Unique constraint:** `uq_team_user (team_id, user_id)` — a user can only appear once per team

---

### `persona_panels`

A named group of AI personas used to evaluate project screens via the Sounding Board feature. Panels may be system-wide defaults (`org_id IS NULL`) or org-specific overrides.
Added in Phase 3 (Sounding Board).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NULL, FK → organisations | NULL = system-default panel visible to all orgs |
| `panel_type` | ENUM | NOT NULL | `executive`, `product_management` |
| `name` | VARCHAR(255) | NOT NULL | Panel display name |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE

---

### `persona_members`

Individual AI personas within a panel. Each member has a role title and a natural-language prompt description used when building the Gemini evaluation prompt.
Added in Phase 3 (Sounding Board).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `panel_id` | INT UNSIGNED | NOT NULL, FK → persona_panels | |
| `role_title` | VARCHAR(255) | NOT NULL | e.g. `CEO`, `Senior Developer` |
| `prompt_description` | TEXT | NOT NULL | Description of the persona's perspective and focus areas |

**Foreign keys:** `panel_id` → `persona_panels.id` ON DELETE CASCADE

---

### `evaluation_results`

Stores the structured JSON output from a sounding board evaluation run. Each row represents one evaluation of a project screen by a panel at a specific criticism level.
Added in Phase 3 (Sounding Board).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `panel_id` | INT UNSIGNED | NOT NULL, FK → persona_panels | |
| `evaluation_level` | ENUM | NOT NULL | `devils_advocate`, `red_teaming`, `gordon_ramsay` |
| `screen_context` | VARCHAR(100) | NOT NULL | Identifier for which screen was evaluated (e.g. `prioritisation`, `strategy`) |
| `results_json` | JSON | NOT NULL | Array of per-persona evaluation objects (role, feedback, status) |
| `status` | ENUM | NOT NULL, DEFAULT `pending` | `pending`, `accepted`, `rejected`, `partial` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `panel_id` → `persona_panels.id` ON DELETE CASCADE

---

### `board_reviews`

Stores the output of a virtual board review session. Each row records the full boardroom conversation, the board's collective recommendation, the proposed changes, and the user's accept/reject response. `content_snapshot` captures the exact page content that was sent to the AI — used for audit purposes and is distinct from project-wide governance baselines in `strategic_baselines`.
Added in Phase 5 (Virtual Board Review).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `panel_id` | INT UNSIGNED | NOT NULL, FK → persona_panels | Panel used for the review |
| `board_type` | ENUM | NOT NULL | `executive`, `product_management` — derived from `screen_context` |
| `evaluation_level` | ENUM | NOT NULL | `devils_advocate`, `red_teaming`, `gordon_ramsay` |
| `screen_context` | VARCHAR(100) | NOT NULL | `summary`, `roadmap`, `work_items`, `user_stories` |
| `content_snapshot` | MEDIUMTEXT | NOT NULL | Exact content sent to AI (per-review audit record) |
| `conversation_json` | JSON | NOT NULL | Array of `{speaker, message}` objects from the boardroom simulation |
| `recommendation_json` | JSON | NOT NULL | Board's collective `{summary, rationale}` |
| `proposed_changes` | JSON | NOT NULL | Context-specific changes object (e.g. `{revised_summary}` for summary, `{items:[]}` for work_items) |
| `status` | ENUM | NOT NULL, DEFAULT `pending` | `pending`, `accepted`, `rejected` |
| `responded_by` | INT UNSIGNED | NULL, FK → users | Set on accept or reject |
| `responded_at` | DATETIME | NULL | Timestamp of accept/reject |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `panel_id` → `persona_panels.id` ON DELETE CASCADE; `responded_by` → `users.id` ON DELETE SET NULL

---

### `strategic_baselines`

Point-in-time snapshots of a project's scope and plan. Created manually by users via the governance dashboard. The Drift Engine compares the current project state against the latest baseline to detect deviations.
Added in Phase 4 (Strategic Drift Engine).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `snapshot_json` | JSON | NOT NULL | Point-in-time snapshot: work_items array, stories.total_count, stories.total_size, stories.by_parent |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE

---

### `drift_alerts`

Alerts raised by the Drift Engine when a project deviates from its strategic baseline. Alert types cover capacity overruns (`capacity_tripwire`), cross-team dependency blockers (`dependency_tripwire`), scope creep (`scope_creep`), and AI-assessed OKR misalignment (`alignment`).
Added in Phase 4 (Strategic Drift Engine).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `alert_type` | ENUM | NOT NULL | `scope_creep`, `capacity_tripwire`, `dependency_tripwire`, `alignment` |
| `severity` | ENUM | NOT NULL, DEFAULT `warning` | `info`, `warning`, `critical` |
| `details_json` | JSON | NOT NULL | Structured alert context (parent item, baseline/current sizes, growth %, blocking story IDs, etc.) |
| `status` | ENUM | NOT NULL, DEFAULT `active` | `active`, `acknowledged`, `resolved` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE

---

### `governance_queue`

Proposed changes to a project that require human review before being applied. Supports the Drift Engine's change-control gate for new stories, scope changes, size changes, and dependency changes. Approval or rejection is recorded by the reviewing user.
Added in Phase 4 (Strategic Drift Engine).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `change_type` | ENUM | NOT NULL | `new_story`, `scope_change`, `size_change`, `dependency_change` |
| `proposed_change_json` | JSON | NOT NULL | Full details of the proposed change (title, description, work item IDs, etc.) |
| `status` | ENUM | NOT NULL, DEFAULT `pending` | `pending`, `approved`, `rejected` |
| `reviewed_by` | INT UNSIGNED | NULL, FK → users | User who approved or rejected the item; NULL until reviewed |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `reviewed_by` → `users.id` ON DELETE SET NULL

---

### `hl_item_dependencies`

Records blocking dependencies between High-Level Work Items. A row `(item_id, depends_on_id)` means "item_id depends on depends_on_id" — i.e., `depends_on_id` must be completed before `item_id` can begin.
Added in Phase 5 (High Level Work Item Dependencies).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `item_id` | INT UNSIGNED | NOT NULL, FK → hl_work_items | The dependent work item (the one that is blocked) |
| `depends_on_id` | INT UNSIGNED | NOT NULL, FK → hl_work_items | The blocking work item (must be completed first) |
| `dependency_type` | ENUM | NOT NULL, DEFAULT `hard` | `hard` (must complete before starting), `soft` (preferred ordering) |

**Foreign keys:** `item_id` → `hl_work_items.id` ON DELETE CASCADE; `depends_on_id` → `hl_work_items.id` ON DELETE CASCADE

**Unique constraint:** `uq_item_dependency (item_id, depends_on_id)` — one dependency link per ordered pair; `ON DUPLICATE KEY UPDATE` prevents errors on re-save

---

### `password_tokens`

Password reset tokens. Generated on `/forgot-password`, consumed on `/set-password/{token}`. Tokens are stored as bcrypt hashes.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users | |
| `token_hash` | VARCHAR(255) | NOT NULL | bcrypt hash of the raw reset token |
| `expires_at` | DATETIME | NOT NULL | Tokens expire after 1 hour |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE

---

### `audit_logs`

Tamper-evident audit event log. Each row records a user action with full context. Migration 038 added an HMAC hash chain: each row's `row_hash` is derived from its content + `prev_hash`, enabling tamper detection.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NULL, FK → organisations | Added migration 038 |
| `user_id` | INT UNSIGNED | NULL, FK → users | NULL for system-generated events |
| `action` | VARCHAR(100) | NOT NULL | e.g. `user.login`, `project.delete` |
| `resource_type` | VARCHAR(50) | NULL | e.g. `project`, `user_story` |
| `resource_id` | INT UNSIGNED | NULL | ID of the affected resource |
| `details_json` | JSON | NULL | Additional event context |
| `prev_hash` | CHAR(64) | NULL | SHA-256 of previous row's hash |
| `row_hash` | CHAR(64) | NULL | HMAC-SHA256 of this row's content |
| `ip_address` | VARCHAR(45) | NULL | |
| `user_agent` | TEXT | NULL | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `user_id` → `users.id` ON DELETE SET NULL; `org_id` → `organisations.id` ON DELETE SET NULL

---

### `sessions`

PHP session storage in the database (migration 016). Replaces file-based sessions for horizontal scalability.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | VARCHAR(128) | PK | PHP session ID |
| `data` | MEDIUMBLOB | NOT NULL | Serialised session data |
| `last_accessed` | INT UNSIGNED | NOT NULL | Unix timestamp |

---

### `integrations`

Stores OAuth credentials and status for third-party integrations (Jira, GitHub App). One row per provider per org.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | |
| `provider` | ENUM | NOT NULL | `jira`, `azure_devops`, `github` |
| `display_name` | VARCHAR(255) | NOT NULL, DEFAULT `''` | Human-readable label |
| `cloud_id` | VARCHAR(255) | NULL | Jira cloud site ID |
| `access_token` | TEXT | NULL | OAuth access token (encrypted) |
| `refresh_token` | TEXT | NULL | OAuth refresh token (encrypted) |
| `token_expires_at` | DATETIME | NULL | |
| `site_url` | VARCHAR(500) | NULL | e.g. `https://myorg.atlassian.net` |
| `config_json` | JSON | NULL | Integration-specific settings |
| `installation_id` | BIGINT UNSIGNED | NULL | GitHub App installation ID |
| `account_login` | VARCHAR(255) | NULL | GitHub account/org login |
| `status` | ENUM | NOT NULL, DEFAULT `disconnected` | `active`, `paused`, `error`, `disconnected`, `inactive`, `revoked` |
| `last_sync_at` | DATETIME | NULL | |
| `error_message` | TEXT | NULL | Last error from sync attempt |
| `error_count` | INT UNSIGNED | NOT NULL, DEFAULT 0 | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE  
**Unique:** `(provider, installation_id)` — one GitHub App installation per install ID

---

### `integration_repos`

GitHub repos visible to a GitHub App installation. Populated on install and kept live via `installation_repositories` webhook events. Added in migration 021.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `integration_id` | INT UNSIGNED | NOT NULL, FK → integrations | |
| `org_id` | INT UNSIGNED | NOT NULL | Denormalised for fast lookups |
| `repo_github_id` | BIGINT UNSIGNED | NOT NULL | GitHub repository ID |
| `repo_full_name` | VARCHAR(255) | NOT NULL | e.g. `acme-corp/hello-world` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `integration_id` → `integrations.id` ON DELETE CASCADE

---

### `project_repo_links`

Many-to-many join: StratFlow projects subscribe to specific GitHub repos. Added in migration 021.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `integration_repo_id` | INT UNSIGNED | NOT NULL, FK → integration_repos | |
| `org_id` | INT UNSIGNED | NOT NULL | Denormalised |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `created_by` | INT UNSIGNED | NULL, FK → users | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `integration_repo_id` → `integration_repos.id` ON DELETE CASCADE

---

### `sync_mappings`

Maps StratFlow entity IDs to their corresponding Jira issue keys. Used by `JiraSyncService` to detect which items already exist in Jira.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `integration_id` | INT UNSIGNED | NOT NULL, FK → integrations | |
| `local_type` | VARCHAR(50) | NOT NULL | e.g. `hl_work_item`, `user_story` |
| `local_id` | INT UNSIGNED | NOT NULL | StratFlow entity ID |
| `external_id` | VARCHAR(255) | NOT NULL | Jira issue key (e.g. `PROJ-123`) |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `integration_id` → `integrations.id` ON DELETE CASCADE

---

### `sync_log`

Append-only log of Jira sync operations. One row per entity per sync run.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `integration_id` | INT UNSIGNED | NOT NULL, FK → integrations | |
| `direction` | ENUM | NOT NULL | `push`, `pull` |
| `action` | ENUM | NOT NULL | `create`, `update`, `delete`, `skip` |
| `local_type` | VARCHAR(50) | NULL | StratFlow entity type |
| `local_id` | INT UNSIGNED | NULL | StratFlow entity ID |
| `external_id` | VARCHAR(255) | NULL | Jira issue key |
| `details_json` | JSON | NULL | Additional context |
| `status` | ENUM | NOT NULL | `success`, `error` |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `integration_id` → `integrations.id` ON DELETE CASCADE

---

### `story_git_links`

Links user stories or work items to git references (PRs, commits, branches). Added in migration 018.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `local_type` | ENUM | NOT NULL | `user_story`, `hl_work_item` |
| `local_id` | INT UNSIGNED | NOT NULL | ID of the linked entity |
| `provider` | ENUM | NOT NULL | `github`, `gitlab`, `manual` |
| `ref_type` | ENUM | NOT NULL | `pr`, `commit`, `branch` |
| `ref_url` | VARCHAR(512) | NOT NULL | Full URL to the PR/commit/branch |
| `ref_label` | VARCHAR(255) | NULL | Display label |
| `status` | ENUM | NOT NULL, DEFAULT `unknown` | `open`, `merged`, `closed`, `unknown` |
| `author` | VARCHAR(255) | NULL | Git author/assignee |
| `ai_matched` | TINYINT(1) | NOT NULL, DEFAULT 0 | Set by AI PR matching service |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Unique:** `(local_type, local_id, ref_url)` — one link per entity per URL

---

### `key_results`

OKR key results attached to High-Level Work Items. Tracks target vs current value and AI-generated momentum assessment. Added in migration 022.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL | Denormalised for tenant scoping |
| `hl_work_item_id` | INT UNSIGNED | NOT NULL, FK → hl_work_items | |
| `title` | VARCHAR(500) | NOT NULL | KR title |
| `metric_description` | TEXT | NULL | What is being measured |
| `baseline_value` | DECIMAL(12,4) | NULL | Starting value |
| `target_value` | DECIMAL(12,4) | NULL | Goal value |
| `current_value` | DECIMAL(12,4) | NULL | Latest measured value |
| `unit` | VARCHAR(50) | NULL | e.g. `%`, `users`, `NZD` |
| `status` | ENUM | NOT NULL, DEFAULT `not_started` | `not_started`, `on_track`, `at_risk`, `off_track`, `achieved` |
| `jira_goal_id` | VARCHAR(255) | NULL | Linked Jira Goal ID |
| `jira_goal_url` | VARCHAR(500) | NULL | |
| `ai_momentum` | TEXT | NULL | AI-generated progress commentary |
| `display_order` | SMALLINT UNSIGNED | NOT NULL, DEFAULT 0 | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `hl_work_item_id` → `hl_work_items.id` ON DELETE CASCADE

---

### `key_result_contributions`

AI-scored links between merged PRs (via `story_git_links`) and key results. One row per PR × KR pair. Added in migration 022.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `key_result_id` | INT UNSIGNED | NOT NULL, FK → key_results | |
| `story_git_link_id` | INT UNSIGNED | NOT NULL, FK → story_git_links | |
| `org_id` | INT UNSIGNED | NOT NULL | Denormalised |
| `ai_relevance_score` | TINYINT UNSIGNED | NOT NULL, DEFAULT 0 | 0–100 |
| `ai_rationale` | TEXT | NULL | One-sentence AI explanation |
| `scored_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `key_result_id` → `key_results.id` ON DELETE CASCADE; `story_git_link_id` → `story_git_links.id` ON DELETE CASCADE  
**Unique:** `(key_result_id, story_git_link_id)`

---

### `story_quality_config`

Org-specific story quality rules: splitting patterns (SPIDR, Happy/Unhappy Path, etc.) and mandatory conditions. Seeded with defaults on migration 023. Configurable per org via Admin → Story Quality Rules.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL | |
| `rule_type` | ENUM | NOT NULL | `splitting_pattern`, `mandatory_condition` |
| `label` | VARCHAR(255) | NOT NULL | Display name of the rule |
| `is_default` | TINYINT(1) | NOT NULL, DEFAULT 0 | 1 = seeded from StratFlow defaults |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | |
| `display_order` | SMALLINT UNSIGNED | NOT NULL, DEFAULT 0 | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

---

### `system_settings`

Single-row table storing superadmin-managed system-wide JSON configuration. Seeded on migration 025. Accessed via `SystemSettings::get()`.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, DEFAULT 1 | Always a single row (id=1) |
| `settings_json` | JSON | NOT NULL | All system settings as a JSON object |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

Key settings: `ai_provider`, `ai_model`, `default_seat_limit`, `quality_threshold`, `quality_enforcement`, feature flags (`feature_sounding_board`, `feature_jira`, `feature_github`, etc.), billing rates.

---

### `personal_access_tokens`

Long-lived bearer tokens for REST API and MCP server access. Added in migration 029. Tokens are never stored in plaintext — only a SHA-256 hash is kept.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | Denormalised for fast middleware lookup |
| `name` | VARCHAR(100) | NOT NULL | User-supplied label |
| `token_hash` | CHAR(64) | NOT NULL, UNIQUE | SHA-256 hex of the raw token |
| `token_prefix` | CHAR(15) | NOT NULL | `sf_pat_` + first 8 chars — shown in UI for recognition |
| `scopes` | JSON | NULL | NULL = full read + story status transitions |
| `last_used_at` | DATETIME | NULL | |
| `last_used_ip` | VARCHAR(45) | NULL | |
| `expires_at` | DATETIME | NULL | NULL = no expiry |
| `revoked_at` | DATETIME | NULL | NULL = active |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `user_id` → `users.id` ON DELETE CASCADE; `org_id` → `organisations.id` ON DELETE CASCADE

---

### `project_members`

Per-project membership for organisations using restricted project visibility. Added in migration 028. When a project's `visibility = 'restricted'`, only users in this table can see it.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `user_id` → `users.id` ON DELETE CASCADE  
**Unique:** `(project_id, user_id)`

---

## Indexes Summary

| Table | Index | Columns | Type |
|-------|-------|---------|------|
| `users` | (implicit) | `email` | UNIQUE |
| `login_attempts` | `idx_ip_time` | `ip_address, attempted_at` | INDEX |
| `risk_item_links` | `uq_risk_item` | `risk_id, work_item_id` | UNIQUE |
| `sprint_stories` | `uq_sprint_story` | `sprint_id, user_story_id` | UNIQUE |
| `team_members` | `uq_team_user` | `team_id, user_id` | UNIQUE |
| `hl_item_dependencies` | `uq_item_dependency` | `item_id, depends_on_id` | UNIQUE |
| `integrations` | `uk_provider_installation` | `provider, installation_id` | UNIQUE |
| `integration_repos` | `uk_integration_repo` | `integration_id, repo_github_id` | UNIQUE |
| `project_repo_links` | `uk_project_repo` | `project_id, integration_repo_id` | UNIQUE |
| `story_git_links` | `uniq_link` | `local_type, local_id, ref_url` | UNIQUE |
| `key_result_contributions` | `uk_kr_link` | `key_result_id, story_git_link_id` | UNIQUE |
| `personal_access_tokens` | `uk_token_hash` | `token_hash` | UNIQUE |

All other lookups rely on primary keys and foreign key indexes created automatically by InnoDB.

---

## Applying Schema Changes

Schema changes are tracked by `MigrationRunner` (see below). Write new migrations as numbered SQL files in `database/migrations/` and they will be applied automatically on next deploy. For manual or emergency changes:

1. Write the `ALTER TABLE` or `CREATE TABLE` statement
2. Test it against a local database first
3. Apply to production via cPanel phpMyAdmin or `mysql` CLI:
   ```bash
   mysql -u <user> -p <db_name> < path/to/change.sql
   ```
4. Update `database/schema.sql` to reflect the new canonical schema

For the Docker dev environment, to re-apply the schema from scratch:

```bash
docker compose down -v
docker compose up -d --build
```

This destroys and recreates the MySQL volume, so all data is lost. Use only in development.

## Migration Ledger

As of Sprint 2 (2026-04-19), migrations are tracked by `MigrationRunner` in a
`schema_migrations` table (filename + SHA-256 checksum + applied_at). Each
migration runs exactly once. A checksum mismatch on a previously-applied file
throws `RuntimeException` at startup.

Migrations 007 and 037 previously used `ADD COLUMN IF NOT EXISTS`, which is
invalid MySQL 8.0 syntax (error 1064). Both have been corrected to `ADD COLUMN`;
the duplicate-column backfill path (error 1060) handles existing deployments.

Migrations 008 and 017 also used `CREATE INDEX IF NOT EXISTS`, which is
likewise invalid in MySQL 8.0. All 10 occurrences have been corrected to
plain `CREATE INDEX`; the duplicate-key backfill path (error 1061) handles
existing deployments.
