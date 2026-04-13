# Secure Coding Rules

Read this only when the task touches auth, sessions, secrets, permissions, uploads, billing, webhooks, or user input.

## Non-Negotiables

- Treat all data as tenant-scoped. Every read and write must preserve `org_id` boundaries.
- Use prepared statements only. Never concatenate SQL.
- Escape all user-visible template output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Keep CSRF protection on every state-changing browser route. Only verified machine-to-machine webhooks are exempt.
- Never log secrets, provider credentials, raw tokens, password-reset URLs, signed links, or API responses containing them.

## Auth And Sessions

- Store password-reset and set-password tokens hashed at rest, never plaintext.
- Deny inactive users on every auth path: browser login, API tokens, background impersonation, and admin actions.
- Secure cookies must stay secure behind proxies/load balancers. Do not rely only on `$_SERVER['HTTPS']`.
- Default to least privilege. If a permission check is unclear, fail closed.

## Secrets And Integrations

- Encrypt third-party tokens and customer-managed API keys at rest.
- Do not add new plaintext secret storage in JSON blobs, settings fields, or logs.
- Avoid sending customer content to external AI/providers unless the feature is explicitly enabled and documented.

## Frontend And Request Handling

- Prefer delegated JS in shared bundles over inline handlers or page-local script blocks.
- New UI should not require weaker CSP. Avoid inline `<script>`, inline event handlers, and unnecessary inline styles.
- Validate and normalize all request input on the server, even if the UI already restricts it.

## HTTP Security Headers (enforced by Response::applySecurityHeaders)

Every response — render, redirect, json, download, AND error paths — MUST call
`Response::applySecurityHeaders()`. Middleware that rejects early (auth, CSRF) must
also call it before writing any output. Failing to do so will leave CSP, HSTS,
Permissions-Policy, and other headers unset (ZAP-10038, ZAP-10035, ZAP-10096).

Rules:
- **Never** write `http_response_code(...)` + `echo` without calling `applySecurityHeaders()` first.
- **Never** use `filemtime()` or Unix timestamps in HTML output (ZAP-10096 timestamp disclosure). Use `ASSET_VERSION` constant instead.
- HSTS is always sent when `APP_URL` starts with `https://` — never gate it only on `$_SERVER['HTTPS']` (Railway proxy strips this).
- CSP profile `'public'` for unauthenticated pages; `'app'` for authenticated pages and error responses.

## Asset Versioning

- Use `ASSET_VERSION` constant (defined in `public/index.php` from `config.app.asset_version`).
- `ASSET_VERSION` resolves to the `ASSET_VERSION` env var (set at deploy) or the git commit short hash — never a timestamp.
- **Never** use `@filemtime(...)` or `time()` as a cache-buster in templates.

## Binary Files And Git

- All binary assets (png, jpg, webp, gif, ico, woff, pdf) **must** have `binary` attribute in `.gitattributes`.
- Omitting the `binary` attribute with `core.autocrlf=true` on Windows corrupts binary files committed from Windows.
- SVG is text — use it for logos and icons so corruption is impossible.

## Morning Security Check

**This rule applies to the first code session each calendar day.**

1. Read `security-reports/shannon-latest.md`. Check the `<!-- Shannon run: ... -->` comment.
   - Overnight report (≤18 h old) → proceed to step 2.
   - Stale (>24 h) → note it, continue — Shannon may not have run yet.
2. Triage: **HIGH** = fix before other work. **MEDIUM** = fix same day. **LOW** = may defer ≤2 days.
3. Fix HIGH/MEDIUM issues first. Commit fixes before other work.
4. Read `security-reports/zap-latest.md`. Address any new findings.
5. After fixing a HIGH/MEDIUM finding, add a permanent rule sub-section below (tool + finding ID + why).

**Do not skip because "there's more important work." Regressions compound if deferred.**

Similarly, check `tests/performance/summary-latest.json` for k6 p95 > 800ms or error-rate > 1%.
Address any perf threshold breaches on the same day they appear.

## ZAP-Sourced Rules (2026-04-12/13)

### HTTP Security Headers (ZAP-10038, ZAP-10035, ZAP-10036, ZAP-10037, ZAP-10049, ZAP-10096, ZAP-10021)

- Every response path incl. middleware rejections, error handlers, fatal shutdown → call `Response::applySecurityHeaders()` first.
- Root cause of ZAP-10038: `/checkout` CSRF rejection wrote output before calling `applySecurityHeaders()`.
- HSTS: send when `APP_URL` starts with `https://` — do not gate on `$_SERVER['HTTPS']` (Railway proxy strips it).

### CSP (ZAP-10055)

- Never `'unsafe-inline'` in `script-src`. All JS in `/assets/js/` bundles.
- `style-src 'unsafe-inline'` allowed only in `'app'` CSP profile. Do not extend to `'public'`.
- External scripts MUST have `integrity="sha384-..."` + `crossorigin="anonymous"` (SRI). Only `cdn.jsdelivr.net` is allowed.

### XSS (ZAP-10031)

- Every echo into HTML attribute or tag body → `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`. No exceptions.
- CSRF tokens: `random_bytes(32)` → `bin2hex()`. Never base64 (ZAP-10094 Base64 Disclosure).

### Caching (ZAP-10049)

- Authenticated: `Cache-Control: no-store, no-cache, must-revalidate, private`. Never override.
- Static `/assets/`: `Cache-Control: public, max-age=86400`. Do not apply `no-store`.

### Server Leakage (ZAP-10037)

- `header_remove('X-Powered-By')` already in `applySecurityHeaders()`. Never undo.
- Never expose PHP version, framework version, or server software in any response.

## Review Triggers

Before merging, re-check these risk areas:
- authn/authz and role/capability enforcement
- tenant isolation and `org_id` scoping
- secret/token exposure
- CSRF/session/cookie behavior
- webhook trust boundaries
- file upload and external-provider data flow
- **every new early-exit code path calls `Response::applySecurityHeaders()`**
- **no new `filemtime()` or timestamp values in HTML output**
- **new binary files added to `.gitattributes`**
