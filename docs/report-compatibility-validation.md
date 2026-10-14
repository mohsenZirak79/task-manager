# Report compatibility repair — deployed on taskover.ir

## Findings and changes

The activate/deactivate/reaction endpoints, allowed_actions and mentions existed in saved local work (`stash@{0}`), not the released main tree. This is not solely a missing-documentation issue. Only related changes were recovered; the stash remains intact. No evidence of a deliberate removal commit was found. Older report docs described inactive/reactions as reserved/deferred; they now describe the explicitly requested recovery.

- Restored POST reports/{report}/activate and /deactivate with reports:manage middleware and the existing ReportPolicy update authorization. Deactivation stores exact previous status; activation restores it. Both retain timestamps and report content/history. Repeated deactivate is idempotent; repeated activate returns409.
- Reused TaskCommentReaction and serialized row-lock updates for PUT reports/{report}/comments/{comment}/reaction. No new reaction model/table. Inputs: like/dislike/null; missing or invalid input422. Invisible403, foreign/deleted comment404, inactive report409.
- All report resources consistently include allowed_actions (view/edit/delete/send/mark_viewed/comment/deactivate/activate). Report comments include edit/delete/reply/react flags, actor-specific reaction summary, and mentions. Gate authorization plus real workflow state determines flags. Endpoints continue independent authorization and validation. The report Policy membership checks now reuse eager-loaded membership without changing who can view.
- Restored report/task comments/mention-users with existing subject policies; optional mention_user_ids max20 are validated and stored atomically. PATCH omission preserves mentions, [] clears them. Deleted comments hide body and mentions. No notifications are delivered.
- Added nullable previous_status and the mention pivot through additive migrations. No guessed previous status or automatic historical data rewrite. Unknown legacy inactive status remains inactive with activate=false and activation409.
- Existing scheduled-meeting restrictions on report edit/delete are preserved. Inactive reports remain readable and deletable when otherwise authorized; comment writes/edit/send/view marking/reactions/mention lookup are blocked. Existing active non-recipient mark-viewed no-op behavior remains.
- Dashboard code/routes/response contract, TaskService/completion behavior and all frontend files are unchanged. action_type, submission_type and progress=100 remain existing backend rules; frontend should consume them.

## Actual verification

Final focused command:

```sh
php artisan test --compact --filter='ReportInteractionTest|ReportWorkflowTest|ReportManagementTest|TaskCommentTest|DashboardTest'
```

SQLite in-memory: **85 total; 83 passed; 2 MySQL-only skipped; 1130 assertions**. Covers active/inactive states, timestamps/restore history, missing prior state, Policy/permission/ownership restrictions, nested/deleted comments, reaction idempotency/replacement/removal, actor-specific output, read-only state, mention visibility/active-user checks, PATCH semantics, migration no-inference/rollback protection, action consistency, and bounded comment query counts. Dashboard regression tests are included. New ReportInteractionTest uses fixed time 2026-10-14T09:00:00Z.

Full suite command `php artisan test --compact`: **160 total; 141 passed; 9 failures; 8 errors; 2 skipped; 2154 assertions**. Therefore the full suite is **not green**. The same 17 failing/error tests were reproduced by running the unchanged HEAD in a separate temporary checkout with its own SQLite in-memory database: **144 total; 125 passed; 9 failures; 8 errors; 2 skipped; 1872 assertions**. No worktree files were replaced to run that comparison.

Inherited failures/errors involve ApiAuthenticationTest, CreateAdminCommandTest, RoleManagementTest and UserManagementAuthorizationTest: removed App\Models\Role/roles routes and mismatched older user/auth assertions. They are outside this report repair and were not changed.

`vendor/bin/pint --test --dirty` and `git diff --check` passed. The JSON contract route inventory matches all current API operations: **85 route definitions, 87 HTTP operations excluding HEAD**. Report response examples pass JSON Schema validation. Both dashboard path objects and all pre-existing shared/dashboard schemas were verified unchanged against the original JSON.

**Production smoke checks passed after deployment**: authenticated me/reports/detail/comments/mention lookups/dashboard/projects returned200; Report allowed_actions keys verified; missing-resource activate/deactivate returned404; invalid pagination422; public HTTPS without credentials401 on all checked report/interaction paths. Both additive migrations were applied; record counts preserved. The sampled report had no comments, so actual comment writes/reactions were not performed on production. Temporary smoke token was revoked.

MySQL execution completed with 82 cases passing in the initial run, one skipped case, and one dashboard environment-precondition failure. That failed case passed on a focused retry with the required READ-COMMITTED test-server default (1 test, 11 assertions, direct PHPUnit exit0). This does not claim a fully green suite in one invocation. Previous dashboard deployment results in the JSON are explicitly nested under prior_deployment_verification and do not certify these local changes. Isolated MySQL config remains available in phpunit.mysql.xml; do not point tests at production.

## Deployment and remaining work

Application commit 4ab6dbb5cb9c4b1c313353e38bcc7bb030935942 was pushed to main and deployed on https://taskover.ir. Server has a private code/database/env backup under /var/www/task-manager/backups/report-compatibility-20261014-112757. PHP-FPM was reloaded and framework caches rebuilt. No business records were modified for smoke testing.

Migration command used successfully:

```sh
php artisan migrate --force
```

Migrations: 2026_10_14_000002_add_previous_status_to_reports and 2026_10_14_000003_create_task_comment_mentions_table. Previous-status rollback refuses to drop activation history while inactive reports exist. Existing data and workflow timestamps are not backfilled/rewritten.

No real notifications API was found in main or saved implementation; frontend notifications service describes mock/future backend behavior. A notification/event-delivery workflow was not invented. Frontend work remains to consume action_type, send submission_type for tasks created from resolutions, and display/handle the existing completion progress validation. The supplied prompt ended before the expected comment JSON; the implemented edit/delete/reply/react keys match the actual frontend report utility.

Frontend contract: [task-manager-frontend-api-taskover.json](task-manager-frontend-api-taskover.json). Workflow and endpoint details: [reports-api.md](reports-api.md).

## Changed files

- `app/Http/Controllers/Api/V1/ReportCommentController.php`
- `app/Http/Controllers/Api/V1/ReportController.php`
- `app/Http/Controllers/Api/V1/TaskCommentController.php`
- `app/Http/Requests/Concerns/HasCommentMentionRules.php`
- `app/Http/Requests/IndexCommentMentionUsersRequest.php`
- `app/Http/Requests/StoreReportCommentRequest.php`
- `app/Http/Requests/StoreTaskCommentRequest.php`
- `app/Http/Requests/UpdateReportRequest.php`
- `app/Http/Requests/UpdateTaskCommentRequest.php`
- `app/Http/Resources/ReportCommentResource.php`
- `app/Http/Resources/ReportResource.php`
- `app/Http/Resources/TaskCommentResource.php`
- `app/Models/Report.php`
- `app/Models/TaskComment.php`
- `app/Policies/ReportPolicy.php`
- `app/Services/CommentMentionService.php`
- `app/Services/ReportCommentService.php`
- `app/Services/ReportService.php`
- `app/Services/TaskCommentService.php`
- `database/migrations/2026_10_14_000002_add_previous_status_to_reports.php`
- `database/migrations/2026_10_14_000003_create_task_comment_mentions_table.php`
- `docs/report-compatibility-validation.md`
- `docs/reports-api.md`
- `docs/task-manager-frontend-api-taskover.json`
- `routes/api.php`
- `tests/Feature/ReportInteractionTest.php`
- `tests/Feature/TaskCommentTest.php`

## Frontend publication

At the user’s subsequent request, the server frontend was fast-forwarded from ea1e572 to demo-report commit d888f8c26e71a8609d0fa42a948b4ee30824752d. No frontend source was edited by this backend repair. Existing .env.production and pnpm-workspace.yaml were retained, and prior frontend source/build/config were backed up with the backend backup. Dependencies/lockfile did not change, so no dependency reinstall was needed.

Server pnpm test: **215 passed, 0 failed**. Production Next.js build completed (including lint/type checks), build ID 5N0XlDZCP310LIKoW7LXb. task-manager-frontend.service restarted and active. Public HTTPS root/login/reports/new build manifest/two JS chunk assets all returned200; page HTML contains the new build ID. Frontend API base remains /api/v1 on the same domain.

The first isolated MySQL attempt completed: 84 tests, 82 passed, 1 failed, 1 skipped, 1126 assertions. The sole failure was the dashboard concurrency test’s initial assertion requiring READ-COMMITTED; this temporary instance had MySQL’s default REPEATABLE-READ. The unchanged dashboard test was rerun with its required test-server default and passed (1 test, 11 assertions, direct PHPUnit exit0). No production isolation setting was modified. The existing old Report rollback/FK-index test was explicitly excluded, and the new SQLite DDL rollback test is skipped on MySQL.

Final MySQL verification command:

```sh
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter=test_mysql_dashboard_has_repeatable_read_snapshot_despite_read_committed_default --display-warnings --display-notices --display-deprecations
```

A redundant full retry was interrupted to honor expedited publication; the originally failed case was then rerun to completion. The 82 other cases had already passed in the initial full selected run. Temporary MySQL was shut down after verification. Production database configuration/isolation was never changed.
