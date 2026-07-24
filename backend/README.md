# SOVA backend

Slim 4 REST API pre multitenantný issue tracker SOVA.

## Požiadavky

- PHP 8.3 alebo 8.4,
- Composer 2,
- PHP rozšírenia `json`, `pdo` a `pdo_pgsql`,
- PostgreSQL 17.

## Lokálne nastavenie

Z koreňového adresára projektu spustite databázu:

```powershell
docker compose up -d postgres
```

V adresári `backend` pripravte lokálnu konfiguráciu a závislosti:

```powershell
Copy-Item .env.example .env
composer install
```

Predvolené vývojové pripojenie:

```text
Host: 127.0.0.1
Port: 5432
Database: sova
User: sova
Password: sova_dev_password
```

Heslo je určené iba pre lokálny vývoj. V staging a production prostredí musí byť
nahradené bezpečným secretom.

## Spustenie API

```powershell
composer serve
```

API bude dostupné na `http://127.0.0.1:8080`.

Endpointy základného projektu:

| Metóda a route | Účel |
|---|---|
| `GET /api/v1` | Informácie o API |
| `GET /api/v1/health` | Liveness alias |
| `GET /api/v1/health/live` | Kontrola behu API bez databázy |
| `GET /api/v1/health/ready` | Readiness vrátane PostgreSQL |

## Databázové migrácie

```powershell
composer db:status
composer db:migrate
```

Novú prázdnu migráciu možno pripraviť príkazom:

```powershell
composer db:generate
```

## Kontroly kvality

```powershell
composer test
composer analyse
composer cs:check
composer check
```

Automatická oprava formátovania:

```powershell
composer cs:fix
```

## Konfigurácia

Lokálna konfigurácia sa načítava z `.env`, ktorý nie je verzovaný. Dostupné premenné
sú zdokumentované v `.env.example`.

Najdôležitejšie skupiny:

- `APP_*` – prostredie, debug režim a verzia,
- `LOG_*` – úroveň a cieľ logovania,
- `DATABASE_*` – PostgreSQL pripojenie,
- `CORS_ALLOWED_ORIGINS` – povolené Angular origins.

## Štruktúra

```text
backend/
├── config/          # Bootstrap, DI, middleware, routy a settings
├── migrations/      # Doctrine databázové migrácie
├── public/          # Verejný HTTP entry point
├── src/
│   └── Shared/      # Zdieľaná infraštruktúra a HTTP základ
├── tests/           # Unit, integračné a API testy
├── var/             # Dočasné cache a runtime súbory
└── composer.json
```

Ďalšie domény budú pridávané ako samostatné moduly, napríklad `Identity`, `Tenancy`,
`Authorization`, `Workgroups`, `Projects`, `Issues`, `Notifications` a `Audit`.
