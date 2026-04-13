# CLAUDE.md - StratFlow

## Startup

Start with `MEMORY.md`.
Only open the specific docs linked from there that match the task.
Do not read the whole repo documentation set by default.
If the task touches auth, permissions, sessions, secrets, uploads, billing, webhooks, external providers, HTTP headers, middleware, controllers, or templates, also read `docs/SECURE_CODING.md`.

## Context Discipline

- Prefer the smallest relevant context slice.
- Read one targeted doc before several broad docs.
- For codebase work, use indexed/project search tools before manual repo-wide scans.
- If a task spans more than 3 files, plan first and keep the plan short.
- If the same fix attempt fails 3 times, stop and rescope instead of thrashing.
- Before finishing, prefer the simpler solution if it reduces code and context size safely.

## Project Rules

- Keep all tenant-scoped queries filtered by `org_id`.
- Use prepared statements only; never concatenate SQL.
- Escape all user-visible template output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Keep CSRF protection on every state-changing browser route except verified webhooks.
- Never log secrets, password-reset URLs, raw tokens, or provider credentials.
- Hash recovery tokens at rest and encrypt stored provider/customer secrets.
- Deny inactive users on every auth path and fail closed on unclear authorization checks.
- Prefer delegated JS in shared bundles; do not introduce inline handlers or new CSP regressions.
- Preserve the existing vanilla PHP MVC structure and current UI patterns unless the task explicitly changes them.

## Security Rules (sourced from ZAP scans 2026-04-12 and 2026-04-13)

These rules are non-negotiable. Every one maps to a real ZAP finding. Violating them
will re-introduce a recorded vulnerability.

### HTTP Security Headers (ZAP-10038, ZAP-10035, ZAP-10036, ZAP-10037, ZAP-10049, ZAP-10096, ZAP-10021)

- **Every response path** — render, redirect, json, download, AND all early-exit paths
  (middleware rejections, error handlers, fatal shutdown, DB failure) — MUST call
  `Response::applySecurityHeaders()` before writing any output.
- **Never** write `http_response_code(N); echo ...` without calling
  `Response::applySecurityHeaders()` first. This is the root cause of ZAP-10038 on
  the `/checkout` CSRF rejection path.
- `applySecurityHeaders()` sets: `X-Content-Type-Options`, `X-Frame-Options`,
  `X-XSS-Protection`, `Permissions-Policy`, `CSP`, `COEP`, `COOP`, `CORP`, `HSTS`.
  Do not remove or weaken any of these.
- HSTS is always sent when `APP_URL` starts with `https://` — do not gate it only on
  `$_SERVER['HTTPS']` (Railway's proxy strips this header).

### Content Security Policy (ZAP-10055 script-src unsafe-inline, ZAP-10055 style-src unsafe-inline)

- **Never** add `'unsafe-inline'` to `script-src`. All JS must be in bundled files
  under `/assets/js/`. No inline `<script>` blocks, no `onclick=`, no `javascript:` URLs.
- `style-src 'unsafe-inline'` is allowed only in the `'app'` CSP profile (existing
  permission for Tailwind-style dynamic classes). Do not extend this to the `'public'` profile.
- External scripts (CDN/third-party) MUST include `integrity="sha384-..."` and
  `crossorigin="anonymous"` (Sub-Resource Integrity — ZAP-10055 SRI finding). The only
  allowed external script host is `https://cdn.jsdelivr.net` (already in `script-src`).

### Asset Cache-Busting (ZAP-10096 Timestamp Disclosure)

- **Never** use `filemtime()`, `time()`, `microtime()`, or any Unix timestamp as an
  asset query-string version parameter in templates. Use the `ASSET_VERSION` constant.
- `ASSET_VERSION` is defined in `public/index.php` from `config.app.asset_version`,
  which resolves to the `ASSET_VERSION` env var (set at deploy) or the git commit short hash.

### Server Information Leakage (ZAP-10037 X-Powered-By)

- `header_remove('X-Powered-By')` is already in `applySecurityHeaders()` and
  `public/index.php`. Never undo this. Never add `expose_php = On` anywhere.
- Do not expose PHP version, framework version, or server software in any response body,
  header, or error page.

### Cross-Origin Policies (ZAP-10049 COEP, ZAP-10049 CORP)

- `Cross-Origin-Embedder-Policy: require-corp` and `Cross-Origin-Resource-Policy: same-origin`
  are set in `applySecurityHeaders()`. Do not relax them without a documented exception.
- If a new endpoint needs to serve assets to cross-origin consumers, add a targeted
  `header('Cross-Origin-Resource-Policy: cross-origin')` only for that endpoint — never
  weaken the default.

### Template Output / XSS (ZAP-10031 User-Controllable HTML Attribute)

- Every value echoed into an HTML attribute or tag body — including CSRF tokens
  reflected back into `<input value="">` — must be wrapped with
  `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`. No exceptions.
- CSRF tokens use `random_bytes(32)` → `bin2hex()` (hex string). Never use base64 for
  tokens (ZAP-10094 Base64 Disclosure — base64 is more easily mistaken for encoded data).

### Caching (ZAP-10049 Storable/Cacheable Content)

- Authenticated responses use `Cache-Control: no-store, no-cache, must-revalidate, private`
  (already set in `applySecurityHeaders()`). Never override this with a permissive
  cache header on an authenticated route.
- Static assets under `/assets/` use `Cache-Control: public, max-age=86400` — this is
  intentional and correct. Do not apply `no-store` to static files.

### Binary Files and Git (no ZAP ID — binary corruption root cause)

- All binary assets committed to this repo MUST have `binary` in `.gitattributes`.
  Currently covered: `*.png *.jpg *.jpeg *.gif *.webp *.ico *.woff *.woff2 *.ttf *.eot *.pdf`.
- When adding a new binary format, add it to `.gitattributes` in the same commit.
- Use SVG for logos and icons — SVG is XML text, immune to CRLF corruption.

### Security Regression Test Checklist

Before committing any change to `src/`, `templates/`, or `public/`:
1. Does every new response path (including error branches) call `applySecurityHeaders()`?
2. Does any new template output use `echo $var` without `htmlspecialchars()`?
3. Does any new external script lack an `integrity` attribute?
4. Does any new template use `filemtime()` or a timestamp as a cache-buster?
5. Does any new binary file need adding to `.gitattributes`?

## Morning Security Check

**This rule applies to the first code session each calendar day.**

Before starting any feature, bug fix, or refactor work:

1. Read `security-reports/shannon-latest.md`.
   - Check the `<!-- Shannon run: ... -->` comment at the top to see how fresh it is.
   - If the report is from overnight (within the last ~18 hours), proceed to step 2.
   - If it is stale (>24 hours old), note it but continue — Shannon may not have run yet.

2. Triage findings by severity:
   - **HIGH** — must be fixed before any other work starts. Create a task for each.
   - **MEDIUM** — must be fixed the same day. Create a task for each.
   - **LOW / INFORMATIONAL** — log them but may be deferred; do not defer more than 2 consecutive days.

3. Fix all HIGH and MEDIUM issues first, following the same coding rules in this file.
   Commit fixes before proceeding to other work.

4. Read `security-reports/zap-latest.md`. Address any new findings not already
   captured in the `## Security Rules` section below.

5. **Promote findings to permanent rules.**
   After fixing a HIGH or MEDIUM finding from Shannon or ZAP:
   - Add a new sub-section under `## Security Rules` in this file documenting:
     - The rule (what must/must not be done)
     - The tool and finding ID that discovered it (e.g. `Shannon 2026-04-15 HIGH #3`)
     - Why (the attack vector or root cause)
   - Format matches the existing ZAP rules above — short heading, one-line rule, one-line why.
   - This ensures every pen-test finding becomes a permanent guardrail for all future sessions.

**Do not skip this step because "there's more important work."
Security regressions discovered by pen testing compound if deferred.**

## Commands

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit
docker compose exec php php -l path/to/file.php
docker compose logs -f php
```

## Routing Docs

- Architecture: `docs/ARCHITECTURE.md`
- Database: `docs/DATABASE.md`
- Testing: `docs/TESTING.md`
- Deployment: `docs/DEPLOYMENT.md`
- Roles and access flags: `docs/USER_ROLES_GUIDE.md`
- AI prompt constants: `docs/GEMINI_PROMPTS.md`

## Local Overrides

Use `CLAUDE.local.md` for machine-specific or temporary personal instructions.
Keep repo-wide guidance here lean so it stays cheap to load.
