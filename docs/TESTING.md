# Testing

StratFlow uses [PHPUnit 11](https://phpunit.de/) for automated testing. Tests live in the `tests/` directory and are split into unit and integration suites.

## Running the Test Suite

All test commands run inside the `php` Docker container:

```bash
# Run the full test suite
docker compose exec php composer test

# Run only unit tests
docker compose exec php composer test:unit

# Run only integration tests
docker compose exec php composer test:integration
```

The `composer test` script maps to:

```bash
phpunit --configuration tests/phpunit.xml
```

---

## Test Suite Breakdown

### Unit Tests (`tests/Unit/`)

Unit tests exercise a single class in isolation. External dependencies (database, HTTP, session) are replaced with PHPUnit mocks.

**Current coverage:**

| Test File | Class Under Test | What Is Tested |
|-----------|-----------------|----------------|
| `Unit/Core/RouterTest.php` | `Core\Router` | Route registration, method normalisation, middleware storage, `patternToRegex()` conversion, URI matching, no false positives on deeper paths |

Unit tests use `ReflectionMethod` and `ReflectionProperty` to access private internals where needed (e.g., `patternToRegex`, `$routes`). This avoids changing visibility for testability while still validating internal logic.

### Integration Tests (`tests/Integration/`)

Integration tests wire up real application components against a test database. They are slower and require a running MySQL instance.

The test bootstrap (`tests/bootstrap.php`) loads the application's `.env` file, so integration tests use the same environment variables as the running app. Point `DB_NAME` at a separate test database to avoid corrupting development data.

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
    // ===========================
    // HELPERS
    // ===========================

    private function makeSubject(): <ClassUnderTest>
    {
        // Construct with mocked dependencies
        $dep = $this->createMock(SomeDependency::class);
        return new <ClassUnderTest>($dep);
    }

    // ===========================
    // TESTS
    // ===========================

    /** @test */
    public function testItDoesSomething(): void
    {
        $subject = $this->makeSubject();
        $result  = $subject->doSomething('input');

        $this->assertSame('expected', $result);
    }
}
```

Place unit test files in `tests/Unit/<Namespace>/` mirroring the `src/` structure. For example, a test for `src/Services/GeminiService.php` goes in `tests/Unit/Services/GeminiServiceTest.php`.

### Mocking notes

- Use `$this->createMock(ClassName::class)` for classes that should be fully stubbed
- Use `$this->createStub()` when you only need return values and don't care about call assertions
- Use `$this->getMockBuilder()` when you need partial mocks (mock some methods, call real implementations for others)
- Mock the `Database` class to avoid real SQL in unit tests; assert that specific query methods are called with expected arguments

### Integration test notes

- Integration tests should use a dedicated test database (configure via `.env` or a `.env.testing` override)
- Wrap each test in a transaction and roll back in `tearDown()` to keep the database clean:
  ```php
  protected function setUp(): void    { $this->db->beginTransaction(); }
  protected function tearDown(): void { $this->db->rollBack(); }
  ```
- Test the full controller→model→database chain for critical paths (e.g., login, project creation, work item generation)

---

## Test Database

For integration tests, point the application at a separate database. The simplest approach when using Docker is to create a second database in the MySQL container:

```bash
docker compose exec mysql mysql -u root -proot_secret -e "CREATE DATABASE stratflow_test;"
docker compose exec mysql mysql -u root -proot_secret -e \
  "GRANT ALL PRIVILEGES ON stratflow_test.* TO 'stratflow'@'%';"
```

Then export a test-specific `.env` or temporarily set `DB_NAME=stratflow_test` before running integration tests.

---

## Continuous Integration

There is no CI pipeline configured yet. To add one, run `composer test` as part of your CI workflow after `docker compose up -d --build` and waiting for MySQL to be healthy.
