# SOVA – bezpečnostný audit

Bezpečnostný audit je oddelený od používateľskej histórie doménových objektov.
Slúži na dohľadateľnosť privilegovaných a bezpečnostne významných operácií a
neudeľuje žiadne oprávnenie k auditovanému objektu.

## Nemennosť

`security_audit_events` aj samostatný autentifikačný audit
`authentication_events` sú append-only tabuľky. Aplikácia do nich môže pridávať
nové udalosti, ale PostgreSQL triggery `trg_security_audit_events_append_only` a
`trg_authentication_events_append_only` odmietnu každý `UPDATE` aj `DELETE` vrátane
priameho SQL vykonaného pod aplikačným databázovým používateľom. Zmena alebo
odstránenie už zapísanej udalosti preto nie je aplikačná operácia.

Migrácia pridáva indexy `(occurred_at DESC, id DESC)` pre systémový pohľad a
`(tenant_id, occurred_at DESC, id DESC)` pre tenantový pohľad. Retenčný purge po
400 dňoch bude samostatná privilegovaná prevádzková procedúra nad časovými
partíciami; nesmie sa implementovať sprístupnením bežného `DELETE` aplikácii.

## Obsah udalosti

Udalosť obsahuje:

- UUIDv7 a databázový UTC čas,
- skutočného aktéra a voliteľného efektívneho používateľa,
- voliteľný tenantový kontext,
- stabilný typ udalosti, výsledok a reason kód,
- request ID a bezpečne zistenú IP adresu,
- obmedzené JSON metadata s relevantnými identifikátormi alebo zmenou.

Heslá, session a CSRF tokeny, jednorazové tokeny, autorizačné hlavičky, cookies,
ciphertext ani kryptografické tajomstvá sa nesmú zapisovať. Čítací repository
navyše rediguje metadata, ktorých kľúč vyzerá ako heslo, token, secret, cookie,
authorization, ciphertext alebo hash, a rediguje aj nečakané vnorené hodnoty.

## Čítacie API

| Metóda | Route                              | Oprávnenie          | Rozsah                                 |
| ------ | ---------------------------------- | ------------------- | -------------------------------------- |
| `GET`  | `/api/v1/system/audit`             | `system.audit.view` | Všetky systémové aj tenantové udalosti |
| `GET`  | `/api/v1/tenants/{tenantId}/audit` | `tenant.audit.view` | Iba route-derived tenant               |

Tenantový endpoint prechádza rovnakým `TenantContextMiddleware` ako ostatné
tenantové API a `tenant_id` vynucuje priamo repository dotaz. Cudzia udalosť sa
preto nedá získať filtrom ani cursorom. `SUPERADMIN` môže použiť systémový endpoint
alebo explicitne vstúpiť do tenantového kontextu; oba prístupy sa samy auditujú.

Podporované filtre:

- `from` a `to` ako RFC 3339 čas,
- `actor_user_id`,
- `event_type`,
- `outcome=SUCCESS|FAILURE`,
- `request_id`,
- `limit` od 1 do 100, predvolene 50,
- nepriehľadný `cursor` vrátený ako `next_cursor`.

Stránkovanie je keysetové podľa `(occurred_at, id)`, nie offsetové. Vďaka tomu je
poradie stabilné aj pri súbežnom pribúdaní nových udalostí. Neplatný filter alebo
cursor vracia `422 AUDIT_QUERY_INVALID`.

## Frontend

Samostatný systémový layout obsahuje lazy route `/system/audit`. Obrazovka
podporuje filtre podľa typu, výsledku, aktéra a request ID, postupné načítanie
ďalšej keyset stránky a zobrazuje systémový alebo tenantový kontext. Route aj API
vyžadujú aktuálnu dynamicky overenú rolu `SUPERADMIN`; frontendový guard nie je
bezpečnostná hranica.

Tenantové auditné API je hotové vrátane samostatnej tenantovej auditnej
obrazovky (`/t/:tenantSlug/admin/audit`) a exportu chráneného
`tenant.audit.export`.

`GET /api/v1/tenants/{tenantId}/audit/export` prijíma rovnaké filtre ako
zoznam, interne postupuje po keyset stránkach po 100 udalostiach a vráti
jediný CSV súbor (`text/csv`, `Content-Disposition: attachment`) obsahujúci
najviac 5000 najnovších udalostí zodpovedajúcich filtru. Redakcia citlivých
metadát je identická so zoznamom a samotný export sa zapisuje do auditu ako
`TENANT_AUDIT_EXPORTED`.

## Impersonácia

Kontrolovaná impersonácia zapisuje úspešný aj neúspešný začiatok, každú požiadavku
v pripnutom tenantovom kontexte a ukončenie. Všetky tieto udalosti obsahujú
`actor_user_id`, `effective_user_id`, `tenant_id`, request ID a identifikátor
impersonácie. Support dôvod sa ukladá iba ako auditné metadata; reautentifikačné
heslo ani request body sa nikdy nezapisujú. Detailný lifecycle je v
[`IMPERSONATION.md`](./IMPERSONATION.md).
