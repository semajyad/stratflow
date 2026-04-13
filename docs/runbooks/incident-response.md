# Incident Response Runbook

## Severity Levels

| Level | Criteria | Target Response |
|-------|----------|-----------------|
| P0 — Critical | Production down, data breach suspected, authentication broken | Immediate (< 15 min) |
| P1 — High | Feature unavailable for all users, data integrity concern | < 1 hour |
| P2 — Medium | Feature degraded, single-tenant impact | < 4 hours |
| P3 — Low | Cosmetic issue, single user impact | Next business day |

## Immediate Actions (P0/P1)

1. **Assess blast radius** — how many orgs/users affected? Check Sentry for error spike.
2. **Notify stakeholders** — send initial update within 15 min using the template below.
3. **Isolate if needed** — if breach suspected, disable affected org via `UPDATE organisations SET is_active = 0 WHERE id = ?` and kill active sessions: `DELETE FROM sessions`.
4. **Preserve evidence** — export audit_logs for the affected org before any remediation changes.
5. **Fix or roll back** — revert via Railway deployment if a recent deploy caused the issue.
6. **Verify fix** — confirm `/healthz` returns 200 and Sentry error rate returns to baseline.
7. **Post-incident review** — write a blameless PIR within 48 hours.

## Comms Template (Initial Update)

```
Subject: [StratFlow] Service Incident — [DATE TIME UTC]

We are investigating an issue affecting [description].
Impact: [who/what is affected]
Status: Investigating / Mitigating / Resolved
Next update: [TIME UTC]

— StratFlow Engineering
```

## Key Contacts

| Role | Contact |
|------|---------|
| On-call engineer | [Add contact] |
| Customer success | [Add contact] |
| Legal / DPA | [Add contact] |

## Post-Incident Review Template

- **Incident summary**: what happened
- **Timeline**: key events with timestamps (UTC)
- **Root cause**: technical root cause
- **Detection**: how/when was it detected
- **Impact**: orgs affected, data at risk, duration
- **Remediation**: what was done to fix it
- **Action items**: what prevents recurrence (each with an owner + due date)

## Security Incidents (Suspected Breach)

Additional steps for data breach / unauthorised access:

1. Immediately isolate affected tenant (see step 3 above).
2. Export and preserve `audit_logs` — do not modify.
3. Contact legal within 1 hour.
4. GDPR requires notifying the DPA within 72 hours if personal data is affected.
5. Notify affected users as soon as legally safe to do so.
6. Rotate `TOKEN_ENCRYPTION_KEYS` and `AUDIT_HMAC_KEY` using `bin/rotate_secrets.php`.
