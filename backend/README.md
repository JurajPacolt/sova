# SOVA backend

Slim 4 REST API pre multitenantný issue tracker SOVA.

## Požiadavky

- PHP 8.3 alebo 8.4,
- Composer 2,
- PHP rozšírenia `json`, `pdo`, `pdo_pgsql` a `sodium`,
- PostgreSQL 17.

## Lokálne nastavenie

Z koreňového adresára projektu spustite databázu:

```powershell
docker compose -f docker-compose-postgresql.yml up -d postgres
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

| Metóda a route                                            | Účel                                         |
| --------------------------------------------------------- | -------------------------------------------- |
| `GET /api/v1`                                             | Informácie o API                             |
| `GET /api/v1/health`                                      | Liveness alias                               |
| `GET /api/v1/health/live`                                 | Kontrola behu API bez databázy               |
| `GET /api/v1/health/ready`                                | Readiness vrátane PostgreSQL                 |
| `POST /api/v1/auth/login`                                 | Prihlásenie a vytvorenie serverovej relácie  |
| `POST /api/v1/auth/password/forgot`                       | Všeobecná požiadavka na obnovu hesla         |
| `POST /api/v1/auth/password/reset`                        | Nastavenie hesla jednorazovým tokenom        |
| `POST /api/v1/auth/email/verification/request`            | Všeobecná žiadosť o overovací e-mail         |
| `POST /api/v1/auth/email/verify`                          | Overenie e-mailu jednorazovým tokenom        |
| `POST /api/v1/auth/invitations/inspect`                   | Bezpečný náhľad platnej pozvánky             |
| `POST /api/v1/auth/invitations/accept`                    | Nový účet a členstvo z platnej pozvánky      |
| `POST /api/v1/auth/invitations/accept-existing`           | Prijatie pozvánky existujúcim účtom          |
| `POST /api/v1/auth/logout`                                | Odhlásenie a revokácia aktuálnej relácie     |
| `GET /api/v1/auth/session`                                | Aktuálna identita a dynamická systémová rola |
| `GET /api/v1/auth/sessions`                               | Aktívne relácie používateľa                  |
| `DELETE /api/v1/auth/sessions/{sessionId}`                | Revokácia vlastnej relácie                   |
| `GET /api/v1/tenants`                                     | Tenanty dostupné aktuálnej relácii           |
| `GET /api/v1/tenants/{tenantId}`                          | Overený tenantový kontext                    |
| `POST /api/v1/tenants/{tenantId}/invitations`             | Vytvorenie tenantovej pozvánky               |
| `GET /api/v1/tenants/{tenantId}/roles`                    | Roly a non-system permission katalóg         |
| `POST /api/v1/tenants/{tenantId}/roles`                   | Vytvorenie vlastnej tenantovej roly          |
| `PUT .../tenants/{tenantId}/roles/{roleId}`               | Úprava vlastnej tenantovej roly              |
| `DELETE .../tenants/{tenantId}/roles/{roleId}`            | Archivácia nepriradenej vlastnej roly        |
| `GET .../tenants/{tenantId}/memberships`                  | Administratívny zoznam členstiev a rolí      |
| `PATCH .../tenants/{tenantId}/memberships/{membershipId}` | Zmena lifecycle stavu členstva               |
| `PUT .../memberships/{membershipId}/roles/{roleId}`       | Priradenie tenantovej roly                   |
| `DELETE .../memberships/{membershipId}/roles/{roleId}`    | Odobratie tenantovej roly                    |
| `GET /api/v1/tenants/{tenantId}/audit`                    | Tenantovo izolovaný bezpečnostný audit       |
| `GET /api/v1/system/tenants`                              | Systémový zoznam tenantov                    |
| `POST /api/v1/system/tenants`                             | Tenant a pozvánka prvého vlastníka           |
| `PATCH /api/v1/system/tenants/{tenantId}`                 | Lifecycle tenantu s revision a dôvodom       |
| `GET /api/v1/system/audit`                                | Globálny append-only bezpečnostný audit      |
| `POST /api/v1/system/impersonations`                      | Kontrolovaná 15-minútová impersonácia        |
| `DELETE /api/v1/system/impersonations/current`            | Ukončenie aktuálnej impersonácie             |

Aktuálny strojovo kontrolovaný API kontrakt je v
[`docs/openapi.json`](../docs/openapi.json). Každý nový alebo zmenený endpoint musí
aktualizovať kontrakt v rovnakom checkpointe.

Chybové odpovede používajú spoločnú taxonómiu a stabilné doménové kódy popísané v
[`docs/PROBLEM_DETAILS.md`](../docs/PROBLEM_DETAILS.md). Nový doménový kód musí byť
zdokumentovaný spolu s endpointom, ktorý ho vracia.

Technický autentifikačný kontrakt, cookie a CSRF pravidlá sú v
[`docs/AUTHENTICATION.md`](../docs/AUTHENTICATION.md).
Pravidlá aktívneho členstva, `SUPERADMIN` prístupu a tenantovej izolácie sú v
[`docs/TENANCY.md`](../docs/TENANCY.md).
Permission katalóg, predvolená matica rolí a centrálny deny-by-default kontrakt sú
v [`docs/AUTHORIZATION.md`](../docs/AUTHORIZATION.md).
Nemennosť, čítacie oprávnenia, filtre a keyset stránkovanie bezpečnostného auditu
sú v [`docs/SECURITY_AUDIT.md`](../docs/SECURITY_AUDIT.md).
Session-bound impersonácia, reautentifikácia, tenantový scope a dvojitá identita
sú v [`docs/IMPERSONATION.md`](../docs/IMPERSONATION.md).

## Databázové migrácie

```powershell
composer db:status
composer db:migrate
```

Novú prázdnu migráciu možno pripraviť príkazom:

```powershell
composer db:generate
```

Identity/tenancy migrácie vytvárajú globálnych používateľov, tenantov, tenantové
členstvá, revokovateľné serverové relácie, samostatné systémové roly a bezpečnostný
audit. Autorizačná migrácia pridáva tenantové roly, permission granty, membership
priradenia a monotónnu revíziu na okamžité zneplatnenie decision cache. Kompozitné
cudzie kľúče bránia cross-tenant priradeniu. Verejné identifikátory generuje
aplikácia ako UUIDv7 a všetky databázové časy používajú PostgreSQL
`timestamp with time zone`.

Tabuľka `impersonations` viaže otvorený kontext na pôvodnú `user_sessions`
reláciu, aktéra, efektívneho používateľa a tenant. Databázové constrainty vynucujú
jednu otvorenú impersonáciu na reláciu, rozdielne identity, čerstvú
reautentifikáciu a maximálne 15 minút.

Nový tenant musí v transakcii vytvorenia zavolať `TenantRoleProvisioner`, ktorý
idempotentne založí rezervované roly `TENANT_OWNER`, `TENANT_ADMIN`, `MEMBER` a
`VIEWER`. Migračný backfill ich doplní už existujúcim tenantom. Vlastné role
používajú nemenný tenantovo unikátny kód, optimistickú `revision`, závislosti z
permission katalógu a pred archiváciou musia byť odobraté zo všetkých členstiev.

Členstvo používa `ACTIVE`, `DISABLED` a terminálny soft stav `REMOVED`. Zmena
nevypína globálnu reláciu používateľa, no databázový trigger okamžite zneplatní
tenantové permission rozhodnutia. Vlastné členstvo sa týmto tokom nemení a spoločný
`TenantOwnershipGuard` chráni posledného aktívneho vlastníka pri role assignment aj
membership lifecycle operácii.

Systémové vytvorenie tenantu je idempotentná transakcia, ktorá vytvorí `PENDING`
tenant, rezervované roly a `TENANT_OWNER` pozvánku. Prijatie prvej owner pozvánky
tenant aktivuje. Lifecycle používa optimistickú revíziu, povinný dôvod a 30-dňovú
ochrannú lehotu pred odstránením.

`security_audit_events` aj `authentication_events` sú databázovo append-only:
triggery odmietnu `UPDATE` aj `DELETE`. Systémové a tenantové čítacie API používajú
permission kontrolu, repozitárový tenant filter, bezpečnú redakciu metadata a
keyset stránkovanie.

Databázové integračné testy sa spúšťajú po migrácii s premennou
`RUN_DATABASE_TESTS=true`. CI pre ne poskytuje čistú PostgreSQL 17 službu.

## E-mailový worker

Požiadavka na obnovu hesla, overenie e-mailu alebo tenantovú pozvánku zapisuje
šifrovaný citlivý payload a nesenzitívnu udalosť do PostgreSQL outboxu. Jednorazový
odkaz vytvorí alebo načíta a cez SMTP odošle spoločný worker:

```powershell
composer worker:email
```

Príkaz beží nepretržite; pre jeden batch a ukončenie možno použiť:

```powershell
php bin/email-worker.php --once
```

Lokálne nastavte `MAILER_DSN` na SMTP email catcher. Hodnota `null://null` je vhodná
iba pre testy bez doručovania. Produkčný worker odmietne štart bez reálneho
transportu. `SENSITIVE_PAYLOAD_KEY` je base64 kódovaný 32-bajtový kľúč uložený mimo
repozitára. Aktuálna implementácia používa jeden aktívny kľúč: pred jeho rotáciou
treba najviac 15-minútovú frontu spracovať alebo bezpečne zrušiť a potom nasadiť
nový kľúč aj `SENSITIVE_PAYLOAD_KEY_ID` naraz do API a všetkých workerov.

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
- `AUTH_*` – životnosť relácie, cookie politika a login rate limiting,
- `SENSITIVE_PAYLOAD_*` – aplikačné šifrovanie krátkodobých outbox payloadov,
- `MAILER_*` – SMTP/provider transport a overený odosielateľ,
- `OUTBOX_*` – retry politika background workerov,
- `CORS_ALLOWED_ORIGINS` – povolené Angular origins.

## Štruktúra

```text
backend/
├── bin/             # Dlhobežiace a jednorazové worker entry pointy
├── config/          # Bootstrap, DI, middleware, routy a settings
├── migrations/      # Doctrine databázové migrácie
├── public/          # Verejný HTTP entry point
├── src/
│   ├── Identity/    # Používatelia, login a serverové relácie
│   ├── Authorization/ # Permission katalóg a centrálne rozhodovanie
│   ├── Tenancy/     # Tenantový kontext a membership prístup
│   └── Shared/      # Zdieľaná infraštruktúra, audit a HTTP základ
├── tests/           # Unit, integračné a API testy
├── var/             # Dočasné cache a runtime súbory
└── composer.json
```

Ďalšie domény budú pridávané ako samostatné moduly, napríklad `Identity`, `Tenancy`,
`Authorization`, `Workgroups`, `Projects`, `Issues`, `Notifications` a `Audit`.
