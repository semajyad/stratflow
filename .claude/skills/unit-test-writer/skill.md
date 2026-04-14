---
name: unit-test-writer
description: Invoke immediately after writing or modifying any src/**/*.php file. Spawns a Haiku agent to write the matching PHPUnit test. You are the orchestrator — Haiku does the test writing.
allowed-tools: Read, Grep, Glob, Agent
---

# Unit Test Writer

You are the **orchestrator**. Your job is to:
1. Identify the source file(s) just written or modified.
2. Read the source file and any existing test infrastructure.
3. Spawn a **Haiku** agent with full context to write the test.
4. Review the Haiku output for correctness.
5. Write the final test file to disk yourself using the Edit/Write tools.

---

## Step 1 — Identify files needing tests

For each `src/**/*.php` file you just wrote or changed, derive the test path:

| Source path | Test path |
|---|---|
| `src/Controllers/FooController.php` | `tests/Unit/Controllers/FooControllerTest.php` |
| `src/Models/Bar.php` | `tests/Unit/Models/BarTest.php` |
| `src/Services/BazService.php` | `tests/Unit/Services/BazServiceTest.php` |
| `src/Middleware/QuxMiddleware.php` | `tests/Unit/Middleware/QuxMiddlewareTest.php` |
| `src/Core/Thing.php` | `tests/Unit/Core/ThingTest.php` |
| `src/Security/Policy.php` | `tests/Unit/Security/PolicyTest.php` |

Skip files in `src/Config/` and `src/Services/Prompts/` — no tests needed there.

---

## Step 2 — Gather context for Haiku

Before spawning the agent, read:
- The source file itself (full content).
- The test base class: `tests/Support/StratFlowTestCase.php` (or `ControllerTestCase.php` for controllers).
- One existing test file from the same directory as a style reference.

---

## Step 3 — Spawn Haiku agent

Use the `Agent` tool with `model: "haiku"`. Pass all context in the prompt — Haiku has no access to the repo.

Prompt template:

```
You are writing a PHPUnit 11 unit test for a StratFlow PHP 8.4 class.

## Source file: <path>
<full file content>

## Base test class (extend this):
<StratFlowTestCase or ControllerTestCase content>

## Style reference (existing test in same dir):
<existing test file content>

## Your task
Write a complete test class for `<ClassName>` saved to `<test path>`.

Rules:
- Extend the appropriate base class (ControllerTestCase for controllers, StratFlowTestCase otherwise).
- Declare `strict_types=1`.
- Namespace: mirror the source namespace under Tests\ (e.g. Tests\Unit\Controllers\).
- One test method per public method or behaviour branch.
- Test method names: `test_<method>_<scenario>` snake_case.
- Mock the PDO/database via the base class helpers — never connect to a real DB.
- Mock external services (Stripe, Gemini, HTTP) with PHPUnit's createMock().
- Assert specific return values, not just "no exception thrown".
- Cover: happy path, missing required input (400), unauthorised (403), not found (404), and any service-error branch.
- No lorem ipsum. Use realistic StratFlow domain values (org_id=1, project names, etc.).
- Output ONLY the PHP file content — no explanation, no markdown fences.
```

---

## Step 4 — Write the test file

Take Haiku's output and write it to the correct path using the Write tool.

Run a quick syntax check:
```bash
docker compose exec php php -l <test path>
```

If it fails, fix the syntax issue yourself (do not re-spawn Haiku for trivial fixes).

---

## Step 5 — Verify it runs

```bash
docker compose exec php vendor/bin/phpunit <test path> --no-coverage
```

If tests fail due to missing mocks or wrong assertions, fix them directly. Only re-spawn Haiku if the logic is fundamentally wrong and requires a full rewrite.

---

## Completion criteria

- [ ] Test file exists at the correct path.
- [ ] `php -l` passes.
- [ ] `phpunit <test path>` passes (all tests green or correctly skipped).
- [ ] Both source and test file are staged together in the same commit.
