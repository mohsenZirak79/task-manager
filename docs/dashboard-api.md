# Dashboard API

`GET /api/v1/dashboard` uses the existing `auth:sanctum`, last-activity and active-user middleware. No new permission system is introduced. The response follows the existing `success`, `message`, `data` envelope.

The complete machine-readable OpenAPI 3.1 contract is [dashboard-api.json](dashboard-api.json), including schemas and complete available/unavailable examples. [dashboard-response.example.json](dashboard-response.example.json) is a standalone sample for frontend integration. Sample values are illustrative documentation data; the endpoint always aggregates real authorized records.

## Visibility and unavailable modules

- Tasks, requests, projects and schedule require the existing TaskPolicy `viewAny` (`tasks:view`). All use `TaskVisibilityService::visibleQuery`, the query equivalent of `canView`: existing manage-all access, otherwise creator, requester, financial/equipment provider, or any participant record. List scopes such as `assigned_to_me` are filters, not the complete view permission.
- Reports require ReportPolicy `viewAny` (`reports:view`) and reuse `ReportService::visibleQuery`: existing administrative access, creator, recipient, CC recipient, or member/creator/chairman/secretary of the linked resolution's meeting.
- Meetings require MeetingPolicy `viewAny` (`meetings:view`) and reuse `MeetingService::visibleQuery`: existing administrative access or creator/chairman/secretary/attendee.
- A module without view permission returns exactly `{"available":false,"reason":"missing_module_permission"}`. Its charts and related cards omit counts/items entirely. This differs from an available module with zero records. The rest of the dashboard still returns HTTP 200. Existing super-admin behavior remains controlled by the current permission and policy implementation.

## Time and percentages

`generated_at` is one captured instant in ISO 8601 with Tehran's offset; `timezone` is `Asia/Tehran`. Every schedule calculation uses that same instant. Stored task timestamps follow this application's existing UTC configuration. A date deadline is the end of its Tehran calendar day (`23:59:59.999999`), converted to UTC. SQLite, MySQL/MariaDB and PostgreSQL deadline expressions are implemented; the automated suite runs SQLite. IANA Tehran transitions are used, including historical daylight-saving changes, without requiring MySQL timezone tables.

All percentages and project progress use `round(value, 2, PHP_ROUND_HALF_UP)`: two decimal places, ties rounded up. JSON numbers do not preserve trailing zeroes. Rounded percentages can sum to 99.99 or 100.01; values are not redistributed to force 100. Empty denominators produce 0.

`percentage_denominators` explicitly names the denominator for each chart:

| Chart | Denominator |
| --- | --- |
| `task_statuses` | `task_statuses.total` |
| `task_schedule` | `task_schedule.total` |
| `reports` | `reports.classified_total` |

## Task statuses

Only visible `submission_type=assignment` tasks are included. All ten current TaskStatus values are returned separately in enum order, including zero counts: draft, pending_approval, revision_requested, ready_to_start, in_progress, pending_completion_approval, completed, not_completed, rejected, closed. Each item has `key`, Persian `label`, `count`, `percentage`. `total` is the sum of item counts. Requests are excluded.

## Schedule

The examined population is all visible assignments; requests are excluded. Exclusions are applied in this order, exactly once per task:

1. `excluded_status`: draft, rejected, closed, not_completed, regardless of dates.
2. `missing_due_date`: remaining tasks with no due_date, including completed tasks.
3. `missing_completed_at`: remaining completed tasks with neither completion_requested_at nor completed_at. This existing key is retained for compatibility.

A completed task missing due_date and both completion timestamps is counted only under `missing_due_date`. These reasons are in `excluded_counts`; `excluded_total` is their sum. `examined_total = total + excluded_total`.

For the remaining population:

| Key | Persian label | Rule |
| --- | --- | --- |
| `on_schedule` | طبق برنامه | Open task with more than 48 hours remaining, or effective_completion_at <= deadline |
| `critical` | بحرانی | Open task with 0 through 48 hours remaining, inclusive of both boundaries |
| `overdue` | عقب‌افتاده | Open task after deadline, or effective_completion_at > deadline |

For completed tasks, effective_completion_at is completion_requested_at from the last approved attempt. If it is absent on a historical task, use completed_at as a fallback. `fallback_completed_at_count` counts fallback uses among the eligible chart population (a subset of total); it is zero when empty. If both timestamps are absent, exclude the task using the compatibility key missing_completed_at; no timestamp is inferred. Every other non-excluded status is open. Exactly 48 hours means elapsed time, not two business/calendar days. The three groups are disjoint; their sum is `total`, which is the percentage denominator. Statuses are never modified, and no cron is required.

## Projects / task tags

No Project model or table is introduced. Each Tag with at least one visible assignment appears once. Request-only tags and tags with only invisible tasks are omitted. `tasks_count` counts all visible assignments on the tag, including excluded statuses; `completed_count` counts visible completed assignments. Progress is the arithmetic mean of effective progress over explicitly project-eligible assignment statuses (all current statuses except draft, rejected, closed and not_completed). A completed task contributes 100 even if its historical stored progress is lower; other tasks use current progress_percentage. This never changes task or planning-item data. Null progress contributes zero; an empty eligible set produces zero. A task with several tags contributes once to each associated tag. Exists/subqueries and the existing unique tag/task pivot prevent participant/attachment/multi-tag multiplication.

Projects sort by `tag_id` ascending. `GET /api/v1/dashboard/projects?page=1&per_page=15` returns the same `data.available/items/meta` structure and requires tasks:view (403 otherwise). This endpoint reads only project statistics; it does not calculate other charts/cards. The legacy dashboard parameters below remain supported. Use `?projects_page=1&projects_per_page=15` for the complete list; page is >=1, per-page is 1..100, default 15. The `meta` object contains `current_page`, `last_page`, `per_page`, `total` (number of visible tags). An out-of-range page returns empty items and unchanged totals; invalid parameters return HTTP 422. These parameters affect only project items, not any other chart or card. A persisted status outside explicit timing rules is excluded as `unsupported_status` (key present only when needed), never treated as open. All aggregation is performed by the database; only the current tag page and aggregate results are loaded into PHP. Query count does not grow with task or tag count.

## Report actions

`action_type` is separate from workflow `status`. The additive migration creates a nullable string; existing rows stay null with no backfill. Creation (standalone or linked to a meeting resolution) and PATCH accept optional nullable action_type with these allowed strings:

| Key | Label |
| --- | --- |
| `needs_action` | نیازمند اقدام |
| `needs_review` | نیازمند بررسی |
| `needs_instruction` | نیازمند دستور |

ReportResource includes `action_type`. Omitting it preserves previous behavior; PATCH omission preserves its current value; explicit null removes classification. Unsupported values return validation error 422. Sending a workflow status as action_type is invalid. Existing report policies and workflow operations are unchanged.

The chart includes only visible reports with status `sent` or `viewed`. `total` includes classified and unclassified reports. `classified_total` sums the three category counts, and `unclassified_count` counts null action_type. `total = classified_total + unclassified_count`. Category percentages divide by classified_total. All three categories are present even when empty.

## Cards

Every available card is `{"available":true,"count":0}` with its real nonnegative count.

| Card | Definition (visible records only) |
| --- | --- |
| `active_tasks` | assignment in ready_to_start, in_progress, pending_completion_approval |
| `active_requests` | request in pending_approval, revision_requested, ready_to_start, in_progress, pending_completion_approval |
| `registered_reports` | reports in sent or viewed, including null action_type |
| `meetings` | all visible meetings, without an additional status filter |

## Migration and validation

From `backend` run `php artisan migrate`. For production deployment use `php artisan migrate --force` according to the existing deployment process. The original dashboard migration `2026_10_14_000000_add_action_type_to_reports` adds nullable action_type. The revision adds `2026_10_14_000001_add_context_to_task_workflow_histories`, a nullable JSON context field; old rows remain null and are never backfilled. No frontend assets or database data need rebuilding/backfilling.

Focused tests: `php artisan test --compact --filter=DashboardTest`. They use a fixed clock and an isolated SQLite in-memory database, covering enum statuses, task/request separation, empty data, 48-hour/end-of-day boundaries, late/on-time/missing completion, exclusions, timezone history, policies, per-module permissions, report classification and compatibility, shared tags, null/empty progress, pagination and constant query count.

## Completion workflow and history (revision 1.1)

`completed_at` keeps its existing meaning: manager approval timestamp. `completion_requested_at` is set on each new completion request, retained on approval, and cleared when that attempt is rejected. The next request gets a new timestamp. While pending_completion_approval, the task remains open for scheduling, regardless of the request timestamp. Only a completed task uses the accepted request timestamp.

Requesting or approving completion with progress below 100 returns HTTP 422 with the Persian message `برای درخواست یا تأیید پایان، پیشرفت تسک باید ۱۰۰ درصد باشد.` under `errors.progress_percentage`. Both methods reload and lock the parent task inside their existing mutation transaction before validating. This is the same parent lock acquired by progress/planning mutation methods, so a stale pre-request model cannot bypass approval validation.

The current source of task progress is unchanged: without planning items it is stored progress_percentage. With items it is the rounded weighted mean sum(weight * item_progress)/sum(weight), clamped to 0..100; for zero total weight it is the rounded arithmetic mean. The existing integer rounding semantics remain in effect. Both stored task progress and freshly calculated planning progress must reach 100. No planning item or task progress is synthetically set to 100 by completion approval.

New completion_requested/completion_rejected/completion_approved history rows include `context` with completion_requested_at, completed_at and due_date at that event. Rejection snapshots the attempted timestamp before clearing it. TaskService deadline edits append a deadline_updated event with old_due_date/new_due_date. Earlier events are retained unchanged, so changing the current deadline does not erase previous attempt/deadline evidence. Existing history rows get null context; no historical execution time is fabricated. History resources expose nullable context additively. Current scheduling intentionally uses the current task due_date, not a historical deadline.

## Status rules

TaskStatus::cases() drives status chart order and zero-count entries. Persian labels, active-task/request flags, schedule kind (open/completed/excluded), and project eligibility are explicitly defined in TaskStatus. Exhaustive matches have no default; the enum-coverage test fails if a case is added without labels/rules. SQL status conditions use enum-derived bindings. New states are never automatically classified as open.

## Consistent read snapshot

Both dashboard endpoints execute all their reads within DashboardReadSnapshot. On MySQL/InnoDB, SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY applies only to the next transaction. Neither global nor session defaults are changed. Normal SELECTs use an InnoDB consistent snapshot without SELECT FOR UPDATE or other write locks. A concurrent committed insert between status aggregation and later chart/card queries is covered by a separate-connection test. All underlying application tables must use InnoDB; this was checked on the isolated MySQL test schema.

SQLite uses its existing deferred read transaction; PostgreSQL sets REPEATABLE READ READ ONLY at the beginning of the transaction. Existing non-SQLite outer transactions are rejected rather than pretending their isolation can be upgraded after reads. The HTTP endpoints own their snapshot. No cache is added. generated_at and the scheduling clock are captured once per dashboard response.

## Safe MySQL test execution

The committed `phpunit.mysql.xml` uses only local 127.0.0.1:33317 / task_manager_dashboard_test with an empty test-only root password. Its bootstrap refuses cached application config or a different host, port, environment or database. Never point this configuration at production. Dashboard MySQL fixtures are committed outside a surrounding test transaction so the real read-only snapshot and independent writer can be exercised.

```sh
docker run --detach --rm --name task-manager-dashboard-test-20261014 \
  --publish 127.0.0.1:33317:3306 --tmpfs /var/lib/mysql:rw \
  --env MYSQL_ALLOW_EMPTY_PASSWORD=yes --env MYSQL_ROOT_HOST=% \
  --env MYSQL_DATABASE=task_manager_dashboard_test \
  mysql:8.4 --default-storage-engine=InnoDB --transaction-isolation=READ-COMMITTED
# Wait until mysqladmin reports alive:
docker exec task-manager-dashboard-test-20261014 mysqladmin ping --silent
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter 'DashboardTest|TaskManagementTest|WorkflowRulesTest|ReportManagementTest|ReportWorkflowTest|ReportedBugsTest|TaskListOwnershipTest' --exclude-filter=test_migration_backfills_existing_reports_and_can_roll_back_threads
# Remove the temporary test database when finished:
docker stop task-manager-dashboard-test-20261014
```

## Read-only audit and query plans

Run `php artisan tasks:audit-completion` to count completed records with stored progress below 100, planning-derived progress below 100, their union, historical fallback timestamps, and missing completion timestamps. It performs aggregate SELECTs only and never repairs data. Counts cover completed tasks of all submission types in the configured database, unlike the dashboard's user-scoped assignment population. The delivery audit is in dashboard-completion-audit.json.

EXPLAIN fixtures contain 2000 tasks, 20 tags and 500 participant rows; 500 tasks are visible and 334 are assignments. Status, project and visibility query plans are checked on both engines. See dashboard-query-plans.json and dashboard-validation.md for measured evidence and index decisions. Query count remains constant as task/tag counts grow. Existing direct visibility OR conditions can require a tasks scan; this is documented, not hidden by an untested index.

## Remaining Report API work

Historical frontend mismatches involving allowed_actions, activate/deactivate and reactions remain outside this revision and were not changed.
