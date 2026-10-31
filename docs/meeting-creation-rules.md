# اصلاح صورت‌جلسه و اجرای مصوبات — ۱۴۰۵/۰۸/۱۰

این نسخه روی https://taskover.ir منتشر شده است؛ بک‌اند main و فرانت demo-report پوش و سرور به‌روز شده‌اند. هر دو migration اجرا و سرویس‌ها فعال‌اند. قرارداد کامل: [task-manager-frontend-api-taskover.json](task-manager-frontend-api-taskover.json)، شامل ۷۱ مسیر و ۹۴ عملیات مطابق route:list واقعی.

## رفتار نهایی

- رئیس جلسه از کاربر ایجادکننده تعیین می‌شود و تغییر نمی‌کند؛ فقط همان رئیس می‌تواند شروع جلسه را ثبت کند. رئیس جلسات تاریخی بازنویسی نمی‌شود.
- دبیر خود سازنده یا کاربر فعال پایین‌تر در ساختار واقعی سازمان است. حاضرین اضافی فقط از زیرمجموعه‌های فعال انتخاب می‌شوند؛ رئیس و دبیر از گزینه‌های اضافی حذف‌اند ولی هر کدام یک بار در اعضای نهایی و امضا حضور دارند.
- عبارت «حاضرین جلسه»، توضیح مختصر اجباری، مدت جلسه مستقل از تاریخ/ساعت شروع و مصوبه پیشنهادی قبل از شروع اضافه شده‌اند.
- وضعیت در فهرست و جزئیات، کنار عنوان در پرانتز و کوچک‌تر نمایش داده می‌شود.
- فیلد عددی ترتیب نمایش حذف شده است؛ مصوبات، دستور جلسه و آیتم‌های برنامه‌ریزی با کشیدن جابه‌جا می‌شوند. دکمه‌های بالا/پایین برای موبایل و صفحه‌کلید نیز وجود دارند. sort_order داخلی برای سازگاری API حفظ شده است.
- گزینه اتصال تسک موجود حذف شده است. مسیر قدیمی همچنان کنترل مجوز/والد/ورودی دارد و برای درخواست معتبر 409 می‌دهد. ارتباط‌های تاریخی قابل مشاهده می‌مانند؛ PATCH با task_id:null آن‌ها را پاک نمی‌کند.
- انتخاب «نوع ثبت» از فرم‌های مربوط حذف شده است. در مصوبه، سرور برای مسئولِ خود کاربر assignment و برای فرد دیگر request تعیین می‌کند؛ گردش و محدودیت‌های قبلی تسک/درخواست حفظ شده‌اند. endpoint عمومی tasks و ساخت تسک از گزارش همچنان submission_type می‌خواهند و فرانت آن را از انتخاب مسئول تعیین می‌کند.
- مسئول انجام، ناظر و مسئول پیگیری تسک مصوبه و مخاطب/رونوشت گزارش مصوبه باید اعضای همان جلسه باشند؛ قواعد مجوز و سازمانی ماژول‌ها نیز برقرارند.
- همهٔ مدت‌ها در API همچنان دقیقه‌اند. نمایش/ورودی روز کاری = ۸ ساعت: ۳ ساعت=۱۸۰ دقیقه؛ ۱ روز و ۴ ساعت=۷۲۰ دقیقه. مدت تاریخی در دیتابیس تبدیل یا دست‌کاری نمی‌شود.
- تاریخ‌های ثبت‌شده همراه روز هفته نمایش داده می‌شوند. تاریخ API میلادی باقی می‌ماند؛ timestamp با منطقه تهران نمایش داده می‌شود.
- نقطه قرمز دارای توضیح و متن دسترس‌پذیر روی داده‌ای که کاربر فعلی اقدام مجاز دارد نمایش داده می‌شود. برای reduced-motion ثابت است. requires_action تسک/گزارش/جلسه از قواعد دسترسی می‌آید؛ کارت‌های داشبورد action_required_count دارند. تعریف شمارنده‌های قبلی و مخرج نمودارها تغییر نکرده است.

## گردش قطعی مصوبات

طبق تأیید کاربر، تکمیل فرم همهٔ مصوبات پیش از اتمام الزامی است:

۱. جلسه در draft یا scheduled است؛ فرم تسک/گزارش هر مصوبه فقط با دکمه «ثبت» ذخیره می‌شود. Task یا Report واقعی ساخته نمی‌شود. فرانت به جلسه برمی‌گردد و فرم ذخیره‌شده را هنگام بازکردن دوباره می‌خواند.
۲. اتمام حداقل یک مصوبه و فرم کامل برای همه مصوبات می‌خواهد. ارتباط Task/Report تاریخی به‌عنوان آماده محسوب می‌شود. نبود فرم 422 روی resolutions است.
۳. امضا فقط پس از اتمام و صرفاً توسط خود عضو فعالِ مجاز انجام می‌شود؛ مشاهده و امضا مستقل‌اند. برنامه اجرایی و پیوست‌ها پیش از رضایت امضا نمایش داده می‌شوند.
۴. آخرین امضای نسخه جاری، همهٔ خروجی‌های جدید را در همان تراکنش و زیر قفل والد می‌سازد. تسک از TaskService و گزارش با وضعیت sent از ReportService، به نام ثبت‌کننده اصلی فرم ساخته می‌شود. مجوز، فعال‌بودن ثبت‌کننده و عضویت دوباره بررسی می‌شوند.
۵. شکست هر خروجی، آخرین امضا و تمام خروجی‌های همان تلاش را rollback می‌کند. پس از رفع علت، امضای همان نسخه قابل تکرار است؛ خروجی و امضای تکراری ساخته نمی‌شود. سایر امضاهای قبلی حفظ می‌شوند.
۶. بعد از اتمام، فرم/نوع مصوبه/ترتیب قفل است. نسخه امضا شامل متن، اعضا، دستورها، ترتیب و payload تجاری فرم است. شناسه خروجی ساخته‌شده، registered_at، materialized_at، submission_key و پیشرفت زنده تسک نسخه را عوض نمی‌کنند. برای داده قدیمی بدون فرم/مدت، hash قدیمی حفظ می‌شود.

اگر عضوی غیرفعال یا حذف شود، همهٔ امضاهای معتبرِ اعضای فعال احراز نمی‌شود و خروجی زودهنگام ساخته نمی‌شود. اگر مجوز ثبت‌کننده فرم در فاصله ثبت تا امضا برداشته شود، ساخت خروجی رد می‌شود؛ مجوز لازم باید با همان گردش مدیریت دسترسی موجود اصلاح و امضا تکرار شود.

## قرارداد اتصال فرانت

تمام مسیرها احرازهویت‌شده و با قالب success/message/data فعلی‌اند. توکن یا رمز نمونه داخل فایل تحویل قرار ندارد.

| مسیر | نتیجه |
| --- | --- |
| GET /api/v1/meetings/eligible-users[?meeting_id=24] | chairman، secretaries، attendees طبق ساختار سازنده اصلی و Policy ایجاد/ویرایش؛ بدون نیاز به فهرست عمومی کاربران |
| POST /api/v1/meetings/{meeting}/resolutions/{resolution}/create-task | 200؛ ثبت/ویرایش فرم در data.resolution؛ بدون data.task |
| POST /api/v1/meetings/{meeting}/resolutions/{resolution}/report | 200؛ ثبت/ویرایش فرم در data.resolution؛ بدون data.report |
| PUT /api/v1/meetings/{meeting}/resolutions/order | resolution_ids: همه شناسه‌ها، یکتا و به ترتیب؛ 200 data.meeting |
| POST /api/v1/meetings/{meeting}/complete | 200 data.meeting پس از کنترل همه فرم‌ها؛ به‌تنهایی خروجی جدید نمی‌سازد |
| POST /api/v1/meetings/{meeting}/sign | content_version جاری؛ آخرین امضا خروجی‌ها را اتمی می‌سازد؛ همان پاسخ فعلی امضا |

فیلدهای جدید مصوبه:

```json
{
  "id": 42,
  "resolution_type": "task",
  "execution_status": "registered",
  "execution_plan": {
    "kind": "task",
    "actor_id": 1,
    "registered_at": "2026-11-01T08:00:00.000000Z",
    "payload": {
      "title": "کار مصوبه",
      "short_description": "خلاصه کار",
      "duration_minutes": 720,
      "due_date": "2026-11-10",
      "assignee_ids": [1],
      "follower_ids": [2],
      "supervisor_ids": [2],
      "submission_type": "assignment",
      "submit": true
    }
  },
  "materialized_at": null,
  "task": null,
  "report": null
}
```

این مثال قراردادی است و داده واقعی API نیست. execution_status یکی از unregistered/registered/created است. task/report پس از ساخت واقعی پر می‌شوند. داده قدیمی می‌تواند created با execution_plan:null و materialized_at:null باشد.

GET جزئیات جلسه علاوه بر فیلدهای موجود، execution_files برای metadata پیوست‌های فرم و execution_readiness شامل forms_complete/members_count/signed_count/all_signed/ready می‌دهد. فهرست امضاها همچنان از attendees همان نسخه خوانده می‌شود. بعد از موفقیت امضای آخر، کش جلسه، تسک، گزارش و داشبورد تازه می‌شود؛ پاسخ ثبت فرم نباید وارد کش Report شود.

خطاها: 401 احراز هویت، 403 مجوز یا شرکت‌کننده نامجاز برای امضا، 404 والد/مصوبه ناموجود یا نامرتبط، 409 مرحله نامعتبر/نسخه قدیمی/اتصال غیرفعال، 422 اطلاعات یا فرم ناقص. قواعد کامل ورودی در JSON درج شده‌اند. ارسال task با پیشرفت ناقص برای پایان همچنان طبق کنترل موجود 422 می‌دهد؛ این اصلاح آن گردش را تغییر نداده است.

## دیتابیس و انتشار

دو migration افزایشی:

- 2026_10_31_000000_add_duration_to_meetings.php: duration_minutes nullable؛ بدون جعل مدت قدیمی.
- 2026_11_01_000000_add_resolution_execution_plans.php: execution_plan JSON و materialized_at برای مصوبات و signature_content_version محاسباتی برای جلسات. فقط نسخه متن قدیمی را به شکل batch محاسبه می‌کند؛ امضا، تاریخ یا خروجی قدیمی را بازنویسی نمی‌کند.

```bash
php artisan migrate --force
```

ابتدا بک‌اند و migration و سپس فرانت سازگار منتشر شوند. این گردش عمداً پاسخ ایجاد تسک/گزارش مصوبه را از ایجاد فوری 201 به ثبت فرم 200 تغییر می‌دهد؛ فرانت این نسخه با آن هماهنگ شده است. API عمومی تسک و گزارش مستقل تغییر نکرده‌اند. تنظیم global دیتابیس یا cache جدید اضافه نشده؛ داشبورد از snapshot خواندن موجود استفاده می‌کند. rollback migration جدید در صورت وجود فرم ثبت‌شده، برای جلوگیری از حذف داده رد می‌شود. پیوست فرم ثبت‌شده تا زمان حذف ارتباط قابل حذف مستقیم نیست.

## نتیجه واقعی آزمون

| اجرا | کل | موفق | شکست | skip | assertion |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite مرتبط، اجرای نهایی | 166 | 164 | 0 | 2 | 2126 |
| MySQL 8.4 مستقل، مجموعه اصلی | 153 | 152 | 0 | 1 | 1971 |
| MySQL تکمیلی، بدون هم‌پوشانی | 3 | 3 | 0 | 0 | 36 |
| فرانت Node 22.23.4 | 330 | 330 | 0 | 0 | — |

دو skip SQLite تست snapshot اختصاصی MySQL هستند و روی MySQL موفق اجرا شده‌اند. یک skip MySQL در ReportInteractionTest مربوط به تست DDL/rollback SQLite است. ReportWorkflowTest روی SQLite اجرا شده است؛ مجموعه MySQL آن را شامل نمی‌شود، چون تست rollback قدیمی آن ناسازگاری شناخته‌شده FK/index دارد. جمع MySQL بدون هم‌پوشانی: ۱۵۶ تست، ۱۵۵ موفق و یک skip.

build بهینه Next.js با ۲۴ صفحه static موفق است؛ lint بدون خطا/هشدار و Pint موفق‌اند. تست‌ها شامل ثبت بدون خروجی، اجبار همه فرم‌ها، مرز امضا/اتمام، آخرین امضا، تکرار درخواست، rollback خروجی‌های نیمه‌ساخته، نسخه قدیمی، پیوست‌ها، محدودیت عضو/مجوز، ارتباط‌های تاریخی، ترتیب، داده route-bound قدیمی، حفاظت migration، روز کاری و رندر فرانت هستند. تست کلیک واقعی مرورگر و ایجاد رکورد آزمایشی در production انجام نشده‌اند؛ بررسی خواندنی احرازهویت‌شده سرور و HTTPS عمومی پس از انتشار موفق است؛ کل تست‌های نامرتبط پروژه نیز موفق اعلام نمی‌شوند.

آثار اجرای این جلسه در /tmp با پیشوند taskover-execution هستند؛ این مسیر موقت است. فایل JSON کامل، منبع تحویل پایدار قرارداد است.

گزارش انتشار: [meeting-execution-deployment.md](meeting-execution-deployment.md).

## فایل‌های تغییرکرده

### backend

- `app/Http/Controllers/Api/V1/MeetingController.php`
- `app/Http/Controllers/Api/V1/MeetingResolutionController.php`
- `app/Http/Controllers/Api/V1/ReportController.php`
- `app/Http/Requests/Concerns/HasMeetingPayloadRules.php`
- `app/Http/Requests/CreateMeetingTaskRequest.php`
- `app/Http/Requests/StoreMeetingRequest.php`
- `app/Http/Requests/SubmitMeetingRequest.php`
- `app/Http/Requests/UpdateMeetingRequest.php`
- `app/Http/Resources/MeetingAttendeeResource.php`
- `app/Http/Resources/MeetingResolutionResource.php`
- `app/Http/Resources/MeetingResource.php`
- `app/Http/Resources/MeetingSummaryResource.php`
- `app/Http/Resources/ReportResource.php`
- `app/Http/Resources/TaskResource.php`
- `app/Models/Meeting.php`
- `app/Models/MeetingResolution.php`
- `app/Policies/MeetingPolicy.php`
- `app/Services/DashboardService.php`
- `app/Services/MediaUploadService.php`
- `app/Services/MeetingExecutionService.php`
- `app/Services/MeetingParticipantService.php`
- `app/Services/MeetingService.php`
- `app/Services/MeetingSignatureContent.php`
- `app/Services/MeetingSignatureService.php`
- `app/Services/ReportService.php`
- `app/Services/TaskService.php`
- `app/Services/TaskVisibilityService.php`
- `database/migrations/2026_10_31_000000_add_duration_to_meetings.php`
- `database/migrations/2026_11_01_000000_add_resolution_execution_plans.php`
- `docs/meeting-creation-rules.md`
- `docs/task-manager-frontend-api-taskover.json`
- `routes/api.php`
- `tests/Feature/DashboardTest.php`
- `tests/Feature/MeetingCreationRulesTest.php`
- `tests/Feature/MeetingDraftEditingTest.php`
- `tests/Feature/MeetingExecutionTest.php`
- `tests/Feature/MeetingOfficerAccessTest.php`
- `tests/Feature/MeetingResolutionIntegrationTest.php`
- `tests/Feature/MeetingSignatureTest.php`
- `tests/Feature/ReportManagementTest.php`
- `tests/Feature/ReportWorkflowTest.php`
- `tests/Feature/ReportedBugsTest.php`
- `tests/Feature/WorkflowRulesTest.php`

### frontend

- `docs/meeting-creation-rules.md`
- `src/core/api/dashboardResponseRendering.test.mjs`
- `src/core/api/meetingOfficerUi.test.mjs`
- `src/core/api/meetingResolutionLinks.test.mjs`
- `src/core/api/meetingSignatures.test.mjs`
- `src/features/dashboard/components/DashboardStats/index.js`
- `src/features/meetings/api/meeting.api.js`
- `src/features/meetings/components/MeetingForm/index.js`
- `src/features/meetings/components/MeetingSignatures/ExecutionPlanReview.js`
- `src/features/meetings/components/MeetingSignatures/index.js`
- `src/features/meetings/components/ResolutionDialog/index.js`
- `src/features/meetings/hooks/useMeetings.js`
- `src/features/meetings/pages/MeetingDetailPage.js`
- `src/features/meetings/pages/MeetingsPage.js`
- `src/features/meetings/schemas/meeting.schema.js`
- `src/features/meetings/schemas/meeting.schema.test.mjs`
- `src/features/meetings/schemas/meetingCreationRules.test.mjs`
- `src/features/meetings/schemas/meetingSignature.schema.test.mjs`
- `src/features/meetings/schemas/resolution.schema.js`
- `src/features/meetings/schemas/resolution.schema.test.mjs`
- `src/features/meetings/services/meeting.service.js`
- `src/features/meetings/utils/meetingCapabilities.js`
- `src/features/meetings/utils/meetingParticipants.js`
- `src/features/reports/components/ReportForm/ReportFields.js`
- `src/features/reports/components/ReportForm/ReportResolutionFields.js`
- `src/features/reports/components/ReportForm/index.js`
- `src/features/reports/components/ReportTaskCreateDialog.js`
- `src/features/reports/hooks/useReports.js`
- `src/features/reports/pages/ReportCreatePage.js`
- `src/features/reports/pages/ReportsListPage.js`
- `src/features/reports/services/report.service.js`
- `src/features/tasks/components/CreateTaskForm/CreateTaskFields.js`
- `src/features/tasks/components/CreateTaskForm/PlanningSection.js`
- `src/features/tasks/components/MobileTasksList/index.js`
- `src/features/tasks/components/QuickTaskDialog/index.js`
- `src/features/tasks/components/TaskPlanningTable/index.js`
- `src/features/tasks/components/TaskRow/index.js`
- `src/features/tasks/models/task.model.js`
- `src/features/tasks/services/task.service.js`
- `src/features/tasks/utils/createTaskPayload.js`
- `src/features/tasks/utils/formatDuration.js`
- `src/features/tasks/utils/requestCalendar.test.mjs`
- `src/shared/components/ActionRequiredIndicator/index.js`
- `src/shared/components/WorkDurationInput/index.js`
- `src/shared/hooks/useDragOrder.js`
- `src/shared/utils/calendarDate.js`
- `src/shared/utils/dateTime.js`
- `src/shared/utils/workDuration.js`
- `src/shared/utils/workDuration.test.mjs`

