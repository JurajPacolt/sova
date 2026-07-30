# SOVA – kontrolovaná impersonácia

Impersonácia je podporný nástroj pre `SUPERADMIN`, nie spôsob získania širších
oprávnení. Kontext je viazaný na jednu existujúcu serverovú reláciu, jedného
aktívneho používateľa a jeden aktívny tenant.

## Bezpečnostný kontrakt

Spustenie vyžaduje naraz:

- neimpersonovanú reláciu s aktuálne platnou rolou `SUPERADMIN`,
- oprávnenie `system.impersonate`,
- aktívny cieľový účet s aktívnym členstvom vo vybranom aktívnom tenantovi,
- povinný dôvod podpory s dĺžkou 10 až 500 znakov,
- čerstvé opätovné overenie aktuálnym heslom administrátora,
- double-submit CSRF token.

Kontext platí najviac 15 minút. Databázový constraint nedovolí uložiť dlhšiu
platnosť ani reautentifikáciu staršiu ako päť minút od začiatku. Na jednej relácii
môže byť otvorená najviac jedna impersonácia.

Aktuálna implementácia citlivej impersonačnej operácie naďalej vyžaduje čerstvú
reautentifikáciu heslom. Pri `APP_ENV=production` sa k tejto operácii dostane iba
relácia `SUPERADMIN`, ktorá už pri login-e úspešne dokončila povinné MFA; relácia
bez enrollmentu je serverom obmedzená iba na MFA setup a logout. MFA teda
nenahrádza dôvod, časový limit ani audit impersonácie.

## Identity a autorizácia

Serverová relácia počas impersonácie nesie dve identity:

- `actor_user_id` je skutočný prihlásený `SUPERADMIN`,
- `effective_user_id` je cieľový tenantový používateľ.

`SUPERADMIN` bypass sa vypne a všetky permission rozhodnutia používajú iba granty
efektívneho používateľa. Impersonácia je navyše pripnutá k vybranému
`tenant_id`; ani cieľ s aktívnym členstvom v inom tenantovi doň cez túto reláciu
nevstúpi.

Ak vyprší čas, zanikne systémová rola aktéra, účet alebo tenant prestane byť
aktívny alebo sa deaktivuje cieľové členstvo, stav sa zmení na `EXPIRED` alebo
`INVALIDATED`. Bežné chránené požiadavky dostanú stabilnú chybu 409. Povolené
zostanú iba načítanie aktuálnej relácie, odhlásenie a okamžité ukončenie
impersonácie. Tým sa kontext nikdy potichu neprepne späť na plný
`SUPERADMIN` bypass uprostred tenantovej obrazovky.

## API

| Metóda   | Route                                   | Výsledok                                       |
| -------- | --------------------------------------- | ---------------------------------------------- |
| `POST`   | `/api/v1/system/impersonations`         | Spustí 15-minútový session-bound kontext       |
| `DELETE` | `/api/v1/system/impersonations/current` | Okamžite ukončí aj expirovaný kontext          |
| `GET`    | `/api/v1/auth/session`                  | Vráti efektívnu identitu a voliteľný kontext   |
| `GET`    | `/api/v1/tenants`                       | Počas impersonácie iba pripnutý tenant         |
| `*`      | `/api/v1/tenants/{tenantId}/...`        | Vyžaduje zhodný pripnutý tenant a cieľové roly |

Úplné request a response schémy sú v [`openapi.json`](./openapi.json).

## Audit

Append-only audit zapisuje:

- úspešný aj neúspešný `IMPERSONATION_STARTED`,
- každú tenantovú `IMPERSONATION_REQUEST` vrátane metódy, cesty a výsledku,
- `IMPERSONATION_ENDED` s dôvodom ukončenia.

Udalosti obsahujú skutočného aj efektívneho používateľa, tenant, request ID a
identifikátor impersonácie. Heslo, session token, CSRF token ani request body sa
nezapisujú. Povinný support dôvod je auditné metadata.

## Frontend

Systémový zoznam tenantov načíta iba aktívnych členov zvoleného tenantu a pred
spustením vyžiada cieľ, dôvod a aktuálne heslo. Po úspechu otvorí tenantový shell.

Tenantový shell po celý čas zobrazuje výrazný varovný banner so skutočným a
efektívnym používateľom, dôvodom, zostávajúcim časom a tlačidlom na okamžité
ukončenie. Po ukončení obnoví aktuálnu reláciu zo servera, vyčistí tenantovú cache
a vráti sa do `/system`.
