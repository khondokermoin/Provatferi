# Dates and times in the admin

2026-10-08. Code: `App\Support\AdminTime` (the one formatter), the thin Blade helpers in `app/helpers.php`. Tests:
`tests/Feature/Admin/AdminTimeTest.php` (boundaries), `tests/Feature/Admin/AdminDateDisplayTest.php` (the screens),
`tests/Feature/AdminDateTimeGuardTest.php` (keeps every column on the formatter of its kind).

## The decision

- **Storage stays UTC.** `config('app.timezone')` is `UTC` and is not changed: every timestamp column holds UTC, and
  Eloquent, the queue, the validators and every comparison with `now()` assume it. Changing the app timezone would
  silently re-interpret every stored timestamp and every `now()` comparison — exactly what must not happen.
- **The admin reads Asia/Dhaka.** `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE`, default `Asia/Dhaka`,
  UTC+6, no daylight saving) is the organisation's clock. Every date or time an admin reads, every "today" of the
  organisation's calendar (a form default, a filter day, the copyright year), and every date-time an admin types is on
  this clock. `config('membership.timezone')` (the fee/dues calendar) defaults to the same value; a test keeps them
  equal, and `Notice::DISPLAY_TIMEZONE` (the public notice board's year grouping) is the same zone.

## Three kinds of value — never mixed

| Kind | What it is | Examples | Helper | Converted? |
|---|---|---|---|---|
| **Timestamp** | an instant, stored in UTC (cast `datetime`) | `created_at`, `updated_at`, `approved_at`, `reviewed_at`, `submitted_at`, `verified_at`, `cancelled_at`, `paid_at`, `published_at`, `last_login_at`, a notice's or registration link's `expires_at`, a season's `opens_at` / `closes_at` | `admin_datetime()`, `admin_date()`, `admin_time()`, `admin_relative()` | yes: UTC → Asia/Dhaka |
| **Calendar date** | a day on the organisation's calendar (cast `date`) | a membership's `start_date` / `expiry_date`, a payment's `received_at`, `due_date`, a dues period, `effective_from` / `effective_until`, `fee_effective_on`, a committee term, a job posting's `opening_date` / `application_deadline`, `joined_at`, `established_date` | `calendar_date()`, `calendar_month_year()` | **never** |
| **Wall clock** | a local date-time typed in Bangladesh time and stored as typed | an activity's `start_datetime` / `end_datetime` (also shown as stored on the public site) | `wallclock_datetime()`, `wallclock_date()` | **never** |

Example: an approval at **2026-10-07 18:30 UTC** reads **৮ অক্টোবর ২০২৬, ০০:৩০** / **8 October 2026, 00:30** — not
7 October. A payment received on the calendar day 2026-10-08 reads 8 October whatever the clock.

A calendar date is never converted, because converting a stored midnight can move it to another day (on a clock behind
UTC it would show the previous day; the boundary test proves `calendar_date()` does not move even then).

## Language

Bangla admin: Bengali digits and month names (`৮ অক্টোবর ২০২৬, ০০:৩০`). English admin: Western digits and English month
names (`8 October 2026, 00:30`). 24-hour time in both. Relative time (`৩ মিনিট আগে` / `3 minutes ago`) is available
(`admin_relative()`) but not used on any screen today. The recruitment application document follows its own document
language (`?doclang=`).

## Inputs and filters

- A `datetime-local` input for a **timestamp** shows `AdminTime::toInput($utc)` (Bangladesh time) and the controller
  stores `AdminTime::fromInput($typed)` (UTC): notices, membership season windows, committee registration-link expiry.
  Saving a form unchanged never moves the instant. The form says "Bangladesh time (UTC+6)".
- A **date** input stays `Y-m-d` and is stored as typed; its default "today" is `AdminTime::today()` (the UTC date is
  still yesterday until 06:00 in Dhaka).
- Filtering a timestamp by a day uses the organisation's day:
  `[AdminTime::startOfDayUtc($d), AdminTime::endOfDayUtc($d))` = 18:00 UTC the day before → 18:00 UTC that day. Used by
  the membership-applications date filter and the recruitment-applications from/to filter. Filters on calendar dates
  (the registry's joining date) compare dates as before.

## What changed on 2026-10-08

- `bn_date()` / `bn_datetime()` / `bn_month_year()` were removed: their names did not say whether a value was a UTC
  timestamp or a calendar date, so timestamps were printed as UTC (an action at 01:00 in Dhaka read as the previous
  day). Every call site was classified and moved to the helper of its kind; the guard test fails the build if a
  timestamp column is ever formatted as a date (or the reverse), if a view formats a timestamp by hand, or if a
  controller filters a timestamp by a UTC day.
- Membership season windows and committee registration-link expiry used to store the typed Bangladesh time as if it
  were UTC (a season set to open at 00:00 opened at 06:00 in Dhaka). They are now converted on save and shown in
  Bangladesh time. Rows saved before keep their stored instant; the admin now shows that instant truthfully.
- The registration payment form's default date, the copyright year and the e-mail footer year are the organisation's.
