# Reports API — compatibility repair (pending deployment)

The current local frontend contract is [task-manager-frontend-api-taskover.json](task-manager-frontend-api-taskover.json). The v1.2.0 release contract is historical. This compatibility repair has not been deployed. Existing URLs, success envelopes, action_type classification and dashboard APIs are preserved.

## Workflow

Create defaults to `sent`; explicit `draft` saves without `sent_at`. `POST /api/v1/reports/{report}/send` sends a draft under the existing report management policy; other states return 409. Sending records state only, without introducing notification delivery.

`POST /api/v1/reports/{report}/view` changes only `sent` to `viewed` and only for the main recipient. Other authorized viewers, drafts and already-viewed reports receive an unchanged report. Inactive reports return 409. GET requests never mark a report viewed. First-view timestamps remain stable.

POST `/api/v1/reports/{report}/deactivate` stores the exact previous draft/sent/viewed state and marks the report inactive. Repeating deactivate is idempotent. POST `/api/v1/reports/{report}/activate` restores that stored state and clears previous_status. Activation of an active report or a legacy inactive report without known history returns 409; prior states are never inferred from timestamps. Both operations require reports:manage and the existing ReportPolicy update permission (standalone creator or authorized meeting manager/admin). They retain sent_at/viewed_at and all attachments/comments/reactions/mentions. Status, previous_status and workflow timestamps cannot be edited through PATCH. Draft visibility follows the existing report policy, including recipients and meeting participants.

Inactive reports remain readable/filterable; update, send, mark-viewed, comment mutations, reactions and mention lookup return 409 for otherwise authorized users. Authorized deletion remains supported under the existing scheduled-meeting restriction. These transitions introduce no notification delivery.

For resolution reports, create/update/delete (including attachment sync) require a scheduled meeting. Standalone reports have no meeting restriction. Send, view and comments are allowed regardless of meeting state, subject to their permissions. Changing recipients or content does not reset workflow timestamps.

## Standalone reports

`POST /api/v1/reports` creates a report without a meeting or resolution using the same body as resolution report creation: required `title`, `short_description`, `description`, `recipient_user_id`; optional `status` (`draft` or `sent`, default `sent`), `cc_user_ids`, `attachment_file_ids`, and `tags`. The success envelope is `data.report` (HTTP 201). `meeting_resolution_id`, `meeting`, and `resolution` are null for standalone reports. Existing resolution routes remain available and enforce one report per resolution.

Creation requires `reports:manage`, now included in the default User role. Only the creator or an Admin/Super Admin can manage standalone reports. Creator, recipient, CC users and admins can view them; unrelated users cannot. Existing list, update, delete, send, view and comment endpoints support both report types.

Deploy with `php artisan migrate` and `php artisan access:sync` to update existing User-role permissions. The nullable-resolution migration preserves existing associations and refuses rollback while standalone reports exist, preventing data loss.

## Numbering and migration

Run `php artisan migrate` during deployment. Existing reports become sent, with `sent_at=created_at`, `viewed_at=null`, and unique numbers `RPT-000018` derived from their existing ID. New reports receive the same immutable numbering format; numbers are not gapless.

## Attachments

Use existing `POST /api/v1/uploads/files`, then submit `attachment_file_ids` (distinct, maximum 20). Existing attachment ownership rules apply: uploader or Super Admin may attach a new file; authorized report managers may retain already attached files. Omitted array keeps attachments; `[]` removes all relations; supplied IDs replace the set. MediaFileResource uses `original_name`, `url`, `mime_type`, `category`, `size`, and `id`. Attached files cannot be deleted by the shared upload delete endpoint. Deleting a report never deletes media files.

## Comments

GET/POST `/api/v1/reports/{report}/comments` and PATCH/DELETE `/api/v1/reports/{report}/comments/{comment}` reuse the existing TaskComment storage and soft-delete model with a nullable report owner; task API behavior stays unchanged. List is flat, paginated (15 default, 100 max), supports oldest/newest, and includes tombstones (`body:null`, `is_deleted:true`). Body is required, nonblank, max 5000 characters. Parent must be a live comment on the same report. Existing multilevel threading is retained. Report viewers may create; owner or Super Admin or Admin with reports:manage may edit/delete. Report deletion removes comments, replies and attachment relations.

PUT `/api/v1/reports/{report}/comments/{comment}/reaction` reuses TaskCommentReaction storage, its unique (comment,user) constraint and serialized updates. Body must contain `reaction`: `"like"`, `"dislike"` or `null`. Repeated identical input is idempotent; another value replaces the prior actor reaction; null removes it. Response is `data.reactions: {like, dislike, my_reaction}`. Requires reports:view plus ReportPolicy reactComment, a visible active report and a live comment nested in that report. Foreign/deleted comments return 404; invalid/missing reaction 422; denied access 403; inactive report 409.

## Verification

Regression coverage includes the original report, resolution, task comment and upload suites plus workflow/attachment/comment authorization tests. The JSON contract includes every supported endpoint, examples, schemas, permissions, validation, filters, workflow and frontend flow.

## Allowed actions

Every ReportResource response (create/update/list/show/resolution/send/view/activate/deactivate) includes boolean keys `view`, `edit`, `delete`, `send`, `mark_viewed`, `comment`, `deactivate`, `activate`. Values combine the actual Gate/ReportPolicy authorization with the current state and existing meeting edit/delete restriction. Super Admin still obeys workflow constraints. Server-side policy, nesting, validation and transaction checks run independently of frontend flags. A false `mark_viewed` means the operation cannot change state; the legacy non-recipient/already-viewed no-op response remains compatible.

Report comments consistently return `allowed_actions: {edit, delete, reply, react}`, `reactions: {like, dislike, my_reaction}` and `mentions`. Inactive report or deleted comment => all comment flags false. Deleted comment body is null and mentions is []; reaction counts remain historical. The list eager-loads relevant report membership and actor permissions; query count does not grow with the number of comments.

## Mentions

GET `/api/v1/reports/{report}/comments/mention-users` requires reports:view and ReportPolicy view (inactive =>409). GET `/api/v1/tasks/{task}/comments/mention-users` requires TaskPolicy comment, including tasks:view/tasks:comment. Both accept optional nullable `search` (max255), return `data.items` UserSummary objects without pagination, and filter eligible active involved users/applicable viewers through the existing subject view policy. They are not unrestricted user-directory endpoints.

POST/PATCH comment bodies accept optional `mention_user_ids` (distinct active user IDs, max20). IDs must be eligible for that task/report and are revalidated on write. Omission during PATCH preserves mentions; [] clears them. Invalid/inaccessible users =>422 and the whole comment write rolls back. Comment output includes `mentions: UserSummary[]`. Mentions do not generate notifications.

## Deployment and remaining frontend work

Run `php artisan migrate --force` during deployment. Two additive migrations create nullable reports.previous_status and task_comment_mentions. Existing reports, timestamps and task/report associations are preserved; no historical state is backfilled. Rollback of previous_status is refused while inactive reports exist to avoid losing activation history.

Notifications have no real API implementation in current main or the saved changes; frontend services label them mock/future behavior. No notifications API or new delivery workflow is introduced here. Frontend still needs to consume action_type, submit submission_type for resolution-created tasks, and display the existing completion progress=100 validation. Those backend rules and dashboard endpoints are unchanged.

See [report-compatibility-validation.md](report-compatibility-validation.md) for test evidence and the exact changed file inventory.
