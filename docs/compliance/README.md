# StratFlow Compliance Documentation

This directory contains auto-generated compliance and security documentation
for enterprise client due diligence. All documents are dated and version-controlled.

---

## Document Index

### Security Assessment Reports
| Document | Date | Status |
|---|---|---|
| *(run `security-report-generator` agent to generate)* | | |

### Performance Reports
| Document | Date | Status |
|---|---|---|
| *(run `performance-report-generator` agent to generate)* | | |

### CAIQ Responses
| Document | Date | Status |
|---|---|---|
| *(run `caiq-responder` agent to generate)* | | |

### Security Policies (`policies/`)
| Policy | Version | Effective Date |
|---|---|---|
| Incident Response Plan | *(generate)* | |
| Data Retention Policy | *(generate)* | |
| Access Control Policy | *(generate)* | |
| Vulnerability Disclosure Policy | *(generate)* | |
| Acceptable Use Policy | *(generate)* | |

---

## Update Schedule

| Cadence | Task | Agent / Script |
|---|---|---|
| Weekly | CVE dependency scan → ntfy | `dependency-auditor` |
| Monthly | Security assessment report | `security-report-generator` |
| Monthly | Performance test + report | `performance-report-generator` |
| Quarterly | CAIQ questionnaire update | `caiq-responder` |
| Quarterly | Security policy refresh | `security-policy-pack` skill |

Run manually:
```bash
# Generate all documents now
python scripts/generate_compliance_docs.py --mode all

# Individual runs
python scripts/generate_compliance_docs.py --mode security-report
python scripts/generate_compliance_docs.py --mode performance-report
python scripts/generate_compliance_docs.py --mode caiq
python scripts/generate_compliance_docs.py --mode dependency-audit

# Or invoke agents directly in Claude Code
# "Use the security-report-generator agent"
# "Use the caiq-responder agent"
# "Use the performance-report-generator agent"
```

---

## For Corporate Clients

When a client requests security documentation, share:

1. **Latest security assessment report** — answers OWASP / penetration test questions
2. **CAIQ response** — standard vendor questionnaire for enterprise procurement
3. **Security policy pack** — IRP, data retention, access control, VDP, AUP
4. **Performance report** — p50/p95/p99 response times, SLA commitments

**What we don't have yet:**
- SOC 2 Type II (planned — requires CPA firm engagement)
- Third-party penetration test (recommended for enterprise deals >$50k ACV)
- ISO 27001 certification

---

## Document Classification

All documents in this directory are **Confidential — Recipient Only**.
Do not share publicly. Share via secure link with named recipients only.
