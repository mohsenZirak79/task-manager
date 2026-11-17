# فیلتر مستقل ستون‌های جدول‌ها — ۲۰۲۶/۱۱/۱۷

دامنهٔ این اصلاح فقط بک‌اند، تست و مستندات است. فرانت تغییر نکرده و چت هنوز شروع نشده است. تغییرات محلی‌اند و هنوز push/deploy نشده‌اند. migration یا وابستگی جدید لازم نیست.

## قرارداد مشترک

تمام پارامترهای زیر اختیاری‌اند. نبودن، null یا رشتهٔ خالی/فاصله شرطی اضافه نمی‌کند؛ صفر معتبر حذف نمی‌شود. متن trim می‌شود و حداکثر ۲۵۵ کاراکتر دارد. تطبیق contains است؛ `%`، `_` و escape داخلی `!` در ورودی literal هستند. `ی/ي` و `ک/ك` در هر دو سمت Query یکسان می‌شوند؛ ایمیل case-insensitive است. پارامترهای SQL با binding ارسال می‌شوند.

فیلترهای مستقل و search عمومی با AND ترکیب می‌شوند؛ ORهای داخلی منابع/تأمین‌کننده و جستجوی عمومی گروه‌بندی شده‌اند. فیلترها روی مجموعهٔ مجاز و پیش از pagination در دیتابیس اعمال می‌شوند. join چندمسئولی/چندجایگاهی شمارش یا رکوردها را تکرار نمی‌کند.

تاریخ‌ها میلادی `YYYY-MM-DD` و بازه‌ها شامل دو سرند. برای timestamp، روز تهران از ابتدای from تا ابتدای روز بعد از to محاسبه و به timezone ذخیره‌سازی فعلی (UTC) تبدیل می‌شود. بازهٔ یک‌روزهٔ 2026-11-17 در ذخیرهٔ UTC معادل `[2026-11-16 20:30:00, 2026-11-17 20:30:00)` است. null تاریخ در بازه تطبیق ندارد. حد بالا کوچک‌تر از پایین، نوع نامعتبر، تاریخ غیرواقعی و متن بلند با 422 و `errors[نام_پارامتر]` رد می‌شوند. هزینه‌ها عدد نامنفی برحسب **تومان** مطابق UI فعلی و ستون decimal(18,2) هستند؛ تبدیل ریال/ارز یا گردکردن فیلتر نداریم. مدت دقیقه است؛ یک روز کاری ۴۸۰ دقیقه.

وضعیت‌های «قبلی» از FormRequest و Query واقعی مبنا استخراج شده‌اند. نام پارامترهای قبلی، envelope، scope، permission و معنای شمارنده حفظ شده است. سخت‌گیری قالب تاریخ و literalشدن wildcardها اصلاح عمدی مطابق درخواست است. `page` جلسه پیش‌تر از request توسط Paginator مصرف می‌شد؛ اکنون صریحاً اعتبارسنجی و اعمال می‌شود. سقف متن tag از ۱۰۰ به ۲۵۵ افزایش یافته است.

## GET /api/v1/tasks

| پارامتر | نوع | وضعیت | رفتار |
| --- | --- | --- | --- |
| `search` | string | قبلی، حفظ/تکمیل‌شده | جستجوی عمومی عنوان، short_description و request_description با OR گروه‌بندی‌شده و AND با فیلتر ستون‌ها؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `title` | string | جدید | فقط tasks.title؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `description` | string | جدید | مقدار نمایشی COALESCE(short_description, request_description, '')؛ رشتهٔ خالی short_description به متن درخواست fallback نمی‌شود؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `assignee_search` | string | جدید | نام کامل حداقل یکی از مسئولان انجام؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `financial_search` | string | جدید | financial_resources یا نام کامل financialProvider با OR گروه‌بندی‌شده؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `equipment_search` | string | جدید | equipment_resources یا نام کامل equipmentProvider با OR گروه‌بندی‌شده؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `tag` | string | قبلی، حفظ/تکمیل‌شده | عنوان یکی از تگ‌ها؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `user_id` | integer | قبلی، حفظ/تکمیل‌شده | معنای قبلی: سازنده یا درخواست‌کننده یا تأمین‌کنندهٔ مالی/تجهیزات یا هر participant؛ جایگزین assignee_id نیست |
| `assignee_id` | integer | قبلی، حفظ/تکمیل‌شده | یکی از مسئولان انجام، با role=assignee |
| `follower_id` | integer | قبلی، حفظ/تکمیل‌شده | یکی از مسئولان پیگیری، با role=follower |
| `supervisor_id` | integer | قبلی، حفظ/تکمیل‌شده | یکی از ناظران، با role=supervisor |
| `financial_provider_user_id` | integer | جدید | تأمین‌کننده مالی |
| `equipment_provider_user_id` | integer | جدید | تأمین‌کننده تجهیزات |
| `duration_minutes_min` | integer | جدید | مدت بر حسب دقیقه؛ یک روز کاری ۴۸۰ دقیقه؛ حد پایین شامل. |
| `duration_minutes_max` | integer | جدید | مدت بر حسب دقیقه؛ یک روز کاری ۴۸۰ دقیقه؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `financial_estimated_cost_min` | number | جدید | هزینه بر حسب تومان؛ مقدار ذخیره‌شده decimal(18,2)، بدون تبدیل ریال یا گردکردن فیلتر؛ حد پایین شامل. |
| `financial_estimated_cost_max` | number | جدید | هزینه بر حسب تومان؛ مقدار ذخیره‌شده decimal(18,2)، بدون تبدیل ریال یا گردکردن فیلتر؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `equipment_estimated_cost_min` | number | جدید | هزینه بر حسب تومان؛ مقدار ذخیره‌شده decimal(18,2)، بدون تبدیل ریال یا گردکردن فیلتر؛ حد پایین شامل. |
| `equipment_estimated_cost_max` | number | جدید | هزینه بر حسب تومان؛ مقدار ذخیره‌شده decimal(18,2)، بدون تبدیل ریال یا گردکردن فیلتر؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `due_from` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | due_date؛ تاریخ موعد، مستقل از completed_at؛ حد پایین شامل. |
| `due_to` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | due_date؛ تاریخ موعد، مستقل از completed_at؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `created_from` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | created_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to، تبدیل به timezone ذخیره‌سازی؛ حد پایین شامل. |
| `created_to` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | created_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to، تبدیل به timezone ذخیره‌سازی؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `status` | string enum(draft, pending_approval, revision_requested, ready_to_start, in_progress, pending_completion_approval, completed, not_completed, rejected, closed) | قبلی، حفظ/تکمیل‌شده | وضعیت فعلی تسک؛ بدون ادغام enumها. |
| `submission_type` | string enum(assignment, request) | قبلی، حفظ/تکمیل‌شده | request برای درخواست‌ها، assignment برای تسک‌ها؛ رفتار قبلی حفظ شده. |
| `scope` | string enum(created_by_me, assigned_to_me, involved, action_required, all) | قبلی، حفظ/تکمیل‌شده | محدوده قبلی؛ پیش‌فرض برای request برابر created_by_me، برای assignment برابر assigned_to_me و بدون نوع برابر involved؛ all فقط طبق مجوز واقعی. |
| `sort` | string enum(newest, oldest, longest_duration, shortest_duration, highest_progress, lowest_progress) | قبلی، حفظ/تکمیل‌شده | مرتب‌سازی قبلی؛ ترتیب پایدار شناسه در حالت تساوی. |
| `page` | integer | قبلی، حفظ/تکمیل‌شده | شماره صفحه؛ پیش‌فرض ۱؛ total و last_page پس از فیلتر محاسبه می‌شوند. |
| `per_page` | integer | قبلی، حفظ/تکمیل‌شده | تعداد نتیجه در صفحه؛ پیش‌فرض ۱۵. |

نمونهٔ query (شناسه‌ها فرضی‌اند؛ متن هنگام ارسال URL-encode شود):

```http
GET /api/v1/tasks?submission_type=request&scope=created_by_me&title=فروش&assignee_id=12&duration_minutes_min=480&duration_minutes_max=960&due_from=2026-11-17&due_to=2026-11-30&page=1&per_page=15
```

## GET /api/v1/reports

| پارامتر | نوع | وضعیت | رفتار |
| --- | --- | --- | --- |
| `search` | string | قبلی، حفظ/تکمیل‌شده | جستجوی عمومی عنوان، شماره، خلاصه، شرح و عنوان مصوبه؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `report_number` | string | جدید | شماره نمایشی COALESCE(report_number, CAST(id AS text))؛ fallback فقط برای null؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `title` | string | جدید | فقط عنوان گزارش؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `recipient_search` | string | جدید | نام کامل مخاطب اصلی؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `creator_search` | string | جدید | نام کامل سازنده؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `recipient_user_id` | integer | قبلی، حفظ/تکمیل‌شده | شناسه مخاطب اصلی |
| `creator_user_id` | integer | قبلی، حفظ/تکمیل‌شده | شناسه سازنده |
| `meeting_id` | integer | قبلی، حفظ/تکمیل‌شده | جلسه مرتبط |
| `resolution_id` | integer | قبلی، حفظ/تکمیل‌شده | مصوبه مرتبط |
| `tag_id` | integer | قبلی، حفظ/تکمیل‌شده | تگ مرتبط |
| `status` | string enum(draft, sent, viewed, inactive) | قبلی، حفظ/تکمیل‌شده | status گردش گزارش، مستقل از action_type. |
| `created_from` | date YYYY-MM-DD | جدید | created_at؛ روز شامل دو سر بر اساس Asia/Tehran و انتهای انحصاری ابتدای روز بعد؛ حد پایین شامل. |
| `created_to` | date YYYY-MM-DD | جدید | created_at؛ روز شامل دو سر بر اساس Asia/Tehran و انتهای انحصاری ابتدای روز بعد؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `page` | integer | قبلی، حفظ/تکمیل‌شده | شماره صفحه؛ پیش‌فرض ۱؛ total و last_page پس از فیلتر محاسبه می‌شوند. |
| `per_page` | integer | قبلی، حفظ/تکمیل‌شده | تعداد نتیجه در صفحه؛ پیش‌فرض ۱۵. |

نمونهٔ query (شناسه‌ها فرضی‌اند؛ متن هنگام ارسال URL-encode شود):

```http
GET /api/v1/reports?title=فروش&recipient_user_id=12&creator_search=کریم%20علی&created_from=2026-11-17&created_to=2026-11-30
```

## GET /api/v1/meetings

| پارامتر | نوع | وضعیت | رفتار |
| --- | --- | --- | --- |
| `search` | string | قبلی، حفظ/تکمیل‌شده | جستجوی عمومی عنوان، خلاصه و مکان؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `title` | string | جدید | فقط عنوان جلسه؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `location` | string | جدید | فقط مکان جلسه؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `status` | string enum(draft, scheduled, completed, cancelled) | قبلی، حفظ/تکمیل‌شده | وضعیت فعلی جلسه. |
| `from_date` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | meeting_date؛ شامل هر دو روز؛ حد پایین شامل. |
| `to_date` | date YYYY-MM-DD | قبلی، حفظ/تکمیل‌شده | meeting_date؛ شامل هر دو روز؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `page` | integer | قبلی، حفظ/تکمیل‌شده | شماره صفحه؛ پیش‌فرض ۱؛ total و last_page پس از فیلتر محاسبه می‌شوند. این پارامتر قبلاً توسط Paginator مصرف می‌شد؛ اکنون اعتبارسنجی صریح هم دارد. |
| `per_page` | integer | قبلی، حفظ/تکمیل‌شده | تعداد نتیجه در صفحه؛ پیش‌فرض ۱۵. |

نمونهٔ query (شناسه‌ها فرضی‌اند؛ متن هنگام ارسال URL-encode شود):

```http
GET /api/v1/meetings?title=بررسی&location=دفتر&from_date=2026-11-17&to_date=2026-11-30&status=scheduled&page=2&per_page=15
```

## GET /api/v1/users

| پارامتر | نوع | وضعیت | رفتار |
| --- | --- | --- | --- |
| `search` | string | قبلی، حفظ/تکمیل‌شده | جستجوی عمومی نام، نام خانوادگی، نام کاربری، موبایل، ایمیل، کد سازمانی و نام کامل؛ رفتار نام چندبخشی قبلی حفظ شده؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `username` | string | جدید | نام کاربری؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `full_name` | string | جدید | ترکیب first_name و last_name؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `email` | string | جدید | ایمیل بدون حساسیت به حروف بزرگ/کوچک؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `mobile` | string | جدید | موبایل به‌صورت رشته؛ صفر ابتدایی حفظ می‌شود؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `org_position_title` | string | جدید | عنوان حداقل یکی از org_positions؛ ستون نقش جدول، access_roles نیست؛ contains، trim، حداکثر ۲۵۵ کاراکتر، % و _ literal و ی/ي و ک/ك معادل. |
| `org_position_id` | integer | جدید | عضویت در حداقل یکی از جایگاه‌های سازمانی، نه نقش دسترسی |
| `is_active` | integer enum(0, 1) | قبلی، حفظ/تکمیل‌شده | ۱ فعال، ۰ غیرفعال؛ صفر یک مقدار معتبر است و حذف نمی‌شود. |
| `last_login_from` | date YYYY-MM-DD | جدید | last_login_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to؛ null تطبیق ندارد؛ حد پایین شامل. |
| `last_login_to` | date YYYY-MM-DD | جدید | last_login_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to؛ null تطبیق ندارد؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `last_activity_from` | date YYYY-MM-DD | جدید | last_activity_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to؛ null تطبیق ندارد؛ حد پایین شامل. |
| `last_activity_to` | date YYYY-MM-DD | جدید | last_activity_at؛ روز تهران از ابتدای from تا ابتدای روز بعد از to؛ null تطبیق ندارد؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `tasks_count_min` | integer | جدید | تعداد همان assignedTasks با role=assignee که UserResource برمی‌گرداند؛ بدون تغییر scope/وضعیت شمارنده؛ حد پایین شامل. |
| `tasks_count_max` | integer | جدید | تعداد همان assignedTasks با role=assignee که UserResource برمی‌گرداند؛ بدون تغییر scope/وضعیت شمارنده؛ حد بالا شامل؛ در صورت وجود حد پایین، کوچک‌تر از آن نیست. |
| `page` | integer | قبلی، حفظ/تکمیل‌شده | شماره صفحه؛ پیش‌فرض ۱؛ total و last_page پس از فیلتر محاسبه می‌شوند. |
| `per_page` | integer | قبلی، حفظ/تکمیل‌شده | تعداد نتیجه در صفحه؛ پیش‌فرض ۱۵. |

نمونهٔ query (شناسه‌ها فرضی‌اند؛ متن هنگام ارسال URL-encode شود):

```http
GET /api/v1/users?username=karim&mobile=0912&org_position_id=4&is_active=0&tasks_count_min=0&tasks_count_max=5&last_login_from=2026-11-01&last_login_to=2026-11-17
```

## Lookup گزینه‌ها

- `GET /api/v1/tasks/filter-options/users`: مجوز tasks:view، به همراه Gate فهرست و scope واقعی تسک؛ `field` یکی از assignee_id (پیش‌فرض)، follower_id، supervisor_id، user_id، financial_provider_user_id، equipment_provider_user_id. scope و submission_type همان قرارداد tasks را دارند.
- `GET /api/v1/reports/filter-options/users`: مجوز reports:view؛ field برابر recipient_user_id (پیش‌فرض) یا creator_user_id. رکوردهای قابل مشاهده طبق ReportService فعلی مبنا هستند.
- `GET /api/v1/users/filter-options/org-positions`: فقط users:view؛ org_positions:view لازم نیست. فقط جایگاه‌های متصل به دست‌کم یک کاربر قابل مشاهده در جدول کاربران برمی‌گردند؛ جایگاه صرفاً متعلق به سوپرادمین پنهان برای ناظر عادی افشا نمی‌شود.

هر سه: search اختیاری، page پیش‌فرض۱ و per_page پیش‌فرض۱۵/حداکثر۱۰۰. search روی نام کامل یا عنوان جایگاه است. کاربران غیرفعال مرتبط با سوابق در گزینه‌ها هستند؛ soft-deletedها مطابق رابطهٔ فعلی User نمایش داده نمی‌شوند. گزینه‌ها از کل مجموعهٔ مجاز می‌آیند و به افراد صفحهٔ فعلی محدود نیستند. این lookupها به permission ساخت/ویرایش یا eligible-users وابسته نیستند. برای جلسه فیلتر فردی در دامنهٔ خواسته‌شده وجود ندارد، پس lookup اضافی فرد ایجاد نشده است.

```http
GET /api/v1/tasks/filter-options/users?field=assignee_id&submission_type=request&scope=created_by_me&search=کریم&page=1&per_page=20
GET /api/v1/reports/filter-options/users?field=creator_user_id&page=1&per_page=20
GET /api/v1/users/filter-options/org-positions?search=کارشناس&page=1&per_page=20
```

خروجی در envelope فعلی:

```json
{"success":true,"message":"گزینه‌های مجاز فیلتر","data":{"items":[{"id":12,"full_name":"کاربر نمونه","is_active":false}],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":1}}}
```

نمونه فرضی است. items در lookup جایگاه، `{id,title}` است. خطاها: 401 بدون احراز هویت؛ 403 بدون مجوز مشاهدهٔ ماژول یا scope؛ 422 با errors به نام search، field، page، per_page یا پارامتر scope/نوع.

## اتصال فرانت — صرفاً راهنما، هیچ فایل فرانت تغییر نکرده است

- گزارش: در `src/features/reports/services/report.service.js`، whitelist به نام LIST_FILTERS باید report_number، title، recipient_search، creator_search، created_from و created_to را بپذیرد. بقیه پارامترهای فعلی حفظ شوند؛ لایه api به تنهایی همه params را عبور می‌دهد اما service نام‌های خارج whitelist را حذف می‌کند.
- کاربران: `src/features/users/services/user.service.js` اکنون فقط search، is_active، page و per_page را destructure و ارسال می‌کند. همه پارامترهای جدید جدول کاربران باید به ورودی و شیء خروجی removeEmptyParams افزوده شوند. شرط حذف باید فقط ''/null/undefined باشد؛ ۰ و false حذف نشوند. هنگام ارسال، is_active بولین به 1 یا 0 تبدیل شود؛ mobile رشته بماند.
- تسک و درخواست: از یک API با submission_type مناسب و همان scope استفاده شود. user_id برای جستجوی کلی ارتباط کاربر است؛ ستون مسئول از assignee_id/assignee_search استفاده کند.
- ستون «نقش» کاربران به org_position_id/org_position_title متصل شود، نه access_role_ids. از lookup users/filter-options/org-positions استفاده شود.
- تغییر هر فیلتر مستقل یا search، page را به۱ برگرداند؛ total و last_page فقط از meta پاسخ گرفته شوند.
- تاریخ query به میلادی تبدیل شود؛ تاریخ نمایش جلالی به API ارسال نشود. هزینه تومان و مدت دقیقه فرستاده شود. برای مقدار دقیق، min=max یا from=to ارسال شود.
- autocomplete باید lookup جدید را با همان scope/نوع جدول صفحه‌بندی کند؛ از eligible-users ساخت/ویرایش یا افراد صفحهٔ جاری جدول استفاده نکند.

## فایل‌های این اصلاح

- `app/Support/ListFilters.php`
- `app/Http/Requests/Concerns/ValidatesListFilters.php`
- `app/Http/Requests/IndexTaskRequest.php`, `IndexReportRequest.php`, `IndexMeetingRequest.php`, `IndexUsersRequest.php`
- `app/Http/Requests/IndexListFilterOptionsRequest.php`
- `app/Services/TaskService.php`, `ReportService.php`, `MeetingService.php`, بخش فهرست `UserService.php`
- `app/Services/ListFilterOptionsService.php`
- `app/Http/Controllers/Api/V1/ListFilterOptionsController.php`
- `routes/api.php`
- `tests/Feature/ColumnFiltersTest.php`
- این مستند و قرارداد کامل `task-manager-frontend-api-taskover.json`

تغییرات قبلی و محلی UserService خارج از بخش فهرست و قابلیت تسک ذیل گزارش حفظ شده‌اند و در این کار بازنویسی نشده‌اند. migration، cache، نقش یا سیستم مجوز جدید اضافه نشده است.

## نتیجهٔ واقعی آزمون

- تست‌های مستقل ColumnFiltersTest روی SQLite: ۲۱ تست موفق، ۳۲۱ assertion.
- رگرسیون مرتبط SQLite: ۱۹۸ تست، ۱۹۶ موفق، ۲ skip اختصاصی snapshot داشبورد MySQL، ۲۷۱۱ assertion.
- MySQL 8.4 مستقل با تنظیم READ-COMMITTED اولیه: ۴۵ تست (ColumnFiltersTest، TaskListOwnershipTest، ReportTaskTest، UserApiContractV2Test)، همگی موفق، ۶۸۳ assertion؛ تست روی production اجرا نشده است.
- پوشش: تک‌تک فیلترها، AND، OR گروه‌بندی‌شده، فارسی/نام کامل، literal %/_، مسئول و جایگاه چندگانه، مرز و صفر، null تاریخ، timezone تهران، خطای 422 و ترتیب بازه، pagination/total، scope و محدودیت سوپرادمین، lookup اشخاص غیرفعال و users:view بدون مجوز سازمان.
- دو تست snapshot که در SQLite skip شدند در مجموعهٔ MySQL این اصلاح اجرا نشده‌اند. کل تست‌های نامرتبط پروژه موفق اعلام نمی‌شوند.

ابهام تجاری باقی نمانده است؛ واحد تومان بر اساس برچسب فعلی فرانت است و بک‌اند مقدار را بدون تبدیل ذخیره/فیلتر می‌کند. نتایج صرفاً برای MySQL و SQLite تأیید شده‌اند. انتشار روی سرور انجام نشده است.

[قرارداد کامل OpenAPI 3.1](task-manager-frontend-api-taskover.json) شامل تمام endpointهای قبلی و سه lookup جدید است.
