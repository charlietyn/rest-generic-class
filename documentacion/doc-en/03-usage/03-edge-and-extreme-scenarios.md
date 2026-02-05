# Edge and extreme scenarios

## 1) Config caching hides env changes

**Symptom**
`LOG_QUERY` or `REST_STRICT_COLUMNS` changes do not take effect.

**Cause**
The package reads env values in the config file. With cached config, env updates are ignored until cache is rebuilt.

**Mitigation**
Run `php artisan config:clear` or `php artisan config:cache` after updating env values.

**How to reproduce**
1. Set `LOG_QUERY=true` in `.env`.
2. Run `php artisan config:cache`.
3. Change `LOG_QUERY=false` and observe behavior.

**How to test**
Check `config('rest-generic-class.logging.query')` before/after cache clear.

---

## 2) Queue workers use stale config or permission cache

**Symptom**
Long-running queue workers behave as if old config or permission cache is still active.

**Cause**
Queue workers boot the app once and keep config and permission cache in memory.

**Mitigation**
Restart workers after config or permission changes. If using Spatie teams, ensure team ID is set before `SpatieAuthorize` executes.

**How to reproduce**
1. Start a queue worker.
2. Update `filtering.max_depth` or permission assignments.
3. Run a job that uses `BaseService` or Spatie authorization.

**How to test**
Restart the worker and verify that new config/permissions are respected.

---

## 3) Concurrency collisions during bulk updates

**Symptom**
Two admins update the same record simultaneously and lose changes.

**Cause**
`update_multiple()` applies updates row-by-row without record-level locking.

**Mitigation**
Add optimistic locking at the application layer (e.g., `updated_at` checks) or use DB-level locking in your service overrides.

**How to reproduce**
1. Send two `update-multiple` requests with overlapping IDs.
2. Observe last-write-wins behavior.

**How to test**
Write an integration test that submits concurrent requests and inspects final values.

---

## 4) Large payloads or filter explosions

**Symptom**
Requests fail with `Maximum conditions (...) exceeded` or hit memory limits.

**Cause**
The filter engine enforces `filtering.max_conditions` and `filtering.max_depth` to protect the database.

**Mitigation**
- Split requests into smaller chunks.
- Increase `filtering.max_conditions` only when required.

**How to reproduce**
Send an `oper` with >100 conditions or nested relations >5 levels deep.

**How to test**
Use a load test that increases `oper` size until it triggers an exception.

---

## 5) Deep hierarchy trees cause timeouts

**Symptom**
Hierarchy listing requests time out with large trees.

**Cause**
Hierarchy mode loads the full tree unless `max_depth` or pagination is used.

**Mitigation**
- Set `max_depth`.
- Paginate roots using `pagination`.
- Narrow the dataset using `oper` filters.

**How to reproduce**
Call list endpoints with `hierarchy=true` on a large self-referencing dataset.

**How to test**
Benchmark response time with and without `max_depth` and root pagination.

---

## 6) Multi-tenant permissions mismatch

**Symptom**
Users from one tenant can access permissions from another tenant.

**Cause**
Spatie’s PermissionRegistrar uses a team ID to scope permissions. If not set, it falls back to the global cache key.

**Mitigation**
Set the team ID before `SpatieAuthorize` middleware runs (e.g., in a tenant middleware).

**How to reproduce**
Enable teams in Spatie permissions and skip team ID assignment before authorization.

**How to test**
Write a test that sets different team IDs and verifies permission checks.

---

## 7) Rate limiting vs. heavy filters

**Symptom**
Clients hit rate limits when running expensive filters or nested relation queries.

**Cause**
Complex `oper` filters and relation loading can be expensive and lead to slower requests.

**Mitigation**
- Add API rate limits in the host application.
- Cache common queries.
- Prefer `select` to limit payload size.

**How to reproduce**
Call list endpoints repeatedly with complex `oper` trees and `relations`.

**How to test**
Measure response time and request counts under load.

---

## 8) Failure modes from invalid relation/operator

**Symptom**
Requests return a 400 error stating relation or operator is invalid.

**Cause**
The package validates relations against `RELATIONS` and operators against the allowlist.

**Mitigation**
Add missing relations to `RELATIONS` and ensure operators match the allowlist.

**How to reproduce**
Use `relations=["internalLogs"]` when it is not allowlisted.

**How to test**
Write a test that asserts the error message when a forbidden relation is requested.

[Back to documentation index](../index.md)

## Evidence
- File: config/rest-generic-class.php
  - Symbol: config keys under `logging` and `filtering`
  - Notes: Shows env-driven config and filter limits used in the scenarios.
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::applyOperTree(), BaseService::listHierarchy(), BaseService::normalizeHierarchyParams()
  - Notes: Confirms depth/condition enforcement and hierarchy behaviors.
- File: src/Core/Middleware/SpatieAuthorize.php
  - Symbol: SpatieAuthorize::handle()
  - Notes: Uses Spatie PermissionRegistrar and guard-based permission checks.
