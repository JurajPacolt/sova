# Repository Guidelines

## Project Structure & Module Organization

SOVA is a modular monorepo:

- `backend/` contains the PHP 8.3+ Slim 4 API. Application code belongs in
  `backend/src/`, configuration in `backend/config/`, migrations in
  `backend/migrations/`, HTTP entry points in `backend/public/`, and tests in
  `backend/tests/`.
- `frontend/` contains the Angular 22 application. Use `src/app/core/` for
  application-wide infrastructure, `shared/` for reusable UI, and `features/` for
  isolated, lazy-loaded product areas. Keep component tests beside components as
  `*.spec.ts`.
- `docs/` contains architecture, product analysis, and webflow documentation.
- `docker-compose.yml` provides the local PostgreSQL 17 database.

Do not place feature-specific code in `shared`, and do not import another feature's
internal files directly.

## Build, Test, and Development Commands

Start PostgreSQL from the repository root:

```powershell
docker compose up -d postgres
```

Backend commands, run from `backend/`:

- `composer install` installs locked dependencies.
- `composer serve` starts the API on `127.0.0.1:8080`.
- `composer check` runs Composer validation, formatting checks, PHPStan, and PHPUnit.
- `composer db:migrate` applies Doctrine migrations.

Frontend commands, run from `frontend/` with Node 24.15:

- `npm install` installs locked dependencies.
- `npm start` starts Angular on `localhost:4200`.
- `npm run check` runs Prettier, strict type checking, Vitest, and a production build.
- `npm run build` creates `dist/sova-frontend/`.

## Coding Style & Naming Conventions

PHP files use `declare(strict_types=1)`, four-space indentation, PSR-4 namespace
`Sova\`, PER-CS 2.0 formatting, and PHPStan level `max`. Use PascalCase classes and
suffix HTTP handlers with `Action`.

Angular uses two-space indentation and Prettier. Every component must be standalone,
use `ChangeDetectionStrategy.OnPush`, and prefer signals, `computed()`, `input()`, and
readonly state. Use kebab-case filenames such as `issue-detail-page.component.ts`.
Load feature routes lazily.

## Testing Guidelines

Backend tests use PHPUnit and follow `*Test.php`. Frontend tests use Vitest and follow
`*.spec.ts`. Add tests for new behavior, authorization rules, error states, and every
tenant-isolation boundary. No numeric coverage threshold exists yet; critical paths
must be covered. Run the relevant `check` command before submitting.

## Commit & Pull Request Guidelines

No usable Git history currently establishes a convention. Use Conventional Commits,
for example `feat(issues): add workflow transition endpoint` or
`fix(tenancy): reject cross-tenant assignment`.

Pull requests must explain the change, link the issue, list verification commands,
and identify migrations or security impact. Include screenshots for UI changes and
update OpenAPI or documentation when contracts or workflows change.

## Security & Configuration

Never commit `.env`, credentials, tokens, or production data. Default database
credentials are for local development only. Treat tenant context and backend
authorization as mandatory security boundaries; frontend guards are not sufficient.
