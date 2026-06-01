# Iteration 4 Shared Administration Release Evidence

Record the evidence below for the release candidate commit before promotion. Every record must include the exact command, the full commit SHA from `git rev-parse HEAD`, pass/fail status, and a retained CI artifact or log link.

## Required Records

| Area | Exact command | Required artifact or log | Status |
| --- | --- | --- | --- |
| Account read/list/write/delete and no-`user_group_id` compatibility | `php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-account-compatibility-junit.xml tests/integration/Api/Models/Account/ShowControllerTest.php tests/integration/Api/Models/Account/SharedAdministrationListControllerTest.php tests/integration/Api/Models/Account/SharedAdministrationWriteControllerTest.php` | `storage/logs/shared-admin-account-compatibility-junit.xml` or CI artifact containing the same JUnit/log output. | Pending CI pass |
| Transaction read/create/update/delete and transaction-journal routes | `php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-transaction-journal-junit.xml tests/integration/Api/Models/Transaction/ShowControllerTest.php tests/integration/Api/Models/Transaction/WriteScopeControllerTest.php tests/integration/Api/Models/Transaction/TransactionJournalScopeControllerTest.php` | `storage/logs/shared-admin-transaction-journal-junit.xml` or CI artifact containing the same JUnit/log output. | Pending CI pass |
| User-group administration APIs | `php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-user-groups-junit.xml tests/integration/Api/Models/UserGroup/UserGroupControllerTest.php` | `storage/logs/shared-admin-user-groups-junit.xml` or CI artifact containing the same JUnit/log output. | Pending CI pass |
| Resolver authorization, scoped request authorization, and route binders | `php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-authorization-junit.xml tests/integration/Support/Http/SharedAdministration/AdministrationResolverTest.php tests/integration/Support/Http/SharedAdministration/ScopedRequestAuthorizationTest.php tests/integration/Support/Binder/RouteBinderUserGroupTest.php` | `storage/logs/shared-admin-authorization-junit.xml` or CI artifact containing the same JUnit/log output. | Pending CI pass |
| Multi-group regression and audit tests | `php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-regression-audit-junit.xml tests/integration/Api/SharedAdministration/MultiGroupRegressionTest.php tests/integration/Api/SharedAdministration/SharedAdministrationAuditTest.php` | `storage/logs/shared-admin-regression-audit-junit.xml` or CI artifact containing the same JUnit/log output. | Pending CI pass |

## Combined Gate

Use this command as the final focused release gate after the narrower records pass:

```sh
php vendor/bin/phpunit -c phpunit.xml --no-coverage --log-junit storage/logs/shared-admin-iteration-4-junit.xml tests/integration/Api/Models/Account/ShowControllerTest.php tests/integration/Api/Models/Account/SharedAdministrationListControllerTest.php tests/integration/Api/Models/Account/SharedAdministrationWriteControllerTest.php tests/integration/Api/Models/Transaction/ShowControllerTest.php tests/integration/Api/Models/Transaction/WriteScopeControllerTest.php tests/integration/Api/Models/Transaction/TransactionJournalScopeControllerTest.php tests/integration/Api/Models/UserGroup/UserGroupControllerTest.php tests/integration/Support/Http/SharedAdministration/AdministrationResolverTest.php tests/integration/Support/Http/SharedAdministration/ScopedRequestAuthorizationTest.php tests/integration/Support/Binder/RouteBinderUserGroupTest.php tests/integration/Api/SharedAdministration/MultiGroupRegressionTest.php tests/integration/Api/SharedAdministration/SharedAdministrationAuditTest.php
```

Release evidence must attach `storage/logs/shared-admin-iteration-4-junit.xml`, the CI run URL, and the exact `git rev-parse HEAD` value used for the run.

## No-`user_group_id` Compatibility Gate

The account compatibility record is mandatory, not an accepted risk. It must show account create, update, and delete without `user_group_id` still use the active/default group, leave `users.user_group_id` unchanged, retain legacy response shape, and retain legacy validation failures. Do not promote a release candidate that omits this coverage unless a release owner and security reviewer explicitly replace this section with a dated accepted-risk record.
