# ThreePoints StratFlow V3 — Feature Specification

StratFlow V1 established the strategy-to-delivery pipeline: upload documents, generate strategy diagrams, decompose into work items, score via RICE/WSJF, model risks, break into user stories, and allocate to sprints. V2 added the governance layer — sounding boards, admin surfaces, the Strategic Drift Engine with baselines, tripwires, and a change-control queue — creating the only tool that maintains a live closed loop between strategic intent and execution reality. V3 extends the platform into the enterprise: bidirectional integration with Jira and Azure DevOps, portfolio-level executive dashboards, real-time collaboration, conversational AI strategy coaching, multi-format document intelligence, and meeting platform integration. These six features transform StratFlow from a planning-phase tool into the organisation's permanent strategy operating system.

---

## System Screens and Functionality

---

### 10. Integration Hub Screen

The Integration Hub is the central configuration surface for connecting StratFlow to external project management and work tracking tools. Accessed from the Admin area sidebar, it allows org admins to authenticate with Jira Cloud or Azure DevOps, select target projects, configure field mappings, and monitor sync health. Each connected integration shows its status (active, paused, error), last sync timestamp, and a count of mapped items.

The Integration Hub is the foundation of StratFlow's enterprise value proposition. Without it, StratFlow is a planning-phase tool that users abandon once work moves to Jira. With it, StratFlow becomes embedded in the daily engineering workflow and the Drift Engine gains visibility into changes made outside the platform.

---

#### 10.1 Integration List View

**Route:** `GET /app/admin/integrations`

Displays all configured integrations for the organisation in a table layout.

| Field | Type | Description |
|-------|------|-------------|
| Provider | Badge | `Jira Cloud` or `Azure DevOps` with provider logo |
| Project Name | Text | The external project this integration targets |
| Status | Status badge | `Active` (green), `Paused` (amber), `Error` (red) |
| Last Sync | Relative timestamp | e.g. "3 minutes ago", "Never" |
| Mapped Items | Count | Number of HL work items + user stories currently synced |
| Actions | Button group | `Configure`, `Sync Now`, `Pause/Resume`, `Delete` |

**Empty state:** "No integrations configured. Connect Jira or Azure DevOps to push your strategy backlog and enable bidirectional drift detection."

**Action buttons:**
- `+ Connect Jira Cloud` — initiates OAuth 2.0 flow with Atlassian
- `+ Connect Azure DevOps` — initiates OAuth 2.0 flow with Microsoft

---

#### 10.2 Jira Connection Settings

**Route:** `GET /app/admin/integrations/jira/connect`

OAuth 2.0 authentication flow with Atlassian Cloud.

| Field | Type | Description |
|-------|------|-------------|
| Atlassian Site | Dropdown (auto-populated after OAuth) | The Atlassian Cloud instance to connect to |
| Jira Project | Dropdown | Target Jira project for synced items |
| Sync Direction | Radio group | `Push Only` (StratFlow → Jira), `Bidirectional` (StratFlow ↔ Jira) |
| Auto-Sync | Toggle | When enabled, sync runs automatically on item save |
| Webhook URL | Read-only text + Copy button | URL for Jira to POST change events to (displayed after save) |

**OAuth scopes requested:** `read:jira-work`, `write:jira-work`, `read:jira-user`, `manage:jira-webhook`

---

#### 10.3 Azure DevOps Connection Settings

**Route:** `GET /app/admin/integrations/azure-devops/connect`

OAuth 2.0 authentication flow with Microsoft Azure DevOps.

| Field | Type | Description |
|-------|------|-------------|
| Azure DevOps Organisation | Dropdown (auto-populated after OAuth) | The ADO organisation to connect to |
| ADO Project | Dropdown | Target project for synced items |
| Sync Direction | Radio group | `Push Only`, `Bidirectional` |
| Auto-Sync | Toggle | Automatic sync on item save |
| Webhook URL | Read-only text + Copy button | URL for ADO Service Hooks to POST to |

**OAuth scopes requested:** `vso.work_write`, `vso.hooks_write`

---

#### 10.4 Field Mapping Configuration

**Route:** `GET /app/admin/integrations/{id}/field-mapping`

Configurable mapping between StratFlow fields and external tool fields. Presented as a two-column mapping table with StratFlow fields on the left and external fields on the right (selectable dropdowns).

| StratFlow Field | Maps To (Jira) | Maps To (Azure DevOps) | Default |
|-----------------|----------------|------------------------|---------|
| HL Work Item → | Epic | Feature | Auto |
| User Story → | Story | User Story | Auto |
| Sprint → | Sprint | Iteration | Auto |
| Priority Number → | Priority | Priority | Auto |
| Story Points (size) → | Story Points | Story Points | Auto |
| Owner → | Assignee | Assigned To | Auto |
| OKR Title → | Custom field or Epic description | Custom field or description | Description append |
| Description → | Description | Description | Auto |
| Strategic Context → | Labels | Tags | Auto |

**Custom field mapping:** For fields not in the default list, a "+ Add Custom Mapping" row allows selecting any StratFlow field and mapping it to any external field by name.

---

#### 10.5 Sync Status & History

**Route:** `GET /app/admin/integrations/{id}/status`

Displays sync health and recent sync events for a single integration.

| Field | Type | Description |
|-------|------|-------------|
| Current Status | Status badge | `Active`, `Paused`, `Error` |
| Last Successful Sync | Datetime | Timestamp of last error-free sync |
| Items Synced | Count | Total mapped items (HL work items + user stories) |
| Pending Changes | Count | StratFlow changes not yet pushed, or external changes not yet pulled |
| Error Log | Expandable list | Last 20 sync errors with timestamp, item, and error message |
| Sync History | Table | Last 50 sync events: timestamp, direction (push/pull), items affected, status |

**Manual sync button:** `Sync Now` — triggers an immediate full sync and shows a progress indicator.

---

#### 10.6 Webhook Listener Endpoint

**Route:** `POST /webhook/integration/{provider}`

Receives change notifications from Jira or Azure DevOps. Not a UI screen — this is a server-side endpoint.

**Jira webhook payload handling:**
- `jira:issue_updated` → Compare changed fields against sync_mappings; if mapped item changed, update StratFlow and trigger drift detection
- `jira:issue_created` → If created under a mapped Epic, create corresponding user story in StratFlow with `requires_review = 1`
- `jira:issue_deleted` → Flag mapped StratFlow item for governance review
- `sprint_started`, `sprint_closed` → Update StratFlow sprint status

**Azure DevOps Service Hook handling:**
- `workitem.updated` → Same pattern as Jira issue_updated
- `workitem.created` → Same pattern as Jira issue_created
- `workitem.deleted` → Same pattern as Jira issue_deleted

**Conflict resolution:** When a bidirectional change conflict is detected (both StratFlow and external tool modified the same field since last sync), the system creates a governance queue item of type `external_change` with both values in `proposed_change_json`. The item appears in the Governance Dashboard with an "External Change Detected" label and accept/reject buttons.

---

#### 10.7 Sprint Goal & Implementation Plan

**Sprint Goal:** Integrate StratFlow with enterprise project management tools (Jira Cloud and Azure DevOps) via bidirectional sync, closing the drift detection feedback loop so the Strategic Drift Engine can detect and respond to changes made outside StratFlow.

**Objective:** Eliminate the CSV/JSON export dead-end by establishing a live, authenticated, field-mapped sync pipeline between StratFlow and the two dominant enterprise work tracking platforms.

---

**Week 1 — Phase A: One-Way Push to Jira Cloud**

| Task | Description |
|------|-------------|
| Task 10.1 | Implement `JiraService.php` — OAuth 2.0 client for Atlassian Cloud REST API v3. Methods: `authenticate()`, `getProjects()`, `createEpic()`, `createStory()`, `updateIssue()`, `getIssue()`. Store tokens in `integrations.config_json`. |
| Task 10.2 | Create `Integration.php` and `SyncMapping.php` models with standard CRUD + `findByOrgAndProvider()`, `findByLocalItem()`, `findByExternalId()`. |
| Task 10.3 | Create `IntegrationController.php` — routes for connection setup, field mapping, sync trigger, status view. Register all routes under `/app/admin/integrations/*` with `AdminMiddleware`. |
| Task 10.4 | Build `templates/admin/integrations.php` — integration list, Jira connection form, field mapping table. |
| Task 10.5 | Implement push logic: `JiraService::pushWorkItem()` creates Jira Epic from HL work item, `pushUserStory()` creates Jira Story linked to parent Epic. Insert `sync_mappings` row on each push. |
| Task 10.6 | Add "Push to Jira" button on Work Items and User Stories screens. Button appears only when an active Jira integration exists. |

**Week 2 — Phase B: Bidirectional Sync + Webhook Listener**

| Task | Description |
|------|-------------|
| Task 10.7 | Implement `POST /webhook/integration/jira` endpoint. Verify Jira webhook signature. Parse `jira:issue_updated`, `jira:issue_created`, `jira:issue_deleted` events. |
| Task 10.8 | On external change detection: update the mapped StratFlow item, set `requires_review = 1`, and call `DriftDetectionService::detectDrift()` for the parent project. |
| Task 10.9 | Implement conflict resolution: when both sides changed since last sync, create a `governance_queue` item with `change_type = 'external_change'` containing both values. |
| Task 10.10 | Implement `AzureDevOpsService.php` — OAuth 2.0 client for Azure DevOps REST API. Same method signatures as JiraService. Register ADO webhook via Service Hooks API. |
| Task 10.11 | Build Azure DevOps connection UI and field mapping (reuse Jira templates with provider-conditional rendering). |
| Task 10.12 | Add integration sync status to the Governance Dashboard — show count of pending external changes alongside drift alerts. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| OAuth 2.0 authentication | User can connect Jira Cloud and Azure DevOps via OAuth flow; tokens are stored encrypted in `integrations.config_json` |
| One-way push | HL work items push as Epics, user stories push as Stories, with correct field mapping; `sync_mappings` rows created |
| Bidirectional sync | Changes made in Jira or ADO are detected via webhook, reflected in StratFlow, and trigger drift detection |
| Conflict resolution | Conflicting changes create governance queue items with both values; user can accept or reject |
| Field mapping | Admin can customise field mapping; custom field mappings are persisted and applied on every sync |
| Error handling | Sync errors are logged; integration status changes to `error` on repeated failures; errors visible in status view |
| Multi-tenancy | Integrations are scoped to `org_id`; no cross-org data leakage |

---

**Database Schema**

```sql
CREATE TABLE integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    provider ENUM('jira','azure_devops') NOT NULL,
    config_json JSON NOT NULL COMMENT 'OAuth tokens (encrypted), project mappings, field maps, webhook secret',
    status ENUM('active','paused','error') NOT NULL DEFAULT 'active',
    last_sync_at DATETIME NULL,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
);

CREATE TABLE sync_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL,
    local_type ENUM('hl_work_item','user_story','sprint') NOT NULL,
    local_id INT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NOT NULL COMMENT 'Jira issue key (e.g. PROJ-123) or ADO work item ID',
    external_url VARCHAR(500) NULL COMMENT 'Direct link to the external item',
    sync_hash VARCHAR(64) NULL COMMENT 'SHA-256 of last synced field values for change detection',
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mapping (integration_id, local_type, local_id),
    INDEX idx_external (integration_id, external_id)
);
```

---

---

### 11. Executive Strategy Dashboard Screen

The Executive Strategy Dashboard provides a portfolio-level view of all projects within an organisation, designed for the VP, CPO, or CTO who signs the contract but does not manage individual backlogs. It surfaces composite health scores, aggregated velocity trends, drift alert summaries, and OKR progress across the entire project portfolio. The dashboard also provides per-project analytics including burndown/burnup charts, sprint velocity over time, and scope change timelines derived from the governance queue.

This screen sells to the budget holder. The person who approves an annual subscription needs to see return on investment at a glance — not user stories.

---

#### 11.1 Portfolio Overview

**Route:** `GET /app/dashboard`

The landing view after login for users with `org_admin` or higher roles. Displays all organisation projects as cards in a responsive grid.

| Field | Type | Description |
|-------|------|-------------|
| Organisation Health Score | Large number (0–100) with colour | Weighted average of all project health scores. Green >=75, Amber 50–74, Red <50 |
| Active Projects | Count | Number of projects with status `active` |
| Active Drift Alerts | Count with severity breakdown | Total unresolved drift alerts across all projects, grouped: Critical / Warning / Info |
| Team Utilisation | Percentage bar | Aggregate allocated story points / aggregate sprint capacity across all active sprints |

**Project Cards** (one per project):

| Field | Type | Description |
|-------|------|-------------|
| Project Name | Text | Clickable — navigates to project analytics |
| Status | Badge | `Draft`, `Active`, `Completed` |
| Strategy Health Score | Gauge (0–100) | Composite metric (see 11.2) |
| Current Phase | Text | Which workflow step the project is on (Upload, Diagram, Work Items, etc.) |
| Sprint Progress | Mini progress bar | Story points completed / total in current sprint |
| Active Alerts | Count badge | Unresolved drift alerts for this project |
| Last Activity | Relative timestamp | Most recent change to any project entity |

**Sorting options:** By health score (ascending/descending), by last activity, by name.

**Filtering options:** By status, by health score range, by assigned team.

---

#### 11.2 Strategy Health Score Calculation

The Strategy Health Score is a composite metric (0–100) computed from four weighted dimensions:

| Dimension | Weight | Calculation | Score Range |
|-----------|--------|-------------|-------------|
| OKR Coverage | 25% | (Work items with non-empty `okr_title`) / (Total work items) * 100 | 0–100 |
| Strategic Alignment | 25% | 100 - (Active alignment drift alerts * 15), minimum 0 | 0–100 |
| Risk Exposure | 25% | 100 - (Sum of active risk priorities / Maximum possible risk score * 100) | 0–100 |
| Execution Momentum | 25% | (Story points completed in last 2 sprints) / (Story points planned in last 2 sprints) * 100, capped at 100 | 0–100 |

The health score is recalculated on every dashboard load and cached in `dashboard_cache` for 5 minutes to avoid repeated computation on page refresh.

---

#### 11.3 Burndown / Burnup Charts

**Route:** `GET /app/dashboard/project/{id}/analytics`

Per-project analytics view. The burndown chart plots story points remaining vs. time for each sprint. The burnup chart plots cumulative story points completed vs. total scope over time.

| Field | Type | Description |
|-------|------|-------------|
| Sprint Selector | Dropdown | Choose which sprint to view (defaults to current active sprint) |
| Chart Type Toggle | Button group | `Burndown` / `Burnup` |
| Ideal Line | Dashed line on chart | Linear ideal trajectory from total points to zero (burndown) or zero to total (burnup) |
| Actual Line | Solid line on chart | Actual progress computed from sprint_stories completion |
| Scope Line (burnup only) | Dotted line | Total scope — shows scope changes over time |

**Data source:** Computed from `sprint_stories` join `user_stories` (size column) and `sprints` (date range). Sprint story completion is determined by the `user_stories.status` field (to be added — see schema below).

---

#### 11.4 Velocity Chart

Displayed on the per-project analytics page alongside burndown/burnup.

| Field | Type | Description |
|-------|------|-------------|
| Chart Type | Bar chart | One bar per completed sprint |
| Y-Axis | Story points | Total points delivered in that sprint |
| Average Line | Horizontal dashed line | Rolling 3-sprint average velocity |
| Trend Indicator | Arrow icon | Up/down/stable compared to previous sprint |

---

#### 11.5 OKR Progress Visualisation

**Route:** `GET /app/dashboard/project/{id}/okrs`

Displays each OKR (from `diagram_nodes.okr_title`) with its linked work items and their completion status.

| Field | Type | Description |
|-------|------|-------------|
| OKR Title | Text | From `diagram_nodes.okr_title` |
| OKR Description | Text (expandable) | From `diagram_nodes.okr_description` |
| Progress Bar | Percentage | (Completed work items linked to this OKR) / (Total linked work items) * 100 |
| Status | Badge | `On Track` (>=70%), `At Risk` (40–69%), `Behind` (<40%) |
| Linked Work Items | Expandable list | Each work item with its sprint allocation and story completion % |

---

#### 11.6 Scope Change Timeline

Visual timeline showing all governance queue events for a project, plotted chronologically.

| Field | Type | Description |
|-------|------|-------------|
| Timeline | Horizontal timeline | Left = project creation, right = today |
| Events | Markers on timeline | Each governance queue item plotted at its `created_at` date |
| Event Detail (on hover/click) | Tooltip/popover | Change type, proposed change summary, status (approved/rejected/pending), reviewer |
| Baseline Markers | Vertical lines | Each strategic baseline creation date, showing when scope was locked |

---

#### 11.7 Team Utilisation Aggregation

Displayed on the portfolio dashboard (section 11.1) and also available per-project.

| Field | Type | Description |
|-------|------|-------------|
| Team Name | Text | From `teams.name` |
| Allocated Points | Number | Sum of story points in active sprints assigned to this team |
| Capacity | Number | From `teams.capacity` or sprint `team_capacity` |
| Utilisation % | Percentage bar | Allocated / Capacity * 100. Red if >100% (over-allocated), Amber if >85%, Green otherwise |

---

#### 11.8 Sprint Goal & Implementation Plan

**Sprint Goal:** Build a portfolio-level executive dashboard that surfaces composite health scores, velocity trends, OKR progress, and scope change history, enabling C-suite stakeholders to justify the StratFlow subscription and monitor strategy execution across all projects.

**Objective:** Create the visual surface that sells StratFlow to the person who signs the cheque — the VP or CPO who needs portfolio-level ROI data, not backlog management.

---

**Week 1 — Phase A: Health Score Engine + Portfolio View**

| Task | Description |
|------|-------------|
| Task 11.1 | Create `AnalyticsService.php` — methods: `calculateHealthScore($projectId)`, `getPortfolioSummary($orgId)`, `getVelocityData($projectId)`, `getBurndownData($projectId, $sprintId)`, `getOKRProgress($projectId)`, `getTeamUtilisation($orgId)`. |
| Task 11.2 | Create `DashboardController.php` — routes: `GET /app/dashboard` (portfolio), `GET /app/dashboard/project/{id}/analytics` (per-project), `GET /app/dashboard/project/{id}/okrs`. Register with `AuthMiddleware`. |
| Task 11.3 | Create `DashboardCache.php` model — `getOrCompute($orgId, $cacheKey, $ttlSeconds, $computeFn)` pattern. Invalidate on project/sprint/governance changes. |
| Task 11.4 | Build `templates/dashboard.php` — portfolio grid with project cards, org health score, alert summary, team utilisation bar. |
| Task 11.5 | Add `status` column to `user_stories` table (`ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo'`) to support burndown calculations. |

**Week 2 — Phase B: Charts + Per-Project Analytics**

| Task | Description |
|------|-------------|
| Task 11.6 | Build `templates/analytics.php` — burndown/burnup chart containers, velocity chart, scope change timeline. |
| Task 11.7 | Implement `public/assets/js/charts.js` — Chart.js rendering for burndown, burnup, velocity, and utilisation charts. Load Chart.js from CDN. |
| Task 11.8 | Build `templates/okr-progress.php` — OKR cards with progress bars and linked work item lists. |
| Task 11.9 | Implement scope change timeline using governance queue data — query `governance_queue` by project, plot events on a horizontal CSS timeline. |
| Task 11.10 | Add dashboard link to the main sidebar navigation. Make it the default landing page for org_admin users. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| Portfolio view | All org projects displayed as cards with health scores, last activity, and alert counts |
| Health score | Composite 0–100 score computed from OKR coverage, alignment, risk exposure, and execution momentum |
| Burndown/burnup | Charts render correctly for projects with sprint data; ideal vs. actual lines visible |
| Velocity chart | Bar chart shows points delivered per sprint with rolling average line |
| OKR progress | Each OKR shows completion percentage based on linked work item status |
| Scope change timeline | Governance queue events plotted chronologically; baseline markers visible |
| Team utilisation | Allocated vs. capacity shown per team; over-allocation highlighted in red |
| Performance | Dashboard loads in under 2 seconds with 20 projects; cache invalidation works correctly |

---

**Database Schema**

```sql
CREATE TABLE dashboard_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    cache_key VARCHAR(100) NOT NULL COMMENT 'e.g. portfolio_summary, project_health_42, velocity_42',
    data_json JSON NOT NULL,
    computed_at DATETIME NOT NULL,
    UNIQUE KEY uq_cache (org_id, cache_key),
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
);

-- Migration: add status tracking to user stories for burndown calculations
ALTER TABLE user_stories ADD COLUMN status ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo' AFTER size;
ALTER TABLE user_stories ADD COLUMN completed_at DATETIME NULL AFTER status;
```

---

---

### 12. Collaboration & Activity Feed Screen

The Collaboration system adds multi-user interaction to StratFlow's project workflow. It introduces threaded comments on work items, user stories, and risks with @mention support; a project-level chronological activity feed; and an in-app notification system with a topbar bell icon, unread counts, and optional daily email digests. Without collaboration, StratFlow is a single-player tool — enterprise means teams.

---

#### 12.1 Comment Threads

**Accessible from:** Work Item detail view, User Story detail view, Risk detail view

Comments appear as a threaded list below the item detail panel. Each comment shows the author's name, avatar initial, relative timestamp, and body text with Markdown rendering.

| Field | Type | Description |
|-------|------|-------------|
| Comment Body | Textarea with Markdown preview | Supports `**bold**`, `*italic*`, `` `code` ``, `- lists`, `[links](url)`. @mention autocomplete triggers on `@` character. |
| Author | Text + avatar initial | `users.full_name`, first letter as coloured circle |
| Timestamp | Relative | "2 minutes ago", "Yesterday at 3:14 PM" |
| Edit Button | Icon button | Visible only to comment author. Inline edit with save/cancel. |
| Delete Button | Icon button | Visible only to comment author and org_admin. Soft delete (body replaced with "This comment has been deleted"). |
| Comment Count Badge | Number | Shown on item rows in list views — e.g. "3 comments" |

**@mention behaviour:**
- Typing `@` opens a dropdown of organisation users filtered by name as the user types
- Selecting a user inserts `@Full Name` into the comment body
- On comment save, the system parses `@Full Name` references, resolves them to user IDs, and creates a `notification` for each mentioned user with `type = 'mentioned'`

---

#### 12.2 Activity Feed

**Route:** `GET /app/project/{id}/activity`

A chronological log of all significant events within a project. Also available as a sidebar widget on the project home screen.

| Field | Type | Description |
|-------|------|-------------|
| Event Icon | Icon | Varies by action type (created, updated, commented, scored, approved, etc.) |
| Actor | Text | User who performed the action, or "System" for automated events |
| Action | Text | e.g. "created work item", "commented on", "approved governance item", "drift alert raised" |
| Subject | Link | Clickable link to the affected item |
| Timestamp | Relative | |
| Details | Expandable | JSON details for complex events (field changes, score values, etc.) |

**Activity types logged:**

| Action | Subject Type | Trigger |
|--------|-------------|---------|
| `created` | work_item, user_story, risk, sprint, baseline | Any creation |
| `updated` | work_item, user_story, risk | Any field edit |
| `commented` | work_item, user_story, risk | Comment added |
| `scored` | work_item | RICE/WSJF scores generated or updated |
| `sized` | user_story | Story points set or changed |
| `allocated` | user_story | Assigned to sprint |
| `evaluated` | project | Sounding board evaluation run |
| `baseline_created` | project | Strategic baseline snapshot taken |
| `drift_detected` | project | Drift alert raised |
| `governance_approved` | governance_item | Queue item approved |
| `governance_rejected` | governance_item | Queue item rejected |
| `synced` | integration | External sync completed |

**Filtering:** By activity type, by user, by date range. Pagination: 50 events per page with infinite scroll.

---

#### 12.3 Notification Bell

**Location:** App layout topbar, right side, next to user menu

| Field | Type | Description |
|-------|------|-------------|
| Bell Icon | Icon with badge | Displays unread count as a red circle badge. Hidden when count is zero. |
| Dropdown Panel | Popover | Shows last 10 notifications with mark-all-read button at top |
| Notification Row | Clickable row | Icon + title + relative timestamp. Unread rows have a blue dot indicator. Click navigates to the linked item and marks notification as read. |
| "View All" Link | Link | Navigates to full notification list page |

**Full notification page:** `GET /app/notifications`

| Field | Type | Description |
|-------|------|-------------|
| Notification List | Table | All notifications, newest first, with type icon, title, body preview, timestamp, read/unread status |
| Filter | Dropdown | All, Unread only, by type |
| Mark Selected Read | Bulk action | Checkbox per row + "Mark Read" button |

---

#### 12.4 Notification Types

| Type Key | Trigger | Title Template | Link |
|----------|---------|---------------|------|
| `mentioned` | @mention in comment | "{user} mentioned you in a comment on {item_title}" | Comment anchor on item page |
| `assigned` | Owner/team_assigned field set to current user | "You were assigned to {item_title}" | Item detail page |
| `review_needed` | `requires_review` set to 1 on an item the user owns | "{item_title} requires governance review" | Governance dashboard |
| `drift_alert` | Drift alert created on a project the user is a member of | "Drift alert ({alert_type}) on {project_name}" | Governance dashboard |
| `governance_decision` | Governance item approved or rejected | "{item_title} was {approved/rejected} by {reviewer}" | Governance dashboard |
| `comment_reply` | Comment added to an item the user previously commented on | "{user} also commented on {item_title}" | Comment anchor on item page |
| `evaluation_complete` | Sounding board evaluation finished | "Sounding board evaluation complete for {screen}" | Sounding board results |

---

#### 12.5 Email Digest (Optional)

**Route:** `GET /app/notifications/preferences`

| Field | Type | Description |
|-------|------|-------------|
| Email Digest | Toggle | Enable/disable daily email summary |
| Digest Time | Time picker | When to send the digest (default: 08:00 local time) |
| Include Types | Checkbox group | Which notification types to include in the digest |

The email digest is a scheduled job (daily cron or Task Scheduler) that queries unread notifications created since the last digest, renders them as a plain HTML email, and sends via SMTP. The digest includes a deep link to each notification's target item.

---

#### 12.6 Sprint Goal & Implementation Plan

**Sprint Goal:** Enable multi-user collaboration within StratFlow projects through threaded comments with @mentions, a project activity feed, and an in-app notification system, transforming the platform from single-player to team-ready.

**Objective:** Deliver the collaboration primitives that enterprise teams expect — comments, activity history, and notifications — so that strategy work happens inside StratFlow instead of leaking to email and Slack.

---

**Week 1 — Phase A: Comments + Activity Log**

| Task | Description |
|------|-------------|
| Task 12.1 | Create `Comment.php` model — `create()`, `findByCommentable($type, $id)`, `update()`, `softDelete()`. Polymorphic via `commentable_type` + `commentable_id`. |
| Task 12.2 | Create `ActivityLog.php` model — `log($projectId, $userId, $action, $subjectType, $subjectId, $details)`, `findByProject($projectId, $filters, $page)`. |
| Task 12.3 | Create `CommentController.php` — routes: `POST /app/comments`, `PUT /app/comments/{id}`, `DELETE /app/comments/{id}`. Parse @mentions from body, create notifications. |
| Task 12.4 | Build `templates/partials/comment-thread.php` — reusable comment component. Include in work item, user story, and risk detail templates. |
| Task 12.5 | Instrument all existing controllers with `ActivityLog::log()` calls on create, update, delete, score, allocate, evaluate, baseline, and governance actions. |
| Task 12.6 | Build `templates/activity-feed.php` — project activity page with event list, filters, and pagination. Add sidebar widget variant. |

**Week 2 — Phase B: Notifications + Email Digest**

| Task | Description |
|------|-------------|
| Task 12.7 | Create `Notification.php` model — `create()`, `findByUser($userId, $filters)`, `markRead($id)`, `markAllRead($userId)`, `getUnreadCount($userId)`. |
| Task 12.8 | Create `NotificationService.php` — `notify($userId, $type, $title, $body, $link)`, `notifyMentioned($commentId, $mentionedUserIds)`, `notifyTeam($projectId, $type, ...)`. |
| Task 12.9 | Create `NotificationController.php` — routes: `GET /app/notifications` (full page), `GET /app/notifications/unread-count` (JSON for bell badge), `POST /app/notifications/{id}/read`, `POST /app/notifications/mark-all-read`, `GET /app/notifications/preferences`, `POST /app/notifications/preferences`. |
| Task 12.10 | Build `templates/partials/notification-bell.php` — bell icon with badge count, dropdown panel. Add to `templates/layouts/app.php` topbar. Fetch unread count via AJAX on page load. |
| Task 12.11 | Build `templates/notifications.php` — full notification list with filters and bulk actions. |
| Task 12.12 | Implement email digest — `NotificationService::sendDigest($userId)` method. Schedule as daily task. Render HTML email from unread notifications. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| Comments | Users can add, edit, and delete comments on work items, user stories, and risks |
| @mentions | Typing `@` triggers user autocomplete; mentioned user receives a notification |
| Activity feed | All significant project events are logged and displayed chronologically with filtering |
| Notification bell | Bell icon shows unread count; dropdown shows last 10; click navigates to item |
| Notification types | All 7 notification types fire correctly on their trigger events |
| Email digest | Opt-in daily email summarises unread notifications with deep links |
| Performance | Activity feed loads 50 events in under 1 second; notification unread count query is indexed |
| Multi-tenancy | Comments and notifications scoped to org; no cross-org visibility |

---

**Database Schema**

```sql
CREATE TABLE comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    commentable_type ENUM('hl_work_item','user_story','risk') NOT NULL,
    commentable_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL COMMENT 'Markdown-formatted comment body with @mention references',
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_commentable (commentable_type, commentable_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL COMMENT 'mentioned, assigned, review_needed, drift_alert, governance_decision, comment_reply, evaluation_complete',
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link VARCHAR(500) NULL COMMENT 'Deep link to the relevant item/page',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL COMMENT 'NULL for system-generated events',
    action VARCHAR(50) NOT NULL COMMENT 'created, updated, commented, scored, sized, allocated, evaluated, drift_detected, etc.',
    subject_type VARCHAR(50) NULL COMMENT 'work_item, user_story, risk, sprint, governance_item, integration',
    subject_id INT UNSIGNED NULL,
    details_json JSON NULL COMMENT 'Contextual details: changed fields, old/new values, scores, etc.',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_time (project_id, created_at),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Migration: add email digest preferences to users
ALTER TABLE users ADD COLUMN notification_preferences JSON NULL COMMENT '{"email_digest": true, "digest_time": "08:00", "digest_types": ["mentioned","drift_alert"]}' AFTER role;
```

---

---

### 13. AI Strategy Coach Screen

The AI Strategy Coach is a slide-out conversational panel accessible from any page in the authenticated app. It provides a chat interface where users ask questions about their project strategy, and the AI responds with context-aware answers grounded in the project's actual data — documents, work items, risks, sprints, drift alerts, governance history, and evaluation results. The coach uses pre-built prompt templates for common strategic questions and persists conversation history per project.

This is the "wow factor" feature. No competitor offers a conversational strategy advisor with full project context. WorkBoard has "Chief of Staff" and "Leadership Coach" AI agents — this is the same pattern applied to StratFlow's richer data set.

---

#### 13.1 Coach Panel

**Location:** Slide-out panel triggered by a floating action button (bottom-right corner of every authenticated page). The button displays a chat icon with the label "Strategy Coach".

| Field | Type | Description |
|-------|------|-------------|
| Panel Header | Text + close button | "Strategy Coach — {Project Name}" |
| Conversation History | Scrollable message list | Alternating user (right-aligned, blue) and assistant (left-aligned, grey) message bubbles |
| Message Input | Textarea + send button | Placeholder: "Ask about your strategy, risks, or roadmap..." |
| Pre-built Prompts | Button row (collapsible) | Quick-action buttons for common questions (see 13.2) |
| Conversation Selector | Dropdown | Switch between past conversations or start a new one |
| Loading Indicator | Animated dots | Shown while awaiting AI response |

**Panel behaviour:**
- Opens as a 400px-wide right-side panel with slide animation
- Does not navigate away from the current page
- Persists across page navigation within the same project (state maintained in session)
- Close button or clicking outside the panel closes it

---

#### 13.2 Pre-Built Prompt Templates

Displayed as clickable buttons at the top of the coach panel. Each button pre-fills the message input with the prompt text.

| Button Label | Prompt Text |
|-------------|-------------|
| Assess Roadmap | "Assess the feasibility of my current roadmap given our team capacity and sprint velocity. Identify any sprints that are over-allocated." |
| Identify Risk Gaps | "Review my current risk model and identify risks that may be missing. Consider dependencies, resource constraints, and external factors." |
| Compare Velocity | "Compare my actual sprint velocity to the planned velocity. Are we on track? What is the trend?" |
| OKR Alignment Check | "Are all work items aligned with our strategic OKRs? Identify any items that may have drifted from the original objectives." |
| Scope Creep Analysis | "Analyse scope changes since the last baseline. Quantify the growth and assess whether it was justified." |
| Sprint Recommendation | "Based on current backlog, velocity, and dependencies, recommend the optimal allocation for the next sprint." |

---

#### 13.3 Context Injection Architecture

When the user sends a message, `CoachService` builds a system prompt that includes the full project context. The system prompt is invisible to the user but gives the AI complete strategic awareness.

**Context payload assembled by `CoachService::buildContext($projectId)`:**

| Context Section | Source | Included Data |
|-----------------|--------|---------------|
| Document Summary | `documents.ai_summary` | The AI-generated strategic brief |
| Strategy Diagram | `strategy_diagrams.mermaid_code` | Raw Mermaid source of the latest diagram |
| OKR Data | `diagram_nodes` | All node OKR titles and descriptions |
| Work Items | `hl_work_items` | All items with titles, descriptions, scores, estimated sprints, priorities |
| Risks | `risks` | All risks with likelihood, impact, mitigation status, linked items |
| User Stories | `user_stories` | All stories with sizes, sprint assignments, blocked_by, status |
| Sprint Status | `sprints` + `sprint_stories` | Sprint date ranges, capacities, allocated points, completion % |
| Drift Alerts | `drift_alerts` | Active alerts with type, severity, and details |
| Governance History | `governance_queue` | Recent approved/rejected changes |
| Evaluation History | `evaluation_results` (last 3) | Most recent sounding board evaluations |
| Conversation History | `coach_messages` | Last 20 messages for conversational continuity |

**Token management:** If the total context exceeds 30,000 tokens, the system progressively truncates: evaluation history first, then governance history, then individual story descriptions, keeping titles and scores.

---

#### 13.4 StratFlow Architect System Prompt

**File:** `src/Services/Prompts/CoachPrompt.php`
**Constant:** `CoachPrompt::SYSTEM_PROMPT`

```
You are the ThreePoints StratFlow Architect — a senior strategy and delivery advisor
embedded in the StratFlow platform. You have complete access to the project's strategic
documents, OKR framework, work item backlog, risk model, sprint allocation, drift
alerts, and governance history.

Your role:
1. Answer questions about the project's strategy, risks, and execution with specific
   references to the actual data (cite work item titles, risk names, sprint numbers).
2. Identify gaps, misalignments, and opportunities the user may not have noticed.
3. Provide actionable recommendations grounded in Agile and strategic planning best
   practices.
4. When asked about feasibility, use the actual sprint capacity and velocity data to
   calculate whether the plan is achievable.
5. Never fabricate data. If you lack information to answer a question, say so and
   suggest what data the user should add.

Response guidelines:
- Be concise but thorough. Use bullet points for multi-part answers.
- Reference specific items by name (e.g. "Work item 'API Gateway Implementation' is
  blocking 3 downstream stories").
- When making recommendations, explain the trade-off involved.
- If asked to compare against industry benchmarks, note that your comparison is based
  on general best practices, not the user's specific industry data.
```

---

#### 13.5 Sprint Goal & Implementation Plan

**Sprint Goal:** Build a conversational AI strategy advisor that has full project context and can answer questions about roadmap feasibility, risk gaps, velocity trends, and OKR alignment — providing the "aha moment" that drives premium tier adoption.

**Objective:** Deliver a slide-out chat panel on every page that connects to Gemini with the full project data as context, enabling users to interrogate their strategy through natural language.

---

**Week 1 — Phase A: Context Engine + Chat Backend**

| Task | Description |
|------|-------------|
| Task 13.1 | Create `CoachService.php` — `buildContext($projectId)` assembles the context payload from all project tables. `chat($conversationId, $userMessage)` prepends system prompt + context + conversation history, calls `GeminiService::generate()`, stores response. |
| Task 13.2 | Create `CoachPrompt.php` in `src/Services/Prompts/` — `SYSTEM_PROMPT` constant as specified in 13.4. Pre-built prompt templates as named constants. |
| Task 13.3 | Create `CoachConversation.php` and `CoachMessage.php` models — standard CRUD. `CoachConversation::findByProject($projectId, $userId)` returns conversation list. `CoachMessage::findByConversation($conversationId, $limit)` returns message history. |
| Task 13.4 | Create `CoachController.php` — routes: `GET /app/project/{id}/coach/conversations` (list), `POST /app/project/{id}/coach/conversations` (new), `POST /app/coach/conversations/{id}/message` (send message + receive response), `GET /app/coach/conversations/{id}/messages` (history). |
| Task 13.5 | Implement token counting and progressive truncation in `CoachService::buildContext()` — prioritise document summary, work items, and risks over evaluation/governance history. |

**Week 2 — Phase B: Chat UI + Pre-Built Prompts**

| Task | Description |
|------|-------------|
| Task 13.6 | Build `templates/partials/coach-panel.php` — slide-out panel HTML structure with message list, input area, pre-built prompt buttons, conversation selector. |
| Task 13.7 | Implement `public/assets/js/coach.js` — handles panel open/close, message send via AJAX POST, response rendering, auto-scroll, loading indicator, pre-built prompt button clicks. |
| Task 13.8 | Add floating action button to `templates/layouts/app.php` — visible on all authenticated pages, positioned bottom-right. |
| Task 13.9 | Style the coach panel — message bubbles, typing indicator, responsive (full-width on mobile). |
| Task 13.10 | Add conversation management: new conversation button, conversation list with timestamps, delete old conversations. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| Chat interface | Slide-out panel opens from any page; messages send and render correctly |
| Context awareness | AI references actual project data (work item titles, risk names, sprint numbers) in responses |
| Pre-built prompts | All 6 pre-built prompt buttons work and generate relevant, context-aware responses |
| Conversation persistence | Messages are stored in the database; switching conversations loads correct history |
| Token management | Context is progressively truncated when it exceeds 30,000 tokens; no API errors on large projects |
| Performance | Response returns in under 10 seconds; panel does not affect page load time (lazy-loaded) |
| Multi-tenancy | Coach conversations scoped to project and user; no cross-org data in context |

---

**Database Schema**

```sql
CREATE TABLE coach_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NULL COMMENT 'Auto-generated from first message, or user-set',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_project_user (project_id, user_id)
);

CREATE TABLE coach_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    token_count INT UNSIGNED NULL COMMENT 'Approximate token count for context window management',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES coach_conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation (conversation_id, created_at)
);
```

---

---

### 14. Multi-Format Document Intelligence Screen

The Multi-Format Document Intelligence feature extends StratFlow's document upload pipeline to accept audio recordings (MP4, MP3, WAV, M4A), images (PNG, JPG), presentations (PPTX), and URLs (Confluence, Notion, Google Docs). Each new format is processed into extracted text using the appropriate service (OpenAI Whisper for audio/video, Tesseract or Google Vision for images, ZipArchive for PPTX, HTTP fetch + HTML parsing for URLs) and then fed into the existing summary → diagram → work item pipeline unchanged. This removes the single biggest top-of-funnel friction point: "I have a strategy meeting recording, but StratFlow only takes PDFs."

---

#### 14.1 Enhanced Upload Screen

**Route:** `GET /app/project/{id}/upload` (existing route, enhanced)

The existing upload screen is extended with new accepted formats and a URL import field.

| Field | Type | Description |
|-------|------|-------------|
| File Upload Zone | Drag-and-drop + file picker | Accepts: PDF, TXT, DOCX (existing) + MP4, MP3, WAV, M4A, PNG, JPG, JPEG, PPTX (new) |
| URL Import Field | Text input + "Import" button | Accepts: Confluence page URL, Notion page URL, Google Docs share link |
| Format Indicator | Badge | Shown on each uploaded file: `PDF`, `Audio`, `Video`, `Image`, `Presentation`, `URL` |
| Processing Status | Progress bar + status text | "Transcribing audio...", "Extracting text from image...", "Importing page..." |
| Transcription Preview | Expandable panel | For audio/video: shows transcript with speaker labels and timestamps before proceeding |

**Accepted MIME types (new):**
- Audio: `audio/mpeg`, `audio/wav`, `audio/mp4`, `audio/x-m4a`
- Video: `video/mp4`
- Image: `image/png`, `image/jpeg`
- Presentation: `application/vnd.openxmlformats-officedocument.presentationml.presentation`

**Maximum file sizes:**
- Audio/Video: 100 MB (Whisper API limit: 25 MB per request — files larger than 25 MB are chunked)
- Image: 20 MB
- Presentation: 50 MB

---

#### 14.2 Audio/Video Transcription

**Service:** `TranscriptionService.php`

| Field | Type | Description |
|-------|------|-------------|
| Transcription Provider | Config | OpenAI Whisper API (default) or Google Speech-to-Text |
| Language | Auto-detected | Whisper auto-detects language; manual override available in config |
| Speaker Diarisation | Boolean | When available, transcript includes speaker labels (Speaker 1, Speaker 2) |
| Timestamps | Boolean | Transcript includes segment timestamps for reference |

**Processing flow:**
1. User uploads audio/video file
2. `FileProcessor::processAudio($filePath)` calls `TranscriptionService::transcribe()`
3. For files >25 MB: split into chunks using ffmpeg, transcribe each, concatenate
4. Transcript stored in `documents.extracted_text`
5. Transcription metadata (duration, language, word count, speaker count) stored in `documents.transcription_metadata`
6. User previews transcript, clicks "Continue" to proceed to summary generation

---

#### 14.3 Image OCR

**Service:** `OCRService.php`

| Field | Type | Description |
|-------|------|-------------|
| OCR Provider | Config | Tesseract (local, free) or Google Vision API (cloud, more accurate) |
| Pre-processing | Automatic | Image is converted to greyscale, contrast-enhanced, and deskewed before OCR |
| Confidence Score | Percentage | OCR confidence displayed to user; warning if <70% |

**Processing flow:**
1. User uploads image file (whiteboard photo, screenshot, etc.)
2. `FileProcessor::processImage($filePath)` calls `OCRService::extract()`
3. Extracted text stored in `documents.extracted_text`
4. Low-confidence warning shown if OCR quality is poor — suggests re-uploading a clearer image

---

#### 14.4 Presentation Support (PPTX)

**Service:** Extension to `FileProcessor.php`

| Field | Type | Description |
|-------|------|-------------|
| Slide Text | Extracted | Text from all slide text boxes, concatenated by slide number |
| Speaker Notes | Extracted | Notes from each slide appended after slide text |
| Embedded Images | Optional | Images within slides can be extracted and OCR'd (toggle in config) |

**Processing flow:**
1. User uploads PPTX file
2. `FileProcessor::processPptx($filePath)` opens the PPTX as a ZIP archive
3. Iterates `ppt/slides/slide{N}.xml` files, extracts text from `<a:t>` elements
4. Iterates `ppt/notesSlides/notesSlide{N}.xml` for speaker notes
5. Concatenated text stored in `documents.extracted_text`

---

#### 14.5 URL Import

**Route:** `POST /app/project/{id}/upload/url`

| Field | Type | Description |
|-------|------|-------------|
| URL Input | Text | The page URL to import |
| Detected Platform | Badge | "Confluence", "Notion", "Google Docs", or "Web Page" |
| Import Preview | Text area | Extracted text shown for user confirmation before saving |

**Platform-specific handling:**

| Platform | Method |
|----------|--------|
| Google Docs | Use export URL: `https://docs.google.com/document/d/{id}/export?format=txt` |
| Confluence | Confluence REST API: `GET /wiki/rest/api/content/{id}?expand=body.storage` (requires API token) |
| Notion | Notion API: `GET /v1/blocks/{id}/children` (requires integration token) |
| Generic web page | HTTP GET + strip HTML tags via `strip_tags()` + remove scripts/styles |

---

#### 14.6 Sprint Goal & Implementation Plan

**Sprint Goal:** Extend StratFlow's document upload pipeline to accept audio, video, images, presentations, and URLs — removing the top-of-funnel friction that prevents users from uploading their actual strategy artifacts.

**Objective:** Ensure that no matter how a strategy conversation was captured (recorded meeting, whiteboard photo, slide deck, wiki page), StratFlow can ingest it and produce a sprint-ready backlog.

---

**Week 1 — Phase A: Audio/Video Transcription + Image OCR**

| Task | Description |
|------|-------------|
| Task 14.1 | Create `TranscriptionService.php` — `transcribe($filePath, $mimeType)` method. Implements OpenAI Whisper API via `multipart/form-data` POST to `https://api.openai.com/v1/audio/transcriptions`. Handles chunking for files >25 MB. |
| Task 14.2 | Create `OCRService.php` — `extract($filePath)` method. Tesseract via `exec('tesseract ...')` or Google Vision API via HTTP POST. Returns extracted text + confidence score. |
| Task 14.3 | Extend `FileProcessor.php` — add `processAudio()` and `processImage()` methods. Route new MIME types to the appropriate processor. |
| Task 14.4 | Add `transcription_metadata` JSON column to `documents` table. Store: duration, language, word count, speaker count (audio/video) or confidence score (image). |
| Task 14.5 | Update `templates/upload.php` — extend drag-and-drop zone accepted types, add format badges, add transcription preview panel with continue/retry buttons. |
| Task 14.6 | Add `OPENAI_API_KEY` and `GOOGLE_VISION_API_KEY` to `.env.example` and `src/Config/config.php`. |

**Week 2 — Phase B: PPTX Support + URL Import**

| Task | Description |
|------|-------------|
| Task 14.7 | Extend `FileProcessor.php` — add `processPptx($filePath)` method. Use PHP `ZipArchive` to open PPTX, parse slide XML for `<a:t>` text elements and notes XML for speaker notes. |
| Task 14.8 | Create URL import route `POST /app/project/{id}/upload/url` in `UploadController`. Detect platform from URL pattern, fetch content via appropriate method, store as document. |
| Task 14.9 | Implement Google Docs export-as-text fetcher. Implement generic web page text extractor with `strip_tags()` and script/style removal. |
| Task 14.10 | Implement Confluence and Notion API integrations (optional — behind config flags, since they require API tokens). |
| Task 14.11 | Update upload screen with URL import field. Show detected platform badge and text preview before saving. |
| Task 14.12 | Add processing status indicators — progress bar and status text for long-running transcription jobs. Consider async processing with polling for audio files >5 minutes. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| Audio transcription | Upload an MP3/MP4 meeting recording; transcript appears in extracted_text; pipeline proceeds to summary generation |
| Video transcription | Upload an MP4 file; audio track extracted and transcribed; transcript available for review |
| Image OCR | Upload a whiteboard photo (PNG/JPG); OCR text extracted; low-confidence warning shown when appropriate |
| PPTX extraction | Upload a PPTX deck; text from all slides and speaker notes extracted correctly |
| URL import | Import a Google Doc and a generic web page; text extracted and saved as a document |
| Transcription metadata | Duration, language, and word count stored for audio; confidence score stored for images |
| File size handling | Files >25 MB are chunked correctly for Whisper API; files >100 MB are rejected with a clear error |
| Existing formats | PDF, TXT, and DOCX uploads continue to work unchanged |

---

**Database Schema**

```sql
-- Migration: add transcription metadata to documents
ALTER TABLE documents ADD COLUMN transcription_metadata JSON NULL
    COMMENT '{"duration_seconds": 1842, "language": "en", "word_count": 3200, "speaker_count": 4, "ocr_confidence": 0.92}'
    AFTER ai_summary;

-- Migration: extend mime_type to accommodate new formats
-- No schema change needed — mime_type is VARCHAR(100), sufficient for all new types
```

---

---

### 15. Meeting Platform Integration Screen

The Meeting Platform Integration connects StratFlow to Microsoft Teams, Zoom, and Google Meet to automatically import meeting transcripts and (where available) AI-generated meeting insights. This enables a "meeting-to-strategy" pipeline: a strategy workshop happens in Teams, the transcript is automatically imported into StratFlow, and the platform generates work items from the discussion. This is the most enterprise-workflow-aligned feature in V3 — it meets users where they already spend their time.

---

#### 15.1 Meeting Integration Settings

**Route:** `GET /app/admin/integrations/meetings`

Central configuration page for connecting meeting platforms. Sits within the Integration Hub (Section 10) as a dedicated sub-section.

| Field | Type | Description |
|-------|------|-------------|
| Microsoft Teams | Connection card | Status badge (Connected/Not Connected), "Connect" button, connected account email |
| Zoom | Connection card | Status badge, "Connect" button, connected account email |
| Google Meet | Connection card | Status badge, "Connect" button, connected account email |
| Auto-Import | Toggle per platform | When enabled, new meeting transcripts are automatically imported on meeting end |
| Default Project | Dropdown per platform | Which project to import transcripts into by default (can be overridden per meeting) |
| Transcript Processing | Radio group | `Raw Transcript Only`, `Transcript + AI Summary`, `Transcript + AI Summary + Work Item Generation` |

---

#### 15.2 Microsoft Teams Connection

**Authentication:** Azure AD app registration with Microsoft Graph API permissions.

**OAuth 2.0 flow:**
- Redirect to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize`
- Scopes: `OnlineMeetingTranscript.Read.All`, `OnlineMeeting.Read`, `User.Read`
- Token stored encrypted in `meeting_integrations.config_json`

**Transcript retrieval:**
1. **Webhook subscription:** Register for `communications/onlineMeetings/getAllTranscripts` change notifications via Microsoft Graph Subscriptions API
2. **On notification received:** Extract `meetingId` and `transcriptId` from the notification payload
3. **Fetch transcript content:** `GET /me/onlineMeetings/{meetingId}/transcripts/{transcriptId}/content` — returns VTT format
4. **Parse VTT:** Extract speaker-attributed, timestamped text segments

**Microsoft Copilot AI Insights (optional, requires Copilot license):**
1. After meeting ends, poll: `GET /copilot/users/{userId}/onlineMeetings/{meetingId}/aiInsights`
2. AI Insights include: `meetingNotes` (structured with titles, text, subpoints), `actionItems` (with owners), `viewpoint` mentions
3. Takes up to 4 hours after meeting ends — poll with exponential backoff (5 min, 15 min, 30 min, 1 hr, 2 hr, 4 hr)
4. If Copilot insights are available, store alongside raw transcript for richer context

**Teams Bot integration (optional, advanced):**
- Register a Teams Bot that receives `meetingStart` and `meetingEnd` lifecycle events
- On `meetingEnd`: automatically subscribe for transcript availability
- Bot manifest permissions: `OnlineMeetingParticipant.Read.Chat`, `OnlineMeeting.ReadBasic.Chat`

---

#### 15.3 Zoom Connection

**Authentication:** Zoom Server-to-Server OAuth app.

**Setup:**
- Create Server-to-Server OAuth app in Zoom Marketplace
- Scopes: `meeting:read:admin`, `recording:read:admin`
- Store Account ID, Client ID, Client Secret in `meeting_integrations.config_json`

**Transcript retrieval:**
1. **Webhook:** Subscribe to `recording.completed` event in Zoom app settings
2. **On notification received:** Extract `meeting_id` from payload
3. **Fetch transcript:** `GET /meetings/{meetingId}/transcript` — returns WebVTT format
4. **Parse VTT:** Same VTT parser as Teams transcripts

**Requirements:**
- Requires Zoom paid plan (Pro, Business, or Enterprise) — transcription is not available on free plans
- Meeting must have cloud recording enabled with "Audio transcript" toggled on

---

#### 15.4 Google Meet Connection

**Authentication:** Google OAuth 2.0 with Google Meet API.

**OAuth 2.0 flow:**
- Scopes: `https://www.googleapis.com/auth/meetings.space.readonly`
- Token stored encrypted in `meeting_integrations.config_json`

**Transcript retrieval:**
1. **List transcripts:** `GET /v2/{parent=conferenceRecords/*}/transcripts`
2. **Get transcript entries:** Each `TranscriptEntry` includes `participant` (speaker), `text`, `startOffset`, `endOffset`
3. **Alternative:** Google Meet saves transcripts as Google Docs in the organiser's Drive — can be fetched via Google Drive API if Meet API access is limited

---

#### 15.5 Meeting Browser & Import UI

**Route:** `GET /app/project/{id}/meetings`

Displays recent meetings from all connected platforms, allowing the user to browse and selectively import transcripts.

| Field | Type | Description |
|-------|------|-------------|
| Platform Filter | Tab bar | `All`, `Teams`, `Zoom`, `Google Meet` |
| Meeting List | Table | Columns: Platform icon, Meeting Title, Date/Time, Duration, Participants, Import Status |
| Import Status | Badge | `Not Imported`, `Imported`, `Processing` |
| Import Button | Button per row | "Import to Project" — triggers transcript fetch, VTT parse, and document creation |
| Bulk Import | Checkbox selection + button | Select multiple meetings and import all at once |

**Import flow:**
1. User clicks "Import" on a meeting row
2. System fetches transcript from the platform API (if not already cached)
3. VTT transcript is parsed into speaker-attributed plain text
4. If Copilot AI Insights are available (Teams), they are appended as a structured summary section
5. A new `documents` row is created with the transcript as `extracted_text` and meeting metadata in `transcription_metadata`
6. User is redirected to the document summary step of the standard pipeline

**Meeting metadata stored in `transcription_metadata`:**

```json
{
    "source": "teams",
    "meeting_id": "AAMkAGI...",
    "meeting_title": "Q3 Strategy Workshop",
    "meeting_date": "2026-04-08T14:00:00Z",
    "duration_minutes": 62,
    "participant_count": 8,
    "participants": ["Alice Smith", "Bob Jones", ...],
    "has_copilot_insights": true,
    "action_items": [
        {"text": "Draft API specification", "owner": "Bob Jones"},
        {"text": "Review budget proposal", "owner": "Alice Smith"}
    ]
}
```

---

#### 15.6 VTT Transcript Parser

**Service:** `VTTParserService.php`

Shared parser used by all three meeting platforms. Converts WebVTT format into structured, speaker-attributed text suitable for the StratFlow AI pipeline.

**Input (WebVTT):**
```
WEBVTT

00:00:05.000 --> 00:00:12.000
<v Alice Smith>We need to focus on the API gateway as our first priority.

00:00:12.500 --> 00:00:20.000
<v Bob Jones>Agreed. The mobile team is blocked until the gateway is ready.
```

**Output (structured text):**
```
[00:00:05] Alice Smith: We need to focus on the API gateway as our first priority.
[00:00:12] Bob Jones: Agreed. The mobile team is blocked until the gateway is ready.
```

**Parser capabilities:**
- Extracts speaker name from VTT `<v>` tags
- Preserves timestamps for reference
- Strips formatting tags (`<b>`, `<i>`, etc.)
- Concatenates consecutive segments from the same speaker
- Returns both formatted text (for `extracted_text`) and structured array (for metadata)

---

#### 15.7 Automatic Import Pipeline

When "Auto-Import" is enabled for a platform (Section 15.1), the system automatically processes new meeting transcripts without user intervention.

**Pipeline:**
1. Webhook receives meeting-end notification
2. System waits for transcript availability (immediate for Zoom, up to 15 minutes for Teams, variable for Meet)
3. Transcript is fetched and parsed
4. A new document is created in the configured default project
5. If "Transcript Processing" is set to "Transcript + AI Summary", the summary is auto-generated
6. If set to "Transcript + AI Summary + Work Item Generation", the full pipeline runs: summary → diagram → work items
7. A notification is created for the project owner: "Meeting transcript imported: {meeting_title}"
8. An activity log entry is created: `action = 'meeting_imported'`

---

#### 15.8 Sprint Goal & Implementation Plan

**Sprint Goal:** Integrate StratFlow with Microsoft Teams, Zoom, and Google Meet to import meeting transcripts (and Copilot AI Insights where available) directly into the strategy pipeline, enabling a seamless "meeting-to-backlog" workflow.

**Objective:** Meet enterprise users where they already work — in video calls. Automatically capture strategy discussions and convert them into actionable backlogs without manual transcription or copy-paste.

---

**Week 1 — Phase A: Microsoft Teams Integration + VTT Parser**

| Task | Description |
|------|-------------|
| Task 15.1 | Create `MeetingIntegrationService.php` — base class with `connect()`, `disconnect()`, `fetchRecentMeetings()`, `fetchTranscript($meetingId)` interface. |
| Task 15.2 | Create `TeamsService.php extends MeetingIntegrationService` — Azure AD OAuth 2.0 flow, Microsoft Graph API client. Implements: `authenticate()`, `listMeetings()`, `getTranscript($meetingId, $transcriptId)`, `getCopilotInsights($meetingId)`. |
| Task 15.3 | Create `VTTParserService.php` — `parse($vttContent)` returns structured text + metadata array. Handle VTT speaker tags, timestamps, formatting strips, speaker concatenation. |
| Task 15.4 | Implement webhook endpoint `POST /webhook/meetings/teams` — receives Microsoft Graph change notifications for transcript availability. Verifies notification via validation token handshake. |
| Task 15.5 | Create `MeetingIntegration.php` and `MeetingImport.php` models. Standard CRUD operations. |
| Task 15.6 | Build `templates/admin/meeting-integrations.php` — connection cards for Teams/Zoom/Meet with status, auto-import toggle, default project selector, transcript processing level. |

**Week 2 — Phase B: Zoom + Google Meet + Meeting Browser UI**

| Task | Description |
|------|-------------|
| Task 15.7 | Create `ZoomService.php extends MeetingIntegrationService` — Server-to-Server OAuth, `recording.completed` webhook handling, transcript fetch via `GET /meetings/{id}/transcript`. |
| Task 15.8 | Create `GoogleMeetService.php extends MeetingIntegrationService` — Google OAuth 2.0, Google Meet REST API for transcript retrieval, fallback to Google Drive API for transcript documents. |
| Task 15.9 | Build `templates/meetings.php` — meeting browser with platform tabs, meeting list table, import buttons, bulk import. |
| Task 15.10 | Implement import flow: fetch transcript → parse VTT → create document → optionally run summary → redirect to pipeline. Store meeting metadata in `transcription_metadata`. |
| Task 15.11 | Implement auto-import pipeline: webhook trigger → wait for transcript → fetch → parse → create document → optionally run full pipeline → notify project owner. |
| Task 15.12 | Implement Copilot AI Insights polling for Teams: exponential backoff (5 min to 4 hr), store structured insights in `transcription_metadata`, append action items to imported document. |

---

**Definition of Done**

| Requirement | Success Criteria |
|-------------|-----------------|
| Teams connection | User can authenticate via Azure AD OAuth; token stored encrypted; connection status shown |
| Teams transcript import | Transcript fetched via Graph API, parsed from VTT, and saved as a StratFlow document |
| Copilot insights | When available, action items and meeting notes are included in the imported document |
| Zoom connection | Server-to-Server OAuth configured; transcript fetched on recording.completed webhook |
| Google Meet connection | OAuth configured; transcript fetched via Meet API or Drive API fallback |
| VTT parser | Correctly handles speaker attribution, timestamps, and formatting across all three platforms |
| Meeting browser | Recent meetings listed with platform icon, title, date, duration; import button creates document |
| Auto-import | Webhook-triggered pipeline creates documents without user intervention; notification sent |
| Error handling | Platform API errors are logged; import failures create a retry-able queue entry; user is notified |
| Multi-tenancy | Meeting integrations scoped to org; meeting imports scoped to project |

---

**Database Schema**

```sql
CREATE TABLE meeting_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    platform ENUM('teams','zoom','google_meet') NOT NULL,
    config_json JSON NOT NULL COMMENT 'OAuth tokens (encrypted), tenant ID, app registration details, webhook subscription ID',
    auto_import TINYINT(1) NOT NULL DEFAULT 0,
    default_project_id INT UNSIGNED NULL COMMENT 'Default project for auto-imported transcripts',
    processing_level ENUM('transcript_only','transcript_summary','full_pipeline') NOT NULL DEFAULT 'transcript_summary',
    status ENUM('active','disconnected','error') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE,
    FOREIGN KEY (default_project_id) REFERENCES projects(id) ON DELETE SET NULL,
    UNIQUE KEY uq_org_platform (org_id, platform)
);

CREATE TABLE meeting_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_integration_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NULL COMMENT 'Created document ID; NULL until import completes',
    external_meeting_id VARCHAR(255) NOT NULL COMMENT 'Platform-specific meeting identifier',
    meeting_title VARCHAR(255) NULL,
    meeting_date DATETIME NULL,
    duration_minutes INT UNSIGNED NULL,
    participant_count INT UNSIGNED NULL,
    import_status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    has_copilot_insights TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_integration_id) REFERENCES meeting_integrations(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
    UNIQUE KEY uq_meeting (meeting_integration_id, external_meeting_id),
    INDEX idx_project (project_id, import_status)
);
```

---

---

## V3 Strategic Summary

### Feature Dependency Map

```
Feature 10 (Jira/ADO Sync) ──────── standalone, highest priority
Feature 11 (Executive Dashboard) ── standalone, builds on existing sprint/governance data
Feature 12 (Collaboration) ──────── standalone, enhances all existing screens
Feature 13 (AI Strategy Coach) ──── depends on Feature 12 (activity feed provides richer context)
Feature 14 (Multi-Format Docs) ──── standalone, extends upload pipeline
Feature 15 (Meeting Integration) ── depends on Feature 14 (shares TranscriptionService + VTT parser)
```

### Implementation Sequencing

| Phase | Timeline | Features | Rationale |
|-------|----------|----------|-----------|
| Phase 1 | Weeks 1–4 | Feature 10 (Jira/ADO Sync) + Feature 11 (Executive Dashboard) | Removes the #1 enterprise objection and creates the executive-facing surface that justifies the subscription |
| Phase 2 | Weeks 5–9 | Feature 12 (Collaboration) + Feature 13 (AI Strategy Coach) | Transforms StratFlow from single-player to team-ready; adds the conversational "wow factor" |
| Phase 3 | Weeks 10–13 | Feature 14 (Multi-Format Docs) + Feature 15 (Meeting Integration) | Widens the upload funnel and connects StratFlow to the enterprise meeting workflow |

### Total New Database Tables

| Table | Feature | Purpose |
|-------|---------|---------|
| `integrations` | 10 | Jira/ADO OAuth connections and configuration |
| `sync_mappings` | 10 | Bidirectional item mapping between StratFlow and external tools |
| `dashboard_cache` | 11 | Materialised cache for dashboard performance |
| `comments` | 12 | Threaded comments on work items, stories, and risks |
| `notifications` | 12 | In-app notification queue per user |
| `activity_log` | 12 | Project-level chronological event log |
| `coach_conversations` | 13 | AI Strategy Coach conversation containers |
| `coach_messages` | 13 | Individual chat messages within coach conversations |
| `meeting_integrations` | 15 | Teams/Zoom/Meet OAuth connections and configuration |
| `meeting_imports` | 15 | Per-meeting import tracking with status and metadata |

### Total New Schema Migrations

| Migration | Feature | Change |
|-----------|---------|--------|
| `ALTER TABLE documents ADD COLUMN transcription_metadata` | 14 | JSON column for audio/video/image processing metadata |
| `ALTER TABLE user_stories ADD COLUMN status` | 11 | ENUM for burndown chart calculations |
| `ALTER TABLE user_stories ADD COLUMN completed_at` | 11 | Timestamp for velocity tracking |
| `ALTER TABLE users ADD COLUMN notification_preferences` | 12 | JSON column for email digest settings |

### New Files Summary

| Directory | New Files | Feature |
|-----------|-----------|---------|
| `src/Services/` | `JiraService.php`, `AzureDevOpsService.php`, `AnalyticsService.php`, `NotificationService.php`, `CoachService.php`, `TranscriptionService.php`, `OCRService.php`, `VTTParserService.php`, `MeetingIntegrationService.php`, `TeamsService.php`, `ZoomService.php`, `GoogleMeetService.php` | 10–15 |
| `src/Services/Prompts/` | `CoachPrompt.php` | 13 |
| `src/Controllers/` | `IntegrationController.php`, `DashboardController.php`, `CommentController.php`, `NotificationController.php`, `CoachController.php`, `MeetingController.php` | 10–15 |
| `src/Models/` | `Integration.php`, `SyncMapping.php`, `DashboardCache.php`, `Comment.php`, `Notification.php`, `ActivityLog.php`, `CoachConversation.php`, `CoachMessage.php`, `MeetingIntegration.php`, `MeetingImport.php` | 10–15 |
| `templates/` | `dashboard.php`, `analytics.php`, `okr-progress.php`, `activity-feed.php`, `notifications.php`, `meetings.php` | 11–15 |
| `templates/admin/` | `integrations.php`, `meeting-integrations.php` | 10, 15 |
| `templates/partials/` | `comment-thread.php`, `notification-bell.php`, `coach-panel.php` | 12, 13 |
| `public/assets/js/` | `charts.js`, `coach.js` | 11, 13 |

### Revenue Impact Projection

| Feature | Enterprise Conversion Impact | Retention Impact | ARPU Impact |
|---------|------------------------------|-----------------|-------------|
| Jira/ADO Sync (10) | +60–80% (removes #1 disqualification) | +30% (embedded in workflow) | Neutral |
| Executive Dashboard (11) | +20% (sells to budget holder) | +40% (daily usage habit) | +15% (portfolio tier) |
| Collaboration (12) | +15% (multi-user requirement) | +25% (engagement loops) | +30% (more seats) |
| AI Strategy Coach (13) | +10% (demo wow factor) | +15% (discovery value) | +20% (premium feature) |
| Multi-Format Docs (14) | +5% (reduces bounce at step 1) | +5% (convenience) | Neutral |
| Meeting Integration (15) | +15% (enterprise workflow fit) | +20% (automated pipeline) | +10% (premium feature) |

### Competitive Position After V3

With all six features deployed, StratFlow will be the only platform that:

1. **Ingests any strategy artifact** — documents, recordings, images, presentations, meeting transcripts, wiki pages
2. **Generates a complete delivery pipeline** — from raw input through OKRs, work items, risks, user stories, and sprint allocation
3. **Maintains a live governance loop** — drift detection that spans both StratFlow and external tools (Jira, Azure DevOps)
4. **Provides portfolio-level visibility** — health scores, velocity trends, OKR progress, and scope change history across all projects
5. **Enables team collaboration** — comments, activity feeds, notifications, and AI-assisted strategic conversations
6. **Connects to the meeting workflow** — strategy workshops in Teams, Zoom, or Meet flow directly into the planning pipeline

No competitor covers more than two of these six capabilities. Jira Align has portfolio views and Jira integration but starts from manual structured input. Productboard has prioritisation and roadmapping but no governance loop. DriftlineAI monitors strategy drift but produces no delivery artifacts. StratFlow V3 is the complete strategy operating system.
