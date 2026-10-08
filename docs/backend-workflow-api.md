# Backend workflow API — 1.4.3

All endpoints require Sanctum authentication and an active account. Existing success envelopes remain `data.task` / `data.meeting`. Frontend files and dependencies are unchanged.

## Request recipients

`GET /api/v1/tasks/eligible-users` keeps its existing query parameters (`search`, `submission_type`, `task_id`). Create context requires `TaskPolicy::create`; edit context requires `TaskPolicy::update` for that record.

- `assignment_targets`: only the current active user, also when `submission_type` is omitted. With `submission_type=request`, this group is empty.
- `request_targets`: active users in the actor's own positions, positions with the same **non-null parent**, or descendants within each actor position's `assignment_down_levels`. A null limit allows all descendants; zero allows no levels below that position. Root positions with no parent are not peers merely because both are roots. With `submission_type=assignment`, this group is empty.
- The current user is excluded from `request_targets`, including for Super Admin. Selecting yourself in request `assignee_ids` returns 422 on create, edit and final submission. Assignment remains personal even for Super Admin.
- `participants`: existing ancestor/descendant scope is preserved for followers, supervisors and resource providers. A peer eligible as a request recipient is not automatically eligible for these other roles.

The same recipient rule applies to POST, PATCH and final submission. Downstream requests remain `request`; their type is never inferred as assignment. Historical upstream requests remain readable but must have valid recipients before editing or resubmitting.

Personal list scopes (`assigned_to_me`, `involved`, `action_required`, including default lists) hide received tasks in `draft`, `revision_requested`, `rejected` or `closed` while the current user is an assignee and is not the creator/requester. A correction saved as a draft stays hidden; successful resubmission to `pending_approval` makes it visible again. Creator/requester lists retain the task for correction. Explicit authorized `scope=all` and detail access retain historical visibility. Filtering happens before pagination.

## Personal task and request lists

The default task list (`GET /tasks` or `scope=involved`) includes only records where the current user is an assignee, follower, supervisor or financial provider. Being only the creator, requester or equipment provider does not add a record to this list. A sender who also holds one of the listed responsibilities can appear in both lists. These rules apply to personal lists even for Super Admin; authorized `scope=all` remains available for administration.

The default request list (`GET /tasks?submission_type=request`) uses `created_by_me`, matching the frontend request tab. Creator/requester records remain visible there for sending and corrections. Explicit responsibility scopes can still be queried for incoming requests. Detail authorization and editing permissions are unchanged.

## Shared project tags

Run `php artisan db:seed --class=ProjectTagSeeder --force` after migration to add the standard project and department tags to both task and report tag lists. This seeder preserves existing tags and assignments and can be run repeatedly without duplicates. It is also included in `DatabaseSeeder`.

## Save / save and submit

`POST /api/v1/tasks` (`tasks:create`) and `PATCH /api/v1/tasks/{task}` (`tasks:update` plus ownership policy):

- Missing `submit` or `submit:false` saves without sending for approval.
- `submit:true` saves changes and submits the **same record in one transaction**. Failure of final validation rolls back task attributes, participants, attachments, tags, planning rows and workflow history from that operation.
- Assignment edits: creator/requester only, in `draft` or `revision_requested`. Its only assignee must be the creator.
- Request edits: creator/requester only, in `draft`, `revision_requested` or `rejected`. Editing a request requiring correction keeps the existing `revision_edit_started` transition to `draft`; `submit:true` then submits it without a second HTTP call.
- Unchanged `submission_type` is accepted in correction states. Actual type changes outside `draft` return 409. Other states remain locked for general editing.
- `allowed_actions.edit` and `.submit` reflect these restrictions; request corrections can advertise `.submit:true` before the first save.

Submission still requires valid title, summary, duration, due date, assignee, and (for requests) at least one tag, follower and supervisor.

```json
{"submission_type":"request","title":"Corrected request","assignee_ids":[23],"submit":true}
```

For a valid existing record, only changed fields need to be provided. Use `submit:false` for save only. Full create payloads must also supply the existing required submission fields.

## Accepted assignment planning

`PATCH /api/v1/tasks/{task}/planning` requires `tasks:update`, `TaskPolicy::updatePlanning`, and the current user must be the assignment's assignee. Only `ready_to_start` and `in_progress` are allowed. General PATCH remains forbidden for these states.

```json
{
  "planning_items": [
    {"id": 101, "title": "Updated step", "weight": 30, "sort_order": 0},
    {"title": "New step", "weight": 70, "sort_order": 1}
  ],
  "delete_planning_item_ids": [102]
}
```

Both top-level arrays are optional (maximum 100 entries each). Each supplied item requires `title` (max 255) and nonnegative numeric `weight`; `sort_order` is optional and nonnegative. An existing item also includes an integer, distinct `id` belonging to this task. Without an ID a new item is created with zero progress. Existing IDs and progress are preserved. Omitted items and `planning_items:[]` do **not** delete anything. Deletion requires explicit, distinct task-owned IDs in `delete_planning_item_ids`. Updating and deleting the same ID is invalid (422).

This endpoint rejects unrelated fields, `submit`, and item `progress_percentage` (422); progress remains handled by the existing `PATCH /tasks/{task}/planning-items/{planningItem}` endpoint and `tasks:update_progress` permission. Foreign item IDs return 422. Wrong actor/type/status returns 403. The operation locks the task and is transactional. It never changes status, timestamps of workflow transitions or workflow history.

Overall progress uses the existing weighted formula: rounded sum(weight × progress) / sum(weight); zero total weight uses the average. With no remaining rows the existing formula preserves the previous overall progress. `allowed_actions.update_planning` indicates access even when no planning items exist; `allowed_actions.edit` remains false after acceptance.

## Meeting edits

`PATCH /api/v1/meetings/{meeting}` requires `meetings:update` and the creator, chairman, secretary, or an authorized Admin/Super Admin. The default User role now includes this permission, but the policy still restricts each record; attendee membership alone grants no edit access. Run `php artisan access:sync` on existing deployments to apply the role definition.

Only `draft` and `scheduled` are editable, strictly **before** their start. The start is composed from `meeting_date` (Gregorian `YYYY-MM-DD`) and `start_time` (`HH:MM`) in `config('app.timezone')` (currently UTC), not the server's or browser's local timezone. An incomplete draft can be edited until it has a start; an incomplete scheduled meeting is not editable. The resulting edited start must also remain in the future. Final states cannot be edited even by Super Admin. Policy denial returns 403; a time/state conflict detected under the transaction lock returns 409.

Scheduled edits retain their status and `submitted_at`, even if `submit:true` is supplied; initial submission is not rerun. Required scheduled fields cannot be cleared (422). Omitted attendees/agenda arrays remain unchanged; supplied agenda IDs must belong to that meeting. Existing agenda rows are updated by ID, and an agenda linked to a resolution cannot be removed (422). Resolutions retain their links.

```json
{"title":"Updated meeting","meeting_date":"2026-12-10","start_time":"10:30","submit":false}
```
