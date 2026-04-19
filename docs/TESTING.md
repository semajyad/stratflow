# Testing

StratFlow uses [PHPUnit 12](https://phpunit.de/) for automated testing, with a multi-layer test pyramid running in CI on every PR.

## Running the Test Suite

All test commands run inside the `php` Docker container:

```bash
# Run the full test suite
docker compose exec php composer test

# Run only unit tests
docker compose exec php composer test:unit

# Run only integration tests
docker compose exec php composer test:integration

# Apply schema + migrations first, then run integration tests
docker compose exec php composer test:integration:fresh
```

If local integration tests fail with missing tables or columns after new migrations land, run `docker compose exec php composer db:init` or use `test:integration:fresh`. The Docker MySQL volume is persistent, so `docker compose up` does not automatically replay migrations against an existing database.

Coverage measurement requires [pcov](https://github.com/krakjoe/pcov), which is enabled in CI via `shivammathur/setup-php`. It is **not** available in the local Docker image — run coverage in CI or install pcov locally.

---

## Test Pyramid

```
Unit (PHPUnit)     → tests/Unit/
Integration (PHPUnit) → tests/Integration/
E2E (Playwright)   → tests/Playwright/
Performance (k6)   → tests/performance/
Security (ZAP)     → tests/zap/
Python CI helpers  -> tests/Python/
```

### Coverage Targets (line coverage, enforced in CI)

| Layer | Wave 1 floor | Wave 2 target | Wave 5 target |
|---|---|---|---|
| Unit — Services | measured in CI | 75% | 85% |
| Unit — Models | measured in CI | 85% | 90% |
| Unit — Controllers | measured in CI | 60% | 80% |
| Overall (whitelisted `src/`) | ≥ 60% (current gate) | ≥ 75% | ≥ 85% |

> **Note:** The pre-Wave 1 coverage gate counted all pcov-detected files, including vendor-adjacent paths. From Wave 1 onward, coverage is measured against the `src/` whitelist only (excluding `src/Services/Prompts/` and `src/Config/`). The gate was kept at 60% pending the first CI run with the new whitelist. Update this table after that run with the true per-layer baseline.

---

## Source Whitelist

`tests/phpunit.xml` includes a `<source>` block that scopes coverage to:

- **Included:** `src/` (all `.php` files)
- **Excluded:** `src/Services/Prompts/` (AI prompt constants), `src/Config/` (static config files)

This ensures coverage numbers reflect the application logic only.

---

## Test Utilities

### `tests/Support/DatabaseTestCase.php`

Base class for integration tests that need a real database. Extends `TestCase`, opens a connection in `setUp()`, starts a transaction, and rolls back in `tearDown()`. Each test runs in isolation — no manual DELETE statements needed.

```php
use StratFlow\Tests\Support\DatabaseTestCase;

class MyIntegrationTest extends DatabaseTestCase
{
    public function testSomething(): void
    {
        // $this->db is available and connected
        // any rows inserted are rolled back automatically
    }
}
```

### `tests/Support/Factory/`

Plain-PHP factory classes that create model rows via the canonical `Model::create($db, $data)` interface:

| Factory | Model | Notable defaults |
|---|---|---|
| `OrgFactory` | `Organisation` | `is_active=1`, auto-sequenced `cus_test_N` Stripe ID |
| `UserFactory` | `User` | `role=user`, pre-computed bcrypt hash |
| `ProjectFactory` | `Project` | `status=active` |
| `UserStoryFactory` | `UserStory` | `status=backlog`, auto-sequenced `priority_number` |
| `HLWorkItemFactory` | `HLWorkItem` | `status=backlog`, auto-sequenced `priority_number` |
| `IntegrationFactory` | `Integration` | `provider=jira`, `status=disconnected` |

Usage:

```php
use StratFlow\Tests\Support\DatabaseTestCase;
use StratFlow\Tests\Support\Factory\{OrgFactory, UserFactory, ProjectFactory};

class MyTest extends DatabaseTestCase
{
    public function testStoryCreation(): void
    {
        $orgId     = OrgFactory::create($this->db);
        $userId    = UserFactory::create($this->db, $orgId);
        $projectId = ProjectFactory::create($this->db, $orgId, $userId);
        // ... assert against $projectId
    }
}
```

---

## Writing New Tests

### Unit test template

```php
<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\<Namespace>;

use PHPUnit\Framework\TestCase;
use StratFlow\<ClassUnderTest>;

class <ClassUnderTest>Test extends TestCase
{
    private function makeSubject(): <ClassUnderTest>
    {
        $dep = $this->createMock(SomeDependency::class);
        return new <ClassUnderTest>($dep);
    }

    public function testItDoesSomething(): void
    {
        $subject = $this->makeSubject();
        $this->assertSame('expected', $subject->doSomething('input'));
    }
}
```

Place unit test files in `tests/Unit/<Namespace>/` mirroring the `src/` structure.

### Mocking notes

- `$this->createMock()` — fully stubbed, call assertions available
- `$this->createStub()` — return-value stubs only, no call assertions
- `$this->getMockBuilder()->onlyMethods([])` — partial mocks

---

## Continuous Integration

CI runs on every push/PR to `main` via `.github/workflows/tests.yml` against PHP 8.3 and 8.4:

1. Lint (`php -l`) → PHPStan level 6 → PHPCS PSR-12
2. PHPUnit full suite with pcov coverage
3. Coverage gate: overall line coverage ≥ **60%** against the whitelisted `src/` (ratchets up each wave — never decreases)
4. Mutation testing (Infection) — nightly, `minMsi=70`, scoped via `infection.json`

Python helper tests (`scripts/ci/*`) also run in CI via `python -m pytest tests/Python -q`.

Playwright fast E2E runs in `.github/workflows/e2e.yml`. Staging smoke intentionally runs only `fast/healthz.spec.js` and `fast/smoke.spec.js` against `STAGING_URL`, using `E2E_EMAIL`/`E2E_PASSWORD` or the more specific `E2E_ADMIN_*` / `E2E_REGULAR_*` credentials when provided.

See `.github/workflows/tests.yml` for the full configuration.
