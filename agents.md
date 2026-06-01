# Agent rules

Fork: `firefly-iii/firefly-iii`. Keep upstream-rebase friendly. No broad refactor.

## Goal

Shared access = whole administration, not per-account ACL.

Use existing:
- `user_groups`
- `group_memberships`
- `user_roles`
- `user_group_id`

`user_id` = creator/audit. Not ACL when explicit admin selected.

v1 API: no `user_group_id` => preserve current behavior.

## Auth

Never trust `users.user_group_id` for auth. It is default selector only.

Explicit shared path must:
1. resolve request `UserGroup`
2. check membership
3. check role
4. pass resolved group to validation/repo/factory/collector/enrichment/binder
5. fail closed

Roles:
- read accounts/trx: `READ_ONLY|FULL|OWNER`
- create/update/delete accounts/trx: `MANAGE_TRANSACTIONS|FULL|OWNER`
- group title/currency/members: `FULL|OWNER`
- group delete/owner ops: `OWNER`

Blocked user, no membership, bad role => deny.

## API

No hidden active-admin scoping in v1.

Use explicit `user_group_id` or additive group routes.

Do not globally swap `{account}` / `{transactionGroup}` binders to active-group binders.

`BelongsUserGroup` only when resolved group exists. Else keep `BelongsUser`.

## User groups

Do not uncomment placeholder routes without real controller + request + role tests.

Implement real:
- store
- use/switch
- update membership
- destroy

Before destroy route: fix `UserGroupRepository::destroy()` replacement assignment.
Use `GroupMembership::user_group_id`, not membership id.

## Docker/deploy

Image repo: `guisardo/firefly-iii`.

Build/push via GitHub Actions.

Tags:
- immutable: `shared-admin-${GITHUB_SHA}`
- moving: `shared-admin-latest`

`firefly-gcp` must pin image tag. No production `fireflyiii/core:latest`.

Prod + local-import must use same image tag.

Before prod cutover: SQLite backup + previous image tag + restore steps.

## GitHub

Use GitHub REST/GraphQL API. Do not use `gh`.

Do not add `Assisted-by` commit footer.

## README

When editing README:
- say fork of `firefly-iii/firefly-iii`
- link upstream docs/license/support
- explain fork goal: multi-user shared administration access
- document Docker Hub tags + `firefly-gcp` consumption

## Tests

Need focused tests:
- same-group read accounts/trx with explicit `user_group_id`
- cross-group denied
- no `user_group_id` unchanged
- read-only cannot write/change members
- multi-group user: requested group wins, active/default differs
- deploy: image pin, local-import parity, update no-op, container health, external login, rollback

Run narrow tests first. Broaden after. Avoid unrelated format churn.
