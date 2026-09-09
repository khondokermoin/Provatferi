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
address — that is `info@provatferi.org`, held in `site.email`.

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
