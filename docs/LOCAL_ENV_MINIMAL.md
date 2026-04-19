# Minimal Local Environment

This is the secrets-free setup for agents that only need tests and browser
sanity checks. Use `.env.example` when you need all optional integrations.

## File

Create `.env` from `.env.local.example`:

```bash
cp .env.local.example .env
```

The sample uses local Docker service names and dummy provider credentials. It is
safe for tests because external AI processing is disabled and third-party keys
are placeholders.

## Start

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php scripts/create-admin.php
```

Then open `http://localhost:8890`.

## When Real Secrets Are Needed

Only add real keys for the feature you are testing:

- Gemini: AI generation flows.
- Stripe: checkout and webhook flows, always test-mode keys.
- GitHub/Jira/Xero: provider integration flows.
- Resend/MailerSend: outbound email flows.

Never commit `.env`, provider tokens, webhook secrets, private keys, or raw API
responses.
