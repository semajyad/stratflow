# StratFlow: 5 High-Value Feature Proposals

**Date**: 2026-04-08
**Author**: Strategic analysis based on competitive research, product positioning, and enterprise buyer requirements

---

## Product Context

StratFlow occupies a genuinely empty market position: **the only tool that takes raw strategy artifacts (documents, meetings) and produces sprint-ready engineering work through an AI-automated pipeline**. The closest competitors either start from structured input (Productboard, Aha!, Jira Align), stop at strategy monitoring (DriftlineAI, Carbon14), or handle only one piece of the pipeline (Posteros AI for sounding boards, airfocus for prioritisation).

The competitive research reveals that enterprise tools in this space charge **$39-$149/user/month** for roadmapping, and **$77,400+/year** for full strategy-to-execution platforms. PMI reports **50% of projects fail**, directly attributable to the strategy-execution gap that StratFlow uniquely solves.

**Key insight**: StratFlow's true differentiation isn't AI-generated work items — it's the **closed-loop governance system** that keeps execution aligned with strategy over months. Features should deepen this moat.

---

## Feature 1: Bidirectional Jira/Azure DevOps Sync (HIGHEST VALUE)

### Priority: #1 — Critical for Enterprise Adoption

### The Problem
StratFlow generates beautiful sprint-ready backlogs, but enterprises already live in Jira or Azure DevOps. Currently, users export CSV/JSON and manually import. This creates a **dead-end** — once work moves to Jira, StratFlow loses visibility. The Strategic Drift Engine can't detect drift if changes happen in Jira, not StratFlow. The governance loop breaks.

### Why This Is #1
- **Every enterprise buyer will ask**: "Does it integrate with Jira?" This is the single most common disqualification criterion for enterprise tools.
- **Enables the Drift Engine's full potential**: bidirectional sync means StratFlow sees when Jira stories change scope, when new stories are added outside StratFlow, when sprint velocity shifts.
- **Network effect**: once connected to Jira, StratFlow becomes embedded in the daily workflow rather than being a "planning phase only" tool.
- **Competitive parity**: Planview, Jira Align, Aha!, Productboard, WorkBoard — all have deep Jira integration. Without it, StratFlow can't sell to enterprises.

### What to Build

**Phase A: One-Way Push (2 weeks)**
- Push High Level work items as Jira Epics with OKR data in description
- Push user stories as Jira Stories linked to parent Epics
- Map StratFlow fields → Jira fields (priority, story points, sprint, assignee)
- OAuth 2.0 authentication with Jira Cloud
- Configuration UI: select Jira project, map fields, choose what to sync

**Phase B: Bidirectional Sync (3 weeks)**
- Webhook listener for Jira change events (story updated, created, deleted, moved)
- When a Jira story's size changes → update StratFlow → trigger drift detection
- When a new story is added in Jira → create in StratFlow → run AI alignment check
- When sprint status changes → update StratFlow sprint allocation
- Conflict resolution: StratFlow shows "External Change Detected" with accept/reject

**Phase C: Azure DevOps Support (2 weeks)**
- Same pattern as Jira but using Azure DevOps REST API
- Map to Work Items, Features, User Stories

### Database Changes
```sql
CREATE TABLE integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    provider ENUM('jira','azure_devops','github') NOT NULL,
    config_json JSON NOT NULL,  -- OAuth tokens, project mappings, field maps
    status ENUM('active','paused','error') NOT NULL DEFAULT 'active',
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
);

CREATE TABLE sync_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL,
    local_type ENUM('hl_work_item','user_story','sprint') NOT NULL,
    local_id INT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    last_synced_at DATETIME NULL,
    FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mapping (integration_id, local_type, local_id)
);
```

### Files to Create/Modify
- `src/Services/JiraService.php` — Jira Cloud REST API client (OAuth 2.0)
- `src/Services/AzureDevOpsService.php` — Azure DevOps REST API client
- `src/Controllers/IntegrationController.php` — CRUD for integrations, sync triggers
- `src/Models/Integration.php`, `src/Models/SyncMapping.php`
- `templates/admin/integrations.php` — Configuration UI
- `src/Config/routes.php` — Integration routes under `/app/admin/integrations`

### Verification
- Connect to a test Jira Cloud project
- Push 5 work items as Epics, verify they appear in Jira with correct fields
- Change a story in Jira, verify StratFlow detects the change and runs drift check

---

## Feature 2: Executive Strategy Dashboard with Portfolio View (HIGH VALUE)

### Priority: #2 — Differentiator for C-Suite Buy-In

### The Problem
StratFlow's current UI is project-centric — you pick a project and work through the pipeline. But enterprise buyers need a **portfolio view**: multiple projects, aggregated metrics, trend lines, and at-a-glance health. The person who signs the contract (VP/CPO) isn't the person using the tool daily (PM/Scrum Master). The executive needs a dashboard that justifies the subscription.

### Why This Is #2
- **Sells to the budget holder**: The person who approves $100K+ annually wants to see ROI, not user stories.
- **No competitor does this from strategy documents**: Jira Align has portfolio views but from manual data. StratFlow can show "strategy intent vs. execution reality" automatically.
- **Retention driver**: Dashboards create daily visit habits. Without them, StratFlow is opened quarterly during planning then forgotten.
- **Enables upsell**: "Upgrade to see portfolio analytics across all teams" is a natural premium tier.

### What to Build

**Portfolio Dashboard** (`/app/dashboard`)
- **Strategy Health Score**: Composite metric (0-100) based on:
  - % of OKRs with linked work items (coverage)
  - Average drift from baseline (alignment)
  - Active unresolved risks (risk exposure)
  - Sprint velocity trend (execution momentum)
- **Project Cards**: Each project shows: name, phase (which workflow step they're on), health score, last activity
- **Drift Alerts Summary**: Count of active alerts across all projects, grouped by severity
- **Timeline View**: Gantt-style view of all projects' sprints on a shared timeline
- **Team Utilisation**: Aggregate capacity vs. allocation across all sprints

**Project Analytics** (within each project)
- **Burndown/Burnup Chart**: Story points completed vs. remaining per sprint
- **Velocity Chart**: Story points delivered per sprint over time
- **Scope Change Log**: Visual timeline of when/why scope changed (from governance queue)
- **OKR Progress**: Which OKRs are on track, at risk, or behind

### Database Changes
```sql
-- No new tables needed — analytics are computed from existing data
-- Add a materialised cache for dashboard performance:
CREATE TABLE dashboard_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    cache_key VARCHAR(100) NOT NULL,
    data_json JSON NOT NULL,
    computed_at DATETIME NOT NULL,
    UNIQUE KEY uq_cache (org_id, cache_key),
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
);
```

### Files to Create/Modify
- `src/Controllers/DashboardController.php` — Portfolio + project analytics
- `src/Services/AnalyticsService.php` — Health score calculation, burndown data, velocity
- `templates/dashboard.php` — Portfolio view with chart containers
- `templates/analytics.php` — Per-project analytics
- `public/assets/js/charts.js` — Chart rendering (use Chart.js CDN)

### Verification
- Dashboard shows all projects with health scores
- Burndown chart renders correctly for a project with sprint data
- Health score changes when drift alerts are created

---

## Feature 3: Real-Time Collaboration & Activity Feed (HIGH VALUE)

### Priority: #3 — Enterprise Workflow Enabler

### The Problem
StratFlow is currently single-player — one person uploads, generates, and manages. Enterprise strategy involves **5-20 stakeholders** who need to comment, approve, discuss, and be notified. Without collaboration, the tool can't replace the "strategy meeting → email chain → Slack thread → Jira ticket" workflow that it aims to eliminate.

### Why This Is #3
- **Multi-user is table stakes for enterprise**: No enterprise tool survives without collaboration. Every competitor has comments, mentions, and notifications.
- **Increases daily active usage**: Comments and activity feeds create engagement loops.
- **Supports governance workflows**: "This work item requires review" should ping the reviewer, not wait for them to check.
- **Enables the consultancy tier**: The "10 hours facilitation" upsell is more valuable when consultants can comment directly in the tool rather than over email.

### What to Build

**Activity Feed** (sidebar widget or dedicated page)
- Chronological feed of all project activity: items created, edited, scored, approved
- Filter by: project, user, activity type
- Shows who did what, when

**Comments on Work Items & Stories**
- Comment thread on each High Level work item and user story
- @mention users (triggers notification)
- Markdown support for formatting
- Comment count badge on item rows

**In-App Notifications**
- Bell icon in topbar with unread count
- Notification types: mentioned in comment, work item assigned, governance review needed, drift alert
- Mark as read/unread
- Optional email digest (daily summary)

### Database Changes
```sql
CREATE TABLE comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    commentable_type ENUM('hl_work_item','user_story','risk') NOT NULL,
    commentable_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_commentable (commentable_type, commentable_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link VARCHAR(500) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    subject_type VARCHAR(50) NULL,
    subject_id INT UNSIGNED NULL,
    details_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_project_time (project_id, created_at),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
```

### Files to Create/Modify
- `src/Controllers/CommentController.php` — CRUD for comments
- `src/Controllers/NotificationController.php` — List, mark read, preferences
- `src/Services/NotificationService.php` — Create notifications, parse @mentions, send email digests
- `src/Models/Comment.php`, `src/Models/Notification.php`, `src/Models/ActivityLog.php`
- `templates/partials/comment-thread.php` — Reusable comment component
- `templates/partials/notification-bell.php` — Topbar notification indicator
- Modify `templates/layouts/app.php` — Add notification bell to topbar

### Verification
- Add a comment on a work item, verify it appears
- @mention a user in a comment, verify notification is created
- Check notification bell shows unread count

---

## Feature 4: AI Strategy Coach / Conversational Interface (MEDIUM-HIGH VALUE)

### Priority: #4 — Differentiation Through Intelligence

### The Problem
StratFlow's AI currently runs at predefined points: summarise → diagram → work items → scores. Users can't **ask questions** about their strategy. "Is my roadmap realistic given our team size?" "What risks am I missing?" "How does this compare to industry benchmarks?" These questions require a conversational AI layer that understands the full project context.

### Why This Is #4
- **Unique differentiator**: No competitor offers a conversational strategy advisor with full project context. WorkBoard has "Chief of Staff" and "Leadership Coach" AI agents — this is the same pattern applied to StratFlow's richer data.
- **Increases perceived value**: Users discover insights they wouldn't find through the structured pipeline. This creates "aha moments" that drive word-of-mouth.
- **Natural evolution of Sounding Boards**: Instead of fire-and-forget persona evaluation, this is an ongoing dialogue.
- **Lower priority than integration/dashboard**: Enterprises need Jira sync and executive dashboards before they need a chatbot. But once those are in place, the coach becomes the "magic" that justifies premium pricing.

### What to Build

**Strategy Coach Panel** (slide-out panel accessible from any page)
- Chat interface: user types questions, AI responds with context-aware answers
- The AI has access to: project documents, diagrams, work items, risks, stories, sprint data, drift alerts, evaluation history
- Pre-built prompts: "Assess my roadmap feasibility", "Identify gaps in my risk model", "Suggest OKRs for this work item", "Compare my sprint velocity to plan"
- Conversation history persisted per project

**Context Injection**
- When user asks a question, the system builds a context prompt including:
  - Document summary
  - Current work items with scores
  - Active risks
  - Sprint allocation status
  - Drift alerts
  - Recent governance decisions
- This gives the AI full strategic awareness

### Database Changes
```sql
CREATE TABLE coach_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE coach_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES coach_conversations(id) ON DELETE CASCADE
);
```

### Files to Create/Modify
- `src/Controllers/CoachController.php` — Chat endpoint, context builder
- `src/Services/CoachService.php` — Builds context from project data, manages conversation
- `src/Services/Prompts/CoachPrompt.php` — System prompt for strategy advisor role
- `templates/partials/coach-panel.php` — Slide-out chat UI
- `public/assets/js/coach.js` — Chat interface JS (streaming responses if possible)

### Verification
- Open coach panel, ask "What are the biggest risks in my project?"
- AI responds referencing actual risks from the risk model
- Ask "Is my sprint allocation realistic?" — AI checks capacity vs. story points

---

## Feature 5: Multi-Format Document Intelligence (MEDIUM VALUE)

### Priority: #5 — Widens the Funnel

### The Problem
StratFlow currently accepts TXT, PDF, and DOCX. But enterprise strategy comes from **everywhere**: recorded Zoom meetings (MP4), whiteboard photos (PNG/JPG), PowerPoint decks (PPTX), Google Docs, Confluence pages, Notion exports. Every format not supported is a user who bounces at step 1.

### Why This Is #5
- **Removes friction at the top of funnel**: The upload step is where users first experience value. If they can't upload their actual strategy artifact, they never see the AI magic.
- **Differentiation**: No competitor ingests video/audio strategy meetings and converts them to backlogs.
- **Lower priority than integration/dashboard/collab**: These are core enterprise requirements. Document intelligence is an enhancement — users can always paste text as a workaround.
- **Technology is mature**: Whisper for transcription, Google Vision for OCR — the building blocks exist.

### What to Build

**Phase A: Audio/Video Transcription (2 weeks)**
- Accept MP4, MP3, WAV, M4A uploads
- Transcribe via OpenAI Whisper API or Google Speech-to-Text
- Store transcript as extracted_text, proceed with normal pipeline
- Show transcript with timestamps for reference

**Phase B: Image OCR (1 week)**
- Accept PNG, JPG, JPEG uploads (whiteboard photos, screenshots)
- OCR via Tesseract (local) or Google Vision API
- Extract text from images, proceed with pipeline

**Phase C: Presentation Support (1 week)**
- Accept PPTX files
- Extract text from all slides using PHP ZipArchive (same pattern as DOCX)
- Extract speaker notes
- Optionally extract images from slides and OCR them

**Phase D: URL Import (1 week)**
- Accept a URL (Confluence page, Notion page, Google Doc)
- Fetch page content, extract text
- Handles common formats: HTML pages, Google Docs (via export API)

### Database Changes
```sql
-- Extend allowed types in config, no schema changes needed
-- Add transcription metadata:
ALTER TABLE documents ADD COLUMN transcription_metadata JSON NULL;
```

### Files to Create/Modify
- `src/Services/TranscriptionService.php` — Whisper/Speech-to-Text API client
- `src/Services/OCRService.php` — Tesseract or Google Vision client
- `src/Services/FileProcessor.php` — Extend with PPTX, image, audio handlers
- `src/Config/config.php` — Add transcription API keys
- `templates/upload.php` — Update accepted formats list

### Verification
- Upload an MP4 meeting recording, verify transcript is generated
- Upload a whiteboard photo, verify OCR text is extracted
- Upload a PPTX deck, verify slide text is extracted

---

## Priority Summary

| Rank | Feature | Value Driver | Effort | Why This Order |
|------|---------|-------------|--------|----------------|
| **#1** | Jira/Azure DevOps Sync | Enterprise adoption gate | 7 weeks | Without this, enterprises won't buy. Period. It also enables the Drift Engine's full potential by closing the feedback loop. |
| **#2** | Executive Dashboard | C-suite buy-in + retention | 4 weeks | The person signing the contract needs to see portfolio-level ROI. This justifies premium pricing and creates daily usage habits. |
| **#3** | Collaboration & Activity | Multi-user enablement | 5 weeks | Enterprise = teams, not individuals. Comments, notifications, and activity feeds are table stakes for any tool used by more than 2 people. |
| **#4** | AI Strategy Coach | Premium differentiation | 3 weeks | The "wow factor" that makes StratFlow feel like having a strategy consultant on demand. Builds on existing Gemini integration. Lower priority because it's a nice-to-have, not a must-have. |
| **#5** | Multi-Format Intelligence | Funnel expansion | 5 weeks | Removes upload friction and enables unique use cases (video meetings → backlogs). But users can paste text as a workaround, so it's less urgent. |

### Strategic Sequencing

```
Phase 1 (Q2): Jira Sync + Executive Dashboard
  → Enables enterprise sales, justifies pricing, creates daily usage
  
Phase 2 (Q3): Collaboration + AI Coach
  → Deepens engagement, increases seat count, enables consultancy tier
  
Phase 3 (Q4): Multi-Format Intelligence
  → Widens funnel, creates marketing differentiation ("upload your Zoom recording")
```

### Revenue Impact Estimates

| Feature | Impact on Enterprise Conversion | Impact on Retention | Impact on ARPU |
|---------|-------------------------------|--------------------:|---------------|
| Jira Sync | **+60-80%** (removes #1 objection) | +30% (embedded in workflow) | Neutral |
| Dashboard | +20% (sells to budget holder) | **+40%** (daily usage habit) | +15% (portfolio tier) |
| Collaboration | +15% (multi-user requirement) | +25% (engagement loops) | **+30%** (more seats) |
| AI Coach | +10% (demo wow factor) | +15% (discovery value) | +20% (premium feature) |
| Multi-Format | +5% (reduces bounce at step 1) | +5% (convenience) | Neutral |

---

## Implementation Readiness

All five features build on StratFlow's existing architecture:
- **Controllers/Models/Services** pattern is established and scalable
- **GeminiService** is already abstracted — Coach and Multi-Format both extend it
- **Audit logging** is in place — all new features automatically get security coverage
- **Route/middleware system** supports new controllers without framework changes
- **Database migration pattern** (`database/migrations/`) handles schema evolution

The app is architecturally ready for all five features. The question is purely about **sequencing for maximum business impact**.
