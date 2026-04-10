---
name: security-policy-pack
description: Generates a complete set of security policy documents for StratFlow — Incident Response Plan, Data Retention Policy, Access Control Policy, Vulnerability Disclosure Policy, and Acceptable Use Policy. Seeded with StratFlow's actual architecture. Run once to generate, re-run when architecture changes significantly.
---

# Security Policy Pack Generator

Generates 5 policy documents into `docs/compliance/policies/`. Each is seeded with StratFlow's actual infrastructure — not generic boilerplate. Corporate clients will cross-check policies against your actual controls, so accuracy matters.

## Policies to generate

---

### 1. Incident Response Plan (`incident-response-plan.md`)

```markdown
# StratFlow Incident Response Plan

**Version:** 1.0 | **Effective:** [today] | **Review:** [today + 12 months]
**Owner:** StratFlow Engineering

## 1. Purpose
Define how StratFlow detects, responds to, contains, and recovers from security incidents affecting customer data or service availability.

## 2. Scope
All StratFlow production systems including Railway-hosted PHP/MySQL application, Stripe payment processing, Gemini AI integration, and customer data.

## 3. Incident Classification

| Severity | Definition | Examples | Response Time |
|---|---|---|---|
| P1 — Critical | Data breach or active exploitation | Confirmed customer data exposed, payment system compromise | Immediate — 1hr |
| P2 — High | Potential breach or significant service impact | Suspected intrusion, >30min downtime | 4 hours |
| P3 — Medium | Security control failure, minor data issue | Audit log gap, failed auth spike | 24 hours |
| P4 — Low | Informational security event | Single failed login, scanner probe | 72 hours |

## 4. Incident Response Phases

### Phase 1: Detection & Triage (0-1 hour for P1)
- Source: Automated alerts (Railway monitoring, Stripe alerts, customer report)
- Assign incident commander
- Create private incident channel
- Initial severity classification

### Phase 2: Containment
**Short-term:** Isolate affected system (Railway: scale to 0, disable endpoints)
**Evidence preservation:** Export logs before any changes
**Communication:** Notify affected customers if P1/P2

### Phase 3: Investigation
- Review application logs, Railway metrics, MySQL query logs
- Identify entry point, scope of exposure, affected tenants
- Document timeline with evidence

### Phase 4: Eradication
- Remove attacker access / patch vulnerability
- Rotate any compromised credentials (Stripe keys, Gemini keys, session secrets)
- Deploy patched code via Railway

### Phase 5: Recovery
- Restore service with monitoring
- Verify fix effectiveness
- Post-recovery monitoring for 48 hours

### Phase 6: Post-Incident Review
- Complete within 5 business days
- Root cause analysis
- Update controls and documentation
- Update this plan if process gaps found

## 5. Notification Obligations

| Scenario | Who to notify | Timeline |
|---|---|---|
| Customer data breach | Affected customers + regulatory bodies | Within 72 hours of confirmation |
| Payment data involved | Stripe, affected customers, card networks | Within 24 hours |
| EU personal data | Relevant Data Protection Authority | Within 72 hours (GDPR Article 33) |

## 6. Key Contacts
- Incident Commander: [name/email]
- Engineering Lead: [name/email]
- Customer Communication: [name/email]

## 7. Breach Notification Template
[Standard template for customer notification — include: what happened, what data, what we're doing, what they should do]
```

---

### 2. Data Retention & Deletion Policy (`data-retention-policy.md`)

```markdown
# StratFlow Data Retention & Deletion Policy

**Version:** 1.0 | **Effective:** [today] | **Review:** [today + 12 months]

## Data Categories and Retention Periods

| Data Type | Retention Period | Deletion Method |
|---|---|---|
| Customer account data | Duration of subscription + 90 days after cancellation | Logical delete → physical purge at 90 days |
| Strategy documents | Duration of subscription + 90 days | Purged with account |
| Work items / user stories | Duration of subscription + 90 days | Purged with account |
| Audit logs | 12 months rolling | Automatic purge |
| Payment records | 7 years (tax/accounting requirement) | Retained — anonymised after account deletion |
| Stripe payment data | Managed by Stripe per PCI DSS | Not stored in StratFlow DB |
| Session data | Until logout or 24-hour expiry | Automatic expiry |
| Application logs | 30 days | Automatic rotation |

## Customer Data Deletion

Upon account cancellation or customer request:
1. Account flagged for deletion (90-day grace period)
2. All org-scoped data purged after grace period: strategies, work items, user stories, diagrams, risks, sprints, documents, audit logs
3. Payment records anonymised but retained for 7 years
4. Confirmation email sent to account owner

**GDPR Right to Erasure:** Customers may request immediate deletion. We will complete within 30 days. Contact: [email]

## Subprocessors and their retention

| Subprocessor | Data shared | Their retention |
|---|---|---|
| Stripe | Payment card data | Per Stripe's PCI DSS terms |
| Google Gemini API | Document content (transient) | Not retained per Google API terms |
| Railway | Application logs, metrics | 30 days |
```

---

### 3. Access Control Policy (`access-control-policy.md`)

```markdown
# StratFlow Access Control Policy

**Version:** 1.0 | **Effective:** [today] | **Review:** [today + 12 months]

## Principle of Least Privilege

All access is granted on a need-to-know basis. Permissions are not granted by default.

## Role Hierarchy

| Role | Description | Permissions |
|---|---|---|
| User | Standard team member | Read/write own org's data |
| Admin | Org administrator | User management, settings, billing, integrations |
| Executive | Senior stakeholder | Executive dashboard, cross-project view |
| Superadmin | StratFlow staff only | System-wide access — StratFlow employees only |

## Authentication Requirements

- **Passwords:** Minimum 8 characters; stored as bcrypt hash (cost factor 12)
- **Session expiry:** 24-hour maximum; re-authentication required
- **MFA:** Available [specify: optional/required] for all accounts
- **Failed login:** Account locked after [N] consecutive failures

## Access Reviews

- Org admin responsible for reviewing user list quarterly
- StratFlow staff access reviewed monthly
- Superadmin accounts reviewed weekly

## Privileged Access (StratFlow Staff)

- Production database: accessible only via Railway private networking
- Superadmin UI: requires staff credentials + IP allowlist [if implemented]
- All staff access is logged and auditable

## Offboarding

Account deactivation within 24 hours of employee/contractor offboarding. Sessions invalidated immediately.
```

---

### 4. Vulnerability Disclosure Policy (`vulnerability-disclosure-policy.md`)

```markdown
# StratFlow Vulnerability Disclosure Policy

**Version:** 1.0 | **Effective:** [today]

## Scope

In scope: StratFlow web application (app.stratflow.com), API endpoints, authentication system.
Out of scope: Railway infrastructure, Stripe, third-party integrations.

## Reporting

Report security vulnerabilities to: security@stratflow.com (or [actual email])
Include: description, steps to reproduce, impact assessment, proof of concept if available.
**Do not:** access customer data, perform denial of service, or publicly disclose before coordination.

## Our Commitments

- Acknowledge receipt within 2 business days
- Provide updates every 5 business days
- Notify you when the vulnerability is fixed
- Credit researchers in our security acknowledgements (if desired)
- No legal action for good-faith research

## Response Timeline

| Severity | Fix target |
|---|---|
| Critical | 48 hours |
| High | 7 days |
| Medium | 30 days |
| Low | 90 days |
```

---

### 5. Acceptable Use Policy (`acceptable-use-policy.md`)

```markdown
# StratFlow Acceptable Use Policy

**Version:** 1.0 | **Effective:** [today]

## Permitted Use
StratFlow may be used for legitimate business strategy planning, project management, and team collaboration purposes.

## Prohibited Use
Users must not:
- Attempt to access other organisations' data
- Upload malicious files or code
- Use the platform for illegal activities
- Attempt to reverse-engineer or bypass security controls
- Share credentials or allow unauthorised access
- Use automated tools to scrape or stress-test the platform without written permission

## AI Feature Use
Content submitted to AI features (strategy generation, document analysis) is transmitted to Google Gemini API. Do not submit content that is: classified, subject to export control, or prohibited by your organisation's AI policy.

## Monitoring
StratFlow logs all user actions for security and audit purposes. Audit logs are available to org administrators.

## Violations
Violations may result in account suspension or termination without refund.
```

---

## Implementation

Write all 5 files to `docs/compliance/policies/`:

```bash
mkdir -p C:/Users/James/Scripts/stratflow/docs/compliance/policies
```

Fill in today's date, review date (today + 12 months), and any placeholders marked with `[`.

Check existing policies first — if they exist, compare version and only update if content has changed significantly. Never downgrade a version number.

After writing, update `docs/compliance/README.md` to list all policies with their version and effective date.

Print:
```
Security policy pack generated: docs/compliance/policies/
  ✅ incident-response-plan.md
  ✅ data-retention-policy.md
  ✅ access-control-policy.md
  ✅ vulnerability-disclosure-policy.md
  ✅ acceptable-use-policy.md
Placeholders remaining: [list any [brackets] not filled]
```
