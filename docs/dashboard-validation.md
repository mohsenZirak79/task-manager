# Dashboard revision delivery

## Changes

- Completed task timing uses the accepted completion_requested_at, with an explicitly counted completed_at fallback. completed_at retains its approval-time meaning.
- Completion requests and approvals validate stored and planning-derived progress while holding the existing task lock; incomplete work returns Persian validation errors without modifying planning progress.
- New immutable history context records attempt timestamps and deadlines; rejected attempts are archived before the current timestamp is cleared. Deadline edits append a separate history event. No old data is backfilled.
- Completed tasks contribute effective progress 100 to project averages; stored historical task/planning records are unchanged.
- TaskStatus defines exhaustive labels and dashboard rules; enum cases drive zero-count chart entries.
- Schedule/project statistics are small dedicated classes. DashboardService coordinates shared visibility and snapshots. The legacy endpoint remains compatible; /api/v1/dashboard/projects independently paginates projects.
- MySQL uses a per-transaction repeatable-read, read-only snapshot, even with a READ COMMITTED session default; the global and session defaults remain unchanged. Independent-writer and read-only/rollback tests verify actual behavior.
- Task list oldest/newest ordering now uses id as a final tie breaker. MySQL testing exposed duplicate/missing page entries when created_at ties had no deterministic order.

## Read-only audit of the existing local configured database

Actual output is in dashboard-completion-audit.json. At delivery: completed_total=3; stored_progress_below_100_count=0; planning_progress_below_100_count=0; inconsistent_completed_count=0; fallback_completed_at_count=0; missing_completion_time_count=3. These are local database counts, not production claims and not per-user dashboard counts. No record was repaired, no timestamp was invented, and no migration was applied to the existing database.

## Query plan evidence

The saved plans in dashboard-query-plans.json are real EXPLAIN QUERY PLAN (SQLite) and EXPLAIN FORMAT=JSON (MySQL 8.4.11) on 2000 test tasks, 20 tags, and 500 task participants. Of these, 500 tasks are visible to the fixture actor and 334 are assignments.

The redundant task-id subquery inside tag counts/visibility was removed by merging the exact existing assignment/visibility constraints into relation queries. On the same MySQL fixture, estimated project query cost decreased from 1738.90 to 1038.90. These are optimizer estimates, not measured latency or production performance claims.

Existing indexes support submission_type filtering, status counts, task-participant lookups and unique task/tag membership. General multi-column OR visibility still scans tasks on this fixture. No new index was added: an additional index's necessary benefit was not established, and an index alone does not eliminate this OR predicate. No cache was introduced. Dashboard query-count tests verify that task/tag growth does not produce N+1 queries.

## Test commands and limits

Final SQLite run: 110 cases, 108 passed, 2 intentionally skipped MySQL-only tests, 1587 assertions; exit code 0.

Final compatible MySQL 8.4.11/InnoDB run: 109 cases, all 109 passed, 1596 assertions; exit code 0. One pre-existing Report migration rollback test was explicitly excluded as documented below. The MySQL-only independent-writer snapshot and read-only rollback tests both executed successfully.

SQLite:

```sh
php artisan test --compact --filter='DashboardTest|TaskManagementTest|WorkflowRulesTest|ReportManagementTest|ReportWorkflowTest|ReportedBugsTest|TaskListOwnershipTest'
```

MySQL test container setup and safeguards are documented in dashboard-api.md. The compatible related suite is:

```sh
vendor/bin/phpunit --configuration phpunit.mysql.xml \
  --filter='DashboardTest|TaskManagementTest|WorkflowRulesTest|ReportManagementTest|ReportWorkflowTest|ReportedBugsTest|TaskListOwnershipTest' \
  --exclude-filter=test_migration_backfills_existing_reports_and_can_roll_back_threads \
  --display-warnings --display-phpunit-deprecations
```

A full MySQL run before excluding that historical test executed 109 cases and returned 106 passes, two failures and one error. The error comes from the pre-existing reports workflow migration's down(): MySQL refuses to drop task_comments_report_id_created_at_index because a foreign key needs it (error 1553). That DDL also commits the surrounding test transaction and contaminates subsequent test data; the report retry count test then failed. MySQL also exposed nondeterministic default task list pagination, which was fixed with an id tie breaker. The old Report migration/test is unchanged and is explicitly excluded from the compatible MySQL command; a completely green unfiltered MySQL suite is not claimed.

SQLite intentionally skips the two MySQL-only snapshot concurrency/read-only tests. PostgreSQL and MariaDB were not executed. Existing non-SQLite outer transactions are deliberately unsupported by DashboardReadSnapshot; HTTP endpoints own the read transaction. Real databases must use UTC timestamps and InnoDB for MySQL snapshot guarantees.

## API and deployment

The complete OpenAPI contract is dashboard-api.json, with full dashboard-response.example.json and dashboard-projects-response.example.json examples. Both routes use the existing authentication and permissions. The projects route accepts page/per_page and does not compute other charts. Legacy projects_page/projects_per_page on /dashboard remain supported. Percentage denominators and half-up rounding to two decimal places are unchanged.

Apply migrations using `php artisan migrate` (or the existing production deployment's --force convention). The new revision migration is 2026_10_14_000001_add_context_to_task_workflow_histories.php. The previous action_type migration is preserved. No index/data-repair migration is introduced.

Read-only audit command: `php artisan tasks:audit-completion`.

## Files added or changed by this revision (paths relative to backend)

- app/Console/Commands/AuditTaskCompletionCommand.php
- app/Enums/TaskStatus.php
- app/Http/Controllers/Api/V1/DashboardController.php
- app/Http/Resources/TaskWorkflowHistoryResource.php
- app/Models/TaskWorkflowHistory.php
- app/Services/DashboardService.php
- app/Services/DashboardScheduleStatistics.php
- app/Services/DashboardProjectStatistics.php
- app/Services/DashboardReadSnapshot.php
- app/Services/TaskService.php
- database/migrations/2026_10_14_000001_add_context_to_task_workflow_histories.php
- routes/api.php
- tests/Feature/DashboardTest.php
- tests/Feature/TaskManagementTest.php
- tests/mysql-bootstrap.php
- phpunit.mysql.xml
- docs/dashboard-api.md
- docs/dashboard-api.json
- docs/dashboard-response.example.json
- docs/dashboard-projects-response.example.json
- docs/dashboard-completion-audit.json
- docs/dashboard-query-plans.json
- docs/dashboard-validation.md

Prior Report action_type changes, existing user changes, and stashed work are preserved. No frontend files were changed.

## Out-of-scope work

Existing Report API/frontend mismatches for allowed_actions, activate/deactivate and reactions remain pending. The historical MySQL Report migration rollback incompatibility described above is also unchanged. These were not mixed into the dashboard correction.
