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
# Edit .env — DB_CONNECTION etc. Defaults to sqlite for local dev.
php artisan migrate --seed
php artisan serve
```

Default seeded admin: `admin@provatferi.org` / `change-me-immediately` — for
**local development only**. When deploying to `admin.provatferi.org`, create
the first super-admin with a freshly generated password instead of reusing
this one (same discipline as the WordPress/DB credential rotations done
earlier in this project), or run `AdminUserSeeder` with a real password.

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
