# Shared Administration Release Readiness

Use this checklist before promoting Iteration 1 shared-administration changes beyond development. Each item needs linked evidence from CI, local commands, review notes, or deployment logs.

| Area | Required evidence | Status |
| --- | --- | --- |
| API contract | Documented request/response behavior for group-scoped account and transaction reads/writes, including error status for authorization failures, route/request mismatch, malformed group ids, and read-only writes. | Pending |
| Focused PHPUnit/API suites | Focused account API tests pass, transaction API tests pass when present, and shared-administration fail-closed coverage passes for the matrix in `docs/shared-administration/fail-closed-test-matrix.md`. | Pending |
| Backward compatibility | Existing single-user and single-group flows still work without requiring clients to send new group fields unless the API contract explicitly requires them. | Pending |
| Regression leakage sweep | Search and test coverage confirm accounts, transactions, attachments, exports, autocomplete, rules, reconciliation, and bulk paths cannot leak data across groups. | Pending |
| Docker smoke | Fresh Docker container boots, health check passes, login/API auth works, and account/transaction read/write smoke tests run against the packaged image. | Pending |
| Migration/upgrade | Database migrations and upgrade commands run from the previous supported release with existing user/group/account/transaction data preserved. | Pending |
| Security/role sign-off | Maintainer review confirms role mapping, read-only enforcement, inactive/blocked user denial, stale membership behavior, and no active-group mutation. | Pending |
| Rollback rehearsal | Rollback steps are documented and rehearsed, including database backup/restore expectations and feature flag/config reversal if applicable. | Pending |
| Observability | Denials, suspicious group mismatches, stale membership failures, and mutation attempts emit actionable logs or metrics without exposing sensitive financial data. | Pending |
| Deployment verification evidence | Post-deploy checklist captures version, image/tag or commit, migration result, smoke-test output, representative API responses, and rollback readiness confirmation. | Pending |

## Release Gate

Do not release until every row is complete or has an explicit accepted risk signed off by the release owner and security reviewer. Accepted risks must include scope, user impact, mitigation, owner, and expiration date.
