# SOVA – implementačný plán

> Trvalý stavový dokument pre pokračovanie vývoja po prerušení.

| Vlastnosť              | Hodnota                                          |
| ---------------------- | --------------------------------------------------- |
| Posledná aktualizácia  | 2026-07-27                                       |
| Aktuálna fáza          | Fáza 4 – pracovné skupiny a projekty             |
| Aktuálny checkpoint    | F4 – projekty (dátový model, doména, API, UI)    |
| Nasledujúci checkpoint | F4 – projektoví členovia, skupiny a projektové roly |

## Ako plán udržiavať

- `[x]` znamená implementované a overené podľa uvedeného dôkazu.
- `[ ]` znamená nehotové; rozpracovaná položka je navyše označená textom
  **ROZPRACOVANÉ**.
- Položka sa označí ako hotová až po relevantných testoch alebo kontrole.
- Po každom pracovnom bloku sa aktualizuje aktuálny checkpoint, checkboxy a
  [denník overenia](#denník-overenia).
- Nové záväzné rozhodnutie patrí aj do `PROJECT_MEMORY.md` a podľa dopadu do ADR.

## Záväzné poradie

```text
Fáza 0: produktové rozhodnutia
→ Fáza 1: technický základ
→ Fáza 2: identity a tenancy
→ Fáza 3: oprávnenia a administrácia
→ Fáza 4: pracovné skupiny a projekty
→ Fáza 5: úlohy, workflow a SovaQL
→ Fáza 6: spolupráca
→ Fáza 7: kompletné UI a dashboardy
→ Fáza 8: stabilizácia
→ Fáza 9: pilot a produkcia
→ Fáza 10: prevádzka a rozvoj
```

Bezpečnostné hranice, audit, OpenAPI a testy nie sú samostatná záverečná práca.
Rozvíjajú sa v každom checkpointe.

## Fáza 0 – produktové a architektonické rozhodnutia

Stav: **hotové**. Produktové a architektonické rozhodnutia sú zapísané v
`PROJECT_MEMORY.md`, hlavnej analýze a registri `docs/adr/`.

- [x] Produktová, technická, webflow a UI analýza.
- [x] Rozsah MVP a Definition of Done.
- [x] Projektom vlastnené typy úloh a verzované workflow.
- [x] ADR 0001 pre typy úloh a workflow.
- [x] Registrácia iba cez jednorazovú pozvánku; tenant zakladá `SUPERADMIN`.
- [x] `SUPERADMIN` má úplný, explicitný a auditovaný prístup ku všetkým tenantom.
- [x] Impersonácia patrí do MVP s MFA/reauth, dôvodom a krátkou expiráciou.
- [x] Pracovné skupiny sú nositeľom projektového prístupu.
- [x] Úloha môže mať súčasne riešiteľa aj pracovnú skupinu.
- [x] Opis a komentáre používajú bezpečne renderovaný CommonMark bez raw HTML.
- [x] Upload limity, retencia, `RPO ≤ 15 min`, `RTO ≤ 4 h` a cieľový model
      nasadenia sú uzavreté v ADR 0009.
- [x] ADR pre modulárny monolit, PostgreSQL multitenancy, session cookie,
      permission model, UUID, OpenAPI, outbox a UTC.

## Fáza 1 – technický základ

Stav: **rozpracované**.

### F1.0 – existujúci skeleton

- [x] PHP 8.3+ / Slim 4 bootstrap a dependency injection.
- [x] Centralizované načítanie konfigurácie.
- [x] Doctrine DBAL a Doctrine Migrations tooling pre PostgreSQL 17.
- [x] Request ID, CORS a Problem Details error middleware.
- [x] API info, liveness a readiness endpointy.
- [x] PHPUnit, PHPStan `max`, PHP CS Fixer a spoločný `composer check`.
- [x] Angular 22 standalone aplikácia so strict režimom a lazy routingom.
- [x] Bootstrap 5, základný responzívny shell a zdieľané UI komponenty.
- [x] Typovo kontrolovaná runtime lokalizácia SK, CS, EN, DE, PL a HU.
- [x] Vitest, Prettier, typecheck, build a spoločný `npm run check`.

### F1.1 – kontrakt, logovanie a CI

- [x] Pridať verzovaný OpenAPI 3.1 kontrakt aktuálnych endpointov.
- [x] Prepnúť backendové logy na štruktúrovaný JSON formát.
- [x] Pridať CI pre backend a frontend s uzamknutými závislosťami.
- [x] Doplniť automatickú kontrolu OpenAPI kontraktu.
- [ ] **BLOKOVANÉ PROSTREDÍM** Spustiť plný `composer check` v CI alebo po oprave lokálnych
      rozšírení `dom`, `xmlwriter` a `pdo_pgsql`; `npm run check` už prešiel.

### F1.2 – dizajnový a HTTP hardening

- [x] Implementovať sémantické light/dark tokeny z UI manuálu.
- [x] Pridať voľbu témy Systém/Svetlá/Tmavá bez bliknutia pri štarte.
- [x] Pridať bezpečnostné HTTP hlavičky a ich testy.
- [x] Zaviesť jednotnú mapu doménových Problem Details chýb.

## Fáza 2 – identity a tenancy

Stav: **hotové**. Prvý bezpečný vertical slice:

```text
prihlásenie
→ výber tenantu
→ overenie aktívneho členstva
→ autorizované načítanie tenantového profilu
→ cross-tenant integračný test
```

### F2.1 – databázový a doménový základ

- [x] Migrácia pre globálnych používateľov, tenantov a členstvá s kompozitnými
      tenantovými constraintmi.
- [x] Migrácia pre hashované, revokovateľné serverové relácie.
- [x] Časovo zoraditeľné verejné UUID a UTC časové pečiatky.
- [x] Stavové modely používateľa, tenantu a členstva.
- [x] Argon2id hasher s `password_needs_rehash()`.
- [x] Kryptograficky náhodné session tokeny uložené iba ako hash.
- [x] Unit a migračné testy vrátane negatívnych stavov.

### F2.2 – autentifikačné API

- [x] `POST /api/v1/auth/login` s jednotnou chybou proti enumerácii.
- [x] `POST /api/v1/auth/logout`.
- [x] `GET/DELETE /api/v1/auth/sessions`.
- [x] Secure, HttpOnly a primerané SameSite cookie nastavenie.
- [x] CSRF ochrana pre stav meniace cookie-auth požiadavky.
- [x] Rate limiting podľa účtu aj IP.
- [x] Audit úspešných a neúspešných bezpečnostných udalostí bez secrets.

### F2.3 – tenantový kontext

- [x] `GET /api/v1/tenants` pre aktívne členstvá; `SUPERADMIN` výnimka pre všetky
      neodstránené tenanty.
- [x] Tenant context middleware z route, nie z dôveryhodného request body.
- [x] `GET /api/v1/tenants/{tenantId}` s kontrolou členstva alebo systémovej roly.
- [x] Cross-tenant API a repository testy.
- [x] Deaktivované členstvo a pozastavený tenant okamžite zablokujú bežný prístup.
- [x] Samostatná systémová väzba `SUPERADMIN` a audit privilegovaného tenantového
      kontextu.

### F2.4 – frontend vertical slice

- [x] Typovaný API klient podľa OpenAPI.
- [x] Auth/session služba a credentials interceptor.
- [x] `AuthGuard`, `AnonymousGuard` a `TenantGuard`.
- [x] Login napojený na API bez tokenu v `localStorage`.
- [x] Výber tenantu napojený na aktívne členstvá.
- [x] Bezpečné `returnUrl`, 401 tok a vyčistenie tenantovej cache po odhlásení.
- [x] Komponentové a navigačné testy.

### F2.5 – obnova prístupu a pozvánky

- [x] Hashované jednorazové tokeny s expiráciou a jednorazovým použitím.
- [x] Forgot/reset password API a zrušenie starých relácií.
- [x] E-mail verification.
- [x] Pozvánky ako jediný registračný tok a prijatie členstva.
- [x] Príslušné verejné obrazovky a E2E scenáre.

## Fáza 3 – oprávnenia a administrácia

Stav: **hotové**. Permission katalóg, tenantová aj systémová administrácia,
append-only audit a kontrolovaná impersonácia sú implementované a overené.
Projektové a workgroup roly presunuté do Fázy 4 (potrebujú reálnu `projects`/
`workgroups` tabuľku). Globálne systémové nastavenia (predvolené limity,
feature flags, maintenance mód) sú vedome odložené – `docs/webflow/
04-ADMINISTRACIA.md` §14 ich sám označuje za „minimalizované“ a žiadna
kategória zatiaľ nemá reálny backing systém, ktorý by konfigurovala.

- [x] Permission katalóg a matica predvolených rolí.
- [x] Centrálna autorizačná služba bez autorizácie podľa názvu roly, napojená na
      vytvorenie pozvánky a Doctrine provider efektívnych tenantových grantov.
- [x] Tenantové role, cache revízia a okamžité zneplatnenie. Projektové a
      workgroup roly presunuté do Fázy 4 – vyžadujú existujúcu `projects`/
      `workgroups` tabuľku pre referenčnú integritu kompozitných cudzích
      kľúčov, viď poznámka vo Fáze 4.
  - [x] Tenantové role, permission granty a membership priradenia s kompozitnými
        tenantovými cudzími kľúčmi.
  - [x] Predvolené tenantové roly, idempotentný provisioning a backfill existujúcich
        tenantov.
  - [x] Monotónna autorizačná revízia a okamžité zneplatnenie decision cache pri
        zmene členstva, identity, tenantu, roly, grantov alebo priradenia.
  - [x] Autorizované a auditované API na zoznam rolí a idempotentné priradenie
        alebo odobratie roly členstvu.
  - [x] API a aplikačné služby na vytvorenie, úpravu a archiváciu vlastných
        tenantových rolí.
- [x] Zoznam tenantových členstiev a auditovaný lifecycle
      `ACTIVE ↔ DISABLED → REMOVED` s okamžitou invalidáciou prístupu.
- [x] Ochrana posledného aktívneho `TENANT_OWNER`.
  - [x] Transakčná ochrana a tenantový lock pri odobratí owner roly.
  - [x] `TENANT_OWNER` môže prideľovať iba aktér s `tenant.roles.manage`;
        samotný `tenant.roles.assign` neumožňuje privilege escalation.
  - [x] Rovnakú ochranu zdieľať s deaktiváciou/odstránením členstva a
        lifecycle operáciami tenantu.
- [x] Oddelená systémová rola `SUPERADMIN`, dynamický session kontrakt, guard a
      systémový layout.
- [x] Tenantová a systémová administrácia.
  - [x] Tenantové membership/role API a lifecycle s ochranou posledného vlastníka.
  - [x] Systémový zoznam, idempotentné vytvorenie tenantu, prvý owner onboarding a
        lifecycle s revision, dôvodom a ochrannou lehotou.
  - [x] Samostatné systémové UI pre správu tenantov a bezpečnostný audit.
  - [x] Tenantové UI pre členov, pozvánky, roly a tenantový audit/export.
  - [x] Systémová správa používateľov a systémových administrátorov: zoznam
        všetkých globálnych účtov, stavový prechod `ACTIVE`/`DISABLED` cez
        `UserStatus` a idempotentný grant/revoke role `SUPERADMIN`. Zakázaná
        zmena vlastného účtu; posledný aktívny superadmin chránený pri revoke
        aj pri deaktivácii účtu. Globálne systémové nastavenia zostávajú
        vedome odložené (viď stav fázy vyššie).
- [x] Append-only bezpečnostný audit.
  - [x] PostgreSQL ochrana bezpečnostného aj autentifikačného auditu proti
        `UPDATE` a `DELETE`.
  - [x] Permission-based systémové a tenantovo izolované čítacie API.
  - [x] Filtre, redakcia citlivých metadata a keyset stránkovanie.
- [x] Kontrolovaná 15-minútová impersonácia s reauth, povinným dôvodom a dvojitou
      identitou v audite.
  - [x] Session-bound databázový kontext s jednou otvorenou impersonáciou,
        maximálnou 15-minútovou platnosťou a čerstvou reautentifikáciou.
  - [x] Cieľ iba ako aktívny používateľ s aktívnym členstvom v jednom pripnutom
        aktívnom tenantovi.
  - [x] Vypnutý `SUPERADMIN` bypass a permission rozhodnutia iba podľa efektívneho
        používateľa.
  - [x] Fail-closed expirácia a invalidácia bez tichého návratu k systémovej moci.
  - [x] Audit začiatku, každej tenantovej požiadavky a ukončenia s oboma identitami.
  - [x] Systémový štartovací formulár a trvalý tenantový banner s odpočtom a
        okamžitým ukončením.

## Fáza 4 – pracovné skupiny a projekty

Presunuté z Fázy 3 (2026-07-27): projektové a workgroup roly potrebujú reálnu
`projects`/`workgroups` tabuľku pre kompozitné cudzie kľúče, nie provizórnu
náhradu. `EffectivePermissionProvider` preto zostáva pre `PROJECT` scope
fail-closed až do dokončenia tejto fázy (viď `AUTHORIZATION.md`); `WORKGROUP`
scope je od 2026-07-27 vyhodnocovaný.

- [x] Pracovné skupiny, manažéri, členstvá a archivácia.
  - [x] Migrácia `workgroups`/`workgroup_members` s kompozitnými tenantovými
        cudzími kľúčmi a revision triggerom zdieľaným s tenantovou
        autorizáciou.
  - [x] `EffectivePermissionProvider` vyhodnocuje `WORKGROUP` scope
        (`workgroup.view`/`manage`/`members.manage`) podľa `member_role`
        MEMBER/MANAGER na konkrétnej skupine.
  - [x] API: zoznam/vytvorenie/archivácia/reaktivácia skupiny a
        zoznam/pridanie/zmena role/odobratie člena. Každý endpoint akceptuje
        buď tenantové `tenant.workgroups.manage`, alebo workgroup-scoped
        oprávnenie na konkrétnej skupine – manažér skupiny tak nepotrebuje
        tenantové administrátorské oprávnenie.
  - [x] Tenantové admin UI (`/t/:tenantSlug/admin/workgroups`): vytvorenie,
        zoznam, archivácia/reaktivácia, inline správa členov vrátane výberu
        role.
- [ ] Projekty, tenantovo verejná/súkromná viditeľnosť a archivácia.
- [ ] Projektoví členovia, skupiny a permission-based prístup.
- [ ] Projektové roly, priradenia a výpočet grantov – rozšírenie
      `EffectivePermissionProvider` o `PROJECT` scope podľa vzoru
      tenantových rolí (`project_roles`, `project_role_permissions`,
      `project_membership_role_assignments`) alebo podľa jednoduchšieho
      workgroup vzoru (`member_role` enum), podľa toho, čo si vyžiada reálny
      rozsah projektových rolí.
- [ ] Atomické vytvorenie projektu s nezávislou kópiou predvolenej konfigurácie.
- [ ] Cross-tenant a cross-project constrainty a integračné testy.
- [ ] Zoznam, detail a administračné UI projektov a skupín.

## Fáza 5 – úlohy, workflow a SovaQL

### F5.1 – typy úloh a runtime workflow

- [ ] Projektové typy, stavy a predvolená šablóna.
- [ ] Hierarchia Epic → štandardný typ → Sub-task.
- [ ] Workflow identity, verzie, prechody a bezpečný register pravidiel.
- [ ] Mapovanie aktívneho typu na publikované workflow.
- [ ] Úlohy, atomický projektový číselný rad a optimistické zamykanie.
- [ ] Dostupné prechody a vykonanie cez `transition_id`.

### F5.2 – publikovanie konfigurácie

- [ ] Draft, validácia grafu a impact report.
- [ ] Atomické publikovanie a migrácia použitých stavov.
- [ ] Zmena typu existujúcej úlohy.
- [ ] História konfigurácie, audit a outbox udalosti.
- [ ] Projektové administračné UI a konflikt dvoch editorov.

### F5.3 – SovaQL

- [ ] Lexer, parser, typované AST, sémantická validácia a kanonizácia SovaQL v1.
- [ ] Whitelist compiler s parametrizovanými hodnotami.
- [ ] Neodstrániteľný tenantový, projektový a `issue.view` scope.
- [ ] Cursor pagination, statement timeout, rate limit a limity zložitosti.
- [ ] PostgreSQL fulltext a indexy overené query plánmi.
- [ ] Textový editor a vizuálny filter builder nad rovnakým AST.

## Fáza 6 – spolupráca

- [ ] Komentáre, zmienky a používateľská história úlohy.
- [ ] Sledovatelia a väzby úloh.
- [ ] Privátne prílohy, metadata, autorizované stiahnutie a bezpečnostný sken.
- [ ] Transactional outbox a idempotentný background worker.
- [ ] In-app a základné e-mailové notifikácie.
- [ ] Používateľské nastavenia notifikácií.

## Fáza 7 – kompletné UI, uložené dotazy a dashboardy

- [ ] Uložené SovaQL dotazy, obľúbené, explicitné granty a audit.
- [ ] Viac osobných dashboardov na členstvo, predvolený a posledný aktívny.
- [ ] 12-stĺpcový layout s optimistickým zamykaním a mobilným poradím.
- [ ] Widget registry a typy count, list, breakdown, matrix a time series.
- [ ] Tabuľkový zoznam, Kanban a detail úlohy s plnými stavmi.
- [ ] Kompletné tenantové, projektové a systémové administračné obrazovky.
- [ ] Loading, empty, stale, forbidden, conflict, offline a error stavy.
- [ ] WCAG 2.2 AA, klávesnicové alternatívy a responzívnosť.

## Fáza 8 – stabilizácia

- [ ] Kompletné unit, integračné, API a E2E testy kritických ciest.
- [ ] Automatizovaný tenant isolation test suite a RLS overenie.
- [ ] Threat model a bezpečnostná revízia.
- [ ] Povinné MFA pre produkčných `SUPERADMIN` účtov; impersonačný MVP tok už
      používa povolenú vetvu čerstvej reautentifikácie heslom.
- [ ] Výkonnostné testy, query plány, indexy a N+1 kontrola.
- [ ] Monitoring, metriky, alerty a redakcia citlivých logov.
- [ ] Zálohy databázy a príloh s úspešným restore testom.
- [ ] Používateľská, prevádzková a incidentná dokumentácia.

## Fáza 9 – pilot a produkcia

- [ ] Opakovateľné staging nasadenie.
- [ ] Pilotný tenant a spracovaná spätná väzba.
- [ ] Vyriešené kritické chyby a žiadne známe kritické zraniteľnosti.
- [ ] Produkčné nasadenie, health overenie, monitoring a alerty.
- [ ] Incidentný, rollback a obnovovací postup.

## Fáza 10 – prevádzka a rozvoj

- [ ] Pravidelné aktualizácie a bezpečnostné skeny závislostí.
- [ ] Sledovať upstream opravu `@angular/cli` → `@modelcontextprotocol/sdk` →
      `@hono/node-server`; audit hlási iba dev-only moderate Windows path traversal
      a zatiaľ ponúka len nekompatibilný downgrade.
- [ ] Pravidelné restore testy a revízia RPO/RTO.
- [ ] Revízia oprávnení, auditov a bezpečnostných udalostí.
- [ ] Vývoj ďalších modulov podľa metrík a reálneho používania.

## Denník overenia

| Dátum      | Checkpoint                   | Overenie                                                                                                   | Výsledok                                                                                                                                                                                          |
| ---------- | ---------------------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-07-26 | Audit východiskového stavu   | Kompletná dokumentácia a zdrojový audit                                                                    | F1 skeleton existuje; OpenAPI, CI a JSON logy chýbajú                                                                                                                                             |
| 2026-07-26 | F1.1                         | Composer validate, PHP CS Fixer, PHPStan `max`                                                             | Prešlo bez chýb                                                                                                                                                                                   |
| 2026-07-26 | F1.1                         | OpenAPI/route a JSON logger runtime smoke                                                                  | Prešlo                                                                                                                                                                                            |
| 2026-07-26 | F1.1                         | `npm run check` na Node 24.15.0                                                                            | Prešlo: Prettier, typecheck, 7 testov, production build                                                                                                                                           |
| 2026-07-26 | F1.1                         | PHPUnit / plný `composer check`                                                                            | Lokálny host blokuje chýbajúce `dom` a `xmlwriter`; `pdo_pgsql` navyše hlási ABI chybu. CI inštaluje explicitné rozšírenia.                                                                       |
| 2026-07-26 | F1.2                         | `npm run check` po téme, tokenoch a oprave prepínača jazyka                                                | Prešlo: Prettier, typecheck, 11 testov, production build; build hlási iba warning 503,75 kB proti 500 kB warning budgetu, limit 1 MB nebol prekročený                                             |
| 2026-07-26 | F1.2                         | Security headers: PHP CS Fixer, PHPStan `max`, runtime smoke                                               | Prešlo pre úspešnú, chybovú aj HTTPS odpoveď; PHPUnit čaká na opravu lokálneho PHP alebo CI                                                                                                       |
| 2026-07-26 | F1.2                         | Problem Details: Composer validate, PHP CS Fixer, PHPStan `max`, 404 runtime smoke                         | Prešlo; všetky chyby majú centrálny typ, bezpečný detail, stabilný kód a request ID; PHPUnit čaká na lokálne PHP alebo CI                                                                         |
| 2026-07-26 | F2.1                         | Doctrine migrácia na dočasnej PostgreSQL 16, kompatibilný SQL subset PostgreSQL 17                         | Prešlo: 4 tabuľky, 10 SQL príkazov; PostgreSQL odmietol duplicitné členstvo aj plaintext session token                                                                                            |
| 2026-07-26 | F2.1                         | PHPStan `max` a runtime smoke pre UUIDv7, Argon2id, rehash, session tokeny a stavové prechody              | Prešlo; databázové PHPUnit testy sú zapojené do CI s PostgreSQL 17                                                                                                                                |
| 2026-07-26 | F2.2                         | Dve Doctrine migrácie a auth HTTP smoke na čistej PostgreSQL                                               | Prešlo: login, jednotná 401, hashované cookies, session list, CSRF 403, logout, revokácia vlastnej relácie, account rate limit a audit                                                            |
| 2026-07-26 | F2.2                         | PHPStan `max`, OpenAPI/route kontrola a PHP CS Fixer                                                       | Prešlo; OpenAPI pokrýva všetky implementované auth routy, plný PHPUnit zostáva lokálne blokovaný rozšíreniami PHP a je zapojený v CI                                                              |
| 2026-07-26 | F0                           | Kontrola rozhodnutí, hlavnej analýzy, webflow a ADR 0002–0009                                              | Fáza 0 uzavretá: invite-only, úplný SUPERADMIN, MVP impersonácia, skupinový prístup, CommonMark, upload/retencia/RPO/RTO a deployment baseline                                                    |
| 2026-07-26 | F2.3                         | Tri migrácie na čistej PostgreSQL a HTTP smoke tenantového kontextu                                        | Prešlo: active membership, jednotné cross-tenant 404, disabled/suspended blokácia, SUPERADMIN bez členstva, pozastavený tenant a bezpečnostný audit                                               |
| 2026-07-26 | F2.3                         | Composer validate, PHP CS Fixer, PHPStan `max` a OpenAPI/route kontrola                                    | Prešlo; 10 rout zodpovedá kontraktu. PHPUnit testy sú pripravené, lokálne ich blokuje iba chýbajúce DOM/XMLWriter a plne sa spustia v CI                                                          |
| 2026-07-26 | F2.4                         | `npm run check` na Node 24.15.0                                                                            | Prešlo: Prettier, strict typecheck, 12 súborov/31 testov a production build; iba warning 514,11 kB proti 500 kB, hard limit 1 MB nebol prekročený                                                 |
| 2026-07-26 | F2.5 tokenový základ         | Štyri migrácie na čistej PostgreSQL 16, PHPStan `max` a repository smoke                                   | Prešlo: 30 SQL príkazov; iba hashe tokenov, nahradenie starého tokenu a atómové jednorazové spotrebovanie. PHPUnit ostáva lokálne blokovaný DOM/XMLWriter                                         |
| 2026-07-26 | F2.5 obnova hesla            | Piata migrácia, HTTP/worker smoke, PHPStan `max`, CS Fixer a OpenAPI/route kontrola                        | Prešlo: jednotné 202, šifrovaný outbox, známy/neznámy účet, rollback slabej politiky, reset, dve revokované relácie, starý login 401, nový 200 a reuse 410                                        |
| 2026-07-26 | F2.5 overenie e-mailu        | Šiesta migrácia, HTTP/worker PostgreSQL smoke, PHPStan `max`, CS Fixer a OpenAPI/route kontrola            | Prešlo: jednotné 202, oddelený rate limit, šifrovaný outbox, doručenie iba pending účtu, aktivácia, audit, idempotentný reuse, invalid 410 a login 200                                            |
| 2026-07-26 | F2.5 tenantové pozvánky      | PostgreSQL HTTP/worker smoke pre nový aj existujúci účet, PHPStan `max`, CS Fixer a OpenAPI                | Prešlo: SUPERADMIN create, iba hash tokenu, šifrované doručenie, atomicita účtu/členstva, email mismatch, CSRF, audit, reuse 410 a presná zhoda 18 rout                                           |
| 2026-07-26 | F2.5 verejné access UI       | `npm run check` na Node 24.15.0                                                                            | Prešlo: Prettier, strict typecheck, 17 súborov/38 testov a production build; warning 517,12 kB proti 500 kB, hard limit 1 MB nebol prekročený                                                     |
| 2026-07-26 | F2.5 browser E2E             | Playwright 1.62 / Chromium: forgot, reset, verify a nový invitation účet                                   | Prešlo 4/4; tokeny odstránené z URL a iba v POST body. Test odhalil a regresný unit test pokrýva 401 race počas aktivácie verejnej route                                                          |
| 2026-07-26 | F2.5 závislosti              | `composer audit --locked`, `npm audit --audit-level=high`                                                  | Composer bez advisory; npm bez high/critical, 3 moderate iba v dev Angular CLI reťazci pre Windows static serving, bez kompatibilnej upstream opravy                                              |
| 2026-07-26 | F3.1 permission základ       | PHPStan `max`, CS Fixer a runtime katalóg/matica/DI smoke                                                  | Prešlo: 39 permissions, 4 explicitné scopes, 8 predvolených rolí, závislosti matice, deny-by-default, SUPERADMIN a invitation endpoint cez centrálnu službu                                       |
| 2026-07-26 | F3.1 databázové granty       | Čistá a backfill Doctrine migrácia, rollback, PostgreSQL runtime/API smoke, PHPStan `max`, CS Fixer        | Prešlo: 4 systémové tenantové roly/76 grantov, idempotentný provisioning, tenant-admin invitation, grant/revoke revision invalidácia, inactive stavy a cross-tenant FK                            |
| 2026-07-26 | F3.2 tenantové priradenia    | PostgreSQL API smoke, OpenAPI/route kontrola, PHPStan `max`, CS Fixer                                      | Prešlo: 21 API operácií, 4 roly/32 non-system permissions, grant/revoke, idempotencia, audit, 403 owner escalation, 409 last owner, úspešný transfer a cross-tenant test                          |
| 2026-07-27 | F3.2 vlastné tenantové roly  | PostgreSQL API/cache/audit smoke s rollbackom, OpenAPI JSON, PHPStan `max`, CS Fixer                       | Prešlo: 24 API operácií; create/update/archive, permission závislosti, 403 admin, immutable systémové roly, optimistic revision 409, assigned archive 409, cache invalidácia a 3 auditné udalosti |
| 2026-07-27 | F3.2 lifecycle členstva      | PostgreSQL API/cache/owner-guard smoke s rollbackom, OpenAPI/route kontrola, PHPStan `max`, CS Fixer       | Prešlo: 26 API operácií; list, disable/reactivate/remove, stará session 404, idempotencia, terminálny stav, self block, owner privilege 403, posledný owner 409, cross-tenant 404 a 5 auditov     |
| 2026-07-27 | F3 systémový kontext         | PostgreSQL dynamická rola, session/guard testy, Angular test/build, OpenAPI a statické kontroly            | Prešlo: okamžité grant/revoke `SUPERADMIN`, oddelený lazy layout, bezpečný `/system` return URL a návrat do explicitného tenantového kontextu                                                     |
| 2026-07-27 | F3 správa tenantov           | Doctrine migrácia, PostgreSQL end-to-end API smoke, PHPStan `max`, CS Fixer, Angular test/build            | Prešlo: idempotentný create, owner pozvánka/aktivácia, revision lifecycle, suspend/archive, 30-dňová grace lehota, cancel, audit a systémové UI                                                   |
| 2026-07-27 | F3 append-only audit         | Migrácia up/down/up, PostgreSQL API/immutability smoke, OpenAPI 28 ciest/32 operácií, PHPStan, CS, Angular | Prešlo: systémové a tenantové permission hranice, cross-tenant izolácia, filtre, redakcia, keyset cursor, odmietnutý UPDATE/DELETE v oboch auditoch a 19 súborov/45 frontend testov               |
| 2026-07-27 | F3 kontrolovaná impersonácia | Migrácia up/down/up, PostgreSQL HTTP/DB smoke, PHPStan `max`, CS, OpenAPI 30 ciest/34 operácií, Angular    | Prešlo: reauth, 15 minút, jedna relácia/tenant, cieľové permission, cross-tenant 404, systémový bypass 403, expirácia 409, explicitný end, dvojitý audit a 20 súborov/48 frontend testov          |
| 2026-07-27 | F3 tenantové admin UI a export | Nový `TenantSecurityAuditExportAction` (PHPStan `max`, CS Fixer, manuálny route/OpenAPI parity skript namiesto blokovaného PHPUnit), `npm run check` na Node 24.15.0, Playwright smoke s mockovaným API bez reálnej PostgreSQL | Prešlo: 31 rout zodpovedá OpenAPI kontraktu; Prettier, strict typecheck, 27 súborov/66 testov, production build (535 kB proti 500 kB warningu, pod 1 MB limitom); mockovaný prehliadačový beh cez `/admin/members`, `/admin/roles` (auto-výber tranzitívnych permission závislostí pri zaškrtnutí, systémové role bez edit/archive) a `/admin/audit` (filtre, export tlačidlo) bez konzolových chýb. Projektové/workgroup role formálne presunuté do Fázy 4. PHPUnit test exportu pripravený, beží v CI (lokálne chýbajú `dom`/`xmlwriter`/`pdo_pgsql`) |
| 2026-07-27 | F3 systémová správa používateľov (dokončenie fázy) | Nový `Identity\Application\System` (repository, service, 3 HTTP akcie), PHPStan `max`, CS Fixer, manuálny route/OpenAPI parity skript (34 ciest), `npm run check`, Playwright smoke s mockovaným API vrátane grant-superadmin interakcie | Prešlo: zoznam s `is_superadmin` príznakom, idempotentný status prechod a superadmin grant/revoke, zakázaná zmena vlastného účtu, zakázaná vlastná superadmin revoke, posledný aktívny superadmin chránený (overené priamym service-level testom pri revoke aj status-change vetve), 29 súborov/72 frontend testov, production build; PHPUnit pripravený pre CI. Fáza 3 uzavretá – globálne systémové nastavenia vedome odložené (viď stav fázy) |
| 2026-07-27 | F4 pracovné skupiny | Nová migrácia `workgroups`/`workgroup_members` zdieľajúca revision trigger s tenantovou autorizáciou, nový modul `Sova\Workgroups`, rozšírený `DoctrineEffectivePermissionProvider` o `WORKGROUP` scope, PHPStan `max`, CS Fixer, manuálny route/OpenAPI parity skript (38 ciest), `npm run check`, Playwright smoke s mockovaným API | Prešlo: dvojcestná autorizácia (tenantové `tenant.workgroups.manage` ALEBO workgroup-scoped `workgroup.manage`/`members.manage` cez `member_role` MANAGER) overená end-to-end testom, kde manažér skupiny bez tenantového oprávnenia spravuje vlastnú skupinu a je odmietnutý na cudzej; plný CRUD skupiny aj členov, cross-tenant izolácia, idempotentný status prechod, 31 súborov/78 frontend testov, production build (536 kB); mockovaný prehliadačový beh cez `/admin/workgroups` vrátane pridania manažéra bez konzolových chýb. PHPUnit pripravený pre CI |
