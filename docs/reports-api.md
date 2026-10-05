# Reports API 1.2.0

The complete frontend contract is [task-manager-reports-api-v1.2.0.json](../releases/task-manager-reports-api-v1.2.0.json), extending v1.1.0 with standalone reports without changing existing URLs or success envelopes.

## Workflow

Create defaults to `sent`; explicit `draft` saves without `sent_at`. `POST /api/v1/reports/{report}/send` sends a draft under the existing report management policy; other states return 409. Sending records state only, without introducing notification delivery.

`POST /api/v1/reports/{report}/view` changes only `sent` to `viewed` and only for the main recipient. Other authorized viewers, drafts, inactive and already-viewed reports receive an unchanged report. GET requests never mark a report viewed. First-view timestamps remain stable.

`inactive` is reserved and accepted in list filtering but has no public transition. Status and workflow timestamps cannot be edited through PATCH. Draft visibility follows the existing report policy, including recipients and meeting participants.

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

Report reactions are deferred pending product approval, although task reactions exist. No report like/reaction routes or response fields are exposed.

## Verification

Regression coverage includes the original report, resolution, task comment and upload suites plus workflow/attachment/comment authorization tests. The JSON contract includes every supported endpoint, examples, schemas, permissions, validation, filters, workflow and frontend flow.
