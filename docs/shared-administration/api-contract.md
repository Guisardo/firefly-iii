# Iteration 1 shared-administration API contract

This document defines how v1 API requests select a user group for shared
administration authorization.

## Scope

Iteration 1 applies to authenticated `/api/v1` endpoints that read or mutate
group-owned financial data, group metadata, reports, export/destroy/purge
operations, and user-group membership resources.

The contract does not change OAuth/token authentication, system-owner checks,
cron endpoints, installation endpoints, unauthenticated endpoints, or global
system configuration endpoints unless those endpoints are explicitly migrated to
group authorization later.

## Allowed `user_group_id` locations

`user_group_id` is the only group override field name accepted by Iteration 1.
Aliases such as `group_id`, `userGroupId`, `active_group`, and
`user_group` are unsupported.

| Location | Allowed | Contract |
| --- | --- | --- |
| Route parameter | Only for routes whose resource identity is a user group, currently `/api/v1/user-groups/{userGroup}`. This route parameter is named `userGroup`, not `user_group_id`. | The route parameter selects the membership resource and is treated as the requested group id for authorization. |
| Query string | Yes, for migrated authenticated `/api/v1` endpoints. | `?user_group_id=<integer>` selects the group for this request only. |
| JSON body | Yes, for migrated endpoints that accept a JSON request body. | `{ "user_group_id": <integer> }` selects the group for this request only. |
| Form body | Yes, for migrated endpoints that already accept form or multipart bodies. | `user_group_id=<integer>` selects the group for this request only. |
| Nested body fields | No. | Nested fields such as `transactions[0][user_group_id]` or `data.attributes.user_group_id` are ordinary payload fields only when an endpoint explicitly documents them; they do not select the authorization group. |
| Headers | No. | Headers such as `X-User-Group-Id` are ignored for selection and should be rejected by clients before sending. |

## Selection and precedence

The effective group id is selected in this order:

1. Supported route group parameter.
2. Top-level query parameter `user_group_id`.
3. Top-level body parameter `user_group_id`.
4. The authenticated user's current active group from `users.user_group_id`.

If no `user_group_id` is supplied in a supported route, query, or body location,
the request preserves current v1 behavior: it authorizes and executes against
the authenticated user's active group from `users.user_group_id`.

Using `user_group_id` is request-scoped. A successful request with
`user_group_id` must never mutate `users.user_group_id`. A failed request with
`user_group_id` must also never mutate `users.user_group_id`.

## Conflict behavior

The server must normalize all supplied group ids to integers before conflict
checks.

| Request shape | Result |
| --- | --- |
| Route/query/body locations provide the same normalized integer. | Accept the request and use that group id. |
| Route and query provide different group ids. | Reject with `409 Conflict`. |
| Route and body provide different group ids. | Reject with `409 Conflict`. |
| Query and body provide different group ids. | Reject with `409 Conflict`. |
| Query and body provide the same group id, with no route group parameter. | Accept the request; query has precedence for request logging, but the effective group is identical. |
| Multiple query values are supplied, such as `?user_group_id=1&user_group_id=2`. | Reject with `409 Conflict` when values differ; accept only if every supplied value normalizes to the same integer. |

Conflict checks happen before object lookup and before role checks. Conflict
responses must not disclose which group id, if any, exists or is accessible.

## Malformed and missing handling

| Case | Response code | Error shape | Notes |
| --- | --- | --- | --- |
| Missing `user_group_id` on a migrated endpoint. | Endpoint's normal success or auth failure code. | Endpoint's normal shape. | Uses `users.user_group_id` for this request only and does not mutate it. |
| Authenticated user has no active `users.user_group_id` and no request group is supplied. | `401 Unauthorized`. | Authorization error shape. | Treat as no accessible group. |
| `user_group_id` is `null`, empty string, non-numeric, float, boolean, object, array, zero, or negative. | `422 Unprocessable Entity`. | Validation error shape. | Reject before membership and object lookup. |
| `user_group_id` exceeds the supported integer range. | `422 Unprocessable Entity`. | Validation error shape. | Reject before membership and object lookup. |
| `user_group_id` identifies no group. | `401 Unauthorized`. | Authorization error shape. | Same response as inaccessible group. |
| `user_group_id` identifies a group where the user has no membership. | `401 Unauthorized`. | Authorization error shape. | Same response as missing group. |
| User has membership but lacks the required role for the endpoint. | `401 Unauthorized`. | Authorization error shape. | Same response as inaccessible group. |
| Resource id exists in another group but not in the effective group. | `404 Not Found` or `401 Unauthorized`, matching the endpoint's existing binder behavior. | Non-enumerating error shape. | Must not reveal cross-group existence. |

Validation error shape:

```json
{
  "message": "Validation exception: The given data was invalid.",
  "errors": {
    "user_group_id": [
      "The user group id field must be a positive integer."
    ]
  }
}
```

Authorization error shape:

```json
{
  "message": "This user does not have access to this user group.",
  "exception": "AuthorizationException"
}
```

Not-found error shape:

```json
{
  "message": "Resource not found",
  "exception": "NotFoundHttpException"
}
```

Conflict error shape:

```json
{
  "message": "Conflicting user_group_id values were supplied.",
  "exception": "ConflictHttpException"
}
```

Unsupported-location or unsupported-endpoint error shape:

```json
{
  "message": "The user_group_id parameter is not supported for this endpoint.",
  "exception": "HttpException"
}
```

## Unsupported endpoints

If an endpoint has not been migrated to Iteration 1 group authorization, it must
not silently apply a submitted `user_group_id`.

| Endpoint category | `user_group_id` behavior | Response code |
| --- | --- | --- |
| Unauthenticated endpoints, OAuth token endpoints, login/logout, registration, password reset, health checks, installation, and cron. | Unsupported. | `400 Bad Request` when `user_group_id` is supplied. |
| System-scoped endpoints that manage application configuration, system users, diagnostics, or non-group global state. | Unsupported unless the endpoint explicitly documents group selection. | `400 Bad Request` when `user_group_id` is supplied. |
| Endpoints that are migrated but do not own group data and do not need group context. | Unsupported unless explicitly documented. | `400 Bad Request` when `user_group_id` is supplied. |
| Endpoints migrated for group-owned resources. | Supported in the allowed locations above. | Endpoint-specific success code, `401`, `404`, `409`, or `422` as applicable. |

## Response-code contract

| Condition | Code |
| --- | --- |
| Successful read/list/report/autocomplete/export. | Existing endpoint code, usually `200 OK`. |
| Successful create. | Existing endpoint code, usually `200 OK` or `201 Created`. |
| Successful update. | Existing endpoint code, usually `200 OK`. |
| Successful delete/destroy/purge. | Existing endpoint code, usually `204 No Content` or `200 OK`. |
| Unauthenticated request. | `401 Unauthorized`. |
| Missing group, inaccessible group, or insufficient role. | `401 Unauthorized`. |
| Malformed `user_group_id`. | `422 Unprocessable Entity`. |
| Conflicting route/query/body group ids. | `409 Conflict`. |
| `user_group_id` supplied to unsupported endpoint or unsupported location. | `400 Bad Request`. |
| Object not found in the effective group. | `404 Not Found`. |
| Method not supported by route. | `405 Method Not Allowed`. |

Iteration 1 intentionally uses `401 Unauthorized` for missing group,
inaccessible group, and insufficient role to preserve the current v1
authorization behavior and avoid group enumeration.

## Non-enumerating failure requirements

Failures for missing groups, inaccessible groups, insufficient roles, and
cross-group object ids must be indistinguishable to the client except where the
endpoint's existing binder already returns `404 Not Found`.

Responses must not include:

- Group title, owner, member count, role list, or timestamps.
- Whether the requested group id exists.
- Whether the requested object id exists in another group.
- Which role would have been sufficient, unless the endpoint is a membership
  self-inspection endpoint that the user is authorized to read.

Logs may include diagnostic details for operators, but API responses must keep
the non-enumerating shape above.

## Role authorization

After the effective group is selected, authorization checks the authenticated
user's membership and roles in that group only. Roles in the active group do not
grant access to a different requested group. Roles in another group do not grant
access to the effective group.

`FULL` and `OWNER` satisfy role checks as documented in the role matrix.
System-owner privileges remain outside this Iteration 1 group-role contract and
must not be used to infer group access unless an endpoint explicitly documents a
system-admin override.

## Active-group preservation

Requests without `user_group_id` must preserve the current v1 active-group
behavior:

- Use `users.user_group_id` as the effective group id.
- Do not require clients to send `user_group_id`.
- Do not change `users.user_group_id`.
- Do not create, remove, or reorder group memberships.

Requests with `user_group_id` must also be request-scoped:

- Use the supplied group only for authorization, binding, querying, creation,
  update, deletion, reports, export, destroy, or purge performed by that
  request.
- Stamp newly created group-owned records with the effective group id.
- Never persist the override into `users.user_group_id`.
- Never switch the user's UI active group as a side effect.
