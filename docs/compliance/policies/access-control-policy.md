# StratFlow Access Control Policy

**Version:** 1.0 | **Effective:** 2026-04-10 | **Review:** 2027-04-10
**Owner:** StratFlow Engineering

## 1. Purpose

Establish how access to StratFlow systems and customer data is granted, maintained, and revoked — ensuring least privilege at all layers.

## 2. Scope

All StratFlow production systems: web application, MySQL 8.4 database, Railway hosting environment, Stripe dashboard, and Gemini API console.

## 3. Principle of Least Privilege

All access is granted on a need-to-know basis. No access is granted by default. Permissions are scoped to the minimum required for the role and are bound to the user's `org_id` at the application layer — no query executes without an `org_id` filter.

## 4. Application Role Hierarchy

| Role | Description | Capabilities |
|---|---|---|
| User | Standard team member | Read/write own org's strategies, work items, OKRs, documents |
| Admin | Org administrator | All User permissions + user management, org settings, billing, integrations |
| Executive | Senior stakeholder | Executive dashboard, cross-project read view within the org |
| Superadmin | StratFlow staff only | System-wide access across all orgs — StratFlow employees only, logged |

**Multi-tenancy enforcement:** Every database query in the application layer includes `WHERE org_id = ?` bound to the authenticated user's `$_SESSION['user']['org_id']`. Superadmin queries are separately controlled and audit-logged.

## 5. Authentication Requirements

| Control | Requirement |
|---|---|
| Password minimum length | 8 characters |
| Password storage | bcrypt, cost factor 12 |
| Session lifetime | 24-hour maximum; re-authentication required after expiry |
| Session tokens | Cryptographically random; rotated on privilege change |
| Failed login lockout | Account locked after 10 consecutive failures; manual unlock by org admin |
| CSRF protection | All state-changing POST routes protected by CSRF token middleware |

## 6. Multi-Factor Authentication

MFA is available for all accounts. Org admins may enforce MFA as a requirement for their organisation. StratFlow staff accessing production systems must have MFA enabled.

## 7. Access Reviews

| Scope | Frequency | Responsible Party |
|---|---|---|
| Org user list | Quarterly | Org administrator |
| StratFlow staff app access | Monthly | Engineering lead |
| StratFlow staff infra access (Railway, Stripe, Google Cloud) | Monthly | Engineering lead |
| Superadmin accounts | Weekly | Engineering lead |

Inactive accounts (>90 days no login) are flagged for review and disabled if not confirmed as required.

## 8. Privileged Access — StratFlow Staff

- **Production database:** Accessible only via Railway private networking; no public MySQL port
- **Railway dashboard:** Access restricted to named StratFlow employees with MFA
- **Stripe dashboard:** Access restricted to named employees; read-only access for support roles
- **Gemini API console:** Access restricted to engineering team
- **All staff access is logged** and available for audit

## 9. Offboarding

Upon employee or contractor offboarding:

1. Application accounts deactivated within **24 hours** of departure
2. All active sessions invalidated immediately (session table flush for user)
3. Railway, Stripe, and Google Cloud access revoked within **24 hours**
4. Shared credentials rotated if the departing individual had access
5. Offboarding checklist signed off by engineering lead

## 10. Third-Party and Integration Access

Third-party integrations (e.g., Jira, GitHub) access StratFlow data via scoped API tokens. Tokens are:
- Scoped to the minimum required permissions
- Stored encrypted in the database
- Revocable by the org admin at any time
- Reviewed during quarterly access reviews

## 11. Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0 | 2026-04-10 | StratFlow Engineering | Initial release |
