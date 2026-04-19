# Validation Matrix

Use the smallest set that covers the risk of the change. Add broader checks when
the blast radius crosses modules, data boundaries, or user-facing flows.

| Change type | Minimum local validation | Add when applicable |
|---|---|---|
| Docs only | `python scripts/ci/check_docs.py --staged` | Link-check manually if adding external references |
| Agent scripts / CI scripts | `python -m py_compile <changed .py files>` and shell syntax check for changed hooks | Run the changed script in its staged/no-op mode |
| PHP service/model logic | `docker compose exec php composer test:unit` or focused PHPUnit unit test | Integration test if database behavior changes |
| PHP controller/middleware/template | Focused PHPUnit unit/integration test plus PHP lint | Browser sanity check; security notes if auth/session/headers/data touched |
| Database migration/schema | `python scripts/ci/check_destructive_migrations.py` if migration touched | Integration tests and `docs/DATABASE.md` update |
| JavaScript/CSS UI behavior | `node --check public/assets/js/app.js` when JS changes | Focused Playwright spec for the changed flow |
| Major user-facing flow | Focused Playwright or integration test exercising the real HTTP stack | Full fast Playwright suite if navigation/shared UI changed |
| Dependency change | Relevant unit/integration tests | `composer audit` and dependency-review CI |
| Security-sensitive change | Focused tests plus `python scripts/ci/check_security_rules.py --staged` | Security notes in PR; consider Shannon/ZAP follow-up |

## Standard Pre-Commit Set

Before committing staged workflow or code changes, run:

```bash
python scripts/ci/check_security_rules.py --staged
python scripts/ci/check_agent_commit_gates.py --staged
python scripts/ci/check_docs.py --staged
python scripts/ci/check_test_touches.py --staged
```

The installed git hook runs these automatically, but running them manually gives
faster feedback while iterating.

## Browser Checks

For user-facing changes, retrieve the PR preview URL after the preview job runs:

```bash
python scripts/agent/preview-url.py --pr <number>
```

Use the preview for manual sanity checks when local Docker is not already
running. For local checks, use `http://localhost:8890`.
