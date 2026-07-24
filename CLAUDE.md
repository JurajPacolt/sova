# Claude Project Guide

Use this file as the entry point when working on SOVA. Read `AGENTS.md`,
`docs/PROJECT_MEMORY.md`, and the relevant README before changing code. The detailed
product and architecture analysis is in `docs/ANALYZA_PROJEKTU.md`; user flows are
in `docs/webflow/`.

## Repository layout

- `backend/` — PHP 8.3+ Slim 4 REST API, configuration, Doctrine migrations, and
  PHPUnit tests.
- `frontend/` — Angular 22 application with Bootstrap 5 and Vitest.
- `docs/` — analysis, project memory, architecture decisions, and webflows.
- `docker-compose.yml` — local PostgreSQL 17 service.

Preserve the modular-monolith boundaries. Tenant context, authorization, and data
isolation are mandatory backend security boundaries. Never trust a tenant identifier
or authorization decision supplied only by the browser.

## Frontend rules

Every Angular component must be explicitly standalone and use
`ChangeDetectionStrategy.OnPush`. Prefer signals, `computed()`, signal inputs, and
readonly state. Keep feature code under `src/app/features/`, reusable presentation
under `shared/`, and application-wide singleton infrastructure under `core/`.
Feature route groups must remain lazy-loaded; do not introduce `NgModule`.

The UI supports Slovak, Czech, English, German, Polish, and Hungarian (`sk`, `cs`,
`en`, `de`, `pl`, `hu`). Browser preferences select the first supported language;
unsupported preferences fall back to English. Never hardcode user-facing text in a
component or template. Add every new key to all six files under
`frontend/src/app/core/i18n/translations/`; the English catalog is the canonical
typed key set. Preserve runtime switching and the synchronized `<html lang>`
attribute.

## Verification

Use Node 24.15 or another version allowed by `frontend/package.json`.

```powershell
docker compose up -d postgres

Set-Location backend
composer check

Set-Location ../frontend
npm run check
```

Add `*Test.php` backend tests and colocated `*.spec.ts` frontend tests for changed
behavior. Include cross-tenant denial cases for tenant-scoped backend work. Do not
commit secrets, `.env`, generated dependency directories, or production data.
