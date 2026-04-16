# Architecture

## System Overview

```
Browser
  │
  │ HTTP (port 8890 in Docker / 443 in production)
  ▼
Nginx
  │ FastCGI (PHP-FPM socket)
  ▼
PHP-FPM
  │
  ▼
public/index.php          ← Single entry point
  │
  ├── Bootstrap: .env load, session start, DB connect
  ├── Router::dispatch()
  │     ├── Middleware stack (auth, csrf, workflow_write, ...)
  │     └── Controller::method()
  │           ├── Model layer (PDO queries)
  │           ├── GeminiService (HTTP → Gemini API)
  │           └── StripeService (Stripe SDK)
  │
  └── Response (HTML template render / JSON / redirect)
        │
        ├── templates/layouts/app.php    (authenticated views)
        ├── templates/layouts/public.php (public views)
        └── templates/*.php              (page-level templates)

External services:
  MySQL 8.4    ← all persistent data
  Gemini API   ← AI summary, diagram, work item generation (fallback: OpenAI GPT-4o-mini)
  Stripe API   ← checkout session creation, webhook events
  Jira API     ← issue sync (OAuth 2.0)
  GitHub API   ← repository integration (GitHub App)
  GitLab API   ← repository integration (webhook)
  Xero API     ← invoice sync (OAuth 2.0)
```

---

## Request Lifecycle

1. **Nginx** receives the HTTP request. All requests are proxied to PHP-FPM via FastCGI, except static assets served directly from `public/`.

2. **`public/index.php`** is the single entry point. It:
   - Loads `.env` via `vlucas/phpdotenv`
   - Starts the PHP session
   - Instantiates `Database`, `Session`, `Auth`, `CSRF`, `Request`, `Response`
   - Loads the route definitions from `src/Config/routes.php`
   - Calls `Router::dispatch()`

3. **Router** matches the request method and URI against registered routes. For a matching route:
   - Runs each listed middleware in order
   - Resolves the controller class from `src/Controllers/`
   - Calls the specified method, passing `Request` and `Response`

4. **Middleware** runs before the controller. See [API.md](API.md) for the full middleware table.

5. **Controller** contains the HTTP handler logic:
   - Reads input from the `Request` object
   - Calls one or more Model methods (which run PDO queries)
   - Optionally calls service classes (`GeminiService`, `StripeService`, etc.)
   - Returns a `Response` (render template / redirect / JSON)

6. **Response** is returned to Nginx → Browser.

---

## Directory Structure

```
stratflow/
├── public/
│   ├── index.php               Single entry point
│   └── assets/                 JS, CSS, images
├── src/
│   ├── Config/
│   │   ├── config.php          App-level constants loaded from .env
│   │   └── routes.php          Route definitions
│   ├── Controllers/            One controller per feature area
│   │   ├── AuthController.php              Login, logout, password reset, TOTP MFA
│   │   ├── CheckoutController.php          Stripe Checkout session creation
│   │   ├── DiagramController.php           Strategy diagram generation, OKR management
│   │   ├── HomeController.php              Dashboard, project CRUD
│   │   ├── HealthController.php            /healthz endpoint
│   │   ├── PricingController.php           Public landing/pricing page
│   │   ├── PrioritisationController.php    RICE/WSJF scoring
│   │   ├── RiskController.php              Risk identification and mitigation
│   │   ├── SprintController.php            Sprint creation and AI allocation
│   │   ├── SuccessController.php           Post-Stripe-payment success page
│   │   ├── UploadController.php            Document upload and AI summary
│   │   ├── UserStoryController.php         User story decomposition, sizing, quality
│   │   ├── WebhookController.php           Stripe webhook receiver
│   │   ├── WorkItemController.php          High-level work item CRUD, AI generation, quality
│   │   ├── KrController.php               Key results CRUD
│   │   ├── AdminController.php            User/team management, settings, audit logs, billing
│   │   ├── SoundingBoardController.php    AI persona evaluation panels; seeds defaults on first use
│   │   ├── SuperadminController.php       Cross-org management, persona defaults, role assignment
│   │   ├── DriftController.php            Governance dashboard, baseline, drift detection
│   │   ├── ExecutiveController.php        Org-wide and per-project executive dashboard
│   │   ├── TraceabilityController.php     Story-to-OKR traceability matrix
│   │   ├── IntegrationController.php      Jira OAuth, sync, configuration, webhooks
│   │   ├── GitWebhookController.php       GitHub/GitLab push and PR webhook receiver
│   │   ├── GitHubAppController.php        GitHub App installation flow
│   │   ├── GitLinkController.php          Story-to-commit/PR link management
│   │   ├── GitIntegrationController.php   Generic git provider connect/disconnect
│   │   ├── ProjectGitHubController.php    Per-project GitHub repository settings
│   │   ├── StoryQualityController.php     Org-configurable quality scoring rules
│   │   ├── XeroController.php             Xero OAuth and invoice sync
│   │   ├── AccessTokenController.php      Personal access tokens (PATs) for API/MCP
│   │   ├── UserDataExportController.php   GDPR data export
│   │   ├── ApiStoriesController.php       REST API v1: stories, assignments, status
│   │   └── ApiProjectsController.php      REST API v1: project listing
│   ├── Core/
│   │   ├── Auth.php            Session-based authentication
│   │   ├── CSRF.php            Token generation and validation
│   │   ├── Database.php        PDO singleton wrapper
│   │   ├── Request.php         Input abstraction (GET, POST, FILES, headers)
│   │   ├── Response.php        Render, redirect, JSON helpers
│   │   ├── Router.php          Route registration and dispatch
│   │   └── Session.php         Session wrapper
│   ├── Middleware/
│   │   ├── AuthMiddleware.php              Session presence check
│   │   ├── CSRFMiddleware.php              Form token validation
│   │   ├── AdminMiddleware.php             org_admin or superadmin role required
│   │   ├── SuperadminMiddleware.php        superadmin role required
│   │   ├── WorkflowWriteMiddleware.php     Project write-access check (not viewer-only)
│   │   ├── BillingMiddleware.php           has_billing_access flag or admin role
│   │   ├── ExecutiveMiddleware.php         has_executive_access flag or superadmin
│   │   ├── ProjectCreateMiddleware.php     is_project_admin, org_admin, or superadmin
│   │   ├── ProjectManageMiddleware.php     Project ownership + admin role check
│   │   └── ApiAuthMiddleware.php           Bearer token validation for REST API
│   ├── Models/                 Thin data-access objects; all static methods, PDO prepared statements
│   │   ├── AuditLog.php                  Tamper-evident audit event log (hash chain)
│   │   ├── DiagramNode.php               Mermaid diagram nodes with OKR metadata
│   │   ├── Document.php                  Uploaded files with extracted text and AI summary
│   │   ├── DriftAlert.php                Alerts raised by the drift detection engine
│   │   ├── EvaluationResult.php          Sounding board results; per-persona accept/reject
│   │   ├── GovernanceItem.php            Governance change-control queue
│   │   ├── HLItemDependency.php          Blocking dependencies between work items
│   │   ├── HLWorkItem.php                High-level work items with scoring and quality columns
│   │   ├── Integration.php               Jira/GitHub/GitLab integration credentials per org
│   │   ├── IntegrationRepo.php           GitHub repos visible to an App installation
│   │   ├── KeyResult.php                 OKR key results linked to work items
│   │   ├── KeyResultContribution.php     AI-scored PR contributions to key results
│   │   ├── Organisation.php              Top-level tenant; includes soft-delete support
│   │   ├── PasswordToken.php             Password reset tokens (hashed)
│   │   ├── PersonaMember.php             Individual AI personas within a sounding board panel
│   │   ├── PersonaPanel.php              Panel of personas; org_id NULL = system default
│   │   ├── PersonalAccessToken.php       PATs for REST API / MCP server authentication
│   │   ├── Project.php                   Project CRUD with visibility and Jira link
│   │   ├── ProjectRepoLink.php           Many-to-many: projects ↔ git repositories
│   │   ├── Risk.php                      Project risks with ROAM status and owner
│   │   ├── RiskItemLink.php              Risk ↔ work item join table
│   │   ├── Sprint.php                    Sprint records with team capacity
│   │   ├── SprintStory.php               Sprint ↔ story allocation
│   │   ├── StoryGitLink.php              Story ↔ git commit/PR links
│   │   ├── StoryQualityConfig.php        Org-specific quality scoring rules
│   │   ├── StrategicBaseline.php         Point-in-time project snapshots for drift comparison
│   │   ├── StrategyDiagram.php           Mermaid diagram with version counter
│   │   ├── Subscription.php              Stripe subscription state and feature flags
│   │   ├── SyncLog.php                   Jira sync operation log
│   │   ├── SyncMapping.php               Jira item ID ↔ StratFlow item ID mappings
│   │   ├── SystemSettings.php            Superadmin-managed system-wide JSON config
│   │   ├── Team.php                      Team CRUD; member_count via LEFT JOIN
│   │   ├── TeamMember.php                Team ↔ user junction; INSERT IGNORE prevents duplicates
│   │   ├── User.php                      User accounts with roles, flags, MFA columns
│   │   └── UserStory.php                 User stories with quality score and assignee
│   └── Services/
│       ├── AuditLogger.php               Structured audit event logging with HMAC hash chain
│       ├── DriftDetectionService.php     Baseline snapshots, capacity/dependency tripwires, AI alignment
│       ├── EmailService.php              Transactional email via Resend / MailerSend
│       ├── FileProcessor.php             PDF/DOCX/PPTX/XLSX text extraction
│       ├── GeminiService.php             Gemini API HTTP client with OpenAI fallback
│       ├── GitHubAppClient.php           GitHub App REST API client
│       ├── GitHubClient.php              GitHub REST API client (OAuth)
│       ├── GitLabClient.php              GitLab REST API client
│       ├── GitLinkService.php            Story-to-git link creation and PR matching
│       ├── GitPrMatcherService.php       AI-assisted PR-to-story matching
│       ├── JiraService.php               Jira REST API client (OAuth 2.0)
│       ├── JiraSyncService.php           Bidirectional Jira sync orchestration
│       ├── KrScoringService.php          AI scoring of key result contributions from merged PRs
│       ├── Logger.php                    Structured application logger (replaces error_log)
│       ├── SoundingBoardService.php      Iterates panel members, calls Gemini per persona
│       ├── StoryImprovementService.php   AI rewrites fields scoring below quality threshold
│       ├── StoryQualityScorer.php        AI quality scoring across 6 dimensions (0–100)
│       ├── StripeService.php             Stripe SDK wrapper
│       ├── TotpService.php               TOTP MFA secret generation and code verification
│       ├── TraceabilityService.php       Story-to-OKR linkage matrix builder
│       ├── XeroService.php               Xero API client (OAuth 2.0)
│       └── Prompts/
│           ├── SummaryPrompt.php         Document text → 3-paragraph strategic brief
│           ├── DiagramPrompt.php         Strategic brief → Mermaid flowchart + OKR generation
│           ├── PersonaPrompt.php         Per-persona evaluation prompts with 3 criticality levels
│           ├── WorkItemPrompt.php        Work item generation, sizing, description, quality, improvement
│           ├── PrioritisationPrompt.php  RICE and WSJF AI baseline scoring
│           ├── RiskPrompt.php            Risk identification and mitigation generation
│           ├── UserStoryPrompt.php       Story decomposition, sizing, quality, improvement
│           ├── SprintPrompt.php          Sprint auto-allocation
│           ├── DriftPrompt.php           OKR alignment assessment for drift detection
│           ├── KrScoringPrompt.php       AI scoring of PR contributions to key results
│           └── GitPrMatchPrompt.php      AI matching of PRs to user stories
├── templates/
│   ├── layouts/
│   │   ├── app.php             Authenticated shell (nav, sidebar, modals)
│   │   └── public.php          Public shell (marketing pages)
│   ├── partials/
│   │   ├── workflow-nav.php    Step-by-step navigation bar across app screens
│   │   ├── sounding-board-modal.php   Sounding board evaluation modal
│   │   └── sounding-board-button.php  Sounding board trigger button
│   └── *.php                   Page-level templates
├── database/
│   ├── schema.sql              Canonical schema (applied by Docker on first start)
│   ├── seed.sql                Optional seed data
│   └── migrations/             Sequential SQL migration files (001 – current)
├── bin/
│   └── score_quality.php       Background worker: async quality scoring loop
├── docker/
│   ├── nginx/default.conf      Nginx virtual host config
│   └── php/Dockerfile          PHP-FPM image with extensions
├── scripts/
│   ├── create-admin.php        CLI: bootstrap the first admin user
│   └── init-db.php             CLI: run pending migrations (used in Railway deploy)
├── tests/
│   ├── Unit/                   PHPUnit unit tests
│   ├── Integration/            PHPUnit integration tests
│   ├── bootstrap.php           PHPUnit bootstrap
│   └── phpunit.xml             Test suite configuration
├── .github/
│   ├── workflows/              27 CI/CD workflows (tests, security, deploy, nightly triage)
│   └── PULL_REQUEST_TEMPLATE.md
├── docker-compose.yml
├── composer.json
└── .env.example
```

---

## Security Model

### Authentication

- Session-based. On login, `user_id`, `org_id`, and `role` are written to `$_SESSION`.
- Passwords are hashed with `password_hash()` (bcrypt).
- Login rate-limited by IP via `login_attempts` table.
- **TOTP MFA** (migration 039): users may enable time-based one-time passwords. Secrets stored via `SecretManager` (encrypted if `TOKEN_ENCRYPTION_KEYS` is set). Recovery codes stored as HMAC-SHA256 hashes.
- **Personal Access Tokens** (migration 029): long-lived bearer tokens for REST API/MCP access. Stored as SHA-256 hashes; plaintext shown once.

### CSRF Protection

All state-changing POST routes (except webhooks) include `csrf` middleware. The `CSRF` class generates a per-session token embedded in forms as `_csrf_token`.

### XSS Prevention

All user-supplied values rendered in templates are escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

### SQL Injection Prevention

All queries use PDO prepared statements with bound parameters. No query concatenates user input.

### File Upload Safety

- MIME types validated server-side
- Files stored outside web root with UUID filenames
- Size enforced at PHP and Nginx layers

### Stripe Webhook Verification

`WebhookController` uses Stripe's `Webhook::constructEvent()` to verify the `Stripe-Signature` header.

### Git Webhook Verification

GitHub webhooks verified via `X-Hub-Signature-256` HMAC. GitLab verified via `X-Gitlab-Token`.

### Audit Logging

`AuditLogger` records security-relevant events with an HMAC hash chain (migration 038). Each row's `row_hash` is derived from its content + `prev_hash`, making tampering detectable. Logs are org-scoped and exportable for SOC 2 / HIPAA compliance.

---

## Multi-Tenancy

All application data is scoped by `org_id`. Every query that reads or writes project data includes an `org_id` condition from the authenticated session. Controllers additionally verify that resources belong to the session user's `org_id` via `ProjectPolicy`.

---

## AI Integration Pattern

```
Controller
  └── GeminiService::generate(prompt, content)     ← plain text response
        └── POST https://generativelanguage.googleapis.com/...
              Authorization: Bearer GEMINI_API_KEY
  └── GeminiService::generateJson(prompt, content)  ← JSON mode with 6-level parse recovery
        └── POST https://generativelanguage.googleapis.com/...
              responseMimeType: "application/json"

Fallback on any Gemini error:
  └── GeminiService::openaiGenerate(prompt, content)
        └── POST https://api.openai.com/v1/chat/completions
              model: gpt-4o-mini
```

**Config:** temperature 0.4, maxOutputTokens 8192, 2 retries on 429/5xx.

**Prompt constants** live in `src/Services/Prompts/`. They are pure PHP string constants — no templating library. Dynamic values use `{placeholder}` tokens replaced via `str_replace()`.

See [GEMINI_PROMPTS.md](GEMINI_PROMPTS.md) for full prompt text and tuning notes.

### Async Quality Scoring

`bin/score_quality.php` runs as a Docker `quality-worker` service in a loop (every 2 minutes). It picks up stories/work items with `quality_status = 'pending'`, calls `StoryQualityScorer::score()` via Gemini, and writes the result back. This decouples quality scoring from the HTTP request lifecycle.

---

## Background Worker

The `quality-worker` Docker service runs alongside the main PHP-FPM container:

```
docker-compose quality-worker:
  command: php bin/score_quality.php --limit=25
  restart: unless-stopped
  (loops with 2-minute sleep between batches)
```

This is the only async processing path — all other AI calls are synchronous within the HTTP request.
