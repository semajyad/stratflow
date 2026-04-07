# StratFlow

AI-powered strategy-to-code platform by ThreePoints Solutions.

StratFlow turns raw strategy documents into a prioritised engineering backlog in minutes. Upload your meeting notes or strategy docs, generate a visual strategy diagram, assign OKRs to each node, and let Gemini AI produce a ready-to-execute set of high-level work items — all from a single web UI.

## Features

- **Document upload** — Upload PDF or text documents; text is extracted automatically
- **AI summary** — Gemini condenses uploaded documents into a focused 3-paragraph strategic brief
- **Diagram generation** — AI converts the brief into a Mermaid.js flowchart visualised in the browser
- **OKR attachment** — Click any diagram node to add an OKR title and description
- **Work item creation** — Gemini translates the diagram and OKRs into a prioritised backlog of high-level work items
- **Drag reorder** — Re-prioritise work items with drag-and-drop
- **AI descriptions** — Generate detailed scope descriptions for individual work items on demand
- **CSV / JSON export** — Export the full backlog for use in Jira, Linear, or other tools
- **Stripe payments** — Subscription checkout and webhook handling built in
- **Multi-tenant** — Each organisation's data is fully isolated by `org_id`

## Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.4 (vanilla MVC, no framework) |
| Database | MySQL 8.4 |
| Web server | Nginx |
| AI | Google Gemini API (`gemini-2.0-flash`) |
| Payments | Stripe (Checkout + Webhooks) |
| PDF parsing | smalot/pdfparser |
| Container | Docker Compose |

## Quick Start

```bash
# 1. Clone the repo
git clone <repo-url>
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

## Documentation

| Doc | Description |
|-----|-------------|
| [docs/SETUP.md](docs/SETUP.md) | Local development setup guide |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel production deployment |
| [docs/API.md](docs/API.md) | All application routes |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, tables, and relationships |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System design and request lifecycle |
| [docs/TESTING.md](docs/TESTING.md) | Running and writing tests |
| [docs/GEMINI_PROMPTS.md](docs/GEMINI_PROMPTS.md) | AI prompt reference |

## License

Proprietary — Copyright ThreePoints Solutions. All rights reserved.
