# Report compatibility repair — local, pending deployment

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

**MySQL was not run for this report repair. Production/server smoke testing was not run for this report repair.** Previous dashboard deployment results in the JSON are explicitly nested under prior_deployment_verification and do not certify these local changes. Isolated MySQL config remains available in phpunit.mysql.xml; do not point tests at production.

## Deployment and remaining work

These changes have not been committed, pushed or deployed. Target domain remains https://taskover.ir; the domain is not evidence that this local revision is live.

After publishing the reviewed backend changes, run:

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
