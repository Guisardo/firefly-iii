# Shared Administration Release Readiness

Use this checklist before promoting shared-administration changes beyond development. Each item needs linked evidence from CI, local commands, review notes, or deployment logs.

| Area | Required evidence | Status |
| --- | --- | --- |
| API contract | Documented request/response behavior for group-scoped account and transaction reads/writes, including error status for authorization failures, route/request mismatch, malformed group ids, and read-only writes. | Pending |
| Focused PHPUnit/API suites | Focused account API tests pass, transaction API tests pass, transaction-journal route tests pass, user-group admin API tests pass, and shared-administration fail-closed coverage passes for the matrix in `docs/shared-administration/fail-closed-test-matrix.md`. Record exact commands, commit SHA, pass/fail, and artifact/log links using `docs/shared-administration/iteration-4-release-evidence.md`. | Pending |
| Backward compatibility | Existing single-user and single-group flows still work without requiring clients to send new group fields unless the API contract explicitly requires them. Account create/update/delete without `user_group_id` must pass the mandatory no-`user_group_id` compatibility gate in `docs/shared-administration/iteration-4-release-evidence.md`; this coverage is not risk-accepted by default. | Pending |
| Regression leakage sweep | Search and test coverage confirm accounts, transactions, attachments, exports, autocomplete, rules, reconciliation, and bulk paths cannot leak data across groups. | Pending |
| Docker smoke | Fresh Docker container boots, health check passes, login/API auth works, and account/transaction read/write smoke tests run against the packaged image. | Pending |
| Docker image publication | Manual GitHub Actions workflow `.github/workflows/docker-publish.yml` publishes `guisardo/firefly-iii:shared-admin-${GITHUB_SHA}` and, when approved, `guisardo/firefly-iii:shared-admin-latest`; release evidence records the pushed digest, image metadata, SBOM, scan output, signature verification, and provenance verification. | Pending |
| Production image pin | Production and local-import deployments use the same immutable `shared-admin-${GITHUB_SHA}` tag or image digest. No production deployment uses `fireflyiii/core:latest` or unpinned `shared-admin-latest`. | Pending |
| Migration/upgrade | Database migrations and upgrade commands run from the previous supported release with existing user/group/account/transaction data preserved. | Pending |
| Security/role sign-off | Maintainer review confirms role mapping, read-only enforcement, inactive/blocked user denial, stale membership behavior, and no active-group mutation. | Pending |
| Rollback rehearsal | Rollback steps are documented and rehearsed, including database backup/restore expectations and feature flag/config reversal if applicable. | Pending |
| Observability | Denials, suspicious group mismatches, stale membership failures, and mutation attempts emit actionable logs or metrics without exposing sensitive financial data. | Pending |
| Deployment verification evidence | Post-deploy checklist captures version, image/tag or commit, migration result, smoke-test output, representative API responses, and rollback readiness confirmation. | Pending |

## Docker Validation and Publication

`.github/workflows/shared-access-pr.yml` validates the shared-administration
candidate. It installs locked PHP and Node dependencies, builds both asset
workspaces, and runs the SQLite container boot smoke. It does not publish Docker
Hub images.

`.github/workflows/docker-publish.yml` is the manual publication workflow for
Docker Hub repository `guisardo/firefly-iii`. It publishes the immutable
`shared-admin-${GITHUB_SHA}` tag and, when approved, the moving
`shared-admin-latest` tag.

Deployment consumers, including `firefly-gcp` and local import tooling, must pin
the same immutable `shared-admin-${GITHUB_SHA}` tag or image digest. Do not
deploy `fireflyiii/core:latest` or `shared-admin-latest` to production.

Before production cutover, use
`docs/shared-administration/firefly-gcp-sqlite-rollback-playbook.md` as the
single in-repo source for the SQLite backup, stopped-service cutover, abort, and
rollback sequence. Matching `firefly-gcp` docs must be updated in the
coordinated deploy repository.

## Release Gate

Do not release until every row is complete or has an explicit accepted risk signed off by the release owner and security reviewer. Accepted risks must include scope, user impact, mitigation, owner, and expiration date.

## Docker Hub Image Release Evidence

The shared-administration fork publishes images to Docker Hub repository `guisardo/firefly-iii` with the manual workflow `.github/workflows/docker-publish.yml`.

Required tags:

- Immutable: `guisardo/firefly-iii:shared-admin-${GITHUB_SHA}`
- Moving: `guisardo/firefly-iii:shared-admin-latest`

Required approval controls:

- Dispatch from `main` is required for `shared-admin-production`.
- Dispatch from `develop` or `feature/shared-administration-access` is allowed only for `shared-admin-staging`.
- The workflow requires a GitHub environment approval and Docker Hub secrets `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

Required release evidence:

- `image-digest.txt` records the digest that production and local-import must pin.
- `image-tags.env`, `build-metadata.json`, `image-inspect.txt`, and `image-manifest.json` record build inputs and pushed registry state.
- `trivy-image.sarif` records vulnerability, secret, and image configuration scan results. `high-critical` is the default blocking gate; `advisory` is allowed only with an accepted release risk.
- `sbom.cdx.json` records a retained CycloneDX SBOM. Buildx also publishes OCI SBOM and provenance attestations during image push.
- `cosign-signature-verify.txt`, `cosign-sbom-verify.txt`, and `cosign-provenance-verify.txt` record signature, SBOM attestation, and provenance attestation verification when the vulnerability gate passes.
- `trivy-license.txt` is retained as an advisory license scan. Treat license findings as release-owner review input because policy enforcement depends on the deployment and redistribution posture.

Production cutover must record the previous pinned image ref, take a SQLite
backup before replacing the container, keep `firefly.service` stopped until the
pinned-image cutover starts, deploy the pinned `shared-admin-${GITHUB_SHA}` tag
or digest, verify container health and external login, and keep the rollback
playbook ready before marking the release complete.
