# Shared-administration audit coverage

Iteration 3 uses Firefly III's existing audit conventions instead of adding a
new audit store:

- Operational audit messages continue to go through `Log::channel('audit')`,
  which is formatted by `AuditLogger` and `AuditProcessor`.
- Transaction field changes continue to use `audit_log_entries` through the
  existing transaction-group audit event and listener.
- Explicit shared-administration group selection now emits
  `SharedAdministrationGroupSelected`, handled by
  `LogsSharedAdministrationAccess`, after membership and role checks succeed.
- Explicit shared-administration denial now emits
  `SharedAdministrationAccessDenied`, handled by
  `LogsSharedAdministrationDenial`, before the authorization exception is
  returned.

The shared-administration audit events intentionally log ids and handler names,
not group titles, account names, transaction descriptions, balances, or related
object ids from inaccessible groups. This keeps audit useful for operators
without weakening the non-enumerating API failure contract.

## Captured fields

Successful explicit group selection records:

- authenticated `user_id`
- requested `user_group_id`
- user's active/default `user_group_id`
- validating handler class
- accepted role set for that handler

Denied explicit group selection records:

- authenticated `user_id`
- requested `user_group_id`
- user's active/default `user_group_id`
- validating handler class
- denial reason bucket
- accepted role set for that handler

## Deferred sinks

There is not yet a dedicated shared-administration database audit table. The
existing `audit_log_entries` table is transaction-auditable oriented and is not
a good fit for request authorization decisions without a broader model change.
If this fork later needs queryable request-level audit history, add a separate
request-audit sink keyed by user id, requested group id, effective group id,
route name, method, status, and denial reason while preserving the current
non-enumerating response behavior.
