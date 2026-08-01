# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What SOVA is, and where it currently stands

SOVA is a multitenant issue tracker / task manager (Jira, MantisBT, Bugzilla class) with
its own authentication, work groups, tenant administration, and a global `SUPERADMIN`
role. Original requirements: `zadanie.txt`.

**The repository is at the "technical foundation" stage.** What exists is infrastructure
plus a visual/architectural skeleton; the product domain does not exist yet:

- Backend has only `src/Shared/` — bootstrap, DI, settings, three middleware, and
  info/liveness/readiness actions. No `Identity`, `Tenancy`, `Authorization`,
  `Workgroups`, `Projects`, `Issues`, `Notifications`, or `Audit` module.
- `backend/migrations/` contains **no migrations** — the database schema is unwritten.
- Frontend pages render hardcoded sample data. Nothing calls the API; there are no
  guards, no HTTP interceptors, no `core/api/`, no auth or tenant-context services.
- `docs/adr/` is referenced by the docs but does not exist yet; create it when the
  first ADR is written.

So most feature work here means *creating* the first version of a layer, not editing an
existing one. Follow the designs in `docs/ANALYZA_PROJEKTU.md` rather than inventing a
parallel structure. Project documentation is written in Slovak; code, identifiers, and
commit messages are English.

## Commands

Database (from repository root):

```powershell
docker compose -f docker-compose-postgresql.yml up -d postgres   # PostgreSQL 17, localhost:5432
docker compose up --build         # whole stack on http://localhost:8080, migrations included
```

Backend (from `backend/`, first run needs `Copy-Item .env.example .env`):

```powershell
composer install
composer serve          # http://127.0.0.1:8080
composer check          # validate + cs:check + analyse + test — the gate before submitting
composer test
composer analyse        # PHPStan level max over src, tests, config
composer cs:check
composer cs:fix
composer db:status
composer db:migrate
composer db:generate    # scaffold an empty migration in Sova\Migrations
```

Single backend test:

```powershell
php vendor/bin/phpunit --filter testApiInfoIsAvailable
php vendor/bin/phpunit tests/Api/HealthEndpointTest.php
```

Frontend (from `frontend/`):

```powershell
npm install
npm start               # http://localhost:4200
npm run check           # format:check + typecheck + test + build — the gate before submitting
npm test                # ng test --watch=false (Vitest via @angular/build:unit-test, jsdom)
npm run typecheck
npm run format
```

Single frontend test — the `unit-test` builder takes `--include` (file globs) and
`--filter` (regex over suite/test names):

```powershell
npx ng test --watch=false --include src/app/shared/components/status-badge/status-badge.component.spec.ts
npx ng test --watch=false --filter "localized label"
```

**Node version is a hard gate.** `package.json` engines allow `^22.22.3 || ^24.15.0 ||
>=26.0.0`, and the Angular CLI aborts immediately on anything else. The Node currently
on this machine (v22.17.1) is *below* that floor, so every `ng`/`npm run check`
invocation fails until Node is upgraded — check `node --version` before reporting a
frontend failure as a code problem. `.nvmrc` / `.node-version` pin 24.15.0.

## Backend architecture

Request path: `public/index.php` → `config/bootstrap.php` → `ApplicationFactory::create()`
→ Slim `App` handling. `ApplicationFactory` is the single wiring point: it loads `.env`
(optional, `safeLoad`), builds the PHP-DI container from `config/dependencies.php` with
autowiring on, adds body-parsing and routing middleware, then applies
`config/routes.php` and `config/middleware.php`. Tests construct the identical app via
`ApplicationFactory::create()` and drive it with a PSR-7 `ServerRequest` — no HTTP
server needed; keep new middleware and routes registered in those config files so tests
pick them up for free.

Configuration is two-layered and should stay that way: `config/settings.php` is the only
place that reads `$_ENV`/`$_SERVER` (via local `envString`/`envBool`/`envInt`/`envList`
helpers) and produces a nested array; `Settings` wraps it with dot-path access and
throwing typed accessors (`string()`, `int()`, `bool()`, `stringList()`, `require()`).
Never read `getenv()` from application code — add the key to `settings.php` instead.

Middleware order matters and is inverted: Slim runs `$app->add()` LIFO, so the
registration in `config/middleware.php` yields RequestId → CORS → ApiError → routing →
body parsing → action. `RequestIdMiddleware` sets the request attribute and
`X-Request-ID` response header; `ApiErrorMiddleware` catches every `Throwable` and emits
RFC 7807 `application/problem+json` including `request_id`, leaking exception messages
only when `app.debug` is true. Successful JSON goes through
`Sova\Shared\Presentation\Http\JsonResponse::write()`. Preserve both response shapes —
`HealthEndpointTest` asserts them, and the Angular error handling will depend on them.

Persistence is **Doctrine DBAL only — there is no ORM and no entity manager.**
`ConnectionFactory` builds the connection from `DATABASE_URL` or discrete
`DATABASE_*` settings and hard-rejects any driver other than `pdo_pgsql`. Migrations run
through `cli-config.php`, which builds its own `Settings` + `Connection` and registers
`migrations/` under the `Sova\Migrations` namespace with `all_or_nothing`. Every schema
change is a migration; nothing creates tables at runtime.

New domain modules live at `src/<Module>/` under PSR-4 root `Sova\`, each free to use
`Application/` (Command, Query, DTO, Service), `Domain/` (Entity, ValueObject, Event,
Repository), `Infrastructure/` (Persistence, Integration) and `Presentation/Http/` —
create only the folders a module actually needs. HTTP handlers are invokable classes
suffixed `Action`, referenced in `config/routes.php` by class name and resolved from the
container. Keep actions thin: no SQL in the presentation layer, validation at the
application boundary, one transaction per multi-record use case, UTC timestamps, and
domain events published via a transactional outbox rather than inline side effects.

API conventions (`docs/ANALYZA_PROJEKTU.md` §9.4–9.5): `/api/v1` prefix, JSON, OpenAPI
as the contract source, Problem Details errors, explicit allowlists for filters/sorting,
capped page size, correlation ID per request, optimistic locking via version/`ETag`, and
`409` for version/uniqueness conflicts vs `422` for semantically invalid input.

## Tenant isolation and authorization (non-negotiable)

The backend is authoritative for authentication, authorization, and tenant isolation;
Angular guards are UX only. The intended model is one PostgreSQL schema with `tenant_id`
on every tenant-owned table, defended in layers: verified session → explicit tenant
context → active membership → permission check → tenant-filtered repository → FK and
unique constraints including `tenant_id` → RLS where practical.

Concretely, when writing tenant-scoped code:

- Never accept a tenant identifier from the browser without verifying the caller's
  active membership in that tenant.
- Every tenant-scoped repository call must require a tenant context — no optional
  parameter that silently defaults to "all tenants".
- Tenant ID belongs in cache keys, queue jobs, attachment paths, notifications, and
  audit events, not just in the SQL `WHERE`.
- Authorize on **permissions**, never on role names: `tenant.members.invite`,
  `project.settings.manage`, `issue.transition`, `system.tenants.suspend`. Roles
  (`SUPERADMIN`, `TENANT_OWNER`, `TENANT_ADMIN`, `PROJECT_MANAGER`, `GROUP_MANAGER`,
  `MEMBER`, `REPORTER`, `VIEWER`) are named permission sets; permissions from multiple
  roles union together, and there are no deny rules in v1.
- `SUPERADMIN` reaching into tenant content must be explicit, reason-bearing,
  time-bounded, visibly indicated in the UI, and audited at start and end.
- Cross-tenant denial tests are mandatory for tenant-scoped backend work: tenant A
  requesting tenant B's identifiers must be rejected.

## Frontend architecture

Angular 22, standalone-only — **do not introduce `NgModule`.** Every component must be
explicitly `standalone: true` with `ChangeDetectionStrategy.OnPush` (enforced for
generated components by the `angular.json` schematics defaults), preferring signals,
`computed()`, `input()`/`input.required()`, and readonly state over mutable fields.
Bootstrapping is `main.ts` → `app.config.ts`, which provides the router with
`withComponentInputBinding()` and in-memory scrolling plus `provideHttpClient()`.

Layer boundaries: `core/` holds app-wide singletons and the shell layout, `shared/` holds
reusable presentation components with no feature knowledge, `features/<area>/` holds
isolated product areas with `pages/` beneath them. A feature must not import another
feature's internal files — promote shared code to `shared/` or a shared domain layer
instead. Filenames are kebab-case (`issue-detail-page.component.ts`); specs sit beside
their subject as `*.spec.ts`.

Routing: `app.routes.ts` uses `satisfies Routes` and lazy-loads every area through
`loadChildren`/`loadComponent`. Feature route files export a const (`ISSUE_ROUTES`,
`PROJECT_ROUTES`, …) — never a module. Tenant-scoped screens live under the
`t/:tenantSlug` shell route (`TenantShellComponent`), so new in-tenant areas become
children there and read the slug from the route, not from global state.

Localization is a typed contract, not a convenience. Six languages: `sk`, `cs`, `en`,
`de`, `pl`, `hu`. `translations/en.ts` derives `TranslationKey` from its own object, so a
key missing in any of the other five catalogs breaks `typecheck` — every new user-facing
string means adding the same key to all six files under
`frontend/src/app/core/i18n/translations/`. Never hardcode user-facing text in a
component or template; use `TranslatePipe` or `I18nService`. `I18nService` holds the
active language in a signal, interpolates `{{param}}` placeholders, falls back to the
English catalog then the raw key, and syncs `<html lang>` via an `effect` — keep runtime
switching working (no reload) when touching it. Initial language is the first supported
entry in `navigator.languages` with region codes normalized (`sk-SK` → `sk`), else `en`.

Bootstrap 5 CSS is loaded globally in `angular.json`; Bootstrap's JavaScript plugins are
deliberately unused. Implement interactive behavior with Angular state and signals (see
`TenantShellComponent`'s `sidebarOpen`), not by importing Bootstrap JS or manipulating
the DOM. Production build budgets: 1 MB initial, 8 kB per component stylesheet.

## Style and quality gates

PHP: `declare(strict_types=1)` everywhere, four-space indent, PER-CS 2.0 via PHP CS
Fixer with `strict_comparison`/`strict_param`/ordered imports, PHPStan level `max`
across `src`, `tests`, and `config` — annotate generics (`App<Container>`,
`array<string, mixed>`) as the existing code does, since level max rejects the loose
alternative. Prefer `final readonly` classes with constructor promotion for services and
middleware; static-factory-only classes hide a `private function __construct() {}`.
PHPUnit uses `failOnRisky`/`failOnWarning`, so incomplete assertions fail the suite.

TypeScript: two-space indent, Prettier (100 columns, single quotes, `angular` parser for
HTML), `strict` plus `noPropertyAccessFromIndexSignature`, `noImplicitReturns`,
`noImplicitOverride`, and Angular `strictTemplates`.

Commits follow Conventional Commits (`feat(issues): add workflow transition endpoint`,
`fix(tenancy): reject cross-tenant assignment`). Git history is a single initial commit,
so it establishes no convention of its own. Do not commit `.env`, secrets, generated
dependency directories, `dist/`, or production data.

## Where the specifications live

Read the relevant document before designing a layer — these are decisions already made,
not suggestions.

- `docs/PROJECT_MEMORY.md` — binding technical decisions (frontend rules, localization,
  verification). Update it when a decision changes.
- `docs/ANALYZA_PROJEKTU.md` — the full analysis: roles and permissions (§5), functional
  modules (§6), multitenancy (§7), backend and API design (§9), frontend design (§10),
  the ER data model (§11), security (§12), testing strategy (§14).
- `docs/webflow/` — screen-level user flows: information architecture and guards (`00`),
  auth and onboarding (`01`), projects and issues (`02`), collaboration (`03`),
  administration and impersonation (`04`), UI states, responsiveness, accessibility
  (`05`).
- `AGENTS.md` — condensed contributor guidelines; `README.md` — product overview and the
  recommended implementation order (identity/tenancy first, via a login → tenant select
  → membership check → cross-tenant test vertical slice).
