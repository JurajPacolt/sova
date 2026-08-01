# ADR 0003: PostgreSQL shared-schema multitenancy

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

Používateľ môže patriť do viacerých tenantov, ale dáta tenantov sa nesmú pomiešať.
Databáza na tenant alebo schéma na tenant by v MVP výrazne skomplikovali migrácie,
pooling, prevádzku a globálne identity. Samotný aplikačný filter však nie je
dostatočná ochrana proti chybe.

## Rozhodnutie

- Primárnym úložiskom je PostgreSQL 17 so spoločnou schémou.
- Používateľ je globálna entita. Každá tenantová business tabuľka obsahuje
  neprázdny `tenant_id`; projektové entity obsahujú aj projektový kontext podľa
  potreby.
- Tenantový kontext vzniká z autentifikovanej route a aktuálneho členstva alebo zo
  systémového oprávnenia `SUPERADMIN`. Klient ho nesmie určiť dôveryhodným poľom v
  request body.
- Repository dotazy povinne filtrujú tenant a projekt. Kompozitné unique a foreign
  key väzby, napríklad `(tenant_id, id)`, bránia cross-tenant referenciám.
- Tenantové business tabuľky budú pred produkciou chránené aj PostgreSQL Row-Level
  Security s `FORCE ROW LEVEL SECURITY`. Transakcia nastaví kontext cez `SET LOCAL`,
  aby sa po vrátení connection do poolu nepreniesol na ďalšiu požiadavku.
- Bežná API a worker databázová rola je `NOSUPERUSER` a `NOBYPASSRLS`.
  `SUPERADMIN` je aplikačné oprávnenie, nie databázový `BYPASSRLS`; RLS politika mu
  povolí explicitne auditovaný systémový kontext.
- Cudzí alebo neautorizovaný tenantový identifikátor sa navonok správa ako
  neexistujúci zdroj. Každá hranica má pozitívne aj negatívne integračné testy.
- Zálohovacia rola a kontrola integrity majú osobitné minimálne práva. Referenčná
  integrita sa nepovažuje za kontrolu viditeľnosti.

## Dôsledky

### Pozitívne

- jedna sada migrácií, jednoduché globálne identity a efektívne využitie databázy,
- viacvrstvová ochrana cez aplikáciu, constraints, RLS a testy,
- tenant zostáva explicitný v cache, joboch, súboroch, audite aj metrike.

### Náklady a obmedzenia

- každý tenantový dotaz a index musí zahŕňať správny kontext,
- transakčný context pri poolingu a background joboch vyžaduje prísnu disciplínu,
- mimoriadne veľký tenant môže neskôr vyžadovať partitioning alebo samostatné
  umiestnenie dát.

## Referencie

- [PostgreSQL 17 – Row Security Policies](https://www.postgresql.org/docs/17/ddl-rowsecurity.html)
- [PostgreSQL 17 – Database Roles and BYPASSRLS](https://www.postgresql.org/docs/17/role-attributes.html)
