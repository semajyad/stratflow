# StratFlow Incident Response Plan

**Version:** 1.0 | **Effective:** 2026-04-10 | **Review:** 2027-04-10
**Owner:** StratFlow Engineering

## 1. Purpose

Define how StratFlow detects, responds to, contains, and recovers from security incidents affecting customer data or service availability.

## 2. Scope

All StratFlow production systems including Railway-hosted PHP/MySQL application, Stripe payment processing, Gemini AI integration, and customer data stored in MySQL 8.4.

## 3. Incident Classification

| Severity | Definition | Examples | Response Time |
|---|---|---|---|
| P1 — Critical | Data breach or active exploitation | Confirmed customer data exposed, payment system compromise | Immediate — 1 hr |
| P2 — High | Potential breach or significant service impact | Suspected intrusion, >30 min downtime | 4 hours |
| P3 — Medium | Security control failure, minor data issue | Audit log gap, failed auth spike | 24 hours |
| P4 — Low | Informational security event | Single failed login, scanner probe | 72 hours |

## 4. Incident Response Phases

### Phase 1: Detection & Triage (0–1 hour for P1)

- **Sources:** Railway monitoring alerts, Stripe payment alerts, customer report, internal error spike
- Assign incident commander
- Create private incident channel (e.g., Slack `#incident-YYYY-MM-DD`)
- Initial severity classification using table above
- Begin incident log (timestamp every action)

### Phase 2: Containment

**Short-term (within 1 hour for P1):**
- Isolate affected system (Railway: scale service to 0 replicas, disable affected routes)
- Block attacker IP at Railway edge if identified
- Revoke compromised sessions via database flush

**Evidence preservation (before any changes):**
- Export Railway application logs
- Snapshot MySQL slow query log and audit log
- Screenshot Railway metrics dashboard

**Communication:**
- Notify affected customers within 1 hour for confirmed P1 data exposure

### Phase 3: Investigation

- Review application logs, Railway metrics, MySQL audit logs
- Identify entry point, scope of exposure, and affected `org_id` tenants
- Determine what data was accessed (use audit log table)
- Document timeline with evidence and timestamps

### Phase 4: Eradication

- Remove attacker access and patch root vulnerability
- Rotate all compromised credentials:
  - Stripe API keys (revoke in Stripe dashboard, update Railway env vars)
  - Gemini API key (revoke in Google Cloud console, update Railway env vars)
  - PHP session secret key
  - MySQL application user password
- Deploy patched code via Railway (zero-downtime deploy)
- Re-enable service after patching confirmed

### Phase 5: Recovery

- Restore service with enhanced monitoring enabled
- Verify fix effectiveness with targeted tests
- Monitor for 48 hours post-recovery for signs of re-compromise
- Confirm all affected customers can access their data

### Phase 6: Post-Incident Review

- Complete within 5 business days of closure
- Root cause analysis (5 Whys or equivalent)
- Update security controls and documentation
- Update this plan if process gaps found
- Share blameless post-mortem internally; summary to affected clients if P1/P2

## 5. Notification Obligations

| Scenario | Who to notify | Timeline |
|---|---|---|
| Customer data breach | Affected customers + regulatory bodies | Within 72 hours of confirmation |
| Payment data involved | Stripe, affected customers, card networks | Within 24 hours |
| EU personal data (GDPR) | Relevant Data Protection Authority | Within 72 hours (GDPR Article 33) |
| UK personal data (UK GDPR) | ICO | Within 72 hours |

## 6. Key Contacts

- **Incident Commander:** [name/email]
- **Engineering Lead:** [name/email]
- **Customer Communication Lead:** [name/email]
- **Legal / DPO Contact:** [name/email]

## 7. Breach Notification Template

```
Subject: Important Security Notice from StratFlow

Dear [Customer Name],

We are writing to inform you of a security incident that may have affected your account.

What happened: [Brief description]
When it happened: [Date/time range]
What data was involved: [Data types — e.g., account details, strategy documents]
What we have done: [Steps taken to contain and fix]
What you should do: [Recommended customer actions, e.g., change password]

We take the security of your data seriously and sincerely apologise for this incident.

For questions, contact: security@[domain]
```

## 8. Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0 | 2026-04-10 | StratFlow Engineering | Initial release |
