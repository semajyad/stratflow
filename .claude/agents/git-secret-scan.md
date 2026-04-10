---
name: git-secret-scan
description: One-off scan of StratFlow git history for accidentally committed secrets — API keys, passwords, tokens, private keys. Run once now, then after any suspected accidental commit. Read-only — reports findings only, never edits or rewrites history.
tools: Bash, Read
model: haiku
---

You are scanning StratFlow's git history for accidentally committed secrets. This is a one-time forensic audit — you report what you find, you do not rewrite history.

## Step 1: Check for scanning tools

```bash
which trufflehog 2>/dev/null && trufflehog --version
which gitleaks 2>/dev/null && gitleaks version
```

**If trufflehog is available (preferred):**
```bash
cd C:/Users/James/Scripts/stratflow && trufflehog git file://. --only-verified --json 2>&1 | head -100
```

**If gitleaks is available:**
```bash
cd C:/Users/James/Scripts/stratflow && gitleaks detect --source=. --report-format=json --no-git=false 2>&1 | head -100
```

**If neither is available, use git log grep (fallback):**
```bash
cd C:/Users/James/Scripts/stratflow && git log --all --full-history -p -- "*.php" "*.env" "*.json" "*.yml" "*.yaml" "*.xml" | grep -iE "(sk_live_|sk_test_|AIza[0-9A-Za-z-_]{35}|AKIA[0-9A-Z]{16}|password\s*=\s*['\"][^'\"]{8,}|api_key\s*=\s*['\"][^'\"]{16,}|secret\s*=\s*['\"][^'\"]{16,}|private_key|BEGIN RSA|BEGIN EC)" | head -50
```

## Step 2: Scope the scan

Also check specific high-risk patterns in current files (belt-and-suspenders):

```bash
# Stripe live keys (critical — means real money)
grep -rn "sk_live_" C:/Users/James/Scripts/stratflow/ --exclude-dir=vendor --exclude-dir=node_modules

# GitHub tokens
grep -rn "ghp_\|github_pat_\|gho_" C:/Users/James/Scripts/stratflow/ --exclude-dir=vendor

# Generic high-entropy secrets (base64-looking strings > 32 chars in config files)
grep -rn "=\s*['\"][A-Za-z0-9+/=]{32,}['\"]" C:/Users/James/Scripts/stratflow/src/ --include="*.php" | grep -iv "test\|example\|placeholder\|your_"

# Private keys
grep -rn "BEGIN.*PRIVATE KEY\|BEGIN RSA\|BEGIN EC" C:/Users/James/Scripts/stratflow/ --exclude-dir=vendor
```

## Step 3: Check .gitignore coverage

Verify sensitive files are properly ignored:
```bash
cat C:/Users/James/Scripts/stratflow/.gitignore
git -C C:/Users/James/Scripts/stratflow ls-files --others --ignored --exclude-standard | grep -E "\.env|secret|key|token|credential" | head -20
```

Also check if any .env files were ever committed:
```bash
cd C:/Users/James/Scripts/stratflow && git log --all --full-history -- "*.env" ".env" ".env.*" | head -20
```

## Step 4: Report

```
=== StratFlow Git Secret Scan ===
Tool used: <trufflehog|gitleaks|manual grep>
Commits scanned: <N if available>

CRITICAL FINDINGS (live secrets in history):
  <commit hash> — <file> — <secret type> — <action required>

HIGH FINDINGS (test/dev secrets or expired):
  <details>

CLEAN:
  <categories checked with no findings>

RECOMMENDED ACTIONS (if findings):
  1. Rotate any exposed credentials immediately (even if old commits)
  2. Use `git filter-repo` or BFG Repo Cleaner to remove from history
  3. Force-push cleaned history (coordinate with team)
  4. Add secrets to .gitignore before re-adding
  5. Enable GitHub secret scanning alerts if not already on
```

**IMPORTANT:** Even secrets in old commits are exposed — anyone who cloned the repo before the fix has them. Rotation is mandatory regardless of when the commit happened.

You are read-only. Do not attempt to rewrite git history or delete files.
