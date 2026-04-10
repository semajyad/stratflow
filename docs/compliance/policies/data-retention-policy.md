# StratFlow Data Retention & Deletion Policy

**Version:** 1.0 | **Effective:** 2026-04-10 | **Review:** 2027-04-10
**Owner:** StratFlow Engineering

## 1. Purpose

Define how long StratFlow retains customer and operational data, and the procedures for secure deletion upon account cancellation or customer request.

## 2. Scope

All data stored in StratFlow production systems: MySQL 8.4 database (Railway-hosted), application logs, and data shared with subprocessors.

## 3. Data Retention Schedule

| Data Type | Retention Period | Deletion Method |
|---|---|---|
| Customer account data (users, orgs) | Duration of subscription + 90 days after cancellation | Logical delete → physical purge at day 90 |
| Strategy documents | Duration of subscription + 90 days | Purged with account |
| Work items and user stories | Duration of subscription + 90 days | Purged with account |
| OKRs, KRs, and progress data | Duration of subscription + 90 days | Purged with account |
| Sprint and backlog data | Duration of subscription + 90 days | Purged with account |
| Risk register entries | Duration of subscription + 90 days | Purged with account |
| Diagrams and documents | Duration of subscription + 90 days | Purged with account |
| Audit logs (`audit_log` table) | 12 months rolling | Automatic purge via scheduled job |
| Payment records (invoices, amounts) | 7 years (tax/accounting obligation) | Retained — customer PII anonymised after account deletion |
| Stripe payment card data | Managed by Stripe per PCI DSS | Not stored in StratFlow database |
| Session data | Until logout or 24-hour expiry | Automatic session expiry |
| Application logs (Railway) | 30 days | Automatic rotation by Railway |
| Gemini API request content | Not retained | Transient — not stored per Google API terms |

## 4. Customer Data Deletion Process

### Standard Deletion (account cancellation)

1. Account flagged `status = cancelled` — 90-day grace period begins
2. Customer retains read-only access during grace period to export data
3. At day 90: all `org_id`-scoped data purged in order:
   - User stories, work items, backlogs
   - Strategies, OKRs, risks, sprints
   - Diagrams, documents, audit logs
   - User accounts linked to the org
4. Payment records anonymised (customer name/email replaced, amounts retained)
5. Deletion confirmation email sent to account owner

### Immediate Deletion (GDPR Right to Erasure)

Customers may request immediate deletion without waiting for the grace period. We will complete physical deletion within **30 days** of a verified request.

**Contact:** privacy@[domain] or account settings → Delete Account

## 5. Data Export

Customers may export all their data before deletion via the account settings page. Exports include: strategies, work items, OKRs, documents (JSON format).

## 6. Subprocessors and Their Retention

| Subprocessor | Data Shared | Their Retention Policy |
|---|---|---|
| Stripe | Payment card data, billing details | Per Stripe's PCI DSS-compliant terms |
| Google Gemini API | Document/story content (transient per request) | Not retained per Google Cloud API terms |
| Railway | Application logs, environment variables, metrics | 30 days for logs |

## 7. Legal Holds

If StratFlow receives a valid legal hold order, affected data is flagged and excluded from automated deletion until the hold is lifted. Legal holds are managed by [Legal Contact].

## 8. Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0 | 2026-04-10 | StratFlow Engineering | Initial release |
