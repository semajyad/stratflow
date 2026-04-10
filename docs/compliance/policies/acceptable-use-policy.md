# StratFlow Acceptable Use Policy

**Version:** 1.0 | **Effective:** 2026-04-10 | **Review:** 2027-04-10
**Owner:** StratFlow Engineering

## 1. Purpose

Define the permitted and prohibited uses of the StratFlow platform to protect the security, availability, and integrity of the service for all customers.

## 2. Scope

This policy applies to all users of StratFlow — employees, contractors, and end users — across all subscription tiers.

## 3. Permitted Use

StratFlow may be used for:

- Legitimate business strategy planning, OKR management, and roadmap development
- Project and sprint management for software and product teams
- Document collaboration, risk management, and stakeholder reporting
- Integration with authorised third-party tools (Jira, GitHub, etc.) via the integrations settings

## 4. Prohibited Use

Users must not use StratFlow to:

| Prohibition | Examples |
|---|---|
| Access other organisations' data | Attempting to bypass `org_id` isolation, fuzzing API parameters |
| Upload malicious content | Malware, ransomware, exploits embedded in documents |
| Conduct illegal activities | Storing illegal content, facilitating fraud or money laundering |
| Reverse-engineer security controls | Bypassing authentication, session fixation attempts |
| Share or reuse credentials | Sharing login details, using another user's account |
| Exceed authorised access | Accessing admin or superadmin features without authorisation |
| Stress-test or scrape the platform | Automated crawling, load testing without written permission from StratFlow |
| Violate export controls | Storing ITAR/EAR-controlled data without appropriate controls |

## 5. AI Feature Use

StratFlow's AI features (story generation, document analysis, strategy suggestions) transmit content to **Google Gemini API** for processing. Users should be aware:

- Content submitted to AI features leaves StratFlow's infrastructure temporarily
- Google Gemini API does not retain content after processing per Google's API terms
- **Do not submit** content that is: classified, export-controlled, subject to legal hold, or prohibited by your organisation's AI usage policy
- Org admins may disable AI features in organisation settings if required by policy

## 6. Data Responsibility

- Users are responsible for the accuracy and legality of data they input into StratFlow
- Org admins are responsible for managing user access within their organisation
- StratFlow is not responsible for data entered in violation of applicable laws or regulations

## 7. Monitoring and Audit Logging

StratFlow logs all user actions for security and audit purposes:

- Login/logout events with IP address and timestamp
- Data creation, modification, and deletion events
- Integration access and configuration changes
- All actions are associated with the authenticated user and `org_id`

**Audit logs are available to org administrators** in the Admin → Audit Log section. StratFlow staff may access logs during incident investigation.

## 8. Enforcement

Violations of this policy may result in:

1. Warning and remediation request
2. Temporary account suspension
3. Permanent account termination without refund
4. Referral to law enforcement for criminal violations

The severity of the response will be proportional to the nature of the violation.

## 9. Reporting Violations

Report suspected violations to: support@[domain]
Report security vulnerabilities to: security@[domain] (see Vulnerability Disclosure Policy)

## 10. Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0 | 2026-04-10 | StratFlow Engineering | Initial release |
