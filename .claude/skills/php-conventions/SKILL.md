---
name: php-conventions
description: Invoke when writing or editing PHP code in StratFlow to enforce PSR-12 and StratFlow's vanilla-MVC idioms. Covers namespace/PSR-4 rules, PHP 8.4 type safety, PDO prepared statements, multi-tenant org_id filtering, and XSS/CSRF discipline.
allowed-tools: Read, Grep, Glob, Edit, Write, Bash
---

# StratFlow PHP Conventions

Apply these rules to any file under `src/`, `tests/`, `scripts/`, or `public/`.

## Structural Rules

1. **Namespace matches path.** `src/Services/Prompts/Foo.php` declares `namespace StratFlow\Services\Prompts;` and class `Foo`.
2. **One class per file.** No multiple class declarations.
3. **PSR-4 only.** Never `require` or `include` from inside `src/` — Composer autoload handles it.
4. **Thin controllers, thin models.** Controllers route input to models/services and return responses. Models are DAOs — one class per table, SQL lives here. Business logic lives in `src/Services/`.

## PHP 8.4 Style

- Declare strict types at the top of every file: `declare(strict_types=1);`
- All parameters and return types are typed. `mixed` is a code smell — justify it or remove it.
- Use constructor property promotion: `public function __construct(private Database $db) {}`
- Use `readonly` for immutable properties.
- Prefer `match()` over `switch` for value dispatch.

## Database Rules

- **PDO prepared statements only.** Never concatenate user input into SQL.
- **Every tenant query includes `org_id`** matched against `$_SESSION['user']['org_id']`. No exceptions.
- Use `Database::getInstance()->pdo()` to get the PDO handle — do not instantiate PDO directly.
- Use `PDO::FETCH_ASSOC` explicitly.
- Wrap multi-statement writes in `beginTransaction()` / `commit()` / `rollBack()`.

## Template Rules

- Every user-supplied value echoed in a template uses `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- Use `<?= ?>` short-echo tags for escaped output.

## Gemini Prompt Rules

- All prompt text lives in `src/Services/Prompts/*.php` as class constants.
- Never inline prompts in controllers or services.
- Any prompt change must be reflected in `docs/GEMINI_PROMPTS.md`.

## Error Handling

- Exceptions are caught and logged via `AuditLogger`, or allowed to propagate. Never silently swallowed.

## Commits

- Conventional Commits: `feat(drift):`, `fix(webhook):`, `refactor(models):`, `test(integration):`, `docs(prompts):`.
- One logical change per commit.

## Before Finishing

- Run `php -l` on every file you touched (the PostToolUse hook does this automatically when the Docker stack is up).
- If you touched `src/`, run `docker compose exec php vendor/bin/phpunit --testsuite unit`.
- If you touched a controller or middleware, also run the integration suite.
