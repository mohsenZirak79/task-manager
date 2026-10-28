# اتصال گزارش به تسک — قرارداد تحویل محلی

این تغییرات روی نسخه محلی آماده شده‌اند؛ push، deploy یا migration روی production انجام نشده است. فرانت و APIهای داشبورد تغییر نکرده‌اند.

## نتیجه بررسی نیازمندی‌ها

| نیاز | وضعیت | نتیجه |
| --- | --- | --- |
| ساخت تسک از مصوبه | ALREADY IMPLEMENTED | همان endpoint و TaskService قبلی حفظ شد. |
| امضای اعضای جلسه پس از اتمام | ALREADY IMPLEMENTED | سازوکار نسخه‌بندی امضا حفظ شد؛ قرارداد کامل شامل آن است. |
| وضعیت اتمام جلسه | ALREADY IMPLEMENTED | `POST /api/v1/meetings/{meeting}/complete` و وضعیت `completed` وجود دارند؛ وضعیت جدید نساختیم. |
| مدیریت مصوبات توسط منشی | ALREADY IMPLEMENTED | MeetingPolicy منشی را می‌پذیرد، اما مجوز موجود `meetings:manage_resolutions` لازم است. این مجوز خودکار به همه کاربران داده نشد. |
| تشخیص مجوز مدیریت مصوبات در UI | IMPLEMENTED NOW | `data.meeting.allowed_actions.manage_resolutions` بر اساس Policy و وضعیت scheduled. |
| اتصال تسک موجود به مصوبه | FIXED | کنترلر و سرویس وجود داشتند ولی route ثبت نشده بود؛ مسیر به منطق موجود وصل شد و مجوز زیر قفل دوباره بررسی می‌شود. |
| دریافت اطلاعات مصوبه | ALREADY IMPLEMENTED | در جزئیات جلسه: عنوان، شرح، شناسه دستور جلسه، نوع، ترتیب، تسک خلاصه/گزارش و timestamps. جزئیات کامل تسک از endpoint خودش با مجوز مستقل خوانده می‌شود. |
| ساخت چند تسک از گزارش | IMPLEMENTED NOW | فقط گیرنده اصلی گزارش viewed، با TaskPolicy.create. مخاطبان رونوشت، سازنده و مدیر صرفاً به‌دلیل نقش/مشاهده اجازه ندارند. |
| رابطه دائمی گزارش مبدأ | IMPLEMENTED NOW | FK در tasks، محافظت در حذف گزارش و عدم امکان تغییر مبدأ از payload تسک. داده قدیمی backfill حدسی ندارد. |
| retry و تراکنش | IMPLEMENTED NOW | قفل گزارش، reauthorization، کلید UUID، fingerprint ورودی معتبر و unique constraint؛ task/planning/attachments همگی در همان تراکنش. |
| شمارنده و فهرست تسک‌های گزارش | IMPLEMENTED NOW | فقط تسک‌های قابل‌مشاهده کاربر؛ SQL aggregation و pagination، بدون query افزایشی به‌ازای هر آیتم. |
| فیلدهای تسک و رفتار مالی/برنامه‌ریزی/ارسال | ALREADY IMPLEMENTED | همان StoreTaskRequest/TaskService استفاده می‌شوند؛ برای گزارش مسیر گردش کار جدید ساخته نشد. |
| بخش‌های میانی متن اصلی که ارسال نشده‌اند | NOT IMPLEMENTED | متن از ابتدای بخش ۱ به انتهای مستندات و تحویل می‌پرد؛ هیچ workflow یا permission برای بخش‌های نامعلوم حدس زده نشد. |
| انتشار روی سرور | NOT IMPLEMENTED | مطابق دستور فعلی مجاز نیست؛ فایل JSON تغییرات جدید را local/not deployed معرفی می‌کند. |

## API و اتصال فرانت

قرارداد **کامل**: [task-manager-frontend-api-taskover.json](task-manager-frontend-api-taskover.json)، OpenAPI 3.1، شامل ۶۹ مسیر و ۹۲ عملیات HTTP موجود و جدید. آدرس پایه در فایل `https://taskover.ir` است؛ قابلیت‌های جدید تا deploy در آن دامنه فعال نیستند. schemaها، مثال‌ها، مجوزها و خطاهای مسیرهای جدید داخل همین فایل هستند.

همه مسیرها `Authorization: Bearer <token>` و `Accept: application/json` دارند؛ برای نوشتن `Content-Type: application/json` نیز ارسال شود.

### ساخت تسک از گزارش

1. `GET /api/v1/reports/{report}`؛ دکمه را از `allowed_actions.create_task` کنترل کنید.
2. فرم را از `task_suggestions` پر کنید. عنوان، خلاصه تا ۱۰۰ کاراکتر و شرح گزارش پیشنهاد می‌شوند؛ متن دلخواه کاربر قابل ارسال است. تگ‌ها، پیوست‌ها یا مجوزهای گزارش خودکار به تسک کپی نمی‌شوند.
3. UUID جدید با `crypto.randomUUID()` برای هر ساخت واقعی تولید کنید و در retry شبکه همان کلید و همان JSON را نگه دارید.

```http
POST /api/v1/reports/24/create-task
```

```json
{
  "submission_key": "a9a1c972-871f-4397-ad93-a20710de5c61",
  "submission_type": "assignment",
  "title": "پیگیری گزارش",
  "short_description": "اقدام اصلاحی",
  "request_description": "شرح اقدام",
  "duration_minutes": 60,
  "due_date": "2026-11-05",
  "submit": false
}
```

مجوزها: `reports:view` و `tasks:create`؛ گیرنده اصلی فعال، وضعیت `viewed` و شرط سازمانی TaskPolicy.create نیز لازم‌اند. Super-admin از شرط گیرنده/وضعیت معاف نیست. `submission_type` و UUID اجباری‌اند. سایر قواعد دقیقاً قواعد ایجاد تسک فعلی‌اند: assignment شخصی است؛ request فقط اهداف سازمانی مجاز را می‌پذیرد. تاریخ نمونه باید با تاریخ امروز/آینده جایگزین شود.

برای پیش‌نویس، فیلدهای متنی ارسال‌نشده از گزارش مقدار اولیه می‌گیرند. برای `submit=true` عنوان، خلاصه، مدت و موعد را صریح بفرستید؛ request علاوه بر آن مسئول، تگ، پیرو و ناظر لازم دارد. آیتم‌های برنامه‌ریزی assignment طبق رفتار موجود با پیشرفت صفر آغاز می‌شوند. اعتبارسنجی مالکیت فایل‌ها و سایر امکانات TaskService تغییری ندارد.

پاسخ `201`: `{"success":true,"message":"تسک مرتبط با گزارش ثبت شد.","data":{"task":<TaskResource کامل>,"replayed":false}}`.

retry موفق `200` همان شناسه تسک و `replayed=true` برمی‌گرداند. کلید متعلق به همان گزارش و همان کاربر است. استفاده مجدد از کلید با payload معتبر متفاوت `409` می‌دهد. ترتیب کلیدهای object در fingerprint بی‌اثر است؛ ترتیب لیست و نوع مقدار JSON حفظ می‌شود. تغییر متن گزارش، متن تسک ساخته‌شده را در retry عوض نمی‌کند. retry همچنان اعتبارسنجی و دسترسی/وضعیت فعلی را بررسی می‌کند.

خطاها: `401` بدون احراز هویت؛ `403` نبود مجوز، گیرنده نبودن، وضعیت غیر viewed، کاربر غیرفعال یا نبود موقعیت سازمانی لازم؛ `404` گزارش ناموجود؛ `409` تعارض کلید؛ `422` UUID/فیلد نامعتبر یا شروط ایجاد/ارسال تسک. `source_report_id`، `report_submission_key` و `report_request_hash` در این payload ممنوع‌اند.

### خواندن ارتباط‌ها

- `GET /api/v1/tasks/{task}`: `data.task.report_source = {"id":24,"title":"گزارش عملکرد"}`؛ برای تسک مستقل یا مبدأ غیرمجاز `null`. عنوان مبدأ فعلی است، متن خود تسک بعد از ایجاد مستقل باقی می‌ماند.
- `GET /api/v1/reports/{report}` و فهرست گزارش‌ها: `tasks_count` فقط تعداد تسک‌های قابل‌مشاهده است؛ بدون `tasks:view` مقدار شمارنده و `tasks_url` هر دو `null` هستند. شمارنده در گزارش تو‌در‌توی مصوبه ممکن است نیاید؛ برای شمارنده، جزئیات گزارش را بگیرید.
- `GET /api/v1/reports/{report}/tasks?page=1&per_page=15`: مجوزهای `reports:view`، ReportPolicy.view و `tasks:view`. پاسخ `data.items` از TaskResource و `data.meta` شامل `current_page,last_page,per_page,total` است. `page>=1`، `1<=per_page<=100` و ترتیب `created_at DESC,id DESC`. scope شخصی فهرست اصلی تسک‌ها روی این رابطه تحمیل نمی‌شود؛ فقط visibility واقعی اعمال می‌شود. گزارش غیرفعال هم ارتباط‌های قبلی قابل‌مشاهده را حفظ می‌کند.

### اتصال تسک موجود به مصوبه و مجوز منشی

```http
POST /api/v1/meetings/{meeting}/resolutions/{resolution}/link-task
```

```json
{"task_id":801}
```

مجوزهای `meetings:manage_resolutions` و `tasks:view`، مجوز واقعی مدیر/منشی در MeetingPolicy و مشاهده همان تسک لازم‌اند. جلسه scheduled، مصوبه متعلق به همان جلسه، از نوع task و خالی باشد؛ تسک به مصوبه دیگری وصل نباشد و مسئولان آن از اعضای جلسه باشند. پاسخ `200`، `data.resolution` با task summary است. خطاهای `401/403/404/409/422` در JSON توضیح داده شده‌اند. تکرار لینک اشغال‌شده `409` است و تسک جدید نمی‌سازد.

برای تشخیص دسترسی منشی از `data.meeting.allowed_actions.manage_resolutions` استفاده کنید. منشی بودن به‌تنهایی جای مجوز ماژول را نمی‌گیرد. فیلد `resolutions` در همان جزئیات جلسه، متن و ارتباط‌ها را برمی‌گرداند؛ `agenda_item_id` به `agenda_items` همان پاسخ اشاره می‌کند. برای جزئیات بیشتر تسک مرتبط، `GET /api/v1/tasks/{id}` را بزنید. ساخت تسک از گزارشِ مصوبه، نوع report یا `task_id` خود مصوبه را تغییر نمی‌دهد و امضای مصوبات را بی‌اعتبار نمی‌کند.

## دیتابیس و سازگاری

Migration افزایشی: `2026_10_28_000000_add_report_source_to_tasks_table.php`.

- `tasks.source_report_id`: nullable، FK به reports با `RESTRICT ON DELETE`، رابطه یک گزارش به چند تسک.
- `tasks.report_submission_key`: UUID nullable؛ `report_request_hash`: string(64) nullable، هر دو از JSON مدل پنهان و از payload معمول تسک قابل تغییر نیستند.
- unique روی `(source_report_id,created_by,report_submission_key)` برای retry؛ تسک‌های قدیمی null باقی می‌مانند.
- حذف گزارش دارای تسک از API یا از مسیر حذف مصوبه با `409` متوقف می‌شود؛ FK حذف مستقیم SQL را هم نمی‌پذیرد. `allowed_actions.delete` نیز برای چنین گزارش‌هایی false است. غیرفعال‌سازی ارتباط مبدأ را حفظ می‌کند.
- قواعد حذف پیش‌نویس تسک حفظ شده‌اند. حذف عمدی تسک، کلید ذخیره‌شده همان تسک را هم حذف می‌کند؛ پس بعد از حذف عمدی، درخواست قدیمی را retry نکنید. برای ساخت جایگزین UUID جدید لازم است. نگهداری tombstone برای تسک حذف‌شده در دامنه این تغییر نیست.
- rollback این migration ستون‌های رابطه/کلید را حذف می‌کند؛ اگر داده جدید ثبت شده، پیش از rollback خروجی/پشتیبان رابطه‌ها لازم است. رکوردهای کسب‌وکار در up بازنویسی نمی‌شوند.

دستور اجرا هنگام انتشار مجاز، پس از backup:

```bash
php artisan migrate --force
```

## فایل‌های کد و تست تغییرکرده

- Controller جدید: `app/Http/Controllers/Api/V1/ReportTaskController.php`.
- FormRequestهای جدید: `app/Http/Requests/CreateReportTaskRequest.php`، `app/Http/Requests/IndexReportTasksRequest.php`.
- Service جدید: `app/Services/ReportTaskService.php`؛ تغییر محدود `TaskService.php`، `ReportService.php` و `MeetingService.php` در همان پوشه.
- مدل‌ها: `app/Models/Task.php`، `app/Models/Report.php`.
- Policyها: `app/Policies/ReportPolicy.php`، `app/Policies/TaskPolicy.php`؛ قواعد TaskPolicy.create حفظ شده و رابطه از قبل بارگذاری‌شده برای جلوگیری از N+1 قابل استفاده است؛ هنگام ساخت زیر قفل از نو بررسی می‌شود.
- Resourceها: `app/Http/Resources/TaskResource.php`، `ReportResource.php`، `MeetingResource.php`.
- مسیرها: `routes/api.php`؛ migration بالا.
- تست‌های جدید: `tests/Feature/ReportTaskTest.php`، `tests/Feature/MeetingResolutionIntegrationTest.php`، `tests/Feature/ReportTaskMigrationTest.php`؛ اصلاح انتظار کلید افزوده‌شده در `tests/Feature/ReportInteractionTest.php`.
- مستندات: همین فایل و `docs/task-manager-frontend-api-taskover.json`.

## تست و محدودیت‌های بررسی

نتایج نهایی اجرای واقعی در بخش `x-frontend-integration.verification.report_task_integration` فایل JSON و جدول زیر ثبت می‌شوند. SQLite و MySQL فقط دیتابیس تست‌اند؛ به دیتابیس production متصل نشده‌ایم. همه تست‌های جدید از زمان ثابت `2026-10-28T09:00:00Z` استفاده می‌کنند.

| اجرا | کل تست | موفق | شکست/خطا | skip | assertion |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite — رگرسیون نهایی | 133 | 131 | 0/0 | 2 | 1717 |
| MySQL — قابلیت‌های جدید و امضا | 39 | 39 | 0/0 | 0 | 310 |
| MySQL — رگرسیون نهایی گسترده | 132 | 131 | 0/0 | 1 | 1723 |
| SQLite — rollback و اجرای مجدد migration جدید | 1 | 1 | 0/0 | 0 | 5 |
| MySQL — rollback و اجرای مجدد migration جدید | 1 | 1 | 0/0 | 0 | 5 |

Pint و `git diff --check` موفق بودند. ۱۱ مثال JSON مرتبط با schemaهایشان، تمام ارجاع‌های داخلی و تطابق method/path تمام ۹۲ عملیات با Laravel بررسی شدند. schemaها و مسیرهای داشبورد نسبت به HEAD بدون تغییرند.


دستور اجرای مجموعه مرتبط SQLite:

```bash
php artisan test --compact --filter='ReportTaskTest|MeetingResolutionIntegrationTest|MeetingSignatureTest|WorkflowRulesTest|ReportedBugsTest|ReportManagementTest|ReportWorkflowTest|ReportInteractionTest|DashboardTest'
```

دستور MySQL با `phpunit.mysql.xml` موجود، فقط روی `127.0.0.1:33317` و دیتابیس ایزوله `task_manager_dashboard_test` (bootstrap جلوی اتصال به مقصد دیگر و config کش‌شده را می‌گیرد):

```bash
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter='^(?!.*test_migration_backfills_existing_reports_and_can_roll_back_threads).*(ReportTaskTest|MeetingResolutionIntegrationTest|MeetingSignatureTest|WorkflowRulesTest|ReportedBugsTest|ReportManagementTest|ReportWorkflowTest|ReportInteractionTest|DashboardTest)'
```

تست مستقل migration جدید، بدون تراکنش بیرونی که DDL در MySQL آن را commit می‌کند:

```bash
php artisan test --compact --filter=ReportTaskMigrationTest
vendor/bin/phpunit --configuration phpunit.mysql.xml --filter=ReportTaskMigrationTest
```

تست‌ها شامل تفکیک گیرنده/رونوشت/مدیر، وضعیت‌ها و مجوزها، validation و assignment/request، برنامه‌ریزی و پیوست و منابع، rollback تراکنش، retry، تعارض کلید، unique و FK در دیتابیس، حفاظت مبدأ، صفحه‌بندی، داده پنهان، نبود مجوز ماژول، عدم افزایش query با تعداد تسک/گزارش، مجوز منشی و اتصال تسک موجود و رگرسیون امضا/گزارش/داشبورد هستند.

تست رگرسیون MySQL قدیمی `test_migration_backfills_existing_reports_and_can_roll_back_threads` به‌دلیل rollback ناسازگار FK/index در migration قدیمی از اجرای گسترده MySQL مستثنا شد؛ در SQLite اجرا می‌شود. تست DDL قدیمی ReportInteractionTest نیز خودش در MySQL skip می‌شود. دو تست مخصوص snapshot و read-only در DashboardTest روی SQLite skip و روی MySQL اجرای گسترده اجرا شدند. کل مجموعه نامرتبط پروژه اجرا نشده و موفق اعلام نمی‌شود. تست بار هم‌زمان HTTP یا محیط production انجام نشده است؛ کنترل retry با تراکنش، قفل، unique constraint و تست‌های مرتبط بررسی شده است.

در بررسی harness تست، rollback سراسری migrationهای قدیمی نیز خطاهای موجود index ستون `users.is_super_admin` در SQLite و index/FK مربوط به `task_comments` در MySQL را نشان داد. این rollbackهای نامرتبط اصلاح نشدند. تست migration جدید فقط down/up همان migration را اجرا می‌کند، عدم حذف رکوردها را می‌سنجد و سپس آن را دوباره برقرار می‌کند؛ موفقیت آن به‌معنای تأیید rollback همه تاریخچه پروژه نیست.
