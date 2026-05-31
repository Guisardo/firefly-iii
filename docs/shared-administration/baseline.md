# Shared Administration Baseline

Iteration 1 planning baseline recorded on 2026-05-31.

## Repository State

| Item | Value |
| --- | --- |
| Working directory | `/Users/lucas.rancez/.codex/worktrees/0cdc/firefly-iii` |
| Branch | `feature/shared-administration-access` |
| HEAD | `891f5cb42bc74ed12d1bd0d51ae927375919f65e` |
| Dirty state before these docs | Clean: `git status --porcelain=v1` returned no files |

## Focused Test Baseline

| Check | Status | Evidence |
| --- | --- | --- |
| PHP runtime | Blocked | `php -v` failed with `zsh:1: command not found: php`. |
| PHPUnit binary | Blocked | `vendor/bin/phpunit` was not executable or not present in this checkout. |
| Account API focused tests | Inspected, not run | Focused files present: `tests/integration/Api/Models/Account/ShowControllerTest.php` and `tests/integration/Api/Models/Account/ListControllerTest.php`. |
| Transaction API focused tests | Inspected, not run | No transaction model integration test file was present under `tests/integration/Api/Models` in this checkout. |
| Dockerfile syntax | Passed | `docker build --check .` completed with `Check complete, no warnings found.` |

Recommended focused command once PHP dependencies are available:

```sh
php vendor/bin/phpunit -c phpunit.xml --no-coverage \
    tests/integration/Api/Models/Account/ShowControllerTest.php \
    tests/integration/Api/Models/Account/ListControllerTest.php
```

If transaction API tests are added during Iteration 1, include those files in the same focused run before release readiness sign-off.
