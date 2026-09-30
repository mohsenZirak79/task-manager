# Reports API

Reports are one-to-one children of meeting resolutions. A resolution declares its destination with
`resolution_type`, which is either `task` or `report`. Existing clients may omit the field; it defaults
to `task` for backward compatibility.

All endpoints use the `/api/v1` prefix and require a Sanctum token and an active user.

## Frontend flow

1. Create a resolution with `resolution_type: "task"` or `resolution_type: "report"`.
2. For `task`, continue using `POST /meetings/{meeting}/resolutions/{resolution}/create-task`.
3. For `report`, use `POST /meetings/{meeting}/resolutions/{resolution}/report`.
4. Use `/reports` for the reports section, `/reports/eligible-users` for recipient selectors, and
   `/report-tags` for tag autocomplete.

Reports can only be created, edited, or deleted while the meeting is in `scheduled` status. A
resolution cannot be linked to both a task and a report, and its type cannot be changed after a task
or report has been linked.

## Resolution payload

`POST /meetings/{meeting}/resolutions` and
`PATCH /meetings/{meeting}/resolutions/{resolution}` accept:

```json
{
  "title": "Monthly performance resolution",
  "description": "Optional resolution description",
  "resolution_type": "report",
  "agenda_item_id": 12,
  "sort_order": 1
}
```

The resolution resource now includes `resolution_type`, `task`, and `report`.

## Create report

`POST /meetings/{meeting}/resolutions/{resolution}/report`

```json
{
  "title": "Monthly performance report",
  "short_description": "Summary shown in report lists",
  "description": "Full report body",
  "recipient_user_id": 2,
  "cc_user_ids": [3, 4],
  "tags": ["monthly", "finance"]
}
```

- `title`, `short_description`, `description`, and `recipient_user_id` are required.
- The recipient and CC users must be active.
- The primary recipient cannot also be in `cc_user_ids`.
- `tags` contains tag titles, uses the same `tags` table as tasks, and accepts at most 20 values.
- Each resolution can have only one report.

## List reports

`GET /reports`

Optional query parameters:

- `search`
- `meeting_id`
- `resolution_id`
- `recipient_user_id`
- `tag_id`
- `page`
- `per_page` (1-100, default 15)

The response contains `data.items` and pagination in `data.meta`. Super admins and admins see all
reports. Other users see reports they created or received, reports where they are CC'd, and reports
for meetings in which they participate.

## Read, update, and delete

- `GET /meetings/{meeting}/resolutions/{resolution}/report` — read by resolution when the report ID is not known
- `GET /reports/{report}`
- `PATCH /reports/{report}` — accepts any subset of the create fields
- `DELETE /reports/{report}`

## Selector endpoints

- `GET /reports/eligible-users?search=&page=&per_page=` returns active users for the recipient and CC selectors.
- `GET /report-tags?search=&page=&per_page=` returns shared task/report tags for autocomplete.

## Permissions

- `reports:view`: list and read visible reports; assigned to the built-in user role.
- `reports:manage`: create, update, and delete reports; assigned to admin and super-admin roles.
- Creating a report also requires `meetings:manage_resolutions` and management access to its meeting.
