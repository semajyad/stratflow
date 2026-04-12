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

## Review Triggers

Before merging, re-check these risk areas:
- authn/authz and role/capability enforcement
- tenant isolation and `org_id` scoping
- secret/token exposure
- CSRF/session/cookie behavior
- webhook trust boundaries
- file upload and external-provider data flow
