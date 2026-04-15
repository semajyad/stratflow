# Security Policy

## Supported Versions

Only the current production release receives security patches.

| Version | Supported |
|---------|-----------|
| latest (main) | Yes |
| older releases | No |

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

### Option 1 — GitHub Private Vulnerability Reporting (preferred)

Use GitHub's built-in private reporting:
[Report a vulnerability](../../security/advisories/new)

This creates a private advisory visible only to maintainers. You will receive
a response within 48 hours.

### Option 2 — Email

Send details to **security@stratflow.io** with the subject line:
`[SECURITY] <brief description>`

Encrypt sensitive reports using our PGP key (available on request).

## Response Timeline

| Stage | Timeline |
|---|---|
| Acknowledgement | Within 48 hours of receipt |
| Initial assessment | Within 5 business days |
| Patch for critical/high | Within 7 days of confirmation |
| Patch for medium | Within 30 days of confirmation |
| Patch for low | Next scheduled release |
| Public disclosure | After patch is deployed |

## What Counts as a Security Vulnerability

Reports are in scope if they involve:

- **Authentication bypass** — accessing resources without valid credentials
- **SQL injection** — unsanitised user input reaching database queries
- **Cross-site scripting (XSS)** — injecting scripts into pages served to other users
- **Insecure direct object reference (IDOR)** — accessing another user's data by manipulating IDs
- **Privilege escalation** — gaining permissions beyond those granted to your account
- **Secrets exposure** — API keys, tokens, or credentials leaked in responses or logs
- **Server-side request forgery (SSRF)** — causing the server to make unintended requests
- **Remote code execution (RCE)** — executing arbitrary code on the server

Out of scope: rate limiting on non-sensitive endpoints, clickjacking without
demonstrated impact, social engineering, physical attacks, issues in
third-party services.

## Safe Harbor

We treat good-faith security research under coordinated disclosure as
authorised. We will not pursue legal action against researchers who:

- Report via the channels above before public disclosure
- Avoid accessing, modifying, or deleting user data beyond what is needed to
  demonstrate the vulnerability
- Do not perform denial-of-service attacks or disrupt production systems
- Give us reasonable time to patch before any public disclosure

## Disclosure Policy

We ask that you do not publicly disclose vulnerability details until we have
released a fix. Once a patch is deployed, we are happy to acknowledge your
contribution in the release notes (with your permission).
