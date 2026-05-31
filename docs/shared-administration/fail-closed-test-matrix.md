# Shared Administration Fail-Closed Test Matrix

Iteration 1 must prove shared-administration scoping fails closed. A request that cannot prove the caller is an active member with the required role for the requested user group must be denied before data access or mutation.

| Scenario | Setup | Request | Expected result | Evidence to capture |
| --- | --- | --- | --- | --- |
| Non-member group | User has no membership for requested group A. | Read or write a group-scoped account or transaction resource for group A. | Deny with 403 or equivalent authorization failure; no records from group A are returned or changed. | API response, database count before and after. |
| Active group different from requested | User is active in group A and member of group B; request targets group B while active context remains group A. | Read and write group B resource without an explicit, validated context switch. | Deny unless the endpoint contract explicitly allows requested group B and validates membership and role; active group A is not silently overridden. | Response status and active group value after request. |
| Read-only write denial | User has read-only role in requested group. | POST, PUT, PATCH, DELETE, import, reconciliation, or bulk mutation. | Deny write with 403; allow only documented read endpoints. | Response status, unchanged target row, unchanged audit/event count except denial logging. |
| Route/request mismatch | Route group id is A but request body, query, or nested resource id points at group B. | Submit mismatched group identifiers on account or transaction endpoint. | Deny with 400, 403, 409, or 422; never choose one id implicitly. | Request payload, validation/authorization error, no mutation. |
| Third-group IDs | Active group is A, route targets B, and body/query/nested ids reference C. | Create or update account/transaction using C-owned related ids. | Deny before resolving or mutating C-owned entities; no leakage from B or C. | Response status, logs without sensitive C data, unchanged B and C rows. |
| Malformed group ID | Group id is non-numeric, empty, negative, zero when invalid, overflow, or otherwise malformed. | Call group-scoped route or send group id in body/query. | Reject as bad input or not found; no fallback to active group or default group. | Response status, validation error, active group unchanged. |
| Blocked or inactive user | Authenticated principal is blocked, disabled, inactive, or token belongs to such a user. | Any shared-administration read or write. | Deny before membership or role evaluation; no group data returned. | Response status, authentication/authorization log marker. |
| Stale membership | User had access when token/session was issued, then membership or role was revoked. | Reuse old session/token for read and write requests. | Deny using current membership state; cached membership is invalidated or rechecked. | Membership update timestamp, response status after revocation. |
| Query/body conflicts | Query says group A while body says group B, or duplicated fields disagree. | Submit conflicting identifiers on account/transaction list, store, or update. | Reject conflict; no precedence rule that can be abused. | Response status and explicit conflict/validation error. |
| No active-group mutation | Request includes a valid group id that differs from current active group. | Any failed or successful scoped request that is not the documented group-switch endpoint. | Request does not mutate active group/session/user preference. | Active group before and after, session/user preference diff. |

## Minimum Assertions

- Denied requests assert both status and absence of side effects.
- Positive controls prove the same user can access the same endpoint when membership, role, active context, and route/request group all align.
- Logs and error payloads avoid exposing account names, transaction descriptions, balances, or ids from unauthorized groups.
- Bulk endpoints, nested resources, autocomplete, export, attachments, and reconciliation paths inherit the same fail-closed behavior as direct account and transaction routes.
