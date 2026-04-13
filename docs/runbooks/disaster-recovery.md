# Disaster Recovery Runbook

## RTO / RPO Targets

| Metric | Target |
|--------|--------|
| Recovery Point Objective (RPO) | 24 hours |
| Recovery Time Objective (RTO) | 4 hours |
| Backup frequency | Daily (Railway MySQL plugin / managed prod DB snapshots) |
| Backup retention | 30 days |

## Restore Procedure

### Step 1 — Declare the incident
Notify the team that a DR restore is underway. No code changes during restore.

### Step 2 — Get the backup
**Railway MySQL (test env):**
```
# In Railway dashboard → your MySQL service → Backups tab
# Download the most recent snapshot .sql.gz file
```

**Managed production DB (once prod environment is live):**
Follow the platform-specific snapshot restore procedure documented in `docs/adr/0001-production-platform.md`.

### Step 3 — Restore to a new DB instance
```bash
# Create a fresh DB instance on the chosen platform
# Restore the dump:
gunzip backup.sql.gz
mysql -h <host> -u <user> -p<pass> <dbname> < backup.sql

# Run any pending migrations:
for f in database/migrations/*.sql; do
  mysql -h <host> -u <user> -p<pass> <dbname> < "$f" 2>/dev/null || true
done
```

### Step 4 — Verify the restore
```bash
php bin/verify_backup.php
# Set BACKUP_DB_HOST etc. to point at the newly restored instance
```

Expected output: `All checks passed.`

### Step 5 — Update the application
Update the environment variables in your Railway / prod platform dashboard:
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- Redeploy the application service

### Step 6 — Smoke test
1. Visit `/healthz` — should return `{"status":"ok"}`
2. Log in as a test user
3. Verify a project and its stories are present

### Step 7 — Notify stakeholders
Send an update using the incident comms template in `incident-response.md`.

## What We Can Lose (RPO Impact)

Data written in the last 24 hours before the backup point may be lost. At restore time:
- Stories/items created today → re-create manually or from Jira sync
- Audit logs for that window → unavailable (check `audit-fallback.jsonl` for partial coverage)
- Stripe events → recoverable from Stripe dashboard

## Data We Never Lose

- Stripe billing state (source of truth is Stripe, not our DB)
- GitHub integrations (re-authorise via OAuth)
- User passwords (hashed — reset flow available)
