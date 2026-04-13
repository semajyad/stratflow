# Version 3 — Enterprise Integration & Intelligence

StratFlow V1 (Items 1–9) established the strategy-to-delivery pipeline: upload documents, generate strategy diagrams, decompose into work items, score via RICE/WSJF, model risks, break into user stories, and allocate to sprints. V2 added the governance layer — sounding boards, admin surfaces, the Strategic Drift Engine with baselines, tripwires, and a change-control queue — creating the only tool that maintains a live closed loop between strategic intent and execution reality. Version 3 extends the platform into the enterprise. Six new features transform StratFlow from a planning-phase tool into the organisation's permanent strategy operating system: bidirectional integration with Jira and Azure DevOps, portfolio-level executive dashboards, real-time collaboration with activity feeds, a conversational AI strategy coach, multi-format document intelligence, and meeting platform integration with Microsoft Teams, Zoom, and Google Meet.

---

## Item 10: Integration Hub — Bidirectional Jira & Azure DevOps Sync

**Sprint Goal:** The Connected Ecosystem

**Objective:** Eliminate the CSV/JSON export dead-end by establishing a live, authenticated, field-mapped sync pipeline between StratFlow and the two dominant enterprise work tracking platforms, closing the drift detection feedback loop so the Strategic Drift Engine can detect and respond to changes made outside StratFlow.

---

### Week 1: One-Way Push to Jira Cloud & Azure DevOps

**Focus:** Implement OAuth 2.0 authentication flows for both Jira Cloud and Azure DevOps, build the integration configuration screens, and deliver one-way push of High Level work items as Epics and user stories as Stories with configurable field mapping.

#### Phase 1: OAuth Authentication & Data Model (Days 1–3)

● **Task 10.1: Jira OAuth 2.0 Client.** Implement `JiraService.php` with OAuth 2.0 client for Atlassian Cloud REST API v3. Methods: `authenticate()`, `getProjects()`, `createEpic()`, `createStory()`, `updateIssue()`, `getIssue()`. Store tokens encrypted in `integrations.config_json`. OAuth scopes requested: `read:jira-work`, `write:jira-work`, `read:jira-user`, `manage:jira-webhook`.

● **Task 10.2: Integration & Sync Mapping Models.** Create `Integration.php` and `SyncMapping.php` models with standard CRUD operations plus `findByOrgAndProvider()`, `findByLocalItem()`, `findByExternalId()`. Integration model stores provider type, OAuth config JSON, status, last sync timestamp, and error count.

● **Task 10.3: Integration Controller & Routes.** Create `IntegrationController.php` with routes for connection setup, field mapping, sync trigger, and status view. Register all routes under `/app/admin/integrations/*` with `AdminMiddleware`. Routes include:
  ○ `GET /app/admin/integrations` — integration list view
  ○ `GET /app/admin/integrations/jira/connect` — Jira OAuth flow
  ○ `GET /app/admin/integrations/azure-devops/connect` — Azure DevOps OAuth flow
  ○ `GET /app/admin/integrations/{id}/field-mapping` — field mapping configuration
  ○ `GET /app/admin/integrations/{id}/status` — sync status and history

#### Phase 2: Integration UI & Push Logic (Days 4–5)

● **Task 10.4: Integration Hub Templates.** Build `templates/admin/integrations.php` — integration list view showing all configured integrations with provider badge, project name, status (Active/Paused/Error), last sync timestamp, mapped item count, and action buttons (Configure, Sync Now, Pause/Resume, Delete). Include Jira connection form, Azure DevOps connection form, and field mapping table.

● **Task 10.5: One-Way Push Implementation.** Implement push logic: `JiraService::pushWorkItem()` creates a Jira Epic from an High Level work item, `pushUserStory()` creates a Jira Story linked to the parent Epic. Insert a `sync_mappings` row on each push recording the local type, local ID, external ID, external URL, and a SHA-256 sync hash of field values for change detection.

● **Task 10.6: Push Trigger UI.** Add "Push to Jira" and "Push to Azure DevOps" buttons on Work Items and User Stories screens. Buttons appear only when an active integration exists for the respective provider. Button click triggers push and displays success/error feedback.

---

### Week 2: Bidirectional Sync & Webhook Listener

**Focus:** Implement webhook listeners for both platforms to receive external changes, build bidirectional conflict resolution routed through the governance queue, deliver Azure DevOps feature parity, and surface sync status on the Governance Dashboard.

#### Phase 3: Webhook Listener & Conflict Resolution (Days 1–3)

● **Task 10.7: Jira Webhook Endpoint.** Implement `POST /webhook/integration/jira` endpoint. Verify Jira webhook signature. Parse event types:
  ○ `jira:issue_updated` — compare changed fields against sync_mappings; if a mapped item changed, update StratFlow and trigger drift detection
  ○ `jira:issue_created` — if created under a mapped Epic, create corresponding user story in StratFlow with `requires_review = 1`
  ○ `jira:issue_deleted` — flag the mapped StratFlow item for governance review
  ○ `sprint_started`, `sprint_closed` — update StratFlow sprint status

● **Task 10.8: External Change Processing.** On external change detection: update the mapped StratFlow item, set `requires_review = 1`, and call `DriftDetectionService::detectDrift()` for the parent project. Log the sync event in the integration status history.

● **Task 10.9: Conflict Resolution via Governance Queue.** When a bidirectional change conflict is detected (both StratFlow and the external tool modified the same field since last sync), create a `governance_queue` item with `change_type = 'external_change'` containing both values in `proposed_change_json`. The item appears in the Governance Dashboard with an "External Change Detected" label and accept/reject buttons.

#### Phase 4: Azure DevOps Parity & Dashboard Integration (Days 4–5)

● **Task 10.10: Azure DevOps Service.** Implement `AzureDevOpsService.php` — OAuth 2.0 client for Azure DevOps REST API with the same method signatures as JiraService. OAuth scopes: `vso.work_write`, `vso.hooks_write`. Register ADO webhook via Service Hooks API. Handle event types: `workitem.updated`, `workitem.created`, `workitem.deleted`.

● **Task 10.11: Azure DevOps Connection UI.** Build Azure DevOps connection screen and field mapping configuration. Reuse Jira templates with provider-conditional rendering. Field mappings: High Level Work Item → Feature, User Story → User Story, Sprint → Iteration, Priority Number → Priority, Story Points → Story Points, Owner → Assigned To.

● **Task 10.12: Governance Dashboard Integration.** Add integration sync status to the Governance Dashboard — show count of pending external changes alongside drift alerts. Display sync health indicators: last sync timestamp, error count, and a "Sync Now" quick action.

---

### Field Mapping Configuration

Configurable mapping between StratFlow fields and external tool fields, presented as a two-column mapping table.

| StratFlow Field | Maps To (Jira) | Maps To (Azure DevOps) | Default |
|-----------------|----------------|------------------------|---------|
| High Level Work Item → | Epic | Feature | Auto |
| User Story → | Story | User Story | Auto |
| Sprint → | Sprint | Iteration | Auto |
| Priority Number → | Priority | Priority | Auto |
| Story Points (size) → | Story Points | Story Points | Auto |
| Owner → | Assignee | Assigned To | Auto |
| OKR Title → | Custom field or Epic description | Custom field or description | Description append |
| Description → | Description | Description | Auto |
| Strategic Context → | Labels | Tags | Auto |

Custom field mapping: a "+ Add Custom Mapping" row allows selecting any StratFlow field and mapping it to any external field by name.

---

### Definition of Done (DoD)

| Requirement | Success Criteria |
|-------------|-----------------|
| OAuth 2.0 authentication | User can connect Jira Cloud and Azure DevOps via OAuth flow; tokens are stored encrypted in `integrations.config_json` |
| One-way push | High Level work items push as Epics, user stories push as Stories, with correct field mapping; `sync_mappings` rows created |
| Bidirectional sync | Changes made in Jira or ADO are detected via webhook, reflected in StratFlow, and trigger drift detection |
| Conflict resolution | Conflicting changes create governance queue items with both values; user can accept or reject |
| Field mapping | Admin can customise field mapping; custom field mappings are persisted and applied on every sync |
| Error handling | Sync errors are logged; integration status changes to `error` on repeated failures; errors visible in status view |
| Multi-tenancy | Integrations are scoped to `org_id`; no cross-org data leakage |

---

### Database Schema

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

## Item 11: Executive Strategy Dashboard & Portfolio View

**Sprint Goal:** The Strategic Command Centre

**Objective:** Create the visual surface that sells StratFlow to the person who signs the cheque — a portfolio-level executive dashboard that surfaces composite health scores, velocity trends, OKR progress, and scope change history, enabling C-suite stakeholders to justify the subscription and monitor strategy execution across all projects.

---

### Week 1: Health Score Engine & Portfolio View

**Focus:** Build the composite health score calculation engine, create the portfolio overview with project cards, and implement the dashboard caching layer for performance.

#### Phase 1: Analytics Service & Data Model (Days 1–3)

● **Task 11.1: Analytics Service.** Create `AnalyticsService.php` with methods: `calculateHealthScore($projectId)`, `getPortfolioSummary($orgId)`, `getVelocityData($projectId)`, `getBurndownData($projectId, $sprintId)`, `getOKRProgress($projectId)`, `getTeamUtilisation($orgId)`. The health score is a composite metric (0–100) computed from four weighted dimensions:
  ○ OKR Coverage (25%): work items with non-empty `okr_title` / total work items * 100
  ○ Strategic Alignment (25%): 100 - (active alignment drift alerts * 15), minimum 0
  ○ Risk Exposure (25%): 100 - (sum of active risk priorities / maximum possible risk score * 100)
  ○ Execution Momentum (25%): story points completed in last 2 sprints / story points planned in last 2 sprints * 100, capped at 100

● **Task 11.2: Dashboard Controller & Routes.** Create `DashboardController.php` with routes: `GET /app/dashboard` (portfolio overview), `GET /app/dashboard/project/{id}/analytics` (per-project analytics), `GET /app/dashboard/project/{id}/okrs` (OKR progress). Register with `AuthMiddleware`. Make the portfolio view the default landing page for `org_admin` users.

● **Task 11.3: Dashboard Cache Model.** Create `DashboardCache.php` model with `getOrCompute($orgId, $cacheKey, $ttlSeconds, $computeFn)` pattern. Cache keys include `portfolio_summary`, `project_health_{id}`, `velocity_{id}`. Invalidate on project, sprint, or governance changes. TTL: 5 minutes.

#### Phase 2: Portfolio UI & User Story Status (Days 4–5)

● **Task 11.4: Portfolio Dashboard Template.** Build `templates/dashboard.php` — portfolio grid with project cards showing: project name (clickable), status badge (Draft/Active/Completed), health score gauge (0–100, colour-coded: green >=75, amber 50–74, red <50), current workflow phase, sprint progress mini bar, active alert count badge, and last activity timestamp. Include organisation health score (weighted average), active project count, active drift alerts with severity breakdown, and aggregate team utilisation bar.

● **Task 11.5: User Story Status Migration.** Add `status` column to `user_stories` table (`ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo'`) and `completed_at` DATETIME column to support burndown and velocity calculations.

---

### Week 2: Charts & Per-Project Analytics

**Focus:** Implement Chart.js-powered burndown/burnup charts, velocity tracking, OKR progress visualisation, scope change timeline, and team utilisation views.

#### Phase 3: Chart Rendering & Analytics Templates (Days 1–3)

● **Task 11.6: Analytics Page Template.** Build `templates/analytics.php` — per-project analytics view with burndown/burnup chart containers (toggle between chart types), velocity bar chart, and scope change timeline. Include sprint selector dropdown defaulting to the current active sprint.

● **Task 11.7: Chart.js Implementation.** Implement `public/assets/js/charts.js` — Chart.js rendering for burndown (story points remaining vs. time with ideal dashed line and actual solid line), burnup (cumulative completed vs. total scope with scope change dotted line), velocity (bar chart with one bar per completed sprint and rolling 3-sprint average horizontal dashed line), and team utilisation charts. Load Chart.js from CDN.

● **Task 11.8: OKR Progress Template.** Build `templates/okr-progress.php` — OKR cards displaying each OKR title and expandable description, progress bar showing completion percentage based on linked work item status, status badge (On Track >=70%, At Risk 40–69%, Behind <40%), and expandable list of linked work items with sprint allocation and story completion percentages.

#### Phase 4: Timeline & Navigation (Days 4–5)

● **Task 11.9: Scope Change Timeline.** Implement scope change timeline using governance queue data — query `governance_queue` by project, plot events on a horizontal CSS timeline from project creation to today. Events show as markers with hover/click tooltips displaying change type, proposed change summary, approval status, and reviewer. Baseline creation dates appear as vertical marker lines.

● **Task 11.10: Dashboard Navigation.** Add dashboard link to the main sidebar navigation. Make it the default landing page for `org_admin` users. Add sorting options (by health score ascending/descending, by last activity, by name) and filtering options (by status, by health score range, by assigned team) to the portfolio view.

---

### 11.1 Portfolio Overview

**Route:** `GET /app/dashboard`

The landing view after login for users with `org_admin` or higher roles. The screen will display all organisation projects as cards in a responsive grid.

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
| Strategy Health Score | Gauge (0–100) | Composite metric from four weighted dimensions |
| Current Phase | Text | Which workflow step the project is on |
| Sprint Progress | Mini progress bar | Story points completed / total in current sprint |
| Active Alerts | Count badge | Unresolved drift alerts for this project |
| Last Activity | Relative timestamp | Most recent change to any project entity |

### 11.2 Per-Project Analytics

**Route:** `GET /app/dashboard/project/{id}/analytics`

The screen will display per-project burndown/burnup charts, velocity tracking, and scope change timeline.

| Field | Type | Description |
|-------|------|-------------|
| Sprint Selector | Dropdown | Choose which sprint to view (defaults to current active sprint) |
| Chart Type Toggle | Button group | `Burndown` / `Burnup` |
| Ideal Line | Dashed line on chart | Linear ideal trajectory from total points to zero (burndown) or zero to total (burnup) |
| Actual Line | Solid line on chart | Actual progress computed from sprint_stories completion |
| Scope Line (burnup only) | Dotted line | Total scope — shows scope changes over time |
| Velocity Chart | Bar chart | One bar per completed sprint; rolling 3-sprint average horizontal line |

---

### Definition of Done (DoD)

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

### Database Schema

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

## Item 12: Real-Time Collaboration & Activity Feed

**Sprint Goal:** The Team Workspace

**Objective:** Deliver the collaboration primitives that enterprise teams expect — threaded comments with @mentions, a project activity feed, and an in-app notification system with optional email digest — transforming StratFlow from a single-player planning tool into a team-ready platform.

---

### Week 1: Comments & Activity Log

**Focus:** Implement threaded comments on work items, user stories, and risks with @mention support and Markdown rendering. Build the project-level chronological activity feed with filtering and pagination.

#### Phase 1: Comment System (Days 1–3)

● **Task 12.1: Comment Model.** Create `Comment.php` model — `create()`, `findByCommentable($type, $id)`, `update()`, `softDelete()`. Polymorphic via `commentable_type` (hl_work_item, user_story, risk) + `commentable_id`. Soft delete replaces body with "This comment has been deleted."

● **Task 12.2: Activity Log Model.** Create `ActivityLog.php` model — `log($projectId, $userId, $action, $subjectType, $subjectId, $details)`, `findByProject($projectId, $filters, $page)`. Supports 12 activity types:
  ○ `created` — work_item, user_story, risk, sprint, baseline
  ○ `updated` — work_item, user_story, risk
  ○ `commented` — work_item, user_story, risk
  ○ `scored` — work_item (RICE/WSJF scores generated or updated)
  ○ `sized` — user_story (story points set or changed)
  ○ `allocated` — user_story (assigned to sprint)
  ○ `evaluated` — project (sounding board evaluation run)
  ○ `baseline_created` — project (strategic baseline snapshot taken)
  ○ `drift_detected` — project (drift alert raised)
  ○ `governance_approved` — governance_item (queue item approved)
  ○ `governance_rejected` — governance_item (queue item rejected)
  ○ `synced` — integration (external sync completed)

● **Task 12.3: Comment Controller.** Create `CommentController.php` — routes: `POST /app/comments`, `PUT /app/comments/{id}`, `DELETE /app/comments/{id}`. Parse @mentions from body text, resolve `@Full Name` references to user IDs, and create a notification for each mentioned user with `type = 'mentioned'`.

#### Phase 2: Comment UI & Activity Feed (Days 4–5)

● **Task 12.4: Comment Thread Template.** Build `templates/partials/comment-thread.php` — reusable comment component showing author name with coloured avatar initial, relative timestamp, Markdown-rendered body (supports bold, italic, code, lists, links), edit button (visible to author only), and delete button (visible to author and org_admin). Include @mention autocomplete dropdown triggered by `@` character. Include in work item, user story, and risk detail templates.

● **Task 12.5: Activity Log Instrumentation.** Instrument all existing controllers with `ActivityLog::log()` calls on create, update, delete, score, allocate, evaluate, baseline, and governance actions. Each log entry records project ID, acting user, action type, subject type, subject ID, and contextual details as JSON.

● **Task 12.6: Activity Feed Template.** Build `templates/activity-feed.php` — project activity page with chronological event list showing event icon (varies by action type), actor name, action description, clickable subject link, relative timestamp, and expandable JSON details for complex events. Filtering by activity type, by user, and by date range. Pagination: 50 events per page with infinite scroll. Also build a sidebar widget variant.

---

### Week 2: Notifications & Email Digest

**Focus:** Build the in-app notification system with bell icon, unread counts, and full notification page. Implement seven notification types and an optional daily email digest.

#### Phase 3: Notification Backend (Days 1–3)

● **Task 12.7: Notification Model.** Create `Notification.php` model — `create()`, `findByUser($userId, $filters)`, `markRead($id)`, `markAllRead($userId)`, `getUnreadCount($userId)`. Indexed on `(user_id, is_read, created_at)` for fast unread queries.

● **Task 12.8: Notification Service.** Create `NotificationService.php` — `notify($userId, $type, $title, $body, $link)`, `notifyMentioned($commentId, $mentionedUserIds)`, `notifyTeam($projectId, $type, ...)`. Seven notification types:
  ○ `mentioned` — "@mention in comment" → "{user} mentioned you in a comment on {item_title}"
  ○ `assigned` — "Owner field set to current user" → "You were assigned to {item_title}"
  ○ `review_needed` — "`requires_review` set to 1" → "{item_title} requires governance review"
  ○ `drift_alert` — "Drift alert created" → "Drift alert ({alert_type}) on {project_name}"
  ○ `governance_decision` — "Governance item approved or rejected" → "{item_title} was {approved/rejected} by {reviewer}"
  ○ `comment_reply` — "Comment added to a previously commented item" → "{user} also commented on {item_title}"
  ○ `evaluation_complete` — "Sounding board evaluation finished" → "Sounding board evaluation complete for {screen}"

● **Task 12.9: Notification Controller.** Create `NotificationController.php` — routes: `GET /app/notifications` (full page), `GET /app/notifications/unread-count` (JSON for bell badge AJAX), `POST /app/notifications/{id}/read`, `POST /app/notifications/mark-all-read`, `GET /app/notifications/preferences`, `POST /app/notifications/preferences`.

#### Phase 4: Notification UI & Email Digest (Days 4–5)

● **Task 12.10: Notification Bell Component.** Build `templates/partials/notification-bell.php` — bell icon with red circle badge showing unread count (hidden when zero), dropdown popover showing last 10 notifications with mark-all-read button, and "View All" link. Add to `templates/layouts/app.php` topbar. Fetch unread count via AJAX on page load.

● **Task 12.11: Notifications Page.** Build `templates/notifications.php` — full notification list with type icon, title, body preview, relative timestamp, and read/unread status. Filter by All/Unread only/by type. Bulk action: checkbox per row with "Mark Read" button.

● **Task 12.12: Email Digest.** Implement email digest — `NotificationService::sendDigest($userId)` method. User preferences stored in `users.notification_preferences` JSON column: email_digest toggle, digest_time (default 08:00 local), and included notification types. Schedule as daily task. Render HTML email from unread notifications with deep links to each notification's target item.

---

### 12.1 Comment Threads

The screen will display a threaded comment list below each work item, user story, and risk detail panel.

| Field | Type | Description |
|-------|------|-------------|
| Comment Body | Textarea with Markdown preview | Supports `**bold**`, `*italic*`, `` `code` ``, `- lists`, `[links](url)`. @mention autocomplete triggers on `@` character |
| Author | Text + avatar initial | `users.full_name`, first letter as coloured circle |
| Timestamp | Relative | "2 minutes ago", "Yesterday at 3:14 PM" |
| Edit Button | Icon button | Visible only to comment author |
| Delete Button | Icon button | Visible only to comment author and org_admin |
| Comment Count Badge | Number | Shown on item rows in list views — e.g. "3 comments" |

### 12.2 Notification Bell

The screen will display a bell icon in the app topbar with real-time unread count.

| Field | Type | Description |
|-------|------|-------------|
| Bell Icon | Icon with badge | Displays unread count as a red circle badge. Hidden when count is zero |
| Dropdown Panel | Popover | Shows last 10 notifications with mark-all-read button at top |
| Notification Row | Clickable row | Icon + title + relative timestamp. Unread rows have a blue dot indicator. Click navigates to linked item and marks as read |
| "View All" Link | Link | Navigates to full notification list page |

---

### Definition of Done (DoD)

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

### Database Schema

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

## Item 13: AI Strategy Coach — Conversational Interface

**Sprint Goal:** The Intelligent Advisor

**Objective:** Deliver a slide-out conversational chat panel accessible from every page that connects to Gemini with the full project data as context, enabling users to interrogate their strategy through natural language — the "wow factor" feature that drives premium tier adoption.

---

### Week 1: Context Engine & Chat Backend

**Focus:** Build the context injection architecture that assembles full project data into the AI system prompt, implement conversation persistence, and deliver the chat backend with token management and progressive truncation.

#### Phase 1: Context Assembly & AI Service (Days 1–3)

● **Task 13.1: Coach Service.** Create `CoachService.php` — `buildContext($projectId)` assembles the context payload from all project tables (see context injection architecture below). `chat($conversationId, $userMessage)` prepends system prompt + context + conversation history, calls `GeminiService::generate()`, stores both user message and assistant response. Context payload includes:
  ○ Document Summary — `documents.ai_summary` (the AI-generated strategic brief)
  ○ Strategy Diagram — `strategy_diagrams.mermaid_code` (raw Mermaid source)
  ○ OKR Data — `diagram_nodes` (all node OKR titles and descriptions)
  ○ Work Items — `hl_work_items` (titles, descriptions, scores, estimated sprints, priorities)
  ○ Risks — `risks` (likelihood, impact, mitigation status, linked items)
  ○ User Stories — `user_stories` (sizes, sprint assignments, blocked_by, status)
  ○ Sprint Status — `sprints` + `sprint_stories` (date ranges, capacities, allocated points, completion %)
  ○ Drift Alerts — `drift_alerts` (active alerts with type, severity, details)
  ○ Governance History — `governance_queue` (recent approved/rejected changes)
  ○ Evaluation History — `evaluation_results` (last 3 sounding board evaluations)
  ○ Conversation History — `coach_messages` (last 20 messages for conversational continuity)

● **Task 13.2: Coach System Prompt.** Create `CoachPrompt.php` in `src/Services/Prompts/` — `SYSTEM_PROMPT` constant defining the StratFlow Architect persona: a senior strategy and delivery advisor with complete access to the project's data. Guidelines: cite specific item names, never fabricate data, use bullet points, explain trade-offs, and note when comparisons are based on general best practices rather than industry-specific data. Pre-built prompt templates as named constants.

● **Task 13.3: Conversation & Message Models.** Create `CoachConversation.php` and `CoachMessage.php` models — standard CRUD. `CoachConversation::findByProject($projectId, $userId)` returns conversation list. `CoachMessage::findByConversation($conversationId, $limit)` returns message history ordered by `created_at`. Each message stores role (user/assistant), content, and approximate token count.

#### Phase 2: Controller & Token Management (Days 4–5)

● **Task 13.4: Coach Controller.** Create `CoachController.php` — routes:
  ○ `GET /app/project/{id}/coach/conversations` — list conversations
  ○ `POST /app/project/{id}/coach/conversations` — create new conversation
  ○ `POST /app/coach/conversations/{id}/message` — send message and receive AI response
  ○ `GET /app/coach/conversations/{id}/messages` — load message history

● **Task 13.5: Token Management.** Implement token counting and progressive truncation in `CoachService::buildContext()` — if total context exceeds 30,000 tokens, progressively truncate: evaluation history first, then governance history, then individual story descriptions, keeping titles and scores intact. Ensure no API errors on projects with large data sets.

---

### Week 2: Chat UI & Pre-Built Prompts

**Focus:** Build the slide-out chat panel, implement the real-time message interface with AJAX, deliver the six pre-built prompt templates, and add conversation management.

#### Phase 3: Panel UI & JavaScript (Days 1–3)

● **Task 13.6: Coach Panel Template.** Build `templates/partials/coach-panel.php` — slide-out panel (400px wide, right side, slide animation) with panel header ("Strategy Coach — {Project Name}" + close button), scrollable message list with alternating user (right-aligned, blue) and assistant (left-aligned, grey) bubbles, message input textarea with send button, collapsible pre-built prompt button row, conversation selector dropdown, and loading indicator (animated dots).

● **Task 13.7: Coach JavaScript.** Implement `public/assets/js/coach.js` — handles panel open/close animation, message send via AJAX POST, response rendering with auto-scroll to latest message, loading indicator during AI response, and pre-built prompt button clicks that pre-fill the message input.

● **Task 13.8: Floating Action Button.** Add floating action button to `templates/layouts/app.php` — visible on all authenticated pages, positioned bottom-right, displays chat icon with the label "Strategy Coach". Panel persists across page navigation within the same project via session state.

#### Phase 4: Styling & Conversation Management (Days 4–5)

● **Task 13.9: Panel Styling.** Style the coach panel — message bubbles with proper spacing, typing indicator animation, responsive layout (full-width on mobile), does not navigate away from current page, close on clicking outside panel.

● **Task 13.10: Conversation Management.** Add new conversation button, conversation list with timestamps and auto-generated titles from first message, and delete old conversations. Conversation selector dropdown switches between past conversations and loads correct message history.

---

### 13.1 Pre-Built Prompt Templates

The screen will display clickable buttons at the top of the coach panel. Each button pre-fills the message input.

| Button Label | Prompt Text |
|-------------|-------------|
| Assess Roadmap | "Assess the feasibility of my current roadmap given our team capacity and sprint velocity. Identify any sprints that are over-allocated." |
| Identify Risk Gaps | "Review my current risk model and identify risks that may be missing. Consider dependencies, resource constraints, and external factors." |
| Compare Velocity | "Compare my actual sprint velocity to the planned velocity. Are we on track? What is the trend?" |
| OKR Alignment Check | "Are all work items aligned with our strategic OKRs? Identify any items that may have drifted from the original objectives." |
| Scope Creep Analysis | "Analyse scope changes since the last baseline. Quantify the growth and assess whether it was justified." |
| Sprint Recommendation | "Based on current backlog, velocity, and dependencies, recommend the optimal allocation for the next sprint." |

---

### Definition of Done (DoD)

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

### Database Schema

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

## Item 14: Multi-Format Document Intelligence

**Sprint Goal:** The Universal Ingestor v2

**Objective:** Ensure that no matter how a strategy conversation was captured — recorded meeting, whiteboard photo, slide deck, wiki page — StratFlow can ingest it and produce a sprint-ready backlog, removing the single biggest top-of-funnel friction point.

---

### Week 1: Audio/Video Transcription & Image OCR

**Focus:** Implement OpenAI Whisper API integration for audio and video transcription with file chunking, build image OCR via Tesseract or Google Vision, and extend the upload screen to accept new file formats with processing status indicators.

#### Phase 1: Transcription & OCR Services (Days 1–3)

● **Task 14.1: Transcription Service.** Create `TranscriptionService.php` — `transcribe($filePath, $mimeType)` method. Implements OpenAI Whisper API via `multipart/form-data` POST to `https://api.openai.com/v1/audio/transcriptions`. Auto-detects language with manual override in config. Speaker diarisation includes speaker labels (Speaker 1, Speaker 2) when available. Segment timestamps included for reference. For files >25 MB: split into chunks using ffmpeg, transcribe each chunk, concatenate results.
  ○ Accepted formats: MP4, MP3, WAV, M4A
  ○ Maximum file size: 100 MB (chunked at 25 MB per Whisper API request)
  ○ MIME types: `audio/mpeg`, `audio/wav`, `audio/mp4`, `audio/x-m4a`, `video/mp4`

● **Task 14.2: OCR Service.** Create `OCRService.php` — `extract($filePath)` method. Primary provider: Tesseract via `exec('tesseract ...')`. Alternative: Google Vision API via HTTP POST. Pre-processing: image converted to greyscale, contrast-enhanced, and deskewed before OCR. Returns extracted text and confidence score. Low-confidence warning displayed to user if OCR quality <70%.
  ○ Accepted formats: PNG, JPG, JPEG
  ○ Maximum file size: 20 MB
  ○ MIME types: `image/png`, `image/jpeg`

● **Task 14.3: File Processor Extension.** Extend `FileProcessor.php` — add `processAudio()` and `processImage()` methods. Route new MIME types to the appropriate processor. Processing flow for audio: upload → `TranscriptionService::transcribe()` → store transcript in `documents.extracted_text` → store metadata in `documents.transcription_metadata` → user previews transcript → clicks "Continue" to proceed to summary generation. Processing flow for images: upload → `OCRService::extract()` → store text in `documents.extracted_text` → low-confidence warning if appropriate.

#### Phase 2: Upload UI & Configuration (Days 4–5)

● **Task 14.4: Transcription Metadata Column.** Add `transcription_metadata` JSON column to `documents` table. Stores: `duration_seconds`, `language`, `word_count`, `speaker_count` (audio/video) or `ocr_confidence` (image). Example: `{"duration_seconds": 1842, "language": "en", "word_count": 3200, "speaker_count": 4}`.

● **Task 14.5: Enhanced Upload Template.** Update `templates/upload.php` — extend drag-and-drop zone accepted types to include audio, video, image, and presentation formats. Add format indicator badges (`PDF`, `Audio`, `Video`, `Image`, `Presentation`, `URL`). Add processing status bar with status text ("Transcribing audio...", "Extracting text from image..."). Add transcription preview expandable panel with continue/retry buttons for audio/video uploads.

● **Task 14.6: API Key Configuration.** Add `OPENAI_API_KEY` and `GOOGLE_VISION_API_KEY` to `.env.example` and `src/Config/config.php`. Both keys are optional — Whisper requires OpenAI key, Google Vision is an alternative to Tesseract.

---

### Week 2: PPTX Support & URL Import

**Focus:** Implement PowerPoint text extraction via ZipArchive XML parsing, build URL import for Confluence, Notion, and Google Docs pages, and add asynchronous processing for long-running transcription jobs.

#### Phase 3: PPTX Extraction & URL Import (Days 1–3)

● **Task 14.7: PPTX Processor.** Extend `FileProcessor.php` — add `processPptx($filePath)` method. Use PHP `ZipArchive` to open PPTX file, iterate `ppt/slides/slide{N}.xml` files to extract text from `<a:t>` elements, iterate `ppt/notesSlides/notesSlide{N}.xml` for speaker notes. Concatenate slide text and notes by slide number, store in `documents.extracted_text`.
  ○ Maximum file size: 50 MB
  ○ MIME type: `application/vnd.openxmlformats-officedocument.presentationml.presentation`
  ○ Optional: embedded slide images can be extracted and OCR'd (toggle in config)

● **Task 14.8: URL Import Route.** Create URL import route `POST /app/project/{id}/upload/url` in `UploadController`. Detect platform from URL pattern, fetch content via the appropriate method, store as a new document.

● **Task 14.9: Platform-Specific URL Fetchers.** Implement platform-specific content extraction:
  ○ Google Docs — use export URL: `https://docs.google.com/document/d/{id}/export?format=txt`
  ○ Confluence — REST API: `GET /wiki/rest/api/content/{id}?expand=body.storage` (requires API token)
  ○ Notion — API: `GET /v1/blocks/{id}/children` (requires integration token)
  ○ Generic web page — HTTP GET + `strip_tags()` + remove scripts/styles

#### Phase 4: Platform Integration & Async Processing (Days 4–5)

● **Task 14.10: Confluence & Notion Integration.** Implement Confluence and Notion API integrations behind config flags (both require API tokens). Confluence uses basic auth with API token. Notion uses bearer token from integration setup.

● **Task 14.11: URL Import UI.** Update upload screen with URL import text field and "Import" button. Show detected platform badge ("Confluence", "Notion", "Google Docs", "Web Page") and text preview panel for user confirmation before saving as document.

● **Task 14.12: Processing Status & Async Support.** Add processing status indicators — progress bar and status text for long-running transcription jobs. For audio files >5 minutes duration, consider async processing with polling: submit job → return job ID → client polls for completion → redirect to preview on success.

---

### 14.1 Enhanced Upload Screen

**Route:** `GET /app/project/{id}/upload` (existing route, enhanced)

The screen will display the existing upload interface extended with new accepted formats and a URL import field.

| Field | Type | Description |
|-------|------|-------------|
| File Upload Zone | Drag-and-drop + file picker | Accepts: PDF, TXT, DOCX (existing) + MP4, MP3, WAV, M4A, PNG, JPG, JPEG, PPTX (new) |
| URL Import Field | Text input + "Import" button | Accepts: Confluence page URL, Notion page URL, Google Docs share link |
| Format Indicator | Badge | Shown on each uploaded file: `PDF`, `Audio`, `Video`, `Image`, `Presentation`, `URL` |
| Processing Status | Progress bar + status text | "Transcribing audio...", "Extracting text from image...", "Importing page..." |
| Transcription Preview | Expandable panel | For audio/video: shows transcript with speaker labels and timestamps before proceeding |

---

### Definition of Done (DoD)

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

### Database Schema

```sql
-- Migration: add transcription metadata to documents
ALTER TABLE documents ADD COLUMN transcription_metadata JSON NULL
    COMMENT '{"duration_seconds": 1842, "language": "en", "word_count": 3200, "speaker_count": 4, "ocr_confidence": 0.92}'
    AFTER ai_summary;

-- No additional schema changes required — mime_type is VARCHAR(100), sufficient for all new types
```

---

---

## Item 15: Meeting Platform Integration — Teams, Zoom & Google Meet

**Sprint Goal:** The Meeting-to-Strategy Bridge

**Objective:** Meet enterprise users where they already work — in video calls. Integrate StratFlow with Microsoft Teams, Zoom, and Google Meet to automatically capture meeting transcripts and AI-generated insights, converting strategy discussions into actionable backlogs without manual transcription or copy-paste.

---

### Week 1: Microsoft Teams Integration & VTT Parser

**Focus:** Implement Azure AD OAuth 2.0 with Microsoft Graph API for Teams transcript retrieval, build the shared VTT parser for speaker-attributed text extraction, implement the Copilot AI Insights polling pipeline, and create the meeting integration data model.

#### Phase 1: Teams Service & VTT Parser (Days 1–3)

● **Task 15.1: Meeting Integration Base Service.** Create `MeetingIntegrationService.php` — abstract base class defining the interface: `connect()`, `disconnect()`, `fetchRecentMeetings()`, `fetchTranscript($meetingId)`. Platform-specific services extend this class.

● **Task 15.2: Teams Service.** Create `TeamsService.php extends MeetingIntegrationService` — Azure AD OAuth 2.0 flow via `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize`. OAuth scopes: `OnlineMeetingTranscript.Read.All`, `OnlineMeeting.Read`, `User.Read`. Implements:
  ○ `authenticate()` — Azure AD OAuth flow, token stored encrypted in `meeting_integrations.config_json`
  ○ `listMeetings()` — fetch recent online meetings via Microsoft Graph
  ○ `getTranscript($meetingId, $transcriptId)` — `GET /me/onlineMeetings/{meetingId}/transcripts/{transcriptId}/content` returns VTT format
  ○ `getCopilotInsights($meetingId)` — `GET /copilot/users/{userId}/onlineMeetings/{meetingId}/aiInsights` returns structured meeting notes, action items, and viewpoint mentions

● **Task 15.3: VTT Parser Service.** Create `VTTParserService.php` — `parse($vttContent)` returns structured text and metadata array. Shared parser used by all three meeting platforms. Capabilities:
  ○ Extracts speaker name from VTT `<v>` tags
  ○ Preserves timestamps for reference (output format: `[00:00:05] Alice Smith: text`)
  ○ Strips formatting tags (`<b>`, `<i>`, etc.)
  ○ Concatenates consecutive segments from the same speaker
  ○ Returns both formatted text (for `extracted_text`) and structured array (for metadata)

#### Phase 2: Webhook & Data Model (Days 4–5)

● **Task 15.4: Teams Webhook Endpoint.** Implement `POST /webhook/meetings/teams` — receives Microsoft Graph change notifications for transcript availability via `communications/onlineMeetings/getAllTranscripts` subscription. Verifies notification via validation token handshake. On notification: extract `meetingId` and `transcriptId`, fetch transcript content, trigger import pipeline.

● **Task 15.5: Meeting Integration Models.** Create `MeetingIntegration.php` and `MeetingImport.php` models with standard CRUD operations. `MeetingIntegration` stores platform, OAuth config, auto-import flag, default project, and processing level. `MeetingImport` tracks per-meeting import status (pending/processing/completed/failed), meeting metadata, and linked document ID.

● **Task 15.6: Meeting Integration Settings Template.** Build `templates/admin/meeting-integrations.php` — connection cards for Teams, Zoom, and Google Meet, each showing status badge (Connected/Not Connected), "Connect" button, connected account email. Per-platform settings: auto-import toggle, default project dropdown, transcript processing level radio group (`Raw Transcript Only`, `Transcript + AI Summary`, `Transcript + AI Summary + Work Item Generation`).

---

### Week 2: Zoom, Google Meet & Meeting Browser UI

**Focus:** Implement Zoom and Google Meet transcript retrieval, build the meeting browser with selective import, deliver the automatic import pipeline, and implement Copilot AI Insights polling with exponential backoff.

#### Phase 3: Zoom & Google Meet Services (Days 1–3)

● **Task 15.7: Zoom Service.** Create `ZoomService.php extends MeetingIntegrationService` — Server-to-Server OAuth app. Store Account ID, Client ID, Client Secret in `meeting_integrations.config_json`. Scopes: `meeting:read:admin`, `recording:read:admin`. Subscribe to `recording.completed` webhook event. Fetch transcript via `GET /meetings/{meetingId}/transcript` (WebVTT format). Parse with shared VTT parser. Requires Zoom paid plan (Pro/Business/Enterprise) with cloud recording and audio transcript enabled.

● **Task 15.8: Google Meet Service.** Create `GoogleMeetService.php extends MeetingIntegrationService` — Google OAuth 2.0 with scope `https://www.googleapis.com/auth/meetings.space.readonly`. Transcript retrieval via `GET /v2/{parent=conferenceRecords/*}/transcripts` — each `TranscriptEntry` includes `participant` (speaker), `text`, `startOffset`, `endOffset`. Fallback: Google Meet saves transcripts as Google Docs in organiser's Drive — fetch via Google Drive API if Meet API access is limited.

● **Task 15.9: Meeting Browser Template.** Build `templates/meetings.php` — meeting browser at `GET /app/project/{id}/meetings`. Platform filter tab bar (All, Teams, Zoom, Google Meet). Meeting list table with columns: platform icon, meeting title, date/time, duration, participants, import status badge (Not Imported/Imported/Processing). Per-row "Import to Project" button. Bulk import via checkbox selection and "Import Selected" button.

#### Phase 4: Import Pipeline & Copilot Insights (Days 4–5)

● **Task 15.10: Manual Import Flow.** Implement import flow: user clicks "Import" → system fetches transcript from platform API (if not cached) → VTT transcript parsed into speaker-attributed plain text → if Copilot AI Insights available (Teams), append as structured summary section → create new `documents` row with transcript as `extracted_text` and meeting metadata in `transcription_metadata` → redirect user to document summary step. Meeting metadata stored:
  ○ `source` — platform identifier (teams/zoom/google_meet)
  ○ `meeting_id` — platform-specific meeting identifier
  ○ `meeting_title` — meeting subject line
  ○ `meeting_date` — ISO 8601 datetime
  ○ `duration_minutes` — meeting length
  ○ `participant_count` and `participants` array
  ○ `has_copilot_insights` — boolean
  ○ `action_items` — array of {text, owner} objects (from Copilot)

● **Task 15.11: Automatic Import Pipeline.** Implement auto-import pipeline for platforms with auto-import enabled:
  ○ Webhook receives meeting-end notification
  ○ System waits for transcript availability (immediate for Zoom, up to 15 minutes for Teams, variable for Meet)
  ○ Transcript fetched and parsed via VTT parser
  ○ New document created in configured default project
  ○ If processing level is "Transcript + AI Summary", summary auto-generated
  ○ If "Transcript + AI Summary + Work Item Generation", full pipeline runs: summary → diagram → work items
  ○ Notification created for project owner: "Meeting transcript imported: {meeting_title}"
  ○ Activity log entry created: `action = 'meeting_imported'`

● **Task 15.12: Copilot AI Insights Polling.** Implement Copilot AI Insights polling for Teams with exponential backoff: 5 min → 15 min → 30 min → 1 hr → 2 hr → 4 hr. Copilot insights take up to 4 hours after meeting ends. If insights become available, store alongside raw transcript: `meetingNotes` (structured with titles, text, subpoints), `actionItems` (with owners), `viewpoint` mentions. Append action items to imported document metadata.

---

### 15.1 Meeting Integration Settings

**Route:** `GET /app/admin/integrations/meetings`

The screen will display central configuration for connecting meeting platforms, accessible within the Integration Hub as a dedicated sub-section.

| Field | Type | Description |
|-------|------|-------------|
| Microsoft Teams | Connection card | Status badge (Connected/Not Connected), "Connect" button, connected account email |
| Zoom | Connection card | Status badge, "Connect" button, connected account email |
| Google Meet | Connection card | Status badge, "Connect" button, connected account email |
| Auto-Import | Toggle per platform | When enabled, new meeting transcripts are automatically imported on meeting end |
| Default Project | Dropdown per platform | Which project to import transcripts into by default |
| Transcript Processing | Radio group | `Raw Transcript Only`, `Transcript + AI Summary`, `Transcript + AI Summary + Work Item Generation` |

### 15.2 Meeting Browser

**Route:** `GET /app/project/{id}/meetings`

The screen will display recent meetings from all connected platforms with selective import.

| Field | Type | Description |
|-------|------|-------------|
| Platform Filter | Tab bar | `All`, `Teams`, `Zoom`, `Google Meet` |
| Meeting List | Table | Platform icon, Meeting Title, Date/Time, Duration, Participants, Import Status |
| Import Status | Badge | `Not Imported`, `Imported`, `Processing` |
| Import Button | Button per row | "Import to Project" — triggers transcript fetch, parse, and document creation |
| Bulk Import | Checkbox selection + button | Select multiple meetings and import all at once |

---

### Definition of Done (DoD)

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

### Database Schema

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

### Feature Priority & Effort Matrix

| Item | Feature | Priority | Effort (Sprints) | Business Value | Risk |
|------|---------|----------|-------------------|----------------|------|
| 10 | Integration Hub — Jira & Azure DevOps Sync | Critical | 2 weeks | Removes #1 enterprise disqualification | Medium (third-party API dependency) |
| 11 | Executive Strategy Dashboard & Portfolio View | High | 2 weeks | Sells to the budget holder (VP/CPO/CTO) | Low (internal data only) |
| 12 | Real-Time Collaboration & Activity Feed | High | 2 weeks | Multi-user requirement for enterprise | Low (standard patterns) |
| 13 | AI Strategy Coach — Conversational Interface | Medium | 2 weeks | Demo "wow factor" for premium tier | Medium (LLM token costs, context limits) |
| 14 | Multi-Format Document Intelligence | Medium | 2 weeks | Reduces top-of-funnel bounce rate | Medium (third-party API costs) |
| 15 | Meeting Platform Integration — Teams, Zoom & Google Meet | High | 2 weeks | Enterprise workflow alignment | High (three platform APIs, webhook reliability) |

### Implementation Sequencing

| Phase | Timeline | Features | Rationale |
|-------|----------|----------|-----------|
| Phase 1 (Q2) | Weeks 1–4 | Item 10 (Jira/ADO Sync) + Item 11 (Executive Dashboard) | Removes the #1 enterprise objection and creates the executive-facing surface that justifies the subscription. These two features are standalone with no dependencies. |
| Phase 2 (Q3) | Weeks 5–9 | Item 12 (Collaboration) + Item 13 (AI Strategy Coach) | Transforms StratFlow from single-player to team-ready. Item 13 depends on Item 12 — the activity feed provides richer context for the AI coach. |
| Phase 3 (Q4) | Weeks 10–13 | Item 14 (Multi-Format Docs) + Item 15 (Meeting Integration) | Widens the upload funnel and connects StratFlow to the enterprise meeting workflow. Item 15 depends on Item 14 — shares `TranscriptionService` and VTT parser infrastructure. |

### Feature Dependency Map

```
Item 10 (Jira/ADO Sync) ──────── standalone, highest priority
Item 11 (Executive Dashboard) ── standalone, builds on existing sprint/governance data
Item 12 (Collaboration) ──────── standalone, enhances all existing screens
Item 13 (AI Strategy Coach) ──── depends on Item 12 (activity feed provides richer context)
Item 14 (Multi-Format Docs) ──── standalone, extends upload pipeline
Item 15 (Meeting Integration) ── depends on Item 14 (shares TranscriptionService + VTT parser)
```

### Revenue Impact Projection

| Feature | Enterprise Conversion Impact | Retention Impact | ARPU Impact |
|---------|------------------------------|-----------------|-------------|
| Jira/ADO Sync (Item 10) | +60–80% (removes #1 disqualification) | +30% (embedded in workflow) | Neutral |
| Executive Dashboard (Item 11) | +20% (sells to budget holder) | +40% (daily usage habit) | +15% (portfolio tier) |
| Collaboration (Item 12) | +15% (multi-user requirement) | +25% (engagement loops) | +30% (more seats) |
| AI Strategy Coach (Item 13) | +10% (demo wow factor) | +15% (discovery value) | +20% (premium feature) |
| Multi-Format Docs (Item 14) | +5% (reduces bounce at step 1) | +5% (convenience) | Neutral |
| Meeting Integration (Item 15) | +15% (enterprise workflow fit) | +20% (automated pipeline) | +10% (premium feature) |

### Total New Database Tables

| Table | Item | Purpose |
|-------|------|---------|
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

### Total Schema Migrations

| Migration | Item | Change |
|-----------|------|--------|
| `ALTER TABLE documents ADD COLUMN transcription_metadata` | 14 | JSON column for audio/video/image processing metadata |
| `ALTER TABLE user_stories ADD COLUMN status` | 11 | ENUM for burndown chart calculations |
| `ALTER TABLE user_stories ADD COLUMN completed_at` | 11 | Timestamp for velocity tracking |
| `ALTER TABLE users ADD COLUMN notification_preferences` | 12 | JSON column for email digest settings |

### Competitive Position After V3

With all six features deployed, StratFlow will be the only platform that:

1. **Ingests any strategy artifact** — documents, recordings, images, presentations, meeting transcripts, wiki pages
2. **Generates a complete delivery pipeline** — from raw input through OKRs, work items, risks, user stories, and sprint allocation
3. **Maintains a live governance loop** — drift detection that spans both StratFlow and external tools (Jira, Azure DevOps)
4. **Provides portfolio-level visibility** — health scores, velocity trends, OKR progress, and scope change history across all projects
5. **Enables team collaboration** — comments, activity feeds, notifications, and AI-assisted strategic conversations
6. **Connects to the meeting workflow** — strategy workshops in Teams, Zoom, or Meet flow directly into the planning pipeline

No competitor covers more than two of these six capabilities. Jira Align has portfolio views and Jira integration but starts from manual structured input. Productboard has prioritisation and roadmapping but no governance loop. DriftlineAI monitors strategy drift but produces no delivery artifacts. StratFlow V3 is the complete strategy operating system.
