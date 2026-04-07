# Local Development Setup

This guide walks through setting up StratFlow on your local machine for development.

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Compose)
- [Git](https://git-scm.com/)

No local PHP or MySQL installation is needed — everything runs inside containers.

## Step-by-Step

### 1. Clone the repository

```bash
git clone <repo-url>
cd stratflow
```

### 2. Create your environment file

```bash
cp .env.example .env
```

### 3. Add your API keys

Open `.env` in a text editor and set the following values:

**Gemini API key**

1. Go to [https://aistudio.google.com/apikey](https://aistudio.google.com/apikey)
2. Create a new API key
3. Set `GEMINI_API_KEY=<your-key>` in `.env`

**Stripe test keys**

1. Go to the [Stripe Dashboard](https://dashboard.stripe.com/) → Developers → API keys
2. Copy the **Publishable key** and **Secret key** from the test mode section
3. Set in `.env`:
   ```
   STRIPE_PUBLIC_KEY=pk_test_...
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   ```
   The webhook secret is generated when you create a webhook endpoint in Stripe (see below).

### 4. Start the Docker stack

```bash
docker compose up -d --build
```

This starts three containers:

| Container | Port | Purpose |
|-----------|------|---------|
| `php` | — | PHP-FPM application server |
| `nginx` | 8890 | Web server / reverse proxy |
| `mysql` | 3307 | MySQL 8.4 database |

The MySQL container runs a health check every 5 seconds. The PHP and Nginx containers wait for MySQL to be healthy before starting.

### 5. Wait for the database to initialise

On first run, MySQL initialises the database from `database/schema.sql` and `database/seed.sql`. This takes about 10–20 seconds. Watch progress with:

```bash
docker compose logs -f mysql
```

Wait until you see `ready for connections`.

### 6. Visit the application

Open [http://localhost:8890](http://localhost:8890) in your browser. You should see the pricing / landing page.

### 7. Create your admin user

```bash
docker compose exec php php scripts/create-admin.php
```

Follow the prompts to set an email, password, and name.

### 8. Log in and start using StratFlow

Go to [http://localhost:8890/login](http://localhost:8890/login), sign in with the admin credentials you just created, and you will land on the dashboard.

## Setting Up Stripe Webhooks Locally

To test Stripe webhooks (subscription events) locally, use the [Stripe CLI](https://stripe.com/docs/stripe-cli):

```bash
stripe listen --forward-to http://localhost:8890/webhook/stripe
```

The CLI will print a webhook signing secret — copy it into `STRIPE_WEBHOOK_SECRET` in your `.env`.

## Useful Commands

```bash
# View logs from all containers
docker compose logs -f

# Restart after a code change that requires it
docker compose restart php

# Open a shell inside the PHP container
docker compose exec php bash

# Run composer commands
docker compose exec php composer install

# Stop the stack
docker compose down

# Destroy everything including the database volume
docker compose down -v
```

## Connecting to MySQL Directly

The MySQL port is mapped to `localhost:3307` (not 3306, to avoid conflicts with any local MySQL install).

```
Host: localhost
Port: 3307
Database: stratflow
User: stratflow
Password: stratflow_secret
```
