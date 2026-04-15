## What

<!-- One sentence: what does this PR do? -->

## Why

<!-- Why is this change needed? Link to issue if applicable. -->

## Security notes

<!--
Required if this PR touches: auth, sessions, permissions, billing, webhooks,
file uploads, external APIs, HTTP headers, middleware, or any new stored data.
Delete this section only for pure refactors, tests, docs, or config changes.

Answer whichever of these apply:
- What data does this touch, and who should be able to access it?
- What is the worst-case abuse of this feature by a malicious user or outsider?
- What existing controls cover it (CSRF, auth middleware, prepared statements,
  output escaping, rate limiting) and is anything left unguarded?
-->

## Test plan

<!-- How was this tested? CI covers the basics — note anything manual. -->

## Checklist

- [ ] CI passes (Tests + E2E fast required; full suite runs post-merge)
- [ ] No secrets, hardcoded credentials, or debug statements
- [ ] New `src/**/*.php` files have a matching test, or PR has `no-test-required` label
- [ ] Security notes filled in, or section deleted for non-security changes
