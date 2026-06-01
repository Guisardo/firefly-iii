# Iteration 1 active-group inventory

This document inventories account and transaction flow reads of `users.user_group_id` and related active-group fallbacks. It classifies each location for Iteration 1 account/transaction shared-administration work.

Classification terms:

- **Keep active-group fallback**: keep the existing active-administration behavior for callers that do not submit an explicit `user_group_id`.
- **Replace with resolved group**: in account/transaction API paths, use the request-resolved group instead of `auth()->user()->user_group_id`, `auth()->user()->userGroup`, or user-owned relations.
- **Out of MVP**: leave unchanged for Iteration 1 because it belongs to another domain.

## Request and controller resolution

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| API group resolver | `app/Support/Http/Api/ValidatesUserGroupTrait.php` | If no `user_group_id` query/body value is present, falls back to `auth()->user()->user_group_id`; otherwise validates membership and accepted roles for the requested group. | Keep active-group fallback. This is the desired compatibility fallback. |
| Form request auth | `app/Support/Request/ChecksLogin.php` | Reads route `userGroup`, then request `user_group_id`, then falls back to `auth()->user()->user_group_id`. | Keep active-group fallback. Account/transaction requests can use this fallback, but controllers still need to pass the resolved group into repositories. |
| Base API controller | `app/Api/V1/Controllers/Controller.php` | Pulls primary currency and preferences from current auth user during middleware. | Replace with resolved group in explicit account/transaction paths where primary-currency conversion affects output. Keep active fallback otherwise. |
| Account controllers | `app/Api/V1/Controllers/Models/Account/*Controller.php` | Set account repository user only; no explicit `validateUserGroup()` call in index/show/store/update/delete/list flow. Enrichment receives only user and defaults to active group. | Replace with resolved group for account MVP. Preserve fallback when no explicit group is submitted. |
| Transaction store controller | `app/Api/V1/Controllers/Models/Transaction/StoreController.php` | Calls `validateUserGroup()`, sets transaction group repository group, passes `user_group` into store data, and sets collector group after creation. | Keep active-group fallback plus treat as supported explicit group. Audit remaining factory and currency fallbacks. |
| Transaction read/update/delete/list controllers | `app/Api/V1/Controllers/Models/Transaction/ShowController.php`, `UpdateController.php`, `DestroyController.php`, `ListController.php` | Use route-bound transaction group and collectors/repositories that default through active group unless explicitly set. | Replace with resolved group for transaction MVP. Store is the reference pattern. |
| Autocomplete account/transaction controllers | `app/Api/V1/Controllers/Autocomplete/AccountController.php`, `TransactionController.php` | Call `validateUserGroup()` and set repositories/collectors to the resolved group. | Keep active-group fallback; include in MVP verification. |
| Data bulk transaction controller | `app/Api/V1/Controllers/Data/Bulk/TransactionController.php` | Calls `validateUserGroup()` and sets repository group. | Keep active-group fallback; verify request validation and update services do not fall back to active group. |
| Export account/transaction controller | `app/Api/V1/Controllers/Data/Export/ExportController.php` | Calls `validateUserGroup()` and sets exporter group. | Keep active-group fallback; verify generator internals before promoting as complete. |

## Route binding

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Single account binding | `app/Support/Binder/UserGroupAccount.php` | Resolves `{account}` by `accounts.id` and `accounts.user_group_id = auth()->user()->user_group_id`. | Replace with resolved group. This blocks explicit-group account show/update/delete and subresources. |
| Single transaction binding | `app/Support/Binder/UserGroupTransaction.php` | Resolves `{transactionGroup}` by `transaction_groups.id` and `transaction_groups.user_group_id = auth()->user()->user_group_id`. | Replace with resolved group. This blocks explicit-group transaction show/update/delete/list subresources. |
| Account-list binding | `app/Support/Binder/AccountList.php` | Resolves account lists through `auth()->user()->accounts()`. | Replace with resolved group for account/transaction report/filter flows that remain in MVP; otherwise keep fallback. |
| Journal binding | `transaction-journals` route binding and journal request validation | Journal routes are transaction-adjacent but are not explicitly resolved by user group in the controller flow. | Replace with resolved group before treating journal endpoints as shared-administration supported. |
| Other binders | bill, exchange-rate, tag, category, budget, link, object binders | Mixed user or group checks depending on domain. | Out of MVP except exchange-rate behavior already using group-specific binder. |

## Repositories and services

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Repository group trait | `app/Support/Repositories/UserGroup/UserGroupTrait.php` | `setUser()` sets `$this->userGroup = $user->userGroup`; `setUserGroup()` can override it. | Keep active-group fallback. Account/transaction controllers must call `setUserGroup($resolvedGroup)` after `setUser()` when explicit group is present. |
| Account repository reads | `app/Repositories/Account/AccountRepository.php` | Most account reads use `$this->user->accounts()` or user-owned relationships rather than `$this->userGroup->accounts()`. | Replace with resolved group for account MVP reads and writes. This includes count/find/search/get-by-type/get-by-id/cash-account and order reset behavior. |
| Account tasker | `app/Repositories/Account/AccountTasker.php` | Primary currency uses `$this->user->userGroup`. | Replace with resolved group when tasker is used from account/transaction API paths. |
| Transaction group repository | `app/Repositories/TransactionGroup/TransactionGroupRepository.php` | Implements `UserGroupTrait`, but several helper reads use `$this->user->transactionJournals()`/`transactionGroups()` or `$this->user->userGroup` for primary currency. | Replace with resolved group for transaction MVP. Store already receives group; read/update/delete helper methods need audit. |
| Journal repositories | `app/Repositories/Journal/*Repository.php` | Use user group trait and transaction journal relations. | Replace with resolved group where transaction API paths reach them. |
| Budget/category/bill/piggy/rule/recurring repositories | `app/Repositories/{Budget,Category,Bill,PiggyBank,Rule,RuleGroup,Recurring}` | Many already support `setUserGroup()`, but domain behavior is broader than account/transaction MVP. | Out of MVP, except where transaction validation needs to verify referenced objects within the resolved group. |
| Currency repository and amount helpers | `app/Repositories/Currency/CurrencyRepository.php`, `Amount::getPrimaryCurrencyByUserGroup(...)` callers | Some code uses resolved group; other fallbacks use `$user->userGroup`. | Replace with resolved group in account/transaction API output and storage paths. Broader currency endpoint work is out of MVP. |

## Factories and storage paths

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Account factory create/find-or-create | `app/Factory/AccountFactory.php` | Creates account rows with `user_group_id = $this->user->user_group_id`; find/findOrCreate search via `$this->user->accounts()`. | Replace with resolved group for account MVP and transaction auto-created accounts. Add a `setUserGroup()` path or data-driven group assignment. |
| Transaction group factory | `app/Factory/TransactionGroupFactory.php` | `setUser()` defaults to `$user->userGroup`; `setUserGroup()` overrides and `create()` associates the group. | Keep active-group fallback; verify all API store paths call `setUserGroup($resolvedGroup)`. |
| Transaction journal factory | `app/Factory/TransactionJournalFactory.php` | `setUser()` defaults to `$user->userGroup`; `setUserGroup()` overrides repositories. Journal storage uses `$this->userGroup->id`, but default currency fallback still uses `$this->user->userGroup`. | Replace remaining active-group reads with resolved group in transaction API paths. |
| Transaction row factory | `app/Factory/TransactionFactory.php` | Stores journal/account transactions after journal/account selection. | Keep active-group fallback unless a direct `user_group_id` read is found during implementation. |
| Attachment factory/storage | `app/Factory/AttachmentFactory.php`, attachment repositories/controllers | Attachment ownership can attach to multiple object types. | Out of MVP except account/transaction attachment subresources should not be marked explicit until attachment group ownership is resolved from the attachable. |
| Export storage/generation | `app/Support/Export/ExportDataGenerator.php` and export controller | Controller sets user group, but generator internals have commented/partial group state. | Replace with resolved group for account/transaction export before promotion; other exports out of MVP. |

## Collectors, filters, and search

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Group collector | `app/Helpers/Collector/GroupCollector.php` | `setUserGroup()` stores resolved group and base query uses `$this->userGroup->transactionGroups()`. Without explicit set, it can remain active-group through repository/user defaults. | Keep active-group fallback; transaction MVP controllers must set resolved group for reads as well as store. |
| Collector payload | `app/Helpers/Collector/GroupCollector.php` selected fields | Emits `transaction_groups.user_group_id as user_group_id` into transformed transaction arrays. | Keep; this is output data, not a group-selection source. |
| Account API filter | `app/Support/Http/Api/AccountFilter.php` | Maps requested account types only. | Keep active-group fallback; no group read. |
| Transaction API filter | `app/Support/Http/Api/TransactionFilter.php` | Maps requested transaction types only. | Keep active-group fallback; no group read. |
| Account search | `app/Support/Search/AccountSearch.php` | Searches via `$this->user->accounts()`. | Replace with resolved group for `GET /v1/search/accounts` if search stays in MVP. |
| Transaction search | `app/Api/V1/Controllers/Search/TransactionController.php` and search engine dependencies | Transaction search is transaction-adjacent and should be collector/repository based. | Replace with resolved group before marking search as explicit; otherwise keep active-group only. |

## Enrichments and transformers

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Account enrichment | `app/Support/JsonApi/Enrichments/AccountEnrichment.php` | `setUser()` sets `$this->userGroup = $user->userGroup`; `setUserGroup()` exists but account controllers do not use it. Balance/meta enrichment can therefore reflect active group. | Replace with resolved group for account MVP. Controllers should pass the same resolved group to enrichment and repository. |
| Transaction group enrichment | `app/Support/JsonApi/Enrichments/TransactionGroupEnrichment.php` | `setUserGroup()` is a no-op; current enrichment does not use group context directly. | Keep active-group fallback for now; revisit only if enrichment starts loading group-scoped metadata. |
| Account transformer | `app/Transformers/AccountTransformer.php` | Constructor reads primary currency through `Amount::getPrimaryCurrency()`, not an explicit resolved group. | Replace with resolved group or precomputed primary currency where account API output is explicit-group. |
| Transaction group transformer | `app/Transformers/TransactionGroupTransformer.php` | Emits `user_group` from collector data; repository dependency can default to active group if used for helper lookups. | Keep emitted field; replace repository context with resolved group if helper methods are used in MVP paths. |
| Budget/category/recurring/piggy/webhook enrichments and transformers | `app/Support/JsonApi/Enrichments/*`, `app/Transformers/*` | Several default to `$user->userGroup` or have no-op group setters. | Out of MVP except referenced transaction metadata must not leak across groups. |

## Validation

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Transaction validation | `app/Validation/TransactionValidation.php` | `validateAccountInformation()` accepts optional `UserGroup`; if provided, each transaction row gets `user_group` and `AccountValidator` receives it. Store path currently passes the resolved group through controller data. | Keep active-group fallback; ensure update/bulk flows pass resolved group before explicit support. |
| Account validator | `app/Validation/AccountValidator.php` | Has `setUserGroup()` and forwards it to account repository. | Replace with resolved group in transaction/account API flows. |
| Generic ownership rules | `app/Rules/BelongsUser.php`, `app/Rules/BelongsUserGroup.php` | `BelongsUser` can validate through user ownership; `BelongsUserGroup` validates explicit group ownership for supported models. | Replace `BelongsUser` with group-aware validation in explicit account/transaction paths where IDs can target non-active administrations. |
| Unique account validation | `app/Rules/UniqueAccountNumber.php`, `app/Rules/UniqueIban.php`, `uniqueAccountForUser` validator | Account uniqueness is user-oriented today. | Replace with resolved group for account MVP; otherwise explicit group account creation can reject or accept incorrectly. |
| Request auth accepted roles | Account and transaction request classes under `app/Api/V1/Requests/Models/{Account,Transaction}` | Most set `acceptedRoles = []`, so `ChecksLogin` only verifies login unless controller calls `validateUserGroup()`. | Replace with controller-level resolved-group authorization for account/transaction MVP; keep active fallback. |

## Autocomplete

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Account autocomplete | `app/Api/V1/Controllers/Autocomplete/AccountController.php` | Validates group and sets account repository group. | Keep active-group fallback and include in MVP tests. |
| Transaction autocomplete | `app/Api/V1/Controllers/Autocomplete/TransactionController.php` | Validates group and sets journal and transaction group repositories. | Keep active-group fallback and include in MVP tests. |
| Other autocomplete controllers | `BudgetController`, `BillController`, `CategoryController`, `CurrencyController`, `ObjectGroupController`, `PiggyBankController`, `RecurrenceController`, `RuleController`, `RuleGroupController`, `TagController`, `TransactionTypeController` | Most validate group and set domain repositories. | Out of MVP; keep existing behavior unchanged. |

## Storage and filesystem-adjacent paths

| Area | Location | Current behavior | Iteration 1 classification |
| --- | --- | --- | --- |
| Account row storage | `accounts.user_group_id` via `AccountFactory` and account update services | New accounts currently inherit the active user group unless the storage path is changed. | Replace with resolved group. |
| Transaction group and journal row storage | `transaction_groups.user_group_id`, `transaction_journals.user_group_id` via transaction factories | Store path can write resolved group; non-store paths still need resolved group for updates and reads. | Replace remaining active-group reads with resolved group; keep fallback for omitted `user_group_id`. |
| Attachment upload/download/delete | Attachment controllers/repositories and storage disk paths | Attachments are global file records attached to multiple model types. | Out of MVP; account/transaction attachment subroutes need a separate ownership model. |
| Export downloads | Export controller and generator | Account/transaction exports can validate group at controller boundary but generator internals need confirmation. | Replace with resolved group for account/transaction export; non-account exports out of MVP. |

## Implementation guardrails for the follow-up

- Resolve one request group once, then pass that `UserGroup` object into controller repositories, validators, factories, collectors, enrichments, and transformers.
- Keep the current active-administration fallback when no `user_group_id` is submitted.
- Do not replace global/system endpoints with group-aware behavior.
- Do not promote out-of-MVP domains just because they already call `validateUserGroup()`.
- Add tests for both active-group fallback and explicit non-active group access before claiming an endpoint is supported explicit group.
