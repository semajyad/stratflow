# StratFlow Security Assessment Report

**Classification:** Confidential -- For recipient organisation only  
**Prepared by:** StratFlow Engineering Team  
**Assessment Date:** 2026-04-10  
**Codebase Commit:** `936f14f2c585e2cd96f808c28026f8a11b56d875`  
**Report Version:** 1.0  
**Next Review:** 2026-07-10  

---

## 1. Executive Summary

StratFlow is an AI-powered, multi-tenant SaaS platform that converts strategic plans into prioritised work items, user stories, and sprint plans. The platform integrates with Stripe for subscription billing, GitHub/GitLab for repository intelligence, Jira for issue synchronisation, and the Gemini API for AI generation. All tenant data is isolated by `org_id` enforced at the query layer.

This report documents security controls assessed against OWASP Top 10:2025 and industry best practices for multi-tenant SaaS applications. The assessment covers static analysis of the PHP 8.4 source tree, route and middleware configuration, dependency manifest CVE scanning, and review of security-sensitive controllers.

**Overall Posture:** ADEQUATE -- Strong foundational controls with targeted gaps requiring remediation before enterprise procurement sign-off.

| Category | Status |
|---|---|
| Access Control | PASS |
| Cryptography | PASS |
| Injection Prevention | PASS |
| Authentication | PASS |
| Dependency Security | PASS |
| Logging and Audit Trail | PASS |
| Data Isolation (Multi-Tenant) | PASS |
| CSRF Protection | PARTIAL -- 12 authenticated POST routes lack CSRF tokens |
| Security Headers | PARTIAL -- CSP uses `unsafe-inline` for scripts |

---

## 2. Scope and Methodology

**In scope:**
- PHP 8.4 application source code (`src/`)
- Route and middleware configuration (`src/Config/routes.php`)
- Composer dependency manifest and lock file
- Infrastructure configuration (Docker, Nginx, `php.ini`)
- Webhook handler controllers

**Out of scope:**
- Third-party subprocessor security (Stripe, Gemini API, Railway, Xero)
- Physical infrastructure (managed by Railway)
- Social engineering and phishing vectors
- Live penetration testing against deployed environment

**Methodology:** Automated static analysis via grep pattern matching across the source tree; manual code review of security-sensitive controllers (`AuthController`, `WebhookController`, `GitWebhookController`, `IntegrationController`); dependency CVE scanning via `composer audit`; route enumeration for middleware coverage gaps.

---

## 3. Findings

### 3.1 Critical Findings

No critical findings identified.

No hardcoded production secrets, private keys committed to the repository, or unauthenticated access to sensitive data endpoints were found.

### 3.2 High Severity

No high severity findings identified.

The `composer audit` scan returned zero advisories across all production dependencies. Password storage uses `PASSWORD_DEFAULT` (bcrypt). All webhook endpoints verify cryptographic signatures before processing payloads.

### 3.3 Medium Severity

**MEDIUM-01: Twelve authenticated POST routes lack CSRF middleware**

The following authenticated POST routes carry no `csrf` middleware token and are susceptible to cross-site request forgery if an attacker can direct an authenticated user to a crafted page:

| Route | Controller Action |
|---|---|
| `POST /app/prioritisation/scores` | `PrioritisationController@saveScores` |
| `POST /app/prioritisation/ai-baseline` | `PrioritisationController@aiBaseline` |
| `POST /app/work-items/reorder` | `WorkItemController@reorder` |
| `POST /app/work-items/{id}/generate-description` | `WorkItemController@generateDescription` |
| `POST /app/risks/{id}/mitigation` | `RiskController@generateMitigation` |
| `POST /app/user-stories/reorder` | `UserStoryController@reorder` |
| `POST /app/user-stories/{id}/suggest-size` | `UserStoryController@suggestSize` |
| `POST /app/sprints/assign` | `SprintController@assignStory` |
| `POST /app/sprints/unassign` | `SprintController@unassignStory` |
| `POST /app/sounding-board/evaluate` | `SoundingBoardController@evaluate` |
| `POST /app/sounding-board/results/{id}/respond` | `SoundingBoardController@respond` |
| `POST /app/jira/sync/preview` | `IntegrationController@syncPreview` |

The majority are AI-trigger or reorder endpoints with limited state impact (no destructive writes, no billing changes). However, the sprint assignment and sounding board respond routes mutate persistent data. All routes require authentication, reducing exploitability, but CSRF protection should be consistent across the authenticated surface.

**Remediation:** Add `csrf` to the middleware array for each listed route in `src/Config/routes.php` and confirm the corresponding front-end requests include the CSRF token in the request body.

**MEDIUM-02: Content-Security-Policy allows `unsafe-inline` for scripts**

The CSP emitted by `src/Core/Response.php` includes `unsafe-inline` in the `script-src` directive. This negates nonce- or hash-based XSS protections and permits injected inline script tags to execute if any XSS vector is present in the application.

**Remediation:** Refactor inline JavaScript into external files and remove `unsafe-inline` from the `script-src` directive. If inline event handlers are unavoidable, adopt a per-request nonce approach.

### 3.4 Low / Informational

**LOW-01: Jira webhook HMAC verification is conditional on secret being configured**

The Jira webhook handler (`IntegrationController::jiraWebhook`) applies HMAC sha256 verification only when `JIRA_WEBHOOK_SECRET` is non-empty. When absent, the handler falls back to structural payload validation, which is insufficient to prevent spoofed payloads from a network adversary.

**Remediation:** Document `JIRA_WEBHOOK_SECRET` as a required production environment variable. Consider hard-rejecting all Jira webhook requests when no secret is configured.

**LOW-02: `htmlspecialchars` output escaping count is low relative to template volume**

Only 8 calls to `htmlspecialchars` were identified across `src/` (excluding vendor). Templates reside in `templates/` which was not fully enumerated in this assessment. If templates output user-controlled data without escaping, XSS vectors may exist.

**Remediation:** Audit all files in `templates/` for unescaped `echo $variable` patterns. Enforce `htmlspecialchars($v, ENT_QUOTES, "UTF-8")` on every user-controlled value before output.

**LOW-03: GitHub App private key present on disk in the project root**

A file named `stratflow1.2026-04-09.private-key.pem` was found in the project root. It is correctly listed in `.gitignore` and is confirmed not tracked by git. A key file co-located with source code is at elevated risk of accidental exposure via archive creation, deployment artefact packaging, or IDE cloud sync.

**Remediation:** Store the GitHub App private key as a Railway secret (environment variable or secret file mount) rather than a file on disk. Rotate the key if the workstation has shared-service access.

**LOW-04: PHP `display_errors` is enabled when `APP_DEBUG=true`**

`public/index.php` enables `display_errors` when the application debug flag is active. If `APP_DEBUG` is accidentally set to `true` in a production or staging environment, PHP stack traces could be rendered to unauthenticated users.

**Remediation:** Confirm via Railway environment variable audit that `APP_DEBUG` is `false` in all non-local environments. Consider additionally gating debug mode on `APP_ENVIRONMENT=local`.

---

## 4. Controls Assessment

### 4.1 Multi-Tenant Data Isolation

All models and controllers consistently scope queries to `org_id` extracted from `$_SESSION["user"]["org_id"]`. The `ExecutiveController` -- which aggregates cross-resource data for executive dashboard views -- was reviewed in detail; every query passes `:oid` as a bound parameter. No raw string interpolation of `org_id` was found anywhere in `src/`. The integer cast applied before binding eliminates type-juggling injection. Assessment: **compliant**.

### 4.2 Authentication and Session Management

- **Password hashing:** `password_hash($password, PASSWORD_DEFAULT)` (bcrypt) is used in `AuthController`, `AdminController`, and `WebhookController`. No use of MD5 or SHA-1 for passwords. Assessment: **compliant**.
- **Password verification:** `password_verify()` is used at the single authentication point in `Core/Auth.php`. Assessment: **compliant**.
- **Session regeneration:** `session_regenerate_id(true)` is called on login and periodically every 15 minutes. Assessment: **compliant**.
- **Session cookie hardening:** `httponly: true`, `secure: true`, `samesite: Lax`, `lifetime: 0` (browser session only), `session.use_strict_mode = 1`, `session.use_only_cookies = 1`. Assessment: **compliant**.
- **Session fingerprinting:** User-Agent and partial-IP fingerprint validated on each request to detect session hijacking. Assessment: **compliant**.
- **Rate limiting:** Login and password reset requests are rate-limited via `Core/RateLimiter`. Password reset is capped at 3 requests per hour per IP. Assessment: **compliant**.
- **MFA / 2FA:** No multi-factor authentication implementation found. Informational gap; recommended for enterprise deployments.

### 4.3 CSRF Protection

CSRF middleware is applied to 82 route definitions. All destructive admin routes (user deletion, team management, billing portal, Jira configuration, GitHub disconnect, superadmin write operations) carry the `csrf` guard. The Stripe webhook endpoint (`POST /webhook/stripe`) is correctly exempted -- Stripe sends raw signed POST requests from its servers; `Webhook::constructEvent` signature verification substitutes for CSRF. Git webhook endpoints are HMAC-verified in the controller. The gap (MEDIUM-01) covers 12 authenticated routes with limited-to-moderate data mutation impact.

### 4.4 SQL Injection Prevention

PDO prepared statements with named parameters are used consistently across all reviewed models and inline queries. No string-concatenated SQL was found in `src/`. Assessment: **no SQL injection vectors identified**.

### 4.5 XSS Prevention

Templates reside in `templates/`. The code style requires `htmlspecialchars($v, ENT_QUOTES, "UTF-8")` on all user output. The low count of `htmlspecialchars` calls in `src/` (8 occurrences) warrants a follow-up template audit (LOW-02). The CSP provides partial mitigation but is weakened by `unsafe-inline` in `script-src` (MEDIUM-02). Log injection in the GitHub webhook handler is mitigated by stripping non-printable ASCII characters from the event header before logging.

### 4.6 Stripe Payment Security

- Raw HTTP body read via `php://input` before framework processing.
- `Webhook::constructEvent($payload, $sigHeader, $webhookSecret)` called with the `HTTP_STRIPE_SIGNATURE` header.
- Webhook secret loaded from `$_ENV["STRIPE_WEBHOOK_SECRET"]` -- not hardcoded.
- Endpoint correctly carries no CSRF middleware, with an explanatory inline comment.

Assessment: **compliant**.

### 4.7 Dependency Vulnerability Management

`composer audit` returned zero advisories and zero abandoned packages against the current lock file. Production dependencies: `stripe/stripe-php ^16.0`, `vlucas/phpdotenv ^5.6`, `smalot/pdfparser ^2.0`. The dev dependency `phpunit/phpunit ^11.0` is not deployed to production. Assessment: **compliant -- zero known CVEs at assessment date**.

### 4.8 Secrets Management

| Check | Result |
|---|---|
| Stripe live key (`sk_live_`) in `src/` | No matches |
| Google API key (`AIza...`) in `src/` | No matches |
| Hardcoded passwords in `src/` | No matches (3 hits are form field reads, not credentials) |
| PEM private keys in git-tracked files | No tracked `.pem` or `.env` files |
| `.env` file in git | Correctly gitignored |

All secrets are loaded from environment variables via `vlucas/phpdotenv`. Assessment: **compliant with LOW-03 noted**.

### 4.9 Security Headers

| Header | Value | Status |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Pass |
| `X-Frame-Options` | `DENY` | Pass |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | Pass |
| `Content-Security-Policy` | Set -- see MEDIUM-02 for `unsafe-inline` gap | Partial |
| `Referrer-Policy` | Not set | Gap |

The `frame-ancestors none` CSP directive correctly reinforces `X-Frame-Options: DENY`. The `form-action` directive restricts form submissions to self and the Stripe checkout domain. A `Referrer-Policy` header is not set; the recommended value is `strict-origin-when-cross-origin`.

### 4.10 Logging and Audit Trail

`AuditLogger` is invoked 64 times across controllers, covering authentication events, user lifecycle (create, update, delete), team operations, billing events, and administrative actions. Audit logs are stored in the database and are exportable by admin users and superadmins via dedicated endpoints. PHP errors are directed to the server error log in production. Fatal errors are captured by a shutdown handler. Log retention policy and external log aggregation (Railway drain, SIEM) are outside the scope of this assessment but should be documented as SOC 2 evidence.

---

## 5. Remediation Roadmap

| ID | Finding | Severity | Estimated Effort | Target Date |
|---|---|---|---|---|
| MEDIUM-01 | Add CSRF middleware to 12 authenticated POST routes | Medium | 2 hours | 2026-04-24 |
| MEDIUM-02 | Remove `unsafe-inline` from CSP `script-src` | Medium | 1-2 days | 2026-05-08 |
| LOW-01 | Require Jira webhook secret; reject requests when unset | Low | 1 hour | 2026-05-01 |
| LOW-02 | Template XSS audit -- verify all user output is escaped | Low | 4 hours | 2026-05-01 |
| LOW-03 | Migrate GitHub App private key to Railway secret | Low | 1 hour | 2026-04-17 |
| LOW-04 | Confirm APP_DEBUG=false in all non-local environments | Low | 30 minutes | 2026-04-17 |
| INFO-01 | Evaluate MFA implementation (TOTP or email OTP) | Informational | 3-5 days | 2026-Q3 |
| INFO-02 | Add `Referrer-Policy: strict-origin-when-cross-origin` header | Informational | 30 minutes | 2026-04-24 |

---

## 6. Limitations and Disclaimers

This is an internal security assessment conducted by StratFlow Engineering. It does not replace:

- Third-party penetration testing by a certified security firm (CREST, OSCP)
- SOC 2 Type II audit by an independent CPA firm
- Formal dynamic application security testing (DAST) against a live environment

**Limitations of this assessment:**

- Static analysis cannot detect all runtime vulnerabilities (e.g., second-order SQL injection via stored data, business logic flaws requiring multi-step authenticated flows)
- Infrastructure security (Railway platform, Cloudflare WAF, MySQL at-rest encryption) was not assessed
- Subprocessor security (Stripe, Gemini API, Jira Cloud, Xero) is governed by those vendors own compliance certifications
- Social engineering and phishing vectors are outside scope
- The `templates/` directory was not fully enumerated for XSS escape coverage; see LOW-02

---

## 7. Document Control

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0 | 2026-04-10 | StratFlow Engineering | Initial security assessment -- commit 936f14f |
