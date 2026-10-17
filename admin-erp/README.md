# Provatferi ERP — Laravel 12 (Phase 1)

Institutional ERP/API backend, target domain `admin.provatferi.org`. Serves the
admin dashboard (Blade session auth) and a versioned JSON API (Sanctum tokens)
consumed by `provatferi.org` and `sahittopata.provatferi.org`.

## Phase 1 scope

Included: project setup, MySQL-ready config, custom RBAC (roles/permissions,
not a package — the schema is bespoke to match the org's own design docs),
admin dashboard shell, organization hierarchy, activities, membership,
recruitment, and site settings/content schemas, plus the API structure.

Explicitly **not** in Phase 1: payments, premium/subscriptions, courses,
e-books, advanced analytics, or the full nationwide feature set. See the
project's SRS/Blueprint documents for the long-term scope.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Edit .env — MySQL/MariaDB is the only approved driver, local included.
# SQLite is not approved for this project.
php artisan migrate --seed
php artisan serve
```

`AdminUserSeeder` creates the super admin as `admin@provatferi.org` with a
**randomly generated** password that it prints once to the console. There is
no default or fixed password, in any environment — capture the printed value
at seed time and store it in a password manager. Re-running the seeder is a
no-op if that user already exists, so it will never silently reset a live
password.

`admin@provatferi.org` is an internal ERP login and operational-notice
address only. It is deliberately not the organisation's public contact
address — that is `info@provatferi.org`, held in `site.email`. Which address
plays which e-mail role (public contact, support, security, automated sender,
internal), and how mail is sent and tested: `docs/EMAIL_ARCHITECTURE.md`;
`php artisan mail:identity` checks a running install.

The public contact details (e-mail, phone, address in Bangla and English, Facebook / LinkedIn / YouTube links, map
location) are edited in one place, **Admin → Settings**, and every public page reads them from there — the setting keys,
the link and map rules, the Bangla / English behaviour, the API and the cache flow are in
`docs/PUBLIC_CONTACT_SETTINGS.md`.

The organisation's permanent core people (founder, executive director, directors, advisors — not the term-bound
Committee) are managed in **Admin → Institution → Leadership / Key Persons** and shown at `/leadership`, on each person's
own page and in a small homepage section. Nothing is pre-filled. The data model, the five `leadership.*` permissions, the
publishing, photo, Bangla / English, cache and API rules, and the procedure for creating a profile are in
`docs/LEADERSHIP_SYSTEM.md`.

Staff accounts can protect themselves with two-step verification (an authenticator app, TOTP) in **Admin → Account
security**; it can be required per role or for every privileged account. Enrolment, the sign-in challenge, recovery
codes, the rules for who must use it, step-up, rate limits, the audit trail, staff API tokens, the break-glass reset
(`php artisan mfa:reset`) and the owner's roll-out steps are in `docs/MFA_SECURITY.md`.

## RBAC

Custom tables: `roles`, `permissions`, `role_permissions`, `user_roles`.
Permission slugs are `{module}.{action}`, e.g. `activities.approve`. Guard a
route with `->middleware('permission:activities.approve')`. A user holding
the `super_admin` role bypasses all permission checks.

## API

All endpoints are versioned under `/api/v1`. See the project's readiness
report for the full endpoint list and implementation status. One admin
resource (`organization-units`) is fully implemented as the reference CRUD
pattern; the rest follow the same request/response/permission shape.
