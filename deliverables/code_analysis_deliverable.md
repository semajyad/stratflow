# Penetration Test Scope & Boundaries

**Primary Directive:** This analysis is strictly limited to the **network-accessible attack surface** of the StratFlow application. All findings have been verified against the scope criteria below.

### In-Scope: Network-Reachable Components
A component is considered **in-scope** if its execution can be initiated, directly or indirectly, by a network request that the deployed application server is capable of receiving. This includes:
- Publicly exposed web pages and API endpoints (230+ routes identified)
- Endpoints requiring authentication via session cookies or Personal Access Tokens (PATs)
- Webhook reception endpoints for Stripe, Jira, GitHub, and GitLab
- OAuth callback endpoints for Jira, Xero, and GitHub App installations
- File upload and document processing endpoints
- Administrative panels and superadmin management interfaces

### Out-of-Scope: Locally Executable Only
The following components are **out-of-scope** as they cannot be invoked through the running application's network interface:
- `scripts/create-admin.php` — CLI tool for initial admin user creation (uses `system('stty')` for terminal input)
- `scripts/init-db.php` — Database initialization script run at deployment startup
- `_git_commit.py` — Git commit automation script
- `database/migrations/*.sql` — Migration files applied during deployment
- `tests/` directory — PHPUnit test harnesses and Playwright browser tests
- `docker/` directory — Docker configuration for local development
- `.github/` — CI/CD workflow definitions

---

## 1. Executive Summary

StratFlow is a custom-built PHP 8.4 project management and strategic planning SaaS application deployed on Railway PaaS. The application implements a well-structured security architecture with defense-in-depth patterns including AES-256-GCM encryption for secrets, parameterized SQL queries throughout, comprehensive security headers (HSTS, CSP, X-Frame-Options), CSRF protection on all state-changing routes, and multi-tenant isolation enforced via `org_id` scoping on all database queries. The codebase demonstrates security awareness with session fingerprinting, periodic session ID regeneration, rate limiting on authentication endpoints, and webhook HMAC signature verification.

However, several significant security concerns have been identified that warrant immediate attention from the penetration testing team. The most critical finding is a potential **cross-tenant data leakage in audit log exports** where system events with NULL `user_id` are included in all organizations' export results (`src/Models/AuditLog.php` line 95: `OR al.user_id IS NULL`). Additionally, the **SecretManager conditionally returns plaintext** when the encryption key is not configured (`src/Core/SecretManager.php` lines 35-36), which means integration OAuth tokens could be stored unencrypted. Legacy integration records may still contain plaintext OAuth tokens in the database pending silent backfill encryption. The `.env` file exists in the working directory (untracked by git) but contains real API keys and credentials that should be rotated as a precaution.

The application exposes over **230 network-accessible routes** across public pages, authenticated web routes, JSON API endpoints, webhook receivers, and superadmin management interfaces. The attack surface includes file upload processing (PDF, DOCX extraction), AI-powered features via Gemini/OpenAI APIs, bidirectional Jira sync, GitHub App webhook processing with commit-to-story matching, Xero invoicing integration, and Stripe payment processing. Each integration represents a trust boundary that merits focused testing, particularly the webhook signature verification paths and OAuth token management flows.

## 2. Architecture & Technology Stack

- **Framework & Language:** PHP 8.4+ with `strict_types` enforced throughout. This is a **custom lightweight MVC framework** — no Laravel, Symfony, or Slim dependency. All routing, middleware, session management, authentication, and authorization are custom-built. This means security responsibility is entirely internal with no framework-provided security abstractions to rely on. The minimal dependency footprint (only 3 production composer packages: `smalot/pdfparser`, `stripe/stripe-php`, `vlucas/phpdotenv`) significantly reduces supply-chain attack surface but increases the burden of correct security implementation.

- **Architectural Pattern:** Traditional MVC with a custom router (`src/Core/Router.php`) implementing pattern-based regex routing with middleware chaining. Routes are defined declaratively in `src/Config/routes.php` with middleware metadata arrays (e.g., `['auth', 'csrf', 'workflow_write']`). Controllers receive dependency-injected `Request`, `Response`, `Auth`, `Database`, and `Config` objects. The service layer (`src/Services/`) contains business logic for external integrations. Trust boundaries are enforced through middleware composition — routes declare required middleware, and the router chains them before controller execution. There is no ORM; all database access uses direct PDO with prepared statements.

- **Critical Security Components:** The security architecture centers around several core classes: `Auth.php` (session/token authentication), `Session.php` (database-backed sessions with fingerprinting), `CSRF.php` (per-session CSRF tokens with constant-time comparison), `SecretManager.php` (AES-256-GCM encryption), `RateLimiter.php` (database-backed rate limiting), `PasswordPolicy.php` (12-char minimum with complexity requirements), `Sanitizer.php` (input sanitization helpers), and `Response.php` (comprehensive security headers). Authorization is capability-based through `PermissionService.php` with role-to-capability mapping. Ten middleware classes enforce access control at the routing layer. The `DatabaseSessionHandler.php` persists sessions to MySQL, enabling cross-container session persistence on Railway PaaS.

**Deployment Architecture:**
- **Production:** Railway PaaS using Nixpacks builder. PHP built-in server (`php -S 0.0.0.0:$PORT`) serves the application through `public/router.php`. Database initialization runs on startup via `scripts/init-db.php`.
- **Development:** Docker Compose with PHP-FPM 8.4 + Nginx (port 8890) + MySQL 8.4 (port 3307 exposed to host).
- **External Services:** Stripe (payments), Google Gemini AI (document processing, strategy generation), Jira Cloud (OAuth2 bidirectional sync), GitHub App (PR/commit tracking), GitLab (webhook-based tracking), Xero (OAuth2 invoicing), Resend/MailerSend (email delivery), SMTP (configurable).

## 3. Authentication & Authorization Deep Dive

### Authentication Mechanisms

The application supports two authentication mechanisms: **session-based authentication** for web UI access and **Personal Access Token (PAT) authentication** for API access.

**Session-Based Authentication** (`src/Core/Auth.php`): The `attempt(email, password)` method performs a database lookup by email followed by `password_verify()` against the stored bcrypt hash. On success, `login(user)` regenerates the session ID (`session_regenerate_id(true)`) and stores user context in `$_SESSION['user']` including `id`, `org_id`, `name`, `email`, `role`, `account_type`, and permission flags (`has_billing_access`, `has_executive_access`, `is_project_admin`). The `check()` method validates the session by confirming the user exists, is active, and belongs to an active organization — using a parameterized query that includes `org_id` scoping. Login rate limiting is enforced at 5 failed attempts per 15 minutes per IP address, tracked in the `login_attempts` table.

**PAT Authentication** (`src/Middleware/ApiAuthMiddleware.php`): API requests use `Authorization: Bearer sf_pat_<token>` headers. The middleware computes SHA256 of the token and looks it up in the `personal_access_tokens` table, checking for non-revoked and non-expired status. Scopes are validated per-endpoint: `profile:read`, `profile:write`, `projects:read`, `stories:read`, `stories:write-status`, `stories:assign`. The authenticated principal is injected in-memory via `Auth::loginAsPrincipal()` without touching the session. Token usage is tracked fire-and-forget (`last_used_at`, `last_used_ip`).

**Authentication API Endpoints:**

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|---------------|
| `/login` | GET | Login form display | No |
| `/login` | POST | Email + password authentication (rate-limited) | No |
| `/logout` | POST | Session destruction + cookie deletion | Yes (session) |
| `/forgot-password` | GET | Password reset request form | No |
| `/forgot-password` | POST | Send reset email (rate-limited: 3/hour/IP, no user enumeration) | No |
| `/set-password/{token}` | GET | Password reset form with token | No |
| `/set-password/{token}` | POST | Set new password with token validation + policy enforcement | No |
| `/app/account/tokens` | GET | PAT management page | Yes (session) |
| `/app/account/tokens` | POST | Create new PAT (raw token shown once via session flash) | Yes (session + CSRF) |
| `/app/account/tokens/{id}/revoke` | POST | Revoke PAT | Yes (session + CSRF) |

### Session Management and Token Security

**Session Configuration** (`src/Core/Session.php`, lines 39-54):

| Setting | Value | Location |
|---------|-------|----------|
| Cookie Name | `__Host-stratflow_session` (HTTPS) / `stratflow_session` (HTTP) | Line 47 |
| `HttpOnly` | `true` | **Line 42** (`ini_set`) and **Line 52** (`session_set_cookie_params`) |
| `Secure` | Conditional on HTTPS detection | **Line 51** |
| `SameSite` | `Lax` | **Line 43** (`ini_set`) and **Line 53** (`session_set_cookie_params`) |
| `lifetime` | `0` (browser session) | **Line 48** |
| `session.use_strict_mode` | `1` | **Line 40** |
| `session.use_only_cookies` | `1` | **Line 41** |
| `session.sid_length` | `48` | **Line 44** |
| `session.sid_bits_per_character` | `6` | **Line 45** |

**Session Security Features:**
- **Inactivity Timeout:** 30 minutes (lines 79-90), tracked via `_last_activity` timestamp
- **Session ID Regeneration:** Every 15 minutes (lines 170-182, `REGEN_INTERVAL = 900`)
- **Session Fingerprinting:** SHA256 of User-Agent (lines 98-126). IP excluded to support proxy/load-balancer environments. Session destroyed on fingerprint mismatch.
- **Database Storage:** `DatabaseSessionHandler.php` implements `SessionHandlerInterface` with `sessions` table (INSERT...ON DUPLICATE KEY UPDATE pattern)

**Password Reset Token Security** (`src/Models/PasswordToken.php`):
- Token generation: `bin2hex(random_bytes(32))` = 64-character hex (line 40)
- Storage: SHA256 hash in database (line 41)
- Expiry: 24 hours (line 42)
- Single active token per user: `invalidateForUser()` marks all others as used (lines 116-122)
- Lookup checks both hashed and legacy plaintext tokens (lines 65-82)

### Authorization Model and Potential Bypass Scenarios

The application uses a **capability-based RBAC system** (`src/Security/PermissionService.php`) with the following role hierarchy:

| Role | Key Capabilities | Middleware Enforcement |
|------|-----------------|----------------------|
| `viewer` | `workflow.view`, `tokens.manage_own`, `api.use_own_tokens` | `AuthMiddleware` |
| `member` | + `workflow.edit` | `WorkflowWriteMiddleware` |
| `manager` | + `project.create`, `project.edit_settings`, `project.manage_access` | `ProjectCreateMiddleware`, `ProjectManageMiddleware` |
| `org_admin` | + `admin.access`, `project.view_all`, `project.delete`, `users.manage`, `teams.manage`, `settings.manage`, `integrations.manage`, `audit_logs.view`, `billing.*` | `AdminMiddleware` |
| `superadmin` | Wildcard `['*']` | `SuperadminMiddleware` |
| `developer` | `tokens.manage_own`, `api.use_own_tokens` only — restricted to `/app/account/tokens` in web UI | `AuthMiddleware` (special developer restriction) |

**Additional Access Flags:** `has_billing_access` (checked by `BillingMiddleware`), `has_executive_access` (checked by `ExecutiveMiddleware`), `is_project_admin` (project-level admin).

**Project-Level RBAC:** `project_memberships` table with roles: `viewer`, `editor`, `project_admin`. Enforced by `ProjectPolicy.php` which checks org_id match AND project membership for restricted-visibility projects.

**Potential Bypass Scenarios to Test:**
1. **Developer role web access:** `AuthMiddleware` restricts developers to `/app/account/*` — test if other paths can be accessed by manipulating the URL
2. **Capability escalation:** Capabilities are database-backed with user-level overrides in `user_capabilities` table — test if a user can modify their own capabilities
3. **Project visibility bypass:** Restricted projects require project membership — test if `project_id` parameter manipulation exposes data from other projects
4. **Superadmin role assignment:** `POST /superadmin/assign-superadmin` endpoint exists — verify it cannot be accessed by non-superadmin users
5. **API scope bypass:** PAT scopes are checked per-route in `ApiAuthMiddleware::requiredScopeForRequest()` — test if unscoped endpoints exist

### Multi-Tenancy Security Implementation

All data access is scoped through `org_id` derived from the authenticated user's session. The `Auth::check()` method validates `user.org_id` matches an active organization on every request. All model queries include `org_id` as a parameterized WHERE clause. **Critical exception:** Audit log exports include `OR al.user_id IS NULL` which could leak cross-tenant system events (see Section 4).

### SSO/OAuth/OIDC Flows

**Jira OAuth 2.0** (`src/Controllers/IntegrationController.php`):
- Connect: `GET /app/admin/integrations/jira/connect` — generates state nonce, stores in session, redirects to Atlassian authorization
- Callback: `GET /app/admin/integrations/jira/callback` — validates state parameter against session (timing-safe comparison expected)

**GitHub App Installation** (`src/Controllers/GitHubAppController.php`):
- Install: `GET /app/admin/integrations/github/install` — generates 16-byte random nonce (`bin2hex(random_bytes(16))`, line 71), stores as `github_install_state` in session (line 72), redirects to GitHub
- Callback: `GET /app/admin/integrations/github/callback` — retrieves state from query (line 99) and session (line 100), clears session state immediately (line 101), validates with `hash_equals()` (line 103), returns 403 on mismatch (line 104)

**Xero OAuth 2.0** (`src/Controllers/XeroController.php`):
- Connect: `GET /app/admin/xero/connect` — state nonce generated, redirects to Xero authorization
- Callback: `GET /app/admin/xero/callback` — state nonce validated

## 4. Data Security & Storage

### Database Security

**Database Engine:** MySQL 8.4 with `utf8mb4` charset. Schema defined in `database/schema.sql` with 36 sequential migration files in `database/migrations/`.

**Encryption at Rest:** Integration OAuth tokens (Jira, Xero, GitHub) are encrypted using AES-256-GCM via `SecretManager.php`. The encryption implementation (`src/Core/SecretManager.php`, lines 28-53) uses `random_bytes(12)` for IV generation and returns an envelope `{__enc_v1: true, ciphertext, iv, tag}`. **Critical issue:** If `TOKEN_ENCRYPTION_KEY` environment variable is empty, `SecretManager::protectString()` silently returns plaintext (lines 35-36). This means all token encryption is conditional on proper environment configuration. The `.env.example` file contains a placeholder value `replace-with-a-32-byte-random-secret` for this key.

**Legacy Plaintext Token Migration:** The `Integration` model (`src/Models/Integration.php`, lines 401-447) includes backward compatibility that detects plaintext tokens on read and silently re-encrypts them via an `UPDATE` query. This backfill has no audit trail and depends on the encryption key being configured. The detection logic checks: `json_decode($token) === null && empty($row['token_iv']) && empty($row['token_tag'])`.

**Password Storage:** Passwords are hashed with `PASSWORD_DEFAULT` (bcrypt on PHP 8.4) in `src/Controllers/AuthController.php:294`. Verification uses `password_verify()` in `src/Core/Auth.php:74`. Password hashes are stored in the `users.password_hash` column.

**PAT Storage:** Personal Access Tokens are stored as SHA256 hashes in `personal_access_tokens.token_hash`. Raw tokens are never persisted — they are shown once via session flash (`src/Controllers/AccessTokenController.php:143`). Token prefixes (first 15 chars) are stored for display purposes.

**Query Safety:** All database queries use PDO prepared statements with named parameters. PDO emulated prepares are disabled (`ATTR_EMULATE_PREPARES = false` in `src/Core/Database.php:38-60`). One exception exists: `SuperadminController::countAll()` (lines 804-813) uses string interpolation for table names and WHERE clauses, but is currently called only with hardcoded values.

### Data Flow Security

**Sensitive Data Paths:**
1. **Login credentials:** `POST /login` → `AuthController::login()` → `Auth::attempt()` → `password_verify()` — password never logged, only verified against hash
2. **OAuth tokens:** External provider → `IntegrationController` callback → `Integration::upsert()` → `SecretManager::protectString()` → encrypted in `integrations` table
3. **PAT creation:** `POST /app/account/tokens` → `AccessTokenController::store()` → `PersonalAccessToken::generate()` (random_bytes) → SHA256 hash stored → raw token flash to session once
4. **File uploads:** `POST /app/upload` → `UploadController::store()` → `FileProcessor::validateFile()` + `storeFile()` → UUID filename in `public/uploads/` → text extraction → stored in `documents` table
5. **Webhook payloads:** External service → raw `php://input` → HMAC verification → JSON decode → database operations

**Data Protection Gaps:**
- Session data stored in `sessions` table as MEDIUMBLOB — **not encrypted at rest**
- Audit log `details_json` may contain sensitive context — stored as plain JSON text
- File uploads stored in web-accessible `public/uploads/` directory with UUID filenames but no execution prevention verification

### Multi-Tenant Data Isolation

**Enforcement Model:** All data queries include `org_id` filtering derived from the authenticated user's session. Key enforcement points:
- `ProjectPolicy.php` (lines 63-64): Validates `project.org_id === user.org_id` before any project access
- `Project::findAccessibleByOrgId()`: Filters projects by org_id and applies visibility/membership rules
- `PersonalAccessToken`: All queries include `org_id` (lines 173, 176, 202)
- `Integration` queries: Filtered by org_id through `Integration::findByOrg()`
- `Auth::check()`: Session validation query joins `users` with `organisations` on `org_id` (lines 132-145)

**Critical Isolation Vulnerability — Audit Log Cross-Tenant Leakage:**
- **File:** `src/Models/AuditLog.php`, line 95
- **Query:** `AND (u.org_id = :org_id OR al.user_id IS NULL)`
- **Impact:** Events logged with NULL `user_id` (unauthenticated events, system events) are included in ALL organizations' audit exports. If a superadmin action creates events with a user_id belonging to a different org, those events could leak to requesting org.
- **Exploitation:** `GET /app/admin/audit-logs/export` (requires `admin` middleware) — export CSV includes system-wide NULL-user events

## 5. Attack Surface Analysis

### External Entry Points (In-Scope, Network-Accessible)

**Public Unauthenticated Routes (6 routes):**
These routes require no authentication and are the primary initial attack surface:

| Route | Method | Handler | Security Controls |
|-------|--------|---------|-------------------|
| `/` | GET | `PricingController@index` | None (read-only) |
| `/pricing` | GET | `PricingController@index` | None (read-only) |
| `/login` | GET/POST | `AuthController@showLogin/login` | CSRF on POST, rate limiting (5/15min/IP) |
| `/forgot-password` | GET/POST | `AuthController@showForgotPassword/sendResetEmail` | CSRF on POST, rate limiting (3/hr/IP), no user enumeration |
| `/set-password/{token}` | GET/POST | `AuthController@showSetPassword/setPassword` | CSRF on POST, token validation, password policy |
| `/success` | GET | `SuccessController@index` | None (post-checkout page) |

**Webhook Endpoints (4 routes — no session/CSRF, signature-verified):**
These are high-value targets as they accept unauthenticated POST requests from external services:

| Route | Method | Verification | Handler |
|-------|--------|-------------|---------|
| `/webhook/stripe` | POST | HMAC-SHA256 via `Stripe-Signature` header | `WebhookController@handle` — processes `checkout.session.completed`, creates orgs/subscriptions/users |
| `/webhook/integration/jira` | POST | HMAC-SHA256 (optional, falls back to payload structure check) | `IntegrationController@jiraWebhook` — syncs issue updates |
| `/webhook/git/github` | POST | HMAC-SHA256 via `X-Hub-Signature-256` + `GITHUB_APP_WEBHOOK_SECRET` | `GitWebhookController@receiveGitHub` — multi-tenant routing by `installation.id`, repo allowlist, PR/commit tracking |
| `/webhook/git/gitlab` | POST | Token via `X-Gitlab-Token` header (per-integration secret) | `GitWebhookController@receiveGitLab` — merge request event processing |

**Security Concern — Jira Webhook:** The HMAC verification is optional with a fallback to basic payload structure checking. If the webhook secret is not configured, the endpoint may accept unauthenticated payloads.

**Security Concern — GitHub Webhook:** Signature verification depends on `GITHUB_APP_WEBHOOK_SECRET` environment variable. If not set, the verification may fail open (needs testing).

**Authenticated Web Routes (200+ routes):**
All `/app/*` routes require `AuthMiddleware` (session-based). State-changing operations require `CSRFMiddleware`. Key categories:

- **Document Upload:** `POST /app/upload` — rate-limited (50/hr/user), extension whitelist (txt, csv, md, rtf, pdf, doc, docx, pptx, xlsx), MIME verification via finfo, content scanning for dangerous signatures, UUID filenames, 50MB limit (200MB for media)
- **AI-Powered Features:** Multiple `/app/*/generate`, `/app/*/improve` endpoints send user content to Gemini/OpenAI APIs — potential prompt injection vector
- **Admin Integration Management:** `/app/admin/integrations/*` — OAuth flows, webhook secret management, bidirectional sync operations
- **Billing/Checkout:** `/app/admin/billing/*` — Stripe portal access, seat purchases
- **Data Exports:** `GET /app/work-items/export`, `GET /app/user-stories/export`, `GET /app/admin/audit-logs/export`, `GET /app/admin/integrations/sync-log/export` — CSV exports scoped by org_id

**JSON API Endpoints (8 routes):**
All `/api/v1/*` routes require `ApiAuthMiddleware` (PAT-based, no session/CSRF):

| Route | Method | Required Scope | Description |
|-------|--------|---------------|-------------|
| `/api/v1/me` | GET | `profile:read` | User identity |
| `/api/v1/me/team` | POST | `profile:write` | Set team membership |
| `/api/v1/projects` | GET | `projects:read` | List accessible projects |
| `/api/v1/stories` | GET | `stories:read` | List stories (filterable by `mine`, `status`, `project_id`, `limit`) |
| `/api/v1/stories/team` | GET | `stories:read` | Team stories (requires team set) |
| `/api/v1/stories/{id}` | GET | `stories:read` | Full story detail with context |
| `/api/v1/stories/{id}/status` | POST | `stories:write-status` | Transition story status (auto-syncs to Jira) |
| `/api/v1/stories/{id}/assign` | POST | `stories:assign` | Self-assign story |

**Superadmin Routes (13 routes):**
`/superadmin/*` routes require `SuperadminMiddleware` — platform-wide management including org creation, data export, system defaults, persona management, and superadmin role assignment.

### Internal Service Communication

The application makes outbound HTTP requests to external services using cURL. All external API base URLs are hardcoded:
- Gemini API: `https://generativelanguage.googleapis.com/v1beta/...`
- Jira API: `https://api.atlassian.com/ex/jira/...`
- GitHub API: `https://api.github.com/...`
- Xero API: `https://api.xero.com/api.xro/2.0/...`
- Stripe API: Via Stripe PHP SDK
- Resend: `https://api.resend.com/emails`
- MailerSend: `https://api.mailersend.com/v1/email`

**Trust assumption:** All outbound URLs are hardcoded, reducing SSRF risk. However, `APP_URL` config influences webhook registration URLs sent to Jira and Stripe redirect URLs.

### Input Validation Patterns

**Sanitizer utility** (`src/Core/Sanitizer.php`): Provides `string()` (trim + strip_tags), `email()` (FILTER_SANITIZE_EMAIL), `int()` (FILTER_SANITIZE_NUMBER_INT), `html()` (htmlspecialchars with ENT_QUOTES | ENT_HTML5). However, the `Request` wrapper (`src/Core/Request.php`) provides direct access to `$_GET`, `$_POST`, `$_FILES`, `$_SERVER` with **no automatic sanitization** — controllers must explicitly call sanitization functions.

**Template Output:** Templates consistently use `htmlspecialchars()` for output escaping. JSON output includes `JSON_HEX_TAG | JSON_HEX_AMP` flags for safe embedding.

### Background Processing

The application uses synchronous request processing — no background job queue. Some operations are performed "after response" by flushing output and continuing execution (e.g., PAT `last_used_at` updates in `ApiAuthMiddleware`, AI commit-matching in `GitWebhookController`). These post-response operations execute with the same privileges as the triggering request.

## 6. Infrastructure & Operational Security

### Secrets Management

Secrets are managed through environment variables loaded via `vlucas/phpdotenv` (`src/Config/config.php`, lines 9-16). The `.env` file is loaded if present (local development) but is not required (Railway injects environment variables directly). The `.env` file is listed in `.gitignore` and is **not tracked by git** — however, it exists in the working directory with real test credentials (Stripe test keys, Jira tokens, Gmail SMTP password, API keys). These should be considered compromised and rotated.

**Encryption Key Management:** The `TOKEN_ENCRYPTION_KEY` environment variable provides the master key for AES-256-GCM encryption of integration secrets. There is no key rotation mechanism — changing the key would make existing encrypted tokens undecryptable. The key length is not validated (should be exactly 32 bytes for AES-256). If the key is empty, `SecretManager` silently falls back to plaintext storage.

**GitHub App Private Key:** Can be stored as a newline-escaped string in the `GITHUB_APP_PRIVATE_KEY` environment variable, or as a file path. The PEM file `stratflow1.2026-04-09.private-key.pem` exists in the repository root but is gitignored (`.gitignore` includes `*.pem`).

**Secrets in Error Logs:** Database connection errors (`src/Core/Database.php`, lines 55-59) throw `RuntimeException` with `$e->getMessage()` which could contain connection details. The `AccessTokenController` uses `error_log(var_export(...))` for debugging (lines 207, 216).

### Configuration Security

**Security Headers** (`src/Core/Response.php`, lines 117-203): Comprehensive headers are applied to all responses:
- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` — **HSTS enabled** (line 128, conditional on HTTPS)
- `Cache-Control: no-store, no-cache, must-revalidate, private` — **prevents caching** (line 134)
- `Content-Security-Policy` — **strict CSP** with `'self'` for scripts/styles, only `https://cdn.jsdelivr.net` allowed for app pages, `https://checkout.stripe.com` for frames
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cross-Origin-*` policies
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()` — restricts browser APIs

**PHP Configuration** (`php.ini`):
- `expose_php = Off` — prevents X-Powered-By disclosure
- `upload_max_filesize = 210M`, `post_max_size = 220M` — large limits for document processing
- `max_execution_time = 300` — 5-minute timeout (needed for AI processing)
- `file_uploads = On` — required for document upload feature

**Nginx Configuration** (`docker/nginx/default.conf`): Development-only Nginx config serves static files and proxies PHP to PHP-FPM. Production uses PHP built-in server directly.

**Environment Separation:** `APP_ENV` and `APP_DEBUG` flags control error display. Production should have `APP_DEBUG=false` to suppress stack traces. The `public/index.php` entry point disables `display_errors` and enables `log_errors` in production mode.

### External Dependencies

**Composer Dependencies (production only 3 packages):**
- `smalot/pdfparser` ^2.0 — PDF text extraction (used in file upload processing)
- `stripe/stripe-php` ^16.0 — Stripe API client for payment processing and webhook verification
- `vlucas/phpdotenv` ^5.6 — Environment variable loading

**JavaScript Dependencies:** CDN-loaded `https://cdn.jsdelivr.net` (referenced in CSP). Mermaid.js for diagram rendering set to `securityLevel: 'loose'` (`public/assets/js/app.js:611`) — should be `'strict'`.

**No package-lock.json or npm audit** — frontend assets appear to be vanilla JS with CDN libraries. Playwright tests have their own `node_modules` (gitignored).

### Monitoring & Logging

**Audit Logging** (`src/Services/AuditLogger.php`): Comprehensive event logging to `audit_logs` table covering: `LOGIN_SUCCESS`, `LOGIN_FAILURE`, `LOGOUT`, `PASSWORD_CHANGE`, `PASSWORD_RESET_REQUEST`, `USER_CREATED`, `USER_DELETED`, `USER_ROLE_CHANGED`, `DATA_EXPORT`, `ADMIN_ACTION`, `SETTINGS_CHANGED`, `PROJECT_CREATED`, `PROJECT_DELETED`, `DOCUMENT_UPLOADED`, `API_KEY_USED`, `INTEGRATION_CONNECTED`, `INTEGRATION_DISCONNECTED`, `INTEGRATION_SYNC`, `INTEGRATION_WEBHOOK`. Each entry captures `user_id`, `event_type`, `ip_address` (45 chars), `user_agent` (500 chars), and `details_json`. Logging is non-blocking — failures are caught and logged via `error_log()` but never crash the application.

**Login Attempt Tracking:** `login_attempts` table records IP address, email, and timestamp for rate limiting. Cleaned up after 24 hours via `RateLimiter::cleanup()`.

**Rate Limit Monitoring:** `rate_limits` table tracks key, identifier, and timestamps for all rate-limited operations.

**Error Handling:** Production mode (`public/index.php`, lines 28-72) catches all exceptions and renders generic error pages (403, 404, 500) without technical details. Stack traces are only logged to `error_log`, never displayed to users.

## 7. Overall Codebase Indexing

The StratFlow codebase follows a clean, flat PHP MVC structure rooted in the repository at `/repos/stratflow/`. The `public/` directory serves as the web root containing `index.php` (production front controller), `router.php` (PHP built-in server router), static assets in `assets/js/` and `assets/css/`, and the `uploads/` directory for user-uploaded documents. The `src/` directory contains all application logic organized into `Config/` (routes and configuration), `Core/` (framework primitives: Router, Database, Auth, Session, CSRF, Request, Response, Sanitizer, SecretManager, RateLimiter, PasswordPolicy), `Controllers/` (28+ action controllers handling all route logic), `Middleware/` (10 middleware classes for auth, CSRF, RBAC, rate limiting), `Models/` (37 model classes providing data access with org_id scoping), `Security/` (PermissionService and ProjectPolicy for authorization), and `Services/` (business logic for AuditLogger, EmailService, FileProcessor, GeminiService, GitHubAppClient, GitLinkService, JiraService, StripeService, XeroService).

The `templates/` directory contains PHP template files organized into `layouts/` (public.php, app.php base layouts), `pages/` (individual page templates), and `partials/` (reusable component fragments). The `database/` directory holds `schema.sql` (complete table definitions) and `migrations/` (36 idempotent migration files). The `docker/` directory contains Docker Compose development environment configuration including Nginx virtual host config. The `scripts/` directory holds deployment and maintenance utilities (`init-db.php` for startup, `create-admin.php` for CLI admin creation). Build orchestration uses `nixpacks.toml` for Railway PaaS deployment and `docker-compose.yml` for local development. No code generation tools, monorepo managers, or complex build pipelines are present — the application ships its source directly.

For security component discoverability, the critical pattern is that all security enforcement happens through middleware composition declared in `src/Config/routes.php`. Every route explicitly lists its required middleware (e.g., `['auth', 'csrf', 'admin']`), making it straightforward to audit which routes lack security controls. The `src/Core/` directory is the single location for all security primitives, and `src/Security/` contains the authorization policy engine. External integration security (webhook verification, OAuth token management) is distributed across the respective service and controller files in `src/Services/` and `src/Controllers/`.

## 8. Critical File Paths

### Configuration
- `composer.json` — Dependency manifest (3 production packages)
- `composer.lock` — Locked dependency versions
- `docker-compose.yml` — Development environment (PHP-FPM + Nginx + MySQL)
- `docker/nginx/default.conf` — Nginx virtual host configuration
- `nixpacks.toml` — Railway PaaS build/deploy configuration
- `railway.toml` — Railway deployment settings (healthcheck, restart policy)
- `php.ini` — PHP runtime configuration (upload limits, expose_php)
- `.env.example` — Environment variable template with all required secrets
- `src/Config/config.php` — Application configuration loader
- `src/Config/routes.php` — Route definitions with middleware declarations (100+ routes)

### Authentication & Authorization
- `src/Core/Auth.php` — Session/token authentication (attempt, login, logout, check, rate limiting)
- `src/Core/Session.php` — Session management (cookie flags lines 39-54, timeout, fingerprinting, regeneration)
- `src/Core/DatabaseSessionHandler.php` — Database-backed session storage
- `src/Core/CSRF.php` — CSRF token generation and validation (hash_equals)
- `src/Core/PasswordPolicy.php` — Password complexity requirements (12-char minimum)
- `src/Security/PermissionService.php` — Capability-based RBAC engine
- `src/Security/ProjectPolicy.php` — Project-level access control with org_id validation
- `src/Controllers/AuthController.php` — Login/logout/password-reset handlers
- `src/Controllers/AccessTokenController.php` — PAT management (create, revoke)
- `src/Controllers/GitHubAppController.php` — GitHub OAuth (state nonce lines 71-72, validation lines 99-107)
- `src/Controllers/IntegrationController.php` — Jira OAuth, webhook handling, integration management
- `src/Controllers/XeroController.php` — Xero OAuth flow
- `src/Models/PasswordToken.php` — Password reset token generation and validation
- `src/Models/PersonalAccessToken.php` — PAT model (generation, hashing, scope management)

### API & Routing
- `public/index.php` — Production front controller (error handling, bootstrap)
- `public/router.php` — PHP built-in server router (static file serving, PHP routing)
- `src/Core/Router.php` — Pattern-based regex router with middleware chaining
- `src/Core/Request.php` — Request wrapper ($_GET, $_POST, $_FILES, $_SERVER access)
- `src/Core/Response.php` — Response helper with security headers (lines 117-203)
- `src/Controllers/WebhookController.php` — Stripe webhook handler (HMAC verification)
- `src/Controllers/GitWebhookController.php` — GitHub/GitLab webhook handlers (signature verification)

### Middleware & Input Validation
- `src/Middleware/AuthMiddleware.php` — Session authentication enforcement, developer role restriction
- `src/Middleware/ApiAuthMiddleware.php` — PAT authentication with scope validation
- `src/Middleware/CSRFMiddleware.php` — CSRF token validation on POST/PUT/DELETE
- `src/Middleware/AdminMiddleware.php` — org_admin role enforcement
- `src/Middleware/SuperadminMiddleware.php` — superadmin role enforcement
- `src/Middleware/BillingMiddleware.php` — Billing access control
- `src/Middleware/ExecutiveMiddleware.php` — Executive dashboard access control
- `src/Middleware/ProjectCreateMiddleware.php` — Project creation permission
- `src/Middleware/ProjectManageMiddleware.php` — Project management permission
- `src/Middleware/WorkflowWriteMiddleware.php` — Workflow edit permission
- `src/Core/Sanitizer.php` — Input sanitization utilities (string, email, int, html)

### Data Models & DB Interaction
- `database/schema.sql` — Full table definitions (users, sessions, tokens, integrations, etc.)
- `database/migrations/` — 36 migration files (idempotent)
- `database/migrations/009_jira_integration.sql` — Jira integration token columns (legacy plaintext)
- `database/migrations/013_status_and_jira_fields.sql` — Token encryption columns (token_iv, token_tag)
- `src/Core/Database.php` — PDO wrapper (prepared statements, connection config)
- `src/Models/User.php` — User model with org_id scoping
- `src/Models/Project.php` — Project model with visibility/membership rules
- `src/Models/Integration.php` — Integration model (OAuth token encryption/decryption)
- `src/Models/AuditLog.php` — Audit log queries (**cross-tenant leakage at line 95**)
- `src/Models/Document.php` — Document model (project-scoped access)

### Sensitive Data & Secrets Handling
- `src/Core/SecretManager.php` — AES-256-GCM encryption (**conditional plaintext fallback lines 35-36**)
- `.env.example` — Template listing all required secrets (TOKEN_ENCRYPTION_KEY, Stripe, Gemini, Jira, etc.)

### Logging & Monitoring
- `src/Services/AuditLogger.php` — Comprehensive audit event logging
- `src/Core/RateLimiter.php` — Database-backed rate limiting

### Infrastructure & Deployment
- `nixpacks.toml` — Production build and start commands
- `railway.toml` — Railway deployment configuration
- `docker-compose.yml` — Development Docker Compose
- `docker/nginx/default.conf` — Nginx configuration
- `scripts/init-db.php` — Database initialization on deployment
- `.gitignore` — Excludes vendor/, .env, *.pem, *.key, security-reports/, etc.

### Services (External Integration Security)
- `src/Services/GeminiService.php` — Gemini AI API client (cURL, lines 283-295)
- `src/Services/GitHubAppClient.php` — GitHub App API client (JWT minting, webhook verification)
- `src/Services/GitLinkService.php` — Git link management (commit-to-story matching)
- `src/Services/JiraService.php` — Jira API client (OAuth token refresh, webhook registration)
- `src/Services/XeroService.php` — Xero API client (OAuth, invoice management)
- `src/Services/EmailService.php` — Email delivery (Resend, MailerSend, SMTP — **fsockopen at line 198**)
- `src/Services/StripeService.php` — Stripe checkout session management
- `src/Services/FileProcessor.php` — File upload validation, storage, text extraction

### Frontend & Templates
- `public/assets/js/app.js` — Main JavaScript (**Mermaid securityLevel:'loose' at line 611, innerHTML at line 615**)
- `templates/layouts/public.php` — Public page layout
- `templates/layouts/app.php` — Authenticated app layout
- `templates/partials/row-actions-menu.php` — **Raw HTML echo at line 48** (currently safe, but unsafe pattern)

### File Upload & Storage
- `src/Controllers/UploadController.php` — Upload handler (**web-root storage at line 122**)
- `public/uploads/` — User-uploaded file storage directory (web-accessible)
