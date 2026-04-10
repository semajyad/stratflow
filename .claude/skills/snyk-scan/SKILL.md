---
name: snyk-scan
description: Run Snyk vulnerability scan on StratFlow dependencies and code. Catches CVEs in Composer packages, known vulnerable code patterns, and license issues. Requires Snyk CLI installed and authenticated. Use when doing a security review or before a client demo.
---

# Snyk Security Scan

Runs a Snyk scan on StratFlow. Seven-phase workflow: check → scan → analyze → summarise → fix options → validate → report.

## Phase 1: Check Snyk is available

```bash
snyk --version 2>&1
```

If not installed:
```bash
npm install -g snyk
snyk auth
```

Then re-run. If auth fails, tell user to run `snyk auth` manually and paste the browser token.

## Phase 2: Composer (PHP) dependency scan

```bash
cd C:/Users/James/Scripts/stratflow && snyk test --package-manager=composer --json 2>&1 | head -200
```

Parse output for:
- `vulnerabilities` array — count by severity (critical/high/medium/low)
- `packageName`, `CVSSv3`, `title`, `fixedIn` for each HIGH/CRITICAL

## Phase 3: Code scan (SAST)

```bash
cd C:/Users/James/Scripts/stratflow && snyk code test --json 2>&1 | head -200
```

Focus on HIGH severity findings. Snyk Code detects:
- SQL injection patterns
- XSS sinks
- Path traversal
- Hardcoded secrets
- Insecure crypto

## Phase 4: Analyze findings

Group by:
1. **Exploitable now** — public CVE, no auth required, affects StratFlow's usage pattern
2. **Likely exploitable** — CVE exists, requires auth or specific conditions
3. **Theoretical** — low CVSS, requires unusual conditions

## Phase 5: Fix options

For each HIGH/CRITICAL dependency vulnerability:
```bash
cd C:/Users/James/Scripts/stratflow && snyk wizard 2>&1
```
Or manually: `composer update <package>` for the minimum fix version shown in `fixedIn`.

For code issues: Snyk suggests inline fix — review before applying.

## Phase 6: Validate fixes

```bash
cd C:/Users/James/Scripts/stratflow && snyk test --package-manager=composer 2>&1 | tail -10
```

Confirm vulnerability count dropped.

## Phase 7: Report

Post summary to ntfy:
```bash
curl -s -X POST http://localhost:8090/james_homelab_alerts_2026 \
  -H "Title: Snyk Scan — StratFlow" \
  -H "Priority: <high if critical/high vulns, low if clean>" \
  -H "Tags: shield,package" \
  -d "Snyk scan complete.
Dependency vulns: CRITICAL=<N> HIGH=<N> MEDIUM=<N>
Code issues: HIGH=<N> MEDIUM=<N>
<Top 3 issues with package name and CVE ID>
<'All clean' if nothing found>"
```

Print full summary to terminal including:
- Total packages scanned
- Vulnerability breakdown
- Top findings with CVE IDs and fix versions
- Snyk score if available
