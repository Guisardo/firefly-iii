# Iteration 1 shared-administration role matrix

This document defines the Iteration 1 authorization contract for `UserRoleEnum`.
Roles are additive unless noted otherwise: a member may hold multiple specific
roles in the same user group. `FULL` and `OWNER` are role-family shortcuts.

## Permission levels

| Level | Meaning |
| --- | --- |
| None | The member must not see the navigation entry, list endpoint results, object details, autocomplete results, or action buttons for the capability. Direct API calls fail without confirming whether data exists. |
| Read | The member may list, show, search, autocomplete, and use the object as a selector/input for another authorized action. Read does not include create, update, delete, trigger, execute, export, destroy, purge, membership mutation, or configuration changes. |
| Manage | The member may create, update, delete, reorder, trigger, execute, attach/detach, and otherwise mutate objects in that capability. Manage includes read for the same capability. |
| Admin | The member may perform group-administrative actions that affect membership, role assignment, destructive data operations, or group ownership. Admin includes read/manage where listed for that role family. |

## Role coverage

| `UserRoleEnum` case | Value | Role family | Accounts | Transactions | Metadata | Budgets | Piggy banks | Bills/subscriptions | Rules | Recurring | Webhooks | Currencies | Reports | Export/destroy/purge | Memberships | UI visibility |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `READ_ONLY` | `ro` | Base read | Read | Read | Read | None | None | None | None | None | None | None | None | Export only | None | Show account, transaction, category, tag, object-group, dashboard/chart, search, and export affordances; hide all mutation and non-granted domain navigation. |
| `MANAGE_TRANSACTIONS` | `mng_trx` | Transaction manage | Manage | Manage | Read | None | None | None | None | None | None | None | None | None | None | Show account and transaction mutation controls; hide metadata mutation and non-granted domain controls. |
| `MANAGE_META` | `mng_meta` | Metadata manage | Read | Read | Manage | None | None | None | None | None | None | None | None | None | None | Show category, tag, attachment, link type, object-group, and related metadata mutation controls; hide financial-object mutation unless separately granted. |
| `READ_BUDGETS` | `read_budgets` | Domain read | None | None | None | Read | None | None | None | None | None | None | None | None | None | Show budget navigation, lists, details, autocomplete, and selectors; hide budget mutation controls. |
| `READ_PIGGY_BANKS` | `read_piggies` | Domain read | None | None | None | None | Read | None | None | None | None | None | None | None | None | Show piggy-bank navigation, lists, details, autocomplete, and selectors; hide piggy-bank mutation controls. |
| `READ_SUBSCRIPTIONS` | `read_subscriptions` | Domain read | None | None | None | None | None | Read | None | None | None | None | None | None | None | Show bill/subscription navigation, lists, details, autocomplete, and selectors; hide bill/subscription mutation controls. |
| `READ_RULES` | `read_rules` | Domain read | None | None | None | None | None | None | Read | None | None | None | None | None | None | Show rule and rule-group navigation, lists, details, autocomplete, and selectors; hide rule mutation, test, and trigger controls. |
| `READ_RECURRING` | `read_recurring` | Domain read | None | None | None | None | None | None | None | Read | None | None | None | None | None | Show recurring-transaction navigation, lists, details, autocomplete, and selectors; hide recurring mutation and trigger controls. |
| `READ_WEBHOOKS` | `read_webhooks` | Domain read | None | None | None | None | None | None | None | None | Read | None | None | None | None | Show webhook navigation, lists, details, messages, and attempts; hide webhook mutation, submission, and deletion controls. |
| `READ_CURRENCIES` | `read_currencies` | Domain read | None | None | None | None | None | None | None | None | None | Read | None | None | None | Show currency and exchange-rate navigation, lists, details, autocomplete, and selectors; hide currency mutation controls. |
| `MANAGE_BUDGETS` | `mng_budgets` | Domain manage | None | None | None | Manage | None | None | None | None | None | None | None | None | None | Show budget create, update, delete, limit, available-budget, reorder, and related mutation controls. |
| `MANAGE_PIGGY_BANKS` | `mng_piggies` | Domain manage | None | None | None | None | Manage | None | None | None | None | None | None | None | None | Show piggy-bank create, update, delete, attach/detach, deposit/withdrawal, and reorder controls. |
| `MANAGE_SUBSCRIPTIONS` | `mng_subscriptions` | Domain manage | None | None | None | None | None | Manage | None | None | None | None | None | None | None | Show bill/subscription create, update, delete, rule-linking, and object-group mutation controls. |
| `MANAGE_RULES` | `mng_rules` | Domain manage | None | None | None | None | None | None | Manage | None | None | None | None | None | None | Show rule and rule-group create, update, delete, reorder, test, trigger, and enable/disable controls. |
| `MANAGE_RECURRING` | `mng_recurring` | Domain manage | None | None | None | None | None | None | None | Manage | None | None | None | None | None | Show recurring create, update, delete, trigger, skip, and enable/disable controls. |
| `MANAGE_WEBHOOKS` | `mng_webhooks` | Domain manage | None | None | None | None | None | None | None | None | Manage | None | None | None | None | Show webhook create, update, delete, submit, retry, message, and attempt controls. |
| `MANAGE_CURRENCIES` | `mng_currencies` | Domain manage | None | None | None | None | None | None | None | None | None | Manage | None | None | None | Show currency and exchange-rate create, update, delete, enable/disable, primary/default, and rate-management controls. |
| `VIEW_REPORTS` | `view_reports` | Reports read | Read inputs only | Read inputs only | Read inputs only | Read inputs only | Read inputs only | Read inputs only | None | None | None | Read inputs only | Read | None | None | Show reports, charts, summaries, and report filters for otherwise readable data; hide report actions that mutate source data. |
| `VIEW_MEMBERSHIPS` | `view_memberships` | Membership read | None | None | None | None | None | None | None | None | None | None | None | None | Read | Show group member list, role list, invitations, and membership details; hide invite, remove, role-change, and group-update controls. |
| `FULL` | `full` | Group administrator | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Read/manage reports | Admin, except owner-only group deletion and original-creator changes | Admin, except owner-only original-creator changes | Show all group administration and data-management controls except owner-only controls. |
| `OWNER` | `owner` | Original creator | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Manage | Read/manage reports | Admin, including export, destroy, purge, owner-only group deletion, and original-creator changes | Admin | Show all controls for the group, including owner-only destructive and ownership controls. |

## Capability notes

Accounts include asset, expense, revenue, liability, opening-balance, reconciliation, account attachments, balances, and account chart data.

Transactions include transaction groups, journals, splits, bulk operations, reconciliation entries, transaction links, transaction attachments, imports into the target group, and transaction chart data.

Metadata includes categories, tags, object groups, notes, attachments where not tied to a stricter domain, transaction link types, locations, and other descriptive labels shared by financial objects.

Budgets include budgets, budget limits, available budgets, budget autocomplete, budget charts, and budget report inputs.

Piggy banks include piggy-bank groups, events, deposit/withdrawal actions, target data, piggy-bank autocomplete, and piggy-bank report inputs.

Bills/subscriptions include bills, subscription matching, bill groups, bill-related rules, bill autocomplete, and bill report inputs.

Rules include rules, rule groups, rule actions/triggers, test endpoints, trigger/fire endpoints, and rule autocomplete.

Recurring includes recurrence definitions, recurrence repetitions, skip/trigger actions, generated transaction previews, and recurrence autocomplete.

Webhooks include webhook definitions, delivery attempts, messages, trigger/submit/retry actions, and webhook logs exposed through the API.

Currencies include transaction currencies, exchange rates, default/primary currency selection for the group, currency enablement, and currency autocomplete.

Reports include report pages, chart endpoints, summaries, insights, dashboard aggregates, and report export artifacts that do not perform the data export operation described below.

Export/destroy/purge includes `/api/v1/data/export`, `/api/v1/data/destroy`, `/api/v1/data/purge`, bulk data deletion, and any operation intended to remove all or broad classes of group data. Iteration 1 allows export to `READ_ONLY` because it is a read operation over otherwise readable data. Destroy and purge require `FULL` or `OWNER`; owner-only group deletion remains `OWNER`.

Memberships include listing user groups, showing a group, listing members, showing roles, invitations, accepting/revoking invitations, changing roles, removing members, and changing group metadata. `VIEW_MEMBERSHIPS` is read-only. `FULL` can administer memberships but must not remove or demote the original creator and must not delete the group. `OWNER` can perform owner-only membership and group lifecycle actions.

## Negative-test expectations

Every negative test must assert both the response and the absence of side effects. Failed requests must not create audit-visible mutations, must not update `users.user_group_id`, and must not disclose whether a denied object exists in another group.

| Role family | Expected denied cases |
| --- | --- |
| Base read (`READ_ONLY`) | Creating, updating, deleting, importing, reconciling, bulk-editing, triggering, or purging data fails. Reading budgets, piggy banks, bills/subscriptions, rules, recurring entries, webhooks, currencies, reports, and memberships fails unless a matching role is also assigned. |
| Transaction manage (`MANAGE_TRANSACTIONS`) | Managing metadata, budgets, piggy banks, bills/subscriptions, rules, recurring entries, webhooks, currencies, reports, memberships, destroy, and purge fails unless a matching role is also assigned. |
| Metadata manage (`MANAGE_META`) | Creating or editing financial transactions, accounts, budgets, piggy banks, bills/subscriptions, rules, recurring entries, webhooks, currencies, reports, memberships, destroy, and purge fails unless a matching role is also assigned. |
| Domain read (`READ_*`) | Mutating the same domain fails. Reading or mutating unrelated domains fails. Domain read must not imply `READ_ONLY`, transaction read, report read, membership read, destroy, or purge. |
| Domain manage (`MANAGE_*`) | Mutating unrelated domains fails. Domain manage must not imply `READ_ONLY`, transaction manage, report read, membership admin, destroy, or purge. |
| Reports (`VIEW_REPORTS`) | Source-data mutation, export/destroy/purge, membership access, and reports over domains the member cannot otherwise read fail. |
| Membership read (`VIEW_MEMBERSHIPS`) | Inviting users, changing roles, removing users, changing group metadata, changing active group, deleting groups, and reading financial data fail unless a matching role is also assigned. |
| Group admin (`FULL`) | Removing or demoting the original creator, deleting the group, and owner-only lifecycle actions fail. Cross-group access still fails when the member has no membership in the requested group. |
| Owner (`OWNER`) | Access to groups where the user has no membership fails unless the user is also a system owner under a separately documented system-admin path. Malformed, conflicting, or unsupported `user_group_id` input fails before owner privileges are evaluated. |
