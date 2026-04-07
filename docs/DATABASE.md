# Database Reference

StratFlow uses **MySQL 8.4** with a single database named `stratflow`. All tables use the `InnoDB` engine and `utf8mb4_unicode_ci` collation.

The schema file is at `database/schema.sql`. It is loaded automatically on first container start via the Docker entrypoint.

---

## Entity Relationships (Overview)

```
organisations
  ├── users (org_id → organisations.id)
  ├── subscriptions (org_id → organisations.id)
  └── projects (org_id → organisations.id)
        ├── documents (project_id → projects.id)
        ├── strategy_diagrams (project_id → projects.id)
        │     └── diagram_nodes (diagram_id → strategy_diagrams.id)
        └── hl_work_items (project_id → projects.id,
                           diagram_id → strategy_diagrams.id [nullable])

login_attempts (standalone — tracks IP-based brute force attempts)
```

All project data is scoped to an organisation via `org_id`. Users belong to exactly one organisation.

---

## Tables

### `organisations`

Top-level tenant. Each paying customer is one organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `name` | VARCHAR(255) | NOT NULL | Organisation display name |
| `stripe_customer_id` | VARCHAR(255) | NULL | Stripe customer object ID |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 0 = suspended |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

---

### `users`

Application users. Each user belongs to exactly one organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | |
| `email` | VARCHAR(255) | NOT NULL, UNIQUE | Login identifier |
| `password_hash` | VARCHAR(255) | NOT NULL | bcrypt hash |
| `full_name` | VARCHAR(255) | NOT NULL | |
| `role` | ENUM | NOT NULL, DEFAULT `user` | `user`, `org_admin`, `superadmin` |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE RESTRICT

---

### `subscriptions`

Tracks Stripe subscription state per organisation.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | |
| `stripe_subscription_id` | VARCHAR(255) | NOT NULL | Stripe subscription object ID |
| `plan_type` | ENUM | NOT NULL | `product`, `consultancy` |
| `status` | ENUM | NOT NULL, DEFAULT `active` | `active`, `cancelled`, `expired` |
| `started_at` | DATETIME | NOT NULL | |
| `expires_at` | DATETIME | NULL | NULL = ongoing subscription |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE

---

### `login_attempts`

Rate-limiting table for brute force protection. Stores one row per login attempt, indexed by IP address and time.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `ip_address` | VARCHAR(45) | NOT NULL | Supports IPv6 |
| `attempted_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Indexes:** `idx_ip_time (ip_address, attempted_at)`

---

### `projects`

A project is the top-level work unit within an organisation. It owns documents, diagrams, and work items.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `org_id` | INT UNSIGNED | NOT NULL, FK → organisations | Multi-tenancy scope key |
| `name` | VARCHAR(255) | NOT NULL | |
| `status` | ENUM | NOT NULL, DEFAULT `draft` | `draft`, `active`, `completed` |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `org_id` → `organisations.id` ON DELETE CASCADE; `created_by` → `users.id` ON DELETE RESTRICT

---

### `documents`

Files uploaded to a project. Text is extracted at upload time; AI summary is generated on demand.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `filename` | VARCHAR(255) | NOT NULL | Storage filename (UUID-based) |
| `original_name` | VARCHAR(255) | NOT NULL | Original user filename |
| `mime_type` | VARCHAR(100) | NOT NULL | e.g. `application/pdf` |
| `file_size` | INT UNSIGNED | NOT NULL | Bytes |
| `extracted_text` | LONGTEXT | NULL | Raw text extracted from file |
| `ai_summary` | TEXT | NULL | Gemini-generated summary (populated on demand) |
| `uploaded_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `uploaded_by` → `users.id` ON DELETE RESTRICT

---

### `strategy_diagrams`

A versioned Mermaid.js diagram for a project. Each save creates or updates a record; `version` increments with each save.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `mermaid_code` | TEXT | NOT NULL | Raw Mermaid.js source |
| `version` | INT UNSIGNED | NOT NULL, DEFAULT 1 | Incremented on each save |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users | |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `created_by` → `users.id` ON DELETE RESTRICT

---

### `diagram_nodes`

Individual nodes extracted from a diagram, with optional OKR metadata attached by the user.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `diagram_id` | INT UNSIGNED | NOT NULL, FK → strategy_diagrams | |
| `node_key` | VARCHAR(100) | NOT NULL | Mermaid node ID (e.g. `A`, `STR1`) |
| `label` | VARCHAR(255) | NOT NULL | Node display label |
| `okr_title` | VARCHAR(255) | NULL | OKR title set by user |
| `okr_description` | TEXT | NULL | OKR description set by user |

**Foreign keys:** `diagram_id` → `strategy_diagrams.id` ON DELETE CASCADE

---

### `hl_work_items`

High-Level Work Items (HLWIs) generated from the strategy diagram. Each item represents approximately one month of team effort.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `project_id` | INT UNSIGNED | NOT NULL, FK → projects | |
| `diagram_id` | INT UNSIGNED | NULL, FK → strategy_diagrams | NULL if diagram was deleted |
| `priority_number` | INT UNSIGNED | NOT NULL | Drag-reorder priority (1 = highest) |
| `title` | VARCHAR(255) | NOT NULL | |
| `description` | TEXT | NULL | AI-generated or manually edited scope |
| `strategic_context` | TEXT | NULL | Which diagram nodes this maps to |
| `okr_title` | VARCHAR(255) | NULL | Inherited from diagram node |
| `okr_description` | TEXT | NULL | Inherited from diagram node |
| `owner` | VARCHAR(255) | NULL | Assigned team or person |
| `estimated_sprints` | INT UNSIGNED | NOT NULL, DEFAULT 2 | Default = 2 sprints (~1 month) |
| `created_at` | DATETIME | NOT NULL, DEFAULT NOW | |
| `updated_at` | DATETIME | NOT NULL, ON UPDATE NOW | |

**Foreign keys:** `project_id` → `projects.id` ON DELETE CASCADE; `diagram_id` → `strategy_diagrams.id` ON DELETE SET NULL

---

## Indexes Summary

| Table | Index | Columns | Type |
|-------|-------|---------|------|
| `users` | (implicit) | `email` | UNIQUE |
| `login_attempts` | `idx_ip_time` | `ip_address, attempted_at` | INDEX |

All other lookups rely on primary keys and foreign key indexes created automatically by InnoDB.

---

## Applying Schema Changes

There is no migration framework. Schema changes are applied manually:

1. Write the `ALTER TABLE` or `CREATE TABLE` statement
2. Test it against a local database first
3. Apply to production via cPanel phpMyAdmin or `mysql` CLI:
   ```bash
   mysql -u <user> -p <db_name> < path/to/change.sql
   ```
4. Update `database/schema.sql` to reflect the new canonical schema

For the Docker dev environment, to re-apply the schema from scratch:

```bash
docker compose down -v
docker compose up -d --build
```

This destroys and recreates the MySQL volume, so all data is lost. Use only in development.
