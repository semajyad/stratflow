# StratFlow

AI-powered strategy-to-code platform by ThreePoints Solutions.

StratFlow turns raw strategy documents into a prioritised engineering backlog in minutes. Upload your meeting notes or strategy docs, generate a visual strategy diagram, assign OKRs to each node, and let Gemini AI produce a ready-to-execute set of high-level work items — all from a single web UI.

## Features

- **Document upload** — Upload PDF, DOCX, PPTX, XLSX, TXT, CSV, audio, or video files; text is extracted automatically
- **AI summary** — Gemini condenses uploaded documents into a focused 3-paragraph strategic brief
- **Diagram generation** — AI converts the brief into a Mermaid.js flowchart visualised in the browser
- **OKR attachment** — Click any diagram node to add an OKR title, description, and key results
- **Work item creation** — Gemini translates the diagram and OKRs into a prioritised backlog of high-level work items with quality scoring
- **User story decomposition** — AI breaks work items into sprint-ready user stories with Fibonacci sizing and acceptance criteria
- **Quality scoring** — Automated 6-dimension quality assessment (INVEST, acceptance criteria, value, KR linkage, SMART, splitting) with AI improvement suggestions
- **Sprint planning** — Assign stories to sprints manually or via AI auto-allocation respecting capacity and dependencies
- **Prioritisation** — RICE or WSJF framework scoring with AI-suggested baselines
- **Risk management** — AI-generated risk identification with ROAM status tracking and mitigation strategies
- **Key Results tracking** — OKR key results with progress tracking and AI momentum commentary
- **Sounding Board** — AI panel evaluation: independent per-persona reviews from executive or product management boards
- **Governance & Drift** — Strategic baseline snapshots with automatic drift detection and a change-control approval queue
- **Traceability** — Story-to-OKR linkage matrix
- **Executive dashboard** — Org-wide portfolio health, backlog metrics, sprint velocity, and risk register
- **Git integration** — Link stories to GitHub/GitLab PRs and commits; AI matches merged PRs to relevant stories
- **Jira integration** — Bidirectional sync of work items and user stories with Jira projects
- **Multi-factor authentication** — TOTP-based MFA with recovery codes
- **REST API + MCP server** — Bearer token authentication for external tooling and developer workflows
- **Drag reorder** — Re-prioritise work items and stories with drag-and-drop
- **CSV / JSON / Jira export** — Export the full backlog for use in any project management tool
- **Stripe payments** — Subscription checkout and webhook handling built in
- **Xero integration** — Invoice management and sync
- **Multi-tenant** — Each organisation's data is fully isolated by `org_id`

## Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.4 (vanilla MVC, no framework) |
| Database | MySQL 8.4 |
| Web server | Nginx |
| AI (primary) | Google Gemini API (`gemini-3-flash-preview`) |
| AI (fallback) | OpenAI GPT-4o-mini (automatic on Gemini failure) |
| Payments | Stripe (Checkout + Webhooks) |
| PDF parsing | `smalot/pdfparser` |
| Container | Docker Compose |

## Quick Start

```bash
# 1. Clone the repo
git clone https://github.com/semajyad/stratflow.git
cd stratflow

# 2. Configure environment
cp .env.example .env
# Edit .env — add your GEMINI_API_KEY and STRIPE_* keys

# 3. Start the stack
docker compose up -d --build
# Visit http://localhost:8890
```

After the stack is up, create your first admin user:

```bash
docker compose exec php php scripts/create-admin.php
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for full setup instructions including optional services.

## Docker Services

| Service | Port | Purpose |
|---------|------|---------|
| `nginx` | 8890 | Web server / reverse proxy |
| `php` | — | PHP-FPM application server |
| `mysql` | 3307 | MySQL 8.4 database |
| `quality-worker` | — | Async quality scoring loop (runs every 2 minutes) |

## Documentation

| Doc | Description |
|-----|-------------|
| [CONTRIBUTING.md](CONTRIBUTING.md) | New developer setup guide |
| [docs/SETUP.md](docs/SETUP.md) | Local development setup (Docker) |
| [docs/SETUP_MACOS.md](docs/SETUP_MACOS.md) | Local development setup (macOS, no Docker) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment (Railway / cPanel) |
| [docs/API.md](docs/API.md) | All application routes (200+) |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, tables, and relationships |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System design and request lifecycle |
| [docs/TESTING.md](docs/TESTING.md) | Running and writing tests |
| [docs/GEMINI_PROMPTS.md](docs/GEMINI_PROMPTS.md) | AI prompt reference (13 prompts) |
| [docs/USER_ROLES_GUIDE.md](docs/USER_ROLES_GUIDE.md) | Roles, flags, and project access |
| [docs/SECURE_CODING.md](docs/SECURE_CODING.md) | Security rules and patterns |

## Security Scanning

- **GitHub Actions** — `.github/workflows/security-zap.yml` runs OWASP ZAP scanning against the Railway staging deployment
- **Snyk** — `.github/workflows/snyk.yml` scans Composer dependencies for CVEs
- **CodeQL** — `.github/workflows/codeql.yml` runs static analysis on PHP code
- **Shannon** — local pentest runner: `powershell -ExecutionPolicy Bypass -File .\scripts\run-shannon-official.ps1 -TargetUrl https://stratflow-app-production.up.railway.app`

## License

Proprietary — Copyright ThreePoints Solutions. All rights reserved.
