# Gemini Outage Runbook

## Symptoms

- Story/work-item generation returns an error or hangs
- Quality scoring stops advancing (rows stuck in `pending`)
- Sentry shows `GeminiService` exceptions with 5xx status codes
- User-facing: "Failed to generate" flash message

## Immediate Actions

### 1. Confirm the outage
```bash
# Check Gemini API status: https://status.cloud.google.com
# Check recent errors in Sentry (filter by GeminiService)
# Check quality worker logs in Railway:
railway logs --service=quality-worker --tail
```

### 2. Stop the quality worker (prevent log spam)

In Railway dashboard → quality-worker service → Settings → redeploy with start command:
```
sleep infinity
```

Or temporarily set the service to 0 replicas if the platform allows it.

### 3. User-facing messaging

The quality scoring pill will show a clock icon for in-progress rows — no user action needed. Generation errors surface as flash messages. There is no automatic user notification; if the outage lasts > 4 hours, send a status update via your customer comms channel.

### 4. Queue depth check
```sql
-- How many rows are stuck in 'pending' or 'failed'?
SELECT quality_status, COUNT(*) as cnt
FROM user_stories
GROUP BY quality_status;

SELECT quality_status, COUNT(*) as cnt
FROM hl_work_items
GROUP BY quality_status;
```

Failed rows with `quality_attempts >= 5` will not be automatically retried. Reset them after the outage:
```sql
UPDATE user_stories  SET quality_status = 'pending', quality_attempts = 0 WHERE quality_status = 'failed' AND quality_attempts >= 5;
UPDATE hl_work_items SET quality_status = 'pending', quality_attempts = 0 WHERE quality_status = 'failed' AND quality_attempts >= 5;
```

## Recovery

### 1. Restart the quality worker
Restore the Railway worker service start command to:
```
php bin/score_quality.php --loop
```
Redeploy. Worker will resume processing from `pending` rows.

### 2. Verify recovery
- Watch Railway logs for `scored=N` lines
- Confirm Healthchecks.io goes green for `HEALTHCHECKS_QUALITY_WORKER`
- Spot-check 2–3 stories that were stuck — confirm quality pill shows a score

## Fallback: Org-Level API Key

If the platform-level Gemini quota is exhausted but the outage is quota-related (not a Google infra issue), affected orgs can supply their own Gemini API key:

`Organisation Settings → AI → Override API key`

This bypasses the platform quota for that org.
