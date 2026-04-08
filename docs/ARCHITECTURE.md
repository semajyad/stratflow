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
  │     ├── Middleware stack (auth, csrf)
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
  Gemini API   ← AI summary, diagram, work item generation
  Stripe API   ← checkout session creation, webhook events
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

4. **Middleware** runs before the controller:
   - `AuthMiddleware`: checks `$_SESSION['user_id']`; redirects to `/login` if absent
   - `CSRFMiddleware`: compares `$_POST['_csrf_token']` against the session token; returns 403 on mismatch

5. **Controller** contains the HTTP handler logic:
   - Reads input from the `Request` object
   - Calls one or more Model methods (which run PDO queries)
   - Optionally calls `GeminiService` or `StripeService`
   - Returns a `Response` (render template / redirect / JSON)

6. **Response** is returned to Nginx → Browser.

---

## Directory Structure

```
stratflow/
├── public/
│   └── index.php               Single entry point; static assets live here too
├── src/
│   ├── Config/
│   │   ├── config.php          App-level constants loaded from .env
│   │   └── routes.php          Route definitions closure
│   ├── Controllers/            One controller per feature area
│   │   ├── AuthController.php
│   │   ├── CheckoutController.php
│   │   ├── DiagramController.php
│   │   ├── HomeController.php
│   │   ├── PricingController.php
│   │   ├── PrioritisationController.php  ← Phase 1: RICE/WSJF prioritisation
│   │   ├── RiskController.php            ← Phase 1: Risk modelling
│   │   ├── SprintController.php          ← Phase 1: Sprint allocation
│   │   ├── SuccessController.php
│   │   ├── UploadController.php
│   │   ├── UserStoryController.php       ← Phase 1: User story decomposition
│   │   ├── WebhookController.php
│   │   └── WorkItemController.php
│   ├── Core/
│   │   ├── Auth.php            Session-based authentication
│   │   ├── CSRF.php            Token generation and validation
│   │   ├── Database.php        PDO singleton wrapper
│   │   ├── Request.php         Input abstraction (GET, POST, FILES, headers)
│   │   ├── Response.php        Render, redirect, JSON helpers
│   │   ├── Router.php          Route registration and dispatch
│   │   └── Session.php         Session wrapper
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── CSRFMiddleware.php
│   ├── Models/                 Thin data-access objects; each wraps PDO queries for one table
│   │   ├── DiagramNode.php
│   │   ├── Document.php
│   │   ├── HLWorkItem.php
│   │   ├── Organisation.php
│   │   ├── Project.php
│   │   ├── Risk.php                ← Phase 1
│   │   ├── RiskItemLink.php        ← Phase 1
│   │   ├── Sprint.php              ← Phase 1
│   │   ├── SprintStory.php         ← Phase 1
│   │   ├── StrategyDiagram.php
│   │   ├── Subscription.php
│   │   ├── User.php
│   │   └── UserStory.php           ← Phase 1
│   └── Services/
│       ├── FileProcessor.php   PDF text extraction (smalot/pdfparser)
│       ├── GeminiService.php   Gemini API HTTP client
│       ├── StripeService.php   Stripe SDK wrapper
│       └── Prompts/
│           ├── SummaryPrompt.php
│           ├── DiagramPrompt.php
│           ├── WorkItemPrompt.php
│           ├── PrioritisationPrompt.php  ← Phase 1: RICE_PROMPT and WSJF_PROMPT
│           ├── RiskPrompt.php            ← Phase 1: GENERATE_PROMPT and MITIGATION_PROMPT
│           ├── UserStoryPrompt.php       ← Phase 1: DECOMPOSE_PROMPT and SIZE_PROMPT
│           └── SprintPrompt.php          ← Phase 1: ALLOCATE_PROMPT
├── templates/
│   ├── layouts/                Master layout files (app shell, public shell)
│   ├── partials/               Reusable view fragments
│   └── *.php                   Page templates
├── database/
│   ├── schema.sql              Canonical schema (applied by Docker on first start)
│   └── seed.sql                Optional seed data
├── docker/
│   ├── nginx/default.conf      Nginx virtual host config
│   └── php/Dockerfile          PHP-FPM image with extensions
├── scripts/
│   └── create-admin.php        CLI tool to bootstrap the first admin user
├── tests/
│   ├── Unit/                   PHPUnit unit tests
│   ├── Integration/            PHPUnit integration tests
│   ├── bootstrap.php           PHPUnit bootstrap
│   └── phpunit.xml             Test suite configuration
├── docker-compose.yml
├── composer.json
└── .env.example
```

---

## Security Model

### Authentication

- Session-based. On successful login, `user_id`, `org_id`, and `role` are written to `$_SESSION`.
- Passwords are hashed with `password_hash()` (bcrypt, PHP default cost).
- Login attempts are rate-limited: the `login_attempts` table records each attempt by IP address. Excessive attempts within a time window block further logins from that IP.
- Sessions are destroyed on logout.

### CSRF Protection

All state-changing POST routes (except the Stripe webhook) include `csrf` in their middleware stack. The `CSRF` class generates a token per session, embedded in forms as `_csrf_token`. The middleware validates it on every POST.

### XSS Prevention

All user-supplied values rendered in templates are escaped with `htmlspecialchars()`. No raw user input is inserted into HTML.

### SQL Injection Prevention

All database queries use PDO prepared statements with bound parameters. No query is built via string concatenation of user input.

### File Upload Safety

- Accepted MIME types are validated server-side (not just by file extension)
- Uploaded files are stored outside the web root with a UUID filename (the original filename is never used as a storage path)
- Maximum upload size is enforced at both the PHP layer (`UPLOAD_MAX_SIZE` env var) and Nginx config

### Stripe Webhook Verification

`WebhookController` uses Stripe's SDK `Webhook::constructEvent()` to verify the `Stripe-Signature` header against `STRIPE_WEBHOOK_SECRET`. Requests that fail signature verification are rejected with a 400 response before any business logic runs.

---

## Multi-Tenancy

All application data is scoped by `org_id`. Every query that reads or writes project data includes an `org_id` condition matched against the value stored in the authenticated session. This prevents one organisation from accessing another's data even if a valid session exists.

The `auth` middleware ensures only authenticated users reach app routes. Controllers additionally verify that the resource being accessed belongs to the session user's `org_id`.

---

## AI Integration Pattern

```
Controller
  └── GeminiService::generate(prompt, content)
        └── POST https://generativelanguage.googleapis.com/...
              Authorization: Bearer GEMINI_API_KEY
              Body: { contents: [{ parts: [{ text: prompt + "\n\n" + content }] }] }
        └── Returns: raw text string

Controller extracts structured data from the text response:
  - Summary: plain text (3 paragraphs)
  - Diagram: Mermaid.js source (stripped of any markdown fences)
  - Work items: JSON array (parsed with json_decode)
  - Prioritisation scores: JSON array of {id, reach, impact, ...} objects
  - Risks: JSON array of {title, description, likelihood, impact, linked_items} objects
  - Risk mitigation: plain text (2–3 sentences)
  - User stories: JSON array of {title, description, size} objects
  - Story size: JSON object {size, reasoning}
  - Sprint allocation: JSON array of {story_id, sprint_id} pairs
```

Prompt constants live in `src/Services/Prompts/`. They are pure PHP string constants — no templating library. Prompts that require dynamic values use `{placeholder}` tokens replaced via `str_replace()` before sending (e.g. `DESCRIPTION_PROMPT` uses `{title}`, `{context}`, `{summary}`; `MITIGATION_PROMPT` uses `{title}`, `{description}`, `{likelihood}`, `{impact}`, `{linked_items}`; `SIZE_PROMPT` uses `{title}`, `{description}`; `ALLOCATE_PROMPT` uses `{sprints}`, `{stories}`).

See [GEMINI_PROMPTS.md](GEMINI_PROMPTS.md) for full prompt text and tuning notes.
