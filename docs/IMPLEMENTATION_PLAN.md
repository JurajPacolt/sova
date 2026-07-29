# SOVA – implementačný plán

> Trvalý stavový dokument pre pokračovanie vývoja po prerušení.

| Vlastnosť              | Hodnota                                          |
| ---------------------- | --------------------------------------------------- |
| Posledná aktualizácia  | 2026-07-29                                       |
| Aktuálna fáza          | Fáza 7 – kompletné UI, uložené dotazy a dashboardy |
| Aktuálny checkpoint    | F7.8e – UI dashboardov (prepínač, mriežka, widgety)   |
| Nasledujúci checkpoint | F7.8f – režim úprav dashboardu a správa dashboardov  |

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
- [x] Spustiť plný `composer check`. **Blokáda pominula 2026-07-28**: `dom`,
      `xmlwriter` a `simplexml` sa dajú lokálne doplniť bez sudo rozbalením
      balíka `php8.3-xml` (`apt-get download` → `dpkg-deb -x`) a nasmerovaním
      `PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:<vlastný conf.d>`; `pdo_pgsql`
      je funkčný a varovania o `mysqli`/`pdo_mysql` sú neškodné duplicitné
      pokusy o načítanie. Databázové testy potrebujú `RUN_DATABASE_TESTS=true`
      a PostgreSQL podľa `.env` (lokálne port 5433); bežia v transakcii s
      rollbackom, takže dev databázu nepoškodia. Postup je v
      [denníku overenia](#denník-overenia).

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

Stav: **hotové**. Pracovné skupiny, projekty s vynútenou viditeľnosťou,
projektové roly a granty, projektové UI aj efektívne oprávnenia s
`PermissionGuard` sú implementované a overené.

Presunuté z Fázy 3 (2026-07-27): projektové a workgroup roly potrebujú reálnu
`projects`/`workgroups` tabuľku pre kompozitné cudzie kľúče, nie provizórnu
náhradu. `WORKGROUP` scope je od 2026-07-27 a `PROJECT` scope od 2026-07-28
vyhodnocovaný, takže `EffectivePermissionProvider` už pokrýva všetky štyri
scopes (viď `AUTHORIZATION.md`).

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
- [x] Projekty, tenantovo verejná/súkromná viditeľnosť a archivácia.
  - [x] Migrácia `projects`, `project_roles`, `project_role_permissions`,
        `project_membership_role_assignments` a `project_workgroups` s
        kompozitnými tenantovými cudzími kľúčmi a revision triggerom.
  - [x] `visibility` sa vynucuje pri čítaní, nielen zapisuje:
        `listVisibleForUser()` vracia `TENANT` viditeľné projekty plus
        `PRIVATE`, kde má efektívny používateľ rolu priamo alebo cez prepojenú
        skupinu; `tenant.projects.manage` naďalej vidí celý tenant.
  - [x] Obojsmerná archivácia s idempotentným opakovaním; archivovaný projekt
        nedáva žiadne projektové oprávnenia.
- [x] Projektoví členovia, skupiny a permission-based prístup.
- [x] Projektové roly, priradenia a výpočet grantov – `EffectivePermissionProvider`
      vyhodnocuje `PROJECT` scope podľa vzoru tenantových rolí
      (`project_roles`, `project_role_permissions`,
      `project_membership_role_assignments`) a zjednocuje ho s grantom z
      prepojenej pracovnej skupiny (`project_workgroups`).
- [x] Atomické vytvorenie projektu s nezávislou kópiou predvolenej konfigurácie –
      projektové roly a ich granty. Kópia typov úloh a workflow zo šablóny patrí
      k F5.1, ktorá tie entity zavádza.
- [x] Cross-tenant a cross-project constrainty a integračné testy.
      `tests/Api/ProjectApiTest.php` pokrýva 9 scenárov vrátane viditeľnosti,
      dvojcestného grantu a cross-tenant odmietnutia. PHPUnit lokálne nebeží
      (chýbajú `dom`/`xmlwriter`), preto rovnaké scenáre overil skriptový HTTP
      smoke proti dočasnej PostgreSQL 16 – 33/33 kontrol. Beh samotného PHPUnit
      testu zostáva na CI.
- [x] Zoznam, detail a administračné UI projektov a skupín.
  - [x] Zoznam projektov napojený na API s hľadaním, filtrom stavu, kartami,
        odznakom súkromného projektu a vlastnými rolami používateľa (PRJ-01).
  - [x] Vytvorenie projektu vrátane voľby viditeľnosti a vedúceho, s lokálnou
        kontrolou povinného vedúceho pri súkromnom projekte.
  - [x] Detail projektu so správou členov a ich rolí, prepojením pracovných
        skupín na projektovú rolu, prehľadom projektových rolí a archiváciou;
        sekcie sa načítavajú nezávisle, takže `403` na časti dát nezhodí obrazovku.
- [x] Efektívne oprávnenia v tenantovom kontrakte a `PermissionGuard` podľa
      `docs/webflow/00-INFORMACNA-ARCHITEKTURA.md` §4.
  - [x] `AuthorizationService::grantedPermissions()` a `listPermissions()` pre
        `TENANT`, `PROJECT` aj `WORKGROUP` scope; výsledok sa filtruje podľa
        scope, takže projektové kódy uložené na tenantovej role sa nevydávajú za
        tenantový grant. Impersonácia vypína `SUPERADMIN` zoznam rovnako ako
        bypass.
  - [x] `GET /tenants/{tenantId}` vracia `permissions`; `TenantStore` ich drží a
        zahodí pri zmene alebo strate tenantu.
  - [x] `permissionGuard(...)` na administračných routách a skrytá položka
        „Administrácia“ v ľavej navigácii bez oprávnenia.

## Fáza 5 – úlohy, workflow a SovaQL

### F5.1 – typy úloh a runtime workflow

Stav: **backend hotový**. Nové moduly `Sova\ProjectConfiguration` (vlastník
konfigurácie) a `Sova\Issues` (issue tracking), oddelené podľa
`WORKFLOW-A-TYPY-ULOH.md` §15: issue tracking číta konfiguráciu iba cez
`ProjectConfigurationRepository`, nikdy jej tabuľky priamo. Frontend úloh patrí
do Fázy 7 (`Tabuľkový zoznam, Kanban a detail úlohy`).

- [x] Projektové typy, stavy a predvolená šablóna. Šablóna sa kopíruje v tej
      istej transakcii ako projekt, takže projekt nikdy nevznikne bez typov,
      stavov a publikovaného workflow.
- [x] Hierarchia Epic → štandardný typ → Sub-task. `HierarchyLevel` vynucuje
      `1` bez rodiča, `0` voliteľne pod epicom a `-1` iba pod štandardným typom;
      rodič z iného projektu alebo tenantu vracia `404
      PROJECT_RESOURCE_NOT_FOUND`.
- [x] Workflow identity, verzie a prechody. Jeden draft na workflow chráni
      parciálny unikátny index, publikovaná verzia musí mať počiatočný stav aj
      `published_at`.
- [x] Bezpečný register a **runtime vykonanie** pravidiel prechodov
      (`workflow_transition_rules` – podmienky, validátory a následné akcie).
      F5.2 doplnila úložisko a štrukturálnu validáciu pravidiel v drafte a verzii
      (`TransitionRuleCatalog` overí typ, kľúč aj konfiguráciu); F5.1 doplnila
      runtime engine `TransitionRuleEvaluator`. Podmienky `permission` a
      `assignee_or_manager` filtrujú ponuku aj vykonanie prechodu (fail-closed,
      „manager“ = `issue.assign`); validátor `resolution_required` vynúti
      `resolution` (v ponuke ako `required_fields`, inak `422
      ISSUE_TRANSITION_INVALID`); akcie `set_resolution`, `clear_resolution`,
      `set_resolved_at` a `clear_resolved_at` menia nové stĺpce `issues.resolution`
      a `issues.resolved_at` (migrácia `Version20260728120000`). Issue modul číta
      pravidlá iba cez `ProjectConfigurationRepository` (`RuleView`), hranica
      modulov ostáva. Validátor `required_field` je zdokumentovaná hranica, kým
      neexistujú vlastné polia typu (§5.3).
- [x] Mapovanie aktívneho typu na publikované workflow
      (`project_issue_type_workflows`, jedna väzba na typ).
- [x] Úlohy, atomický projektový číselný rad a optimistické zamykanie. Číslo sa
      rezervuje jedným `INSERT … ON CONFLICT DO UPDATE … RETURNING`, kľúč má tvar
      `KÓD-číslo`; každý zápis zvyšuje `version` a nesúhlasná verzia vráti `409`.
- [x] Dostupné prechody a vykonanie cez `transition_id`. Zoznam vracia iba
      prechody platné pre aktuálny stav, verziu workflow a oprávnenia aktéra
      (vrátane runtime podmienok pravidiel), spolu s verziou úlohy, proti ktorej
      bol vypočítaný, a poľom `required_fields` (dnes `resolution`). Klient nikdy
      neposiela cieľový stav; `resolution` posiela cez `fields.resolution` pri
      vykonaní. Každá zmena zapíše históriu aj outbox udalosť v jednej transakcii.

### F5.2 – publikovanie konfigurácie

Stav: **backend hotový**. Rozširuje modul `Sova\ProjectConfiguration` o autoring
lifecycle z `WORKFLOW-A-TYPY-ULOH.md` §8: jeden editovateľný draft na workflow,
validácia grafu (§6.4), impact report a atomické publikovanie s migráciou úloh.
Issue tracking ostáva oddelený – migráciu úloh vykonáva adaptér
`Sova\Issues\...\DoctrineIssueMigrator` cez port `IssueMigrator`, takže tabuľky
úloh zapisuje iba modul `Sova\Issues`. Projektové administračné UI patrí do
Fázy 7.

- [x] Draft, validácia grafu a impact report. `POST …/workflows/{id}/draft`
      skopíruje publikovanú verziu do jedného draftu (parciálny unikátny index
      povolí najviac jeden, duplicita vráti `409 WORKFLOW_DRAFT_EXISTS`), `PUT`
      nahradí jeho obsah s optimistickým zámkom (`409 WORKFLOW_DRAFT_CONFLICT`),
      štrukturálne chyby vrátia `422 WORKFLOW_DRAFT_INVALID`. Stavy sú zdieľané
      projektové entity – draft riadi iba členstvo, prechody a počiatočný stav,
      nie atribúty stavu, takže úprava draftu nemení publikovanú verziu.
      `GET …/validation` vracia porušenia grafu (dosiahnuteľnosť, aspoň jeden
      `DONE`, jedna primárna akcia na stav), `GET …/impact` pridané a odobrané
      stavy/prechody, počty dotknutých úloh a stavy, ktoré potrebujú migračný
      cieľ.
- [x] Atomické publikovanie a migrácia použitých stavov. `POST …/publish` v jednej
      transakcii overí `expected_config_version` (`409
      PROJECT_CONFIG_VERSION_CONFLICT`), znovu zvaliduje graf (`422
      WORKFLOW_INVALID`), prepne aktívnu verziu, zmigruje každú úlohu zo starej
      verzie na novú a zvýši revíziu konfigurácie. Odobraný stav, ktorý ešte nesie
      úlohy, vyžaduje `status_mapping` (`409 WORKFLOW_MIGRATION_REQUIRED`); cieľ
      mimo novej verzie je `422 WORKFLOW_MIGRATION_TARGET_INVALID`. Invariant: po
      publikovaní referencujú všetky úlohy aktuálnu aktívnu verziu.
- [x] Zmena typu existujúcej úlohy (§5.4). `POST …/issues/{issueId}/type`
      (`issue.change-type`, kebab-case kvôli CHECK-u na názvy oprávnení) prepne
      úlohu na iný aktívny typ toho istého projektu: overí `expected_issue_version`
      (`409 ISSUE_VERSION_CONFLICT`), rovnaký typ (`422 ISSUE_TYPE_UNCHANGED`),
      cieľový typ musí byť aktívny s publikovaným workflow (`422
      ISSUE_TYPE_INVALID`), rodič aj existujúce deti musia vyhovieť hierarchii
      cieľa (`422 HIERARCHY_INVALID`) a aktuálny stav sa mapuje do cieľového
      workflowu – ak sa nedá jednoznačne, API žiada `target_status_id` (`409
      ISSUE_TYPE_STATUS_MAPPING_REQUIRED`, nesprávny cieľ `422
      ISSUE_TYPE_STATUS_INVALID`). Zápis prebehne v jednej transakcii s
      `issue_history ISSUE_TYPE_CHANGED` (metadáta starý/nový typ) a outbox
      udalosťou. Issue modul číta konfiguráciu iba cez
      `ProjectConfigurationRepository` (hranica modulov zachovaná).
- [x] História konfigurácie, audit a outbox udalosti. Publikovanie zapíše riadok
      do `project_configuration_history` (`WORKFLOW_PUBLISHED` s metadátami),
      outbox udalosť `PROJECT_WORKFLOW_PUBLISHED` (`aggregate_type
      PROJECT_CONFIGURATION`, sekvencia = revízia) a bezpečnostný audit; každá
      zmigrovaná úloha dostane `issue_history ISSUE_MIGRATED` aj outbox
      `ISSUE_MIGRATED`. `GET …/configuration/history` vracia denník najnovší prvý.
- [ ] Projektové administračné UI a konflikt dvoch editorov. Backendová polovica
      (optimistické zamykanie draftu cez `version` a konfigurácie cez revíziu) je
      hotová; samotné Angular UI je **vedome odložené do Fázy 7** (potvrdené
      2026-07-28). Node blokáda pominula (lokálne 24.15.0), odklad je čisto
      rozsahové rozhodnutie – ide o rozsiahly greenfield frontend (celý workflow-config
      API klient, editor draftu, validácia/impact, publish s UX konfliktu), ktorý
      patrí k ostatným administračným obrazovkám vo Fáze 7.

### F5.3 – SovaQL

Stav: **rozpracované**. Analyzátor jazyka je hotový a overený; prekladač do SQL,
scope, stránkovanie a UI ešte nie.

- [x] Lexer, parser, typované AST, sémantická validácia a kanonizácia SovaQL v1.
      Modul `Sova\Issues\Domain\QueryLanguage` je čisto doménový a bez databázy:
      `SovaQlAnalyzer` presadí bajtový limit, lexuje, parsuje, staticky validuje
      a pri čistom dotaze vypíše kanonickú podobu. Katalógy sú úplné podľa §4.4 a
      §4.6–4.7; polia bez vlastného stĺpca (`watcher`, `labels`, `due`,
      `estimate`, `closed`) sú vedome označené ako nepodporované, takže vrátia
      `QUERY_FIELD_NOT_SUPPORTED` a rozsvietia sa vo svojej fáze bez zmeny verzie
      jazyka. `summary` je prechodný alias `title`; rezervovaný priestor
      `cf.<key>` sa odmieta, nie odhaduje. Chyby sa zbierajú naraz (editor
      zvýrazní všetky) a nesú stabilný kód, rozsah v UTF-8 kódových bodoch a
      argumenty. `QUERY_VALUE_NOT_AVAILABLE` a `QUERY_VALUE_AMBIGUOUS` sem
      zámerne nepatria – referencie rieši až compiler v autorizovanom rozsahu.
- [x] Whitelist compiler s parametrizovanými hodnotami. `IssueQueryCompiler`
      (port `QueryCompiler`) prekladá validované AST štruktúrne, nie textovou
      náhradou: názov stĺpca pochádza výhradne z konštánt `COLUMNS`/
      `SORT_EXPRESSIONS`, hodnota je vždy viazaný parameter s menom `q<N>` z
      počítadla, takže vstup nemôže ovplyvniť tvar príkazu. `priority` a
      `statusCategory` sa triedia podľa významu cez konštantný `CASE`, nie
      abecedne. `title ~` je bezpečné `ILIKE` s escapovanými `%`/`_`, `text ~`
      používa `websearch_to_tsquery('simple', …)` – nikdy `LIKE` ani regulárny
      výraz. Referencie sa rozlišujú dvojfázovo (`ReferenceRequest::collect` →
      `ReferenceResolver` → compile), takže jeden dotaz stojí niekoľko
      hromadných SELECT-ov, nie N kontrol.
- [x] Neodstrániteľný tenantový, projektový a `issue.view` scope.
      `DoctrineSearchScopeProvider` odvodí zoznam projektov z `PROJECT` scope
      `issue.view` (priama rola alebo prepojená skupina) rovnakým SQL vzorom ako
      `loadProjectDecision`; `SUPERADMIN` má bypass iba vo vlastnom kontexte, pri
      impersonácii nie. Predikát `tenant_id` + `project_id IN (…)` píše
      repozitár **pred** pripojením skompilovaného filtra, takže žiadny fragment
      ho nevie zhodiť. Prázdny scope vracia prázdnu stránku, nie `403` – bez
      projektovej roly nie je čo vidieť. Referencia mimo scope sa nerozlíši od
      neexistujúcej (`QUERY_VALUE_NOT_AVAILABLE`), takže dotazom sa nedá
      enumerovať cudzia konfigurácia.
- [x] Cursor pagination, statement timeout, rate limit a limity zložitosti.
      Cursor je podpísaný keyset token viazaný cez `CursorBinding` na tenant,
      efektívneho používateľa, autorizačnú revíziu, hash kanonického dotazu a
      špecifikáciu triedenia; kľúč sa odvodzuje z `SENSITIVE_PAYLOAD_KEY`
      doménovou separáciou, takže nepribudlo produkčné tajomstvo. Overenie je
      fail-closed – nesúhlas vráti `422 QUERY_CURSOR_INVALID`, nie tichý návrat
      na prvú stránku. Keyset predikát je lexikografický a rešpektuje umiestnenie
      `NULL` podľa skutočného `ORDER BY`; stabilný tie-breaker `issue.id` je vždy
      prítomný. Statement timeout beží ako `SET LOCAL` v transakcii (inak
      session-level s návratom v `finally`) a SQLSTATE `57014` sa mapuje na
      `QUERY_TIMEOUT`. Rate limit je fixné okno na tenant+používateľa s HMAC
      kľúčom (tabuľka `issue_query_rate_limits`), veľkosť stránky je zhora
      orezaná.
- [x] PostgreSQL fulltext a indexy overené query plánmi. Migrácia
      `Version20260728140000` pridáva GIN fulltext presne nad výrazom, ktorý
      compiler generuje, trigramový GIN nad `title`, `LOWER(title)` pre
      triedenie a kompozitné indexy pre `created_at`, `resolved_at`, `priority`,
      `issue_type_id` a `assignee_workgroup_id`. Overené `EXPLAIN`-om nad 40 000
      riadkami, viď [denník overenia](#denník-overenia).
- [x] **Textový editor** nad `/issue-query/validate` a `/issue-query/metadata`.
      Validácia je serverová, nie druhá gramatika v klientovi – klientská kópia
      by sa od jazyka rozišla a začala by tvrdiť niečo iné o tom, čo je platné.
      Presne preto endpoint existuje oddelene od vyhľadávania. Kontrola sa púšťa
      po utíchnutí písania (400 ms, bez opakovania rovnakého textu), prázdny
      dotaz sa nekontroluje vôbec, lebo je legálny a znamená „všetko, čo môžem
      vidieť“. **`message_key` z odpovede sú i18n kľúče frontendu** – doplnených
      všetkých 13 `query.errors.*` do šiestich katalógov; neznámy kľúč zo
      servera sa overí proti katalógu, inak by sa vykreslil sám sebou.
      Rozsah chyby sa vyrezáva cez spread operátor, nie `substring`, lebo
      offsety sú **kódové body**, kým `substring` počíta UTF-16 jednotky a po
      emoji by ukázal na nesprávne znaky. Referencia polí a aktívne limity sa
      berú z `/metadata`, takže UI nikdy neinzeruje pole, ktoré ešte nemá
      úložisko, ani limit, ktorý prevádzka zmenila.
- [x] **Vizuálny filter builder** nad rovnakým AST. Špecifikácia §5.1 žiada, aby
      oba režimy používali **rovnaký serverový parser a AST** a aby prepnutie
      nezmenilo význam dotazu. Validácia preto vracia aj `basic_form` –
      projekciu AST na to, čo vie základný režim nakresliť. Projektuje sa iba
      konjunkcia jednoduchých podmienok; `OR`, `NOT` a zátvorky nesú význam,
      ktorý základný režim nemá ako zobraziť, takže sa dotaz označí ako
      `representable: false` a UI ho ukáže **len na čítanie** s návratom do
      SovaQL – špecifikácia výslovne zakazuje potichu ho zjednodušiť.
      Triedenie je ploché v oboch režimoch, takže prežije aj vtedy, keď filter
      nie. Odstránenie podmienky skladá text z **kanonických kúskov od servera**
      a nechá ho znovu zvalidovať; klient si o význame zvyšku nič nedomýšľa.

## Fáza 6 – spolupráca

Stav: **hotové**. Komentáre, zmienky, história, sledovatelia, väzby,
privátne prílohy, transakčný outbox worker aj obojkanálové notifikácie sú
implementované a overené.

- [x] Komentáre, zmienky a používateľská história úlohy. Komentár ukladá pôvodný
      CommonMark **source**; backend ho nikdy nerenderuje a API nevracia HTML.
      `CommentBodyValidator` odmieta raw HTML na hranici, ale zámerne ignoruje
      fenced bloky a inline code spans – vložiť `<div>` do bloku kódu je v
      issue trackeri bežné a markupom sa to stať nemôže; autolinky
      (`<https://…>`) ostávajú platné. Zmienka je Markdown odkaz
      `[@Meno](sova:user/<membership uuid>)`, takže text a adresáti sa nikdy
      nerozídu a premenovanie člena starý komentár neprepíše.
      **Zmienka neudeľuje prístup**: každý zmienený člen musí byť aktívny v
      tenantovi a už mať `issue.view` na projekte, inak `422
      COMMENT_MENTION_NOT_ALLOWED` (odporúčaný MVP variant webflow §4.2).
      Kontrola vedome nepoužíva `SUPERADMIN` bypass – systémová moc je na
      explicitný auditovaný prístup, nie na tichý notifikačný kanál. Úprava:
      autor vo `COMMENT_EDIT_WINDOW_SECONDS` (predvolene 15 min), inak
      `comment.moderate`. Odstránenie je soft a idempotentné – komentár si
      nechá miesto v diskusii, ale nie text ani zmienky.
      `GET …/issues/{id}/history` sprístupňuje používateľskú históriu
      (`issue.view`), oddelenú od bezpečnostného auditu.
- [x] Sledovatelia a väzby úloh. **Sledovanie** je stĺpec `watching`, nie
      prítomnosť riadku: explicitné odhlásenie musí prežiť automatické pravidlá,
      takže „nesledujem“ je uložené rozhodnutie a auto-pravidlá používajú
      `ON CONFLICT DO NOTHING`. Automaticky sa prihlási autor úlohy, jej
      riešiteľ pri vytvorení a každý, kto pridá komentár; dôvod sa uchováva a
      vracia v API, aby pravidlá boli viditeľné (webflow §6). Člen spravuje iba
      vlastné sledovanie, preto v ceste nie je identifikátor
      (`PUT`/`DELETE …/watchers/me`). **Väzby** (`BLOCKS`, `RELATES_TO`,
      `DUPLICATES`) sa ukladajú raz a čítajú z oboch strán s odvodeným
      inverzným názvom, takže smery si nemôžu protirečiť; hierarchia
      rodič/dieťa zostáva na `issues.parent_issue_id`, aby neexistovali dva
      zdroje pravdy. Cross-tenant väzba nie je reprezentovateľná (dvojica
      zdieľa jeden `tenant_id`), self-link je `422`, existujúci pár v
      ktoromkoľvek smere `409`. Obe strany sa filtrujú cez `issue.view` rozsah
      volajúceho – neprístupná úloha sa v zozname neobjaví a pokus o väzbu na
      ňu vráti rovnaké `404` ako na neexistujúcu.
      **Bonus:** pole `watcher` v SovaQL sa tým rozsvietilo presne tak, ako
      `FieldCatalog` sľuboval – bez zmeny verzie jazyka; compiler ho prekladá
      na `EXISTS` nad `issue_watchers` (negácia obaľuje celý test, aby
      `NOT watcher = …` vrátilo aj úlohy, ktoré nesleduje nikto).
- [x] Privátne prílohy, metadata, autorizované stiahnutie a bezpečnostný sken.
      **Rozhodnutie vlastníka (2026-07-28): adresár na disku s cestou v konfigu.**
      `AttachmentStorage` je port, `FilesystemAttachmentStorage` jeho adaptér
      nad `ATTACHMENT_STORAGE_PATH` (predvolene `backend/var/attachments` – mimo
      `public/` aj mimo gitu). Objektové úložisko sa neskôr vymení bez zásahu do
      pravidiel uploadu. Databáza drží iba metadáta.
      **Typ rozhoduje obsah, nie názov:** `finfo` odsniffuje bajty a
      `AttachmentPolicy` porovná výsledok s allowlistom aj s príponou –
      allowlist kľúčovaný klientskym vstupom nie je allowlist. OOXML sa sniffne
      ako ZIP, preto ho prípona smie *zúžiť*, nikdy rozšíriť. Kľúč úložiska
      generuje server z UUID (`<tenant>/<aa>/<bb>/<uuid>`) a adaptér ho validuje
      aj pri čítaní, takže z triedy sa nedá spraviť path-traversal primitívum.
      Limity podľa MVP: 25 MiB na súbor (veľkosť sa **meria na disku**, neberie
      z requestu), jeden súbor na požiadavku, 20 živých príloh na úlohu,
      tenantová kvóta.
      **Sken:** `AttachmentScanner` je port; keď nie je nakonfigurovaný, zapíše
      sa `SKIPPED` – nie predstieraný `CLEAN` – a produkcia s `none` odmietne
      naštartovať, rovnako ako pri null mail transporte. Stiahnuteľné sú iba
      `CLEAN` a `SKIPPED`.
      **Stiahnutie** sa autorizuje pri každom volaní (žiadna verejná ani
      predpodpísaná URL) a vždy odpovedá `Content-Disposition: attachment` +
      `X-Content-Type-Options: nosniff`, takže používateľské bajty sa nikdy
      nerenderujú inline. Odstránenie je soft (záznam prežije retenciu), ale
      bajty idú okamžite.
- [x] Transactional outbox a idempotentný background worker.
      `OutboxDispatcher` (Shared) je generický: claimuje udalosti cez
      `FOR UPDATE … SKIP LOCKED`, takže viac procesov nikdy nedostane tú istú
      udalosť, a handler beží **v jednej transakcii** so zápisom
      `processed_at` – efekt aj potvrdenie commitnú alebo padnú spolu. Doručenie
      je preto at-least-once a idempotencia je povinnosťou handlera. Claimujú sa
      iba udalosti s registrovaným handlerom, aby dispatcher nebral riadky
      e-mailovým workerom, ktoré majú vlastné šifrované payloady a purge
      pravidlá. Zlyhanie ide do exponenciálneho backoffu a po
      `OUTBOX_MAX_ATTEMPTS` sa vzdá – poison message nesmie navždy zablokovať
      frontu. Spúšťač je `bin/outbox-worker.php`.
- [x] E-mailové notifikácie a používateľské nastavenia. `NotificationEmailHandler`
      je druhý `OutboxHandler` nad rovnakými udalosťami a **zdieľa
      `NotificationAudience`** s in-app handlerom – dve kópie pravidiel publika
      by sa rozišli a tá rozídená by poslala názov úlohy niekomu, kto naň nemá
      nárok. Audience navyše **znovu overí `issue.view` v čase doručenia**:
      sledovanie prežije stratu prístupu k projektu, takže bez tejto kontroly by
      sa odobratý člen ďalej dozvedal kľúč a názov úlohy. E-mail nesie iba kľúč,
      názov a odkaz späť do aplikácie – nikdy text komentára, lebo e-mail
      opúšťa kontrolu systému; všetky interpolované hodnoty sú HTML-escapované.
      Nastavenia sú per člen a typ udalosti (`notification_preferences`); uložia
      sa iba skutočné voľby, zvyšok dopĺňa predvolená hodnota, takže nový typ
      udalosti nepotrebuje backfill. In-app kanál je pri pridelení a zmienke
      **zamknutý** (`ChannelPreference` to vynúti v doméne, nie v HTTP vrstve),
      e-mail je všade vypnutý predvolene.
- [x] In-app notifikácie. Nový modul `Sova\Notifications`; `IssueEventNotifier`
      spracúva `COMMENT_ADDED`, `ISSUE_TRANSITIONED` a `ISSUE_CREATED`.
      Idempotenciu nesie úložisko: unikátny kľúč `(event_id, recipient, kind)`
      s `ON CONFLICT DO NOTHING`, takže replay udalosti nechá schránku
      nezmenenú. Príjemcovia vyplývajú zo **sledovania**, nie z členstva v
      tenantovi; aktér nikdy nedostane notifikáciu o vlastnej akcii a zmienka
      prebíja bežnú notifikáciu o komentári, takže oslovenie nepríde dvakrát.
      Schránku číta iba jej vlastník – žiadny identifikátor v ceste, každý
      príkaz je kľúčovaný membershipom. E-mailové notifikácie a používateľské
      nastavenia zostávajú v F6.5.
- [ ] Používateľské nastavenia notifikácií.

## Fáza 7 – kompletné UI, uložené dotazy a dashboardy

Stav: **rozpracované**. Začalo sa dobiehaním frontendu k tomu, čo backend už
vie – má to okamžitú hodnotu a nevyžaduje ďalšiu backendovú vrstvu. Fáza nesie
aj to, čo sa cestou vedome odložilo: projektové administračné UI (z F5.2) a
editor SovaQL s filter builderom (z F5.3).

- [ ] Uložené SovaQL dotazy, obľúbené, explicitné granty a audit.
      **ROZPRACOVANÉ** – backend aj UI hotové, chýba audit a väzba na widgety.
  - [x] Oprava scope. `saved-query.share` bolo `PROJECT`, ale uložený dotaz je
        **tenantová entita**, ktorá môže odkazovať na viac projektov naraz –
        oprávnenie sa nikdy nemohlo vešať na jeden z nich. Presunuté na
        `TENANT` a doplnené `saved-query.create` a `saved-query.manage`; matica
        rolí ich dáva `TENANT_OWNER`, `TENANT_ADMIN` a tenantovému `MEMBER`,
        `manage` iba prvým dvom. Migrácia grant presúva, nie duplikuje.
  - [x] Schéma: `saved_queries` (kanonický dotaz generuje server, názov je
        unikátny na vlastníka **medzi živými** dotazmi, takže archivácia meno
        uvoľní), `saved_query_grants` (grant menuje práve jedného principála –
        člena alebo skupinu, nikdy oboch ani žiadneho) a `saved_query_favourites`
        (obľúbenie je osobná väzba na membership, nie vlastnosť dotazu).
  - [x] Aplikačná služba, granty, obľúbené a HTTP API. Päť ciest pod
        `/tenants/{tenantId}/saved-queries`. **Zdieľanie nie je prístup** –
        grant dovolí dotaz *spustiť*, nikdy nie vidieť úlohu, na ktorú by
        čitateľ inak nedosiahol; výsledok sa pri každom behu prieniká s jeho
        vlastným `issue.view` rozsahom, takže ten istý zdieľaný dotaz právom
        vracia rôznym ľuďom rôzne riadky. Viditeľnosť aj úroveň prístupu
        počíta **SQL v tom istom dotaze, ktorý číta riadok**, takže dotaz, na
        ktorý volajúci nemá, z databázy vôbec nevyjde – neexistuje filtračný
        krok v PHP, ktorý by sa dal zabudnúť. Cudzí súkromný dotaz preto
        nevracia `403`, ale `404`: nie je zakázaný, je neviditeľný.
        `PUT /grants` nahrádza celú množinu, lebo čiastočná úprava nevie
        zaručiť, že vynechaný principál naozaj stratí prístup; viditeľnosť sa
        z grantov odvodí (aspoň jeden grant = `SHARED`). Archivovať smie iba
        vlastník alebo `saved-query.manage` – držať `EDIT` stačí na zmenu
        dotazu, nikdy na jeho stiahnutie ostatným zo zoznamu. Editor tiež
        nemení viditeľnosť (v tele `PATCH` pole nie je), takže cudzí dotaz
        nezverejní potichu. Unikátnosť mena sa kontroluje voči **vlastníkovi**,
        nie voči editorovi.
  - [x] UI: panel uložených dotazov pod editorom na zozname úloh. Načítanie
        dotazu vypĺňa **ten istý** textový box, ktorý sa spúšťa, takže beží
        vždy to, čo je na obrazovke – a vkladá sa **surový text**, nie
        kanonická forma, lebo znovuotvorenie má ukázať, čo autor napísal.
        Ponuka akcií sa riadi `viewer_access` a `viewer_is_owner`, ktoré
        opisujú volajúceho, nie riadok: držiteľ grantu `EDIT` vidí „Prepísať“,
        ale nie „Archivovať“ ani „Zdieľanie“. Editor zdieľania posiela celú
        množinu grantov a hovorí nahlas, že **zdieľanie nedáva prístup k
        úlohám**. Obľúbené idú navrch zoznamu, archivované sa skryjú za
        rozbaľovací počet – nie sú zamlčané, len nezavadzajú. Volajúci bez
        členstva (čistá systémová moc) panel vôbec nedostane; nemá čo vlastniť
        ani dostať pridelené, takže to nie je chyba, ktorú treba hlásiť.
  - [x] `409 SAVED_QUERY_IN_USE` a počet závislostí. Port `SavedQueryUsageProbe`
        žije v module uložených dotazov a implementáciu dodáva modul
        dashboardov, takže šípka závislosti smeruje **k** dotazom: dashboardy
        vedia o dotazoch, dotazy o dashboardoch nie. Počet cestuje v `detail`,
        nie v `errors` – `DomainProblemException` dovoľuje field errors iba pri
        validačných problémoch a toto je konflikt.
  - [ ] Premenovanie uloženého dotazu z UI – panel dnes prepíše obsah, ale
        názov nechá tak. Premenovanie je iná intencia a koliduje inak (proti
        menám **vlastníka**), takže si zaslúži vlastnú obrazovku, nie tiché
        prilepenie k tlačidlu „Prepísať“.
  - [ ] Audit uložených dotazov – zdieľanie a archivácia sa dnes nezapisujú do
        auditného denníka. Nedodané vedome: audit je vlastná vrstva
        (`audit_events`, výpis, export) a rozšírenie o nový typ udalosti si
        zaslúži vlastný checkpoint spolu s ostatnými chýbajúcimi typmi, nie
        jednorazové pridanie iba pre uložené dotazy.
- [x] Viac osobných dashboardov na členstvo, predvolený a posledný aktívny.
      Nové oprávnenia `dashboard.create`, `dashboard.update-own`,
      `dashboard.delete-own` v `TENANT` scope; dostáva ich aj `VIEWER`, lebo
      dashboard je osobný a aj člen len na čítanie má vlastný. Schéma
      `dashboards`, `dashboard_widgets` a `membership_dashboard_preferences`.
      **Dashboard je osobný**: patrí jednému členstvu, cudzí je neviditeľný
      (`404`, nie `403`), a vlastníctvo je súčasťou `WHERE` každého príkazu, nie
      kontrolou dodatočne v PHP – repozitár nemá metódu, ktorá by dovolila
      siahnuť inam. Práve jeden dashboard je predvolený (parciálny unique index
      + presun v jednej transakcii) a člen má vždy aspoň jeden, takže posledný
      sa nedá zmazať. Posledný aktívny je osobná preferencia, ktorá sa nastavuje
      **explicitným `PUT …/active`, nie vedľajším účinkom `GET`** – prefetch ani
      náhľad odkazu nesmie človeku presunúť, kam nabudúce pristane.
- [x] 12-stĺpcový layout s optimistickým zamykaním. `PUT …/layout` aplikuje
      **celé rozloženie naraz** proti `dashboard.version` – presun dvoch
      widgetov cez seba je legálny iba ako dvojica, takže endpoint na jeden
      widget by musel odmietnuť prvú polovicu legálneho ťahu. Telo musí
      umiestniť **každý** widget dashboardu; čiastočné rozloženie by vynechané
      nechalo tam, kde boli, čo je presne spôsob, akým sa dva ocitnú na sebe.
      Server kontroluje hranice, minimálnu aj maximálnu veľkosť podľa typu a
      prekrytie nad celou množinou. Mobilné jednostĺpcové preusporiadanie je
      odvodené z poradia `y`, `x`, `id` a patrí do UI, nie do schémy.
- [x] Widget registry a typy count, list, breakdown, matrix a time series.
      Registry je **dátová**: kľúč, verzia schémy, veľkosti a agregačné
      dimenzie. Nenesie názov komponentu ani nič spustiteľné a `configuration`
      sa skladá kľúč po kľúči z toho, čo typ deklaruje, takže neznámy kľúč sa
      do úložiska nikdy nedostane – nie je pod čím by pricestoval. Chýbajúca
      hodnota je **predvolená**, nie odmietnutá, aby uložená konfigurácia
      prežila rozšírenie schémy; prítomná ale nesprávna sa odmietne. Neznámy
      `type_key` sa nikdy nepreloží na susedný typ: widget sa označí ako
      nedostupný a ponúkne na odstránenie. Dáta každého typu vracia
      `GET …/widgets/{widgetId}/data` — každý widget zvlášť, s vlastným
      výsledkom alebo vlastnou chybou, aby jeden nedostupný zdroj nezhodil celú
      stránku. **Widget je iba ukazovateľ**: pomenúva uložený dotaz a spôsob
      zhrnutia, a dotaz beží ako volajúci. Rozsah sa aplikuje **pred** akoukoľvek
      agregáciou — súčet nad riadkami, na ktoré čitateľ nemá, by prezradil ich
      existenciu rovnako spoľahlivo ako ich vrátenie. `CLOSED` v časových radoch
      chýba vedome: úlohy zatiaľ nemajú stĺpec `closed_at` (rovnako ako pole
      `closed` v SovaQL), a ponúkať udalosť, ktorú server nevie vypočítať, by
      bol sľub, ktorý nevie dodržať.
- [x] Štartovacia predloha dashboardu (§7.5). Predloha je **verzovaný dátový
      manifest** `StarterTemplate` – žiadny spustiteľný kód, žiadne tenantové UUID
      a žiadna konkrétna osoba: čie úlohy to sú, hovorí `currentUser()`, takže
      jeden manifest platí pre všetkých. Odporúčaná dlaždica „Po termíne“ potrebuje
      pole `due`, ktoré SovaQL zatiaľ hlási ako nepodporované, preto ju nahradila
      otázka, na ktorú server vie odpovedať – ponúkať dotaz, ktorý by pri prvom
      spustení zlyhal, je sľub, ktorý sa nedá dodržať (rovnaká úvaha ako pri
      `CLOSED` v časových radoch).
  - [x] Provisioning beží v **jednej transakcii** – súkromné dotazy, dashboard,
        widgety a rozloženie. Polovica by bola horšia než nič: widget bez zdroja
        vykreslí iba chybu. Manifest sa kopíruje **cez tie isté aplikačné služby a
        tie isté kontroly oprávnení** ako ručne postavený dashboard, takže z
        predlohy sa nedá získať nič, čo si používateľ nesmie vytvoriť sám.
  - [x] Prvé otvorenie: `GET …/dashboards` idempotentne vytvorí štartovací
        dashboard (§7.2 – každý aktívny člen musí mať aspoň jeden). Zapisuje sa
        iba vtedy, keď člen nemá **nič**, a preferencia „posledný aktívny“ sa
        vedome nemení – prefetch ani náhľad odkazu nesmie človeku presunúť, kam
        nabudúce pristane; nový dashboard je predvolený, čo prázdna preferencia
        aj tak vyhodnotí. Súbeh dvoch prvých otvorení rieši unikátny index, nie
        zámok: porazený jednoducho nájde dashboard víťaza.
  - [x] `POST …/dashboards/from-template` **pridáva, neprepisuje** – existujúce
        dashboardy, widgety, dotazy, predvolený príznak aj miesto používateľa
        ostávajú nedotknuté. Obsadené meno sa rieši počítaním nahor
        (`My work 2`), nie odmietnutím; pri explicitnej požiadavke chýbajúce
        oprávnenie vráti `403`, kým automatické prvé otvorenie ticho nespraví nič.
- [ ] UI dashboardov. **ROZPRACOVANÉ** – prepínač, mriežka a vykreslenie
      všetkých piatich typov widgetov sú hotové; režim úprav a obrazovka
      „Spravovať dashboardy“ nie.
  - [x] Kanonická route `/t/:tenantSlug/dashboards/:dashboardId` (§7.2);
        jednotné číslo `dashboard` ostáva ako vstupný bod a presmeruje.
        Holá cesta je **vstup, nie obrazovka**: `DashboardEntryComponent`
        zistí posledný aktívny dashboard a **nahradí** sa ním, takže adresa
        vždy pomenúva to, čo je na obrazovke, a tlačidlo Späť odchádza z
        dashboardov, nie medzi nimi.
  - [x] Prepínač v hlavičke. Prepnutie zapíše preferenciu „posledný aktívny“,
        obyčajné otvorenie nie – server ten zápis vedome drží mimo `GET` a
        klient ho tam nesmie vrátiť. Zlyhanie zápisu sa neukazuje: zlé miesto
        pri budúcom prihlásení je menší problém než chybová hláška nad
        funkčnou obrazovkou.
  - [x] 12-stĺpcová mriežka podľa uložených súradníc; pod `62em` jeden stĺpec v
        poradí `y`, `x`, `id`. Poradie je **odvodené z dokumentu**, takže
        mobilné zobrazenie neprepisuje desktopové súradnice (§7.4).
  - [x] Widgety `issue_count`, `issue_list`, `issue_breakdown`, `issue_matrix`
        a `issue_time_series`. **Každý si načíta vlastné dáta a nesie vlastnú
        chybu** – to je klientská polovica toho, prečo server vydáva dáta po
        jednom widgete; jeden nedostupný dotaz preto nezhodí stránku a dá sa
        zopakovať bez reloadu. Neznámy `type_key` ani nedosiahnuteľný zdroj
        nespustia požiadavku a vykreslia sa ako nedostupné.
  - [x] Dve overené farby sérií (`--sova-color-chart-series-1/2`, viď
        `UI_DESIGN_MANUAL.md` §3.4). Tmavý režim má **vlastný** indigo stupeň,
        nie prevrátený svetlý. Číslo v dlaždici nosí textovú farbu a tón ide
        cez **pomenovaný** odznak; bunka matice vždy obsahuje číslo, nielen
        odtieň; ku grafu patrí skrytá tabuľka. Farba tak nikdy nie je jediný
        nosič významu.
  - [ ] `DONUT` sa vykresľuje ako stĺpcový graf. Prstenec potrebuje farbu na
        výsek a dizajnový systém dodáva dve farby sérií zámerne – desať by bolo
        vymyslených, nie zvolených. Uložená konfigurácia sa nemení, mení sa iba
        vykreslenie; vlastná forma prstenca čaká na kategorickú paletu.
  - [ ] Režim úprav: pridanie, konfigurácia a odobratie widgetu, presun a
        zmena veľkosti proti `dashboard.version` s UX konfliktu (§7.4).
  - [ ] Obrazovka „Spravovať dashboardy“ (§7.3): premenovanie, duplikovanie,
        predvolený, poradie a odstránenie.
- [ ] Tabuľkový zoznam, Kanban a detail úlohy s plnými stavmi. **ROZPRACOVANÉ**
      – tabuľkový zoznam a detail sú hotové a napojené na API, Kanban a úplná
      sada stavov nie.
  - [x] Zoznam úloh je priamo SovaQL vyhľadávanie – druhý listovací endpoint
        neexistuje, takže platí rovnaký autorizovaný rozsah aj rovnaké limity,
        či je pole prázdne alebo nesie dotaz. Stránkovanie **pridáva**, lebo
        cursor kráča iba dopredu; nové hľadanie vždy začína bez cursora, keďže
        token z predošlého dotazu by bol právom odmietnutý. Odmietnutý dotaz
        (`422`) sa hlási inak než nedostupný server – prvé si opraví
        používateľ, druhé nie.
  - [x] Detail úlohy s reálnymi dátami, prechodmi, komentármi, sledovaním a
        históriou. **Každá sekcia sa načítava samostatne**, takže `403` na
        komentároch nezhodí celú obrazovku. Prechod posiela verziu, proti
        ktorej bola ponuka vypočítaná, takže súbežná zmena sa ohlási a
        neprepíše sa potichu. Text komentára sa vymaže až po potvrdení
        serverom – pri chybe používateľ o napísané nepríde. Opis aj komentáre
        sa zobrazujú ako **CommonMark source**; API nikdy nevracia HTML a
        klient ho zatiaľ nerenderuje, takže nemá čo sanitizovať.
  - [x] Kľúč úlohy zostáva v URL (`/t/:tenantSlug/issues/SOVA-1`), lebo to je
        to, čo ľudia čítajú a zdieľajú; na identifikátor ho preloží jedno
        SovaQL vyhľadanie (`key = …`), keďže backend „nájdi podľa kľúča“
        endpoint nemá.
  - [x] Prílohy v detaile. Upload ide ako `multipart/form-data` **bez ručne
        nastaveného `Content-Type`** – boundary si musí doplniť prehliadač, inak
        server požiadavku neprečíta. Sťahovanie ide cez HTTP klienta ako blob,
        nie cez obyčajný `<a href>`, aby prešlo rovnakou autentifikovanou cestou
        ako všetko ostatné; dočasná object URL sa hneď uvoľňuje, inak by si
        súbor držal pamäť po celú reláciu. Stav skenu sa zobrazuje pravdivo –
        `SKIPPED` je „uložené bez skenu“, nie predstieraný čistý verdikt – a
        tlačidlo na stiahnutie sa ponúka iba pri `downloadable`.
  - [x] Väzby v detaile. Klient pošle iba kľúč cieľovej úlohy; na identifikátor
        ho preloží to isté SovaQL vyhľadanie ako pri otváraní detailu. Vzťah sa
        zobrazuje z pohľadu otvorenej úlohy (`IS_BLOCKED_BY` namiesto `BLOCKS`),
        takže smer nemusí domýšľať používateľ. Doménové kódy `ISSUE_LINK_EXISTS`
        a `ISSUE_NOT_FOUND` majú vlastné hlásenia, ostatné padnú na všeobecné.
  - [x] Vytvorenie úlohy. Dovtedy sa úloha cez UI vôbec nedala založiť, hoci
        backend to vie od F5.1 – obrazovka bola len na čítanie. Formulár je
        inline na zozname, rovnako ako pri projektoch. **Typy úloh prichádzajú z
        konfigurácie projektu**, nie z klienta, a ponúkajú sa iba aktívne s
        publikovaným workflow; klient neposiela počiatočný stav ani verziu
        workflowu, tak ako to žiada `WORKFLOW-A-TYPY-ULOH.md`. Ponúkajú sa iba
        aktívne projekty – archivovaný nedáva žiadne projektové oprávnenie.
        Po úspechu sa vyčistí iba názov a opis; projekt a typ ostávajú, lebo
        ďalšia úloha býva rovnakého druhu.
  - [x] Kanban nástenka. **Nástenka je nutne per projekt** – stĺpce sú stavy
        toho projektu a dva projekty môžu mať úplne odlišné workflow; z rovnakého
        dôvodu, z akého neexistuje cross-project workflow, neexistuje ani
        cross-project nástenka. Presun karty je **prechod**, nie priamy zápis
        stavu: klient posiela `transition_id` a verziu, ktorú videl, rovnako ako
        detail, takže o legálnosti naďalej rozhoduje backend. Karta sa usadí v
        novom stĺpci až po súhlase servera, takže odmietnutý presun nikdy
        nenechá nástenku tvrdiť nepravdu. Dostupné presuny sa načítavajú **až na
        vyžiadanie** pre konkrétnu kartu – inak by každé otvorenie nástenky
        znamenalo jednu požiadavku na úlohu. Prechod vyžadujúci ďalšie pole
        (`resolution`) nástenka nespustí naslepo, ale odkáže na detail, kde je
        miesto sa naň spýtať. Route je `issues/board/:projectId`; odkaz vedie z
        detailu projektu, aby projects feature neimportovala issues feature.
  - [ ] Drag and drop nástenky (webflow §9.1) – dnes sa karta presúva cez
        tlačidlo „Presunúť“, čo je **povinná klávesnicová cesta** podľa WCAG a
        funguje bez myši. DnD je nadstavba nad tou istou akciou; polovičné DnD
        bez klávesnicového ekvivalentu by bolo horšie než žiadne, preto sa
        nedodalo naslepo.
  - [ ] Indikátor blokovania na karte – vyžadoval by načítanie väzieb pre každú
        kartu (N+1); potrebuje buď rozšírenie search projekcie, alebo hromadné
        načítanie väzieb.
  - [ ] Úplná sada stavov obrazovky (stale, offline, conflict).
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
| 2026-07-28 | F4 projekty (dokončenie) | Audit rozpracovaného stavu, vynútenie `visibility` pri čítaní, 8 chýbajúcich OpenAPI schém, kompletné projektové UI, PHPStan `max`, CS Fixer, route/OpenAPI parity (45 ciest), `npm run check` na Node 24.15.0, HTTP smoke a migračný down/up proti dočasnej PostgreSQL 16 | Prešlo: 33/33 smoke kontrol, 96 frontend testov v 34 súboroch (predtým 78/31), production build 537,81 kB (nad 500 kB warningom, pod 1 MB limitom), migrácia `Version20260727140000` sa čisto vrátila aj znovu aplikovala. Audit odhalil, že backend modul `Sova\Projects` bol dopísaný, ale nikdy nespustený: `openapi.json` referencoval 8 nedefinovaných schém (rozbitý kontrakt) a `GET /projects` vyžadoval `tenant.projects.manage`, takže bežný člen nevidel nič a stĺpec `visibility` sa nikdy nečítal. Beh odhalil dve ďalšie runtime chyby: `ARRAY_AGG` sa cez PDO vracia ako reťazec, takže hydratácia projektových rolí padala na 500 pri každom vytvorení súkromného projektu (opravené na `STRING_AGG`), a konfliktný `PROJECT_CODE_TAKEN` niesol field errors, ktoré `DomainProblemException` povoľuje iba pri validácii (500 namiesto 409). Overené: tenantová aj súkromná viditeľnosť, grant priamou rolou aj prepojenou skupinou vrátane odobratia, projektový manažér spravuje iba vlastný projekt, člen bez oprávnenia nevytvorí projekt a cross-tenant prístup je odmietnutý. PHPUnit `ProjectApiTest` čaká na CI (lokálne chýbajú `dom`/`xmlwriter`). |
| 2026-07-28 | F4 efektívne oprávnenia a PermissionGuard (uzavretie fázy) | Nové `listPermissions()` pre tenant/project/workgroup scope a `grantedPermissions()` so scope filtrom, `permissions` v tenantovom kontrakte, `TenantStore`, `permissionGuard`, skrytá navigácia; PHPStan `max`, CS Fixer, route/OpenAPI parity, `npm run check` na Node 24.15.0 | Prešlo: 101 frontend testov v 35 súboroch (predtým 96/34), z toho 5 nových pre guard a store. Kľúčové rozhodnutie overené testom: tenantová rola nesie aj projektové kódy (`project.view`, `issue.*`), preto sa zoznam filtruje cez `AuthorizationScope::supports()` – inak by UI tvrdilo, že člen má projektové oprávnenia na úrovni tenantu. Impersonácia nedostane `SUPERADMIN` zoznam. |
| 2026-07-28 | F5.1 typy úloh a runtime workflow | Nová migrácia (10 tabuliek), moduly `Sova\ProjectConfiguration` a `Sova\Issues`, 5 nových rout, `tests/Api/IssueApiTest.php`, PHPStan `max`, CS Fixer, route/OpenAPI parity (50 ciest, 95 schém), HTTP smoke a migračný down/up proti dočasnej PostgreSQL 16 | Prešlo: 46/46 smoke kontrol. Overené: projekt sa vytvorí s 5 typmi, 4 stavmi a publikovaným workflow v jednej transakcii; číslovanie `APP-1..APP-5` bez medzier aj pri odmietnutých vytvoreniach; počiatočný stav a verzia workflow prichádzajú z konfigurácie, nie od klienta; hierarchia odmietne sub-task bez rodiča, epic pod rodičom aj sub-task pod epicom; stará `expected_issue_version` vráti `409`, už vykonaný prechod `422`; história aj outbox zapíšu tri udalosti; člen tenantu bez projektovej roly dostane `403` na zoznam, vytvorenie aj prechod; cross-tenant čítanie a prechod vrátia `404`. Beh odhalil tri chyby v novom kóde: CHECK vyžaduje `published_at` už pri INSERT-e publikovanej verzie, outbox vynucuje `UPPER_SNAKE_CASE` názvy udalostí, a kontrola oprávnenia pri prechode bežala až po kontrole verzie a dostupnosti, takže neoprávnený člen vedel odvodiť stav workflow – oprava presunula `issue.transition` pred načítanie prechodu. PHPUnit `IssueApiTest` čaká na CI. |
| 2026-07-28 | F5.2 publikovanie konfigurácie | Rozšírenie modulu `Sova\ProjectConfiguration` (draft lifecycle, `WorkflowValidator`, `WorkflowConfigurationService`, `DoctrineWorkflowConfigurationRepository`, `DoctrineConfigurationEventPublisher`, adaptér `Sova\Issues\...\DoctrineIssueMigrator`), 6 nových rout (draft cez `->map(['POST','PUT'])`), `tests/Api/WorkflowConfigurationApiTest.php`, PHPStan `max`, CS Fixer, route/OpenAPI parita (56 ciest, 115 schém) a HTTP smoke publish+migrácie proti dočasnej PostgreSQL 16 | Prešlo: 25/25 smoke kontrol. Overené end-to-end: `POST/PUT …/draft` vytvorí a nahradí jediný draft (retain stavov podľa kódu, nové kódy založia nový projektový stav), `GET …/validation` a `…/impact` vrátia porušenia grafu a stavy vyžadujúce migračný cieľ; `POST …/publish` v jednej transakcii prepne aktívnu verziu, zmigruje úlohu z odobraného `IN_PROGRESS` na `OPEN`, zvýši revíziu na 2, zapíše `project_configuration_history WORKFLOW_PUBLISHED`, outbox `PROJECT_WORKFLOW_PUBLISHED` aj `issue_history`/outbox `ISSUE_MIGRATED` a starú verziu označí `RETIRED`; publish bez mapovania použitého stavu vráti `409 WORKFLOW_MIGRATION_REQUIRED`, stará revízia `409 PROJECT_CONFIG_VERSION_CONFLICT`. Parity check odhalil, že multi-verb route na rovnakom vzore musí byť jeden `->map` action (konvencia repozitára), preto sa `Create`/`Update` draft akcie zlúčili do `WorkflowDraftAction`. PHPUnit `WorkflowConfigurationApiTest` čaká na CI (lokálne chýbajú `dom`/`xmlwriter`). |
| 2026-07-28 | F5.1 runtime workflow pravidlá | Migrácia `Version20260728120000` (`issues.resolution`, `issues.resolved_at`, CHECK), runtime engine `TransitionRuleEvaluator` a VO `TransitionEffect`/`TransitionActor`, načítanie pravidiel do runtime cez `jsonb_agg` v `DoctrineProjectConfigurationRepository` (`RuleView` na `TransitionDetails.rules`), úpravy `IssueService`, `DoctrineIssueRepository`, prezentačných akcií a serializéra, 2 nové testy v `tests/Api/IssueApiTest.php`, PHPStan `max`, CS Fixer, route/OpenAPI parita (56 ciest, 115 schém) a HTTP smoke proti dočasnej PostgreSQL 16 | Prešlo: 26/26 smoke kontrol. Overené end-to-end na publikovanom workflow: podmienky `permission`/`assignee_or_manager` filtrujú ponuku aj vykonanie (fail-closed, „manager“ = `issue.assign`); `resolution_required` bez akcie zobrazí `required_fields: [resolution]`, prechod bez hodnoty vráti `422 ISSUE_TRANSITION_INVALID`, s hodnotou nastaví `resolution` a `set_resolved_at` doplní `resolved_at`; `set_resolution` fixuje hodnotu bez vstupu klienta (a vyprázdni `required_fields`); `clear_resolution`/`clear_resolved_at` pri reopen vyčistia oba stĺpce; verzia rastie po každom prechode; effect nesie `resolution` aj do outbox payloadu. Issue modul číta pravidlá iba cez `ProjectConfigurationRepository` (hranica modulov zachovaná). Validátor `required_field` ostáva zdokumentovaná hranica, kým neexistujú vlastné polia typu (§5.3). PHPUnit `IssueApiTest` čaká na CI (lokálne chýbajú `dom`/`xmlwriter`). |
| 2026-07-28 | F5.2 zmena typu úlohy (§5.4) | Migrácia `Version20260728130000` (backfill grantov `issue.change-type`), nové oprávnenie v `Permission`/`DefaultRole`/`PermissionCatalog`, `IssueService::changeType` + helpery, rozšírenie `IssueRepository`/`DoctrineIssueRepository` (`applyTypeChange`, `childHierarchyLevels`, `recordHistory` metadata), nová akcia `ChangeIssueTypeAction`, route `POST …/issues/{issueId}/type`, 9 nových testov v `tests/Api/IssueApiTest.php`, OpenAPI (57 ciest, `ChangeIssueTypeRequest`), PHPStan `max`, CS Fixer | Prešlo: **PHPUnit spustený lokálne** (extrahované `dom`/`xmlwriter`/`simplexml` .so z `php8.3-xml` cez `PHP_INI_SCAN_DIR`) – celá `IssueApiTest` 18/18, plná sada 172/174 (2 zlyhania sú predbežné a nesúvisiace: flaky `SensitivePayloadCipherTest` a už existujúci `WorkgroupApiTest`, oba padajú aj bez týchto zmien). Overené: mapovanie stavu naprieč zdieľaným workflowom drží `OPEN`, rovnaký typ `422 ISSUE_TYPE_UNCHANGED`, archivovaný cieľ `422 ISSUE_TYPE_INVALID`, stará verzia `409`, dieťa/rodič mimo hierarchie `422 HIERARCHY_INVALID`, disjunktný workflow žiada `target_status_id` (`409 ISSUE_TYPE_STATUS_MAPPING_REQUIRED`, cudzí stav `422 ISSUE_TYPE_STATUS_INVALID`), člen bez projektovej roly `403`, cross-tenant `404`; `issue_history ISSUE_TYPE_CHANGED` nesie metadáta typu a outbox zapíše udalosť. |
| 2026-07-28 | Odblokovanie lokálneho overovania | `apt-get download php8.3-xml` + `dpkg-deb -x` a `PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:<scratch>/conf.d`; PostgreSQL 16 na porte 5433 podľa `.env`; Node 24.15.0 cez nvm | Prešlo: PHPUnit sa spustil bez CI. Prvý plný beh 174 testov / 650 assertions so 105 preskočenými (bez DB), s `RUN_DATABASE_TESTS=true` 174 testov / 3 184 assertions a jedno zlyhanie. `pdo_pgsql` je funkčný; varovania o `mysqli`/`pdo_mysql`/`pdo_sqlite` sú neškodné duplicitné pokusy o načítanie a netýkajú sa projektu. Tým padla posledná otvorená položka F1.1. |
| 2026-07-28 | F4 oprava `WorkgroupApiTest` | Diagnostika jediného zlyhávajúceho testu, porovnanie s commitnutou verziou `DoctrineEffectivePermissionProvider` | Prešlo: chyba bola **v teste, nie v kóde**. Test si v polovici scenára archivoval skupinu (`status: ARCHIVED`) a potom očakával, že manažér ju ďalej spravuje. Podmienka `workgroup.status = 'ACTIVE'` je pritom rovnaká aj v commitnutej verzii, takže správanie sa nikdy nezmenilo – F4 log ho označil za overený na základe manuálneho HTTP smoke, PHPUnit vtedy lokálne nebežal. Test prepísaný tak, že manažérske práva (pridanie člena, zoznam, odobratie) overuje na aktívnej skupine a archiváciu robí až nakoniec; navyše pribudlo chýbajúce bezpečnostné tvrdenie, že archivácia manažérovi odoberie jeho workgroup rozsah (`403` na reaktiváciu) a vrátiť ju vie iba tenantové `tenant.workgroups.manage` (`200`). Pravidlo doplnené do `AUTHORIZATION.md`. |
| 2026-07-28 | F5.3 analyzátor SovaQL v1 | Audit rozpracovaného stavu, PHPStan `max`, CS Fixer, nový `tests/Domain/SovaQlAnalyzerTest.php`, plný `composer check` | Prešlo: **246/246 testov, 3 530 assertions** (predtým 174 s jedným zlyhaním), z toho 72 nových pre analyzátor. Audit odhalil, že modul `Sova\Issues\Domain\QueryLanguage` (1 994 riadkov) bol dopísaný, ale **nikdy nespustený**: PHPStan hlásil 3 chyby (dvakrát zbytočný `?->` naľavo od `??` v `CanonicalPrinter`, nedokázateľný offset 6 v `SemanticValidator::isIsoTimestamp`) a neexistoval jediný test. Overené proti §4: príklady zo špecifikácie, precedencia `a OR b AND c` = `a OR (b AND c)` vrátane zachovania nutných a zahodenia zbytočných zátvoriek, kanonizácia ako pevný bod (`analyze(canonical) === canonical`, na čom visí hash cursora), alias `summary` → `title`, voliteľný filter, escapovanie reťazcov, offsety v kódových bodoch (nie bajtoch) a všetkých 11 chybových kódov, ktoré analyzátor vôbec môže vydať, aj s presnými rozsahmi. Beh odhalil rozpor priamo v špecifikácii §4.12: limit 100 AST uzlov a limit 100 hodnôt v jednom `IN` si protirečia, lebo každá hodnota je AST uzol – `IN` so 100 hodnotami stojí 101 uzlov. Prísnejší uzlový limit rozhoduje prvý (deny by default), praktický strop je 99 hodnôt; správanie zostalo nezmenené a rozhodnutie o zmene limitu patrí prevádzke cez `QueryLimits`. |
| 2026-07-28 | Oprava flaky `SensitivePayloadCipherTest` | Analýza base64url kódovania, 10 opakovaných behov | Prešlo 10/10 (predtým padal približne v štvrtine behov a plán ho viedol ako „flaky"). Príčina bola **v teste, nie v šifre**: ciphertext je base64url a test menil jeho posledný znak, ktorý pri tejto dĺžke nesie iba 2 významové bity – zvyšné 4 sú výplň, takže zmena často dekódovala na identické bajty a k poškodeniu vôbec nedošlo. Či test prejde, záviselo od náhodného nonce. Test teraz mení znak v strede reťazca, kde každý znak nesie plných 6 významových bitov, a navyše tvrdí, že sa reťazec naozaj zmenil. |
| 2026-07-28 | F5.3 backend vyhľadávania | Nový `Sova\Issues\Application\Search` (9 portov/DTO, `IssueSearchService`, `CursorCodec`) a `Infrastructure\Persistence` (`IssueQueryCompiler`, `DoctrineSearchScopeProvider`, `DoctrineReferenceResolver`, `DoctrineIssueSearchRepository`, `DoctrineQueryRateLimiter`), migrácia `Version20260728140000`, 3 nové routy, `tests/Api/IssueSearchApiTest.php`, OpenAPI (60 ciest, 124 schém), PHPStan `max`, CS Fixer, plný `composer check` a `EXPLAIN` nad 40 000 riadkami | Prešlo: **260/260 testov, 4 114 assertions**, z toho 14 nových pre vyhľadávanie; migrácia sa čisto vrátila aj znovu aplikovala; všetky `$ref` v kontrakte sa rozlišujú. Overené end-to-end: prázdny dotaz vráti celý autorizovaný rozsah, filtre podľa typu/projektu/stavu/kategórie, `assignee = currentUser()` a `user("…")` cez membership id, `IS (NOT) EMPTY`, fulltext, `ORDER BY priority DESC` v poradí závažnosti (nie abecedne), cursor prejde 5 riadkov po dvoch bez duplicít aj medzier, zmena dotazu, zmena triedenia aj podpis znak navyše vrátia `422 QUERY_CURSOR_INVALID`, člen bez projektovej roly nevidí nič, nedostupný kód je nerozlíšiteľný od neexistujúceho, cudzí tenant `404`, validácia vracia štruktúrované rozsahy, metadata neinzerujú polia bez úložiska, veľkosť stránky sa oreže na 100 a nadlimitný dotaz vráti `QUERY_TOO_LONG`. Merania odhalili dve veci, ktoré by sa inak dostali do produkcie: `title ~` robil **seq scan** (B-tree nad `LOWER(title)` neposlúži hľadaniu so začiatočným wildcardom), preto pribudol trigramový GIN nad `title` presne v tvare, ktorý compiler generuje – plán sa zmenil na `Bitmap Index Scan`; a prvé meranie keysetu ukázalo seq scan len preto, že syntetické dáta mali identický `updated_at` – po rozptýlení časov plán používa `idx_issues_project_updated`. Fulltext používa `idx_issues_fulltext`, triedenie podľa názvu `idx_issues_title_lower`. |
| 2026-07-28 | F6.1 komentáre, zmienky a história | Migrácia `Version20260728150000` (`issue_comments`, `issue_comment_mentions`, `changes_issue`), doména `CommentBodyValidator`/`MentionExtractor`, aplikačná `CommentService` s portami, `DoctrineCommentRepository`/`DoctrineHistoryRepository`/`DoctrineCommentEventPublisher`, 3 nové routy, `tests/Domain/CommentBodyTest.php` (27) a `tests/Api/IssueCommentApiTest.php` (11), OpenAPI (63 ciest, 130 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **298/298 testov, 4 444 assertions** (predtým 260/4 114). Overené: komentár sa uloží ako CommonMark source a objaví sa v histórii aj outboxe; autor upraví vlastný komentár vo window (verzia 2, `edited_at`); odstránenie je soft a idempotentné a zmaže text aj zmienky; zmienka člena s prístupom sa uloží, zmienka člena bez projektovej roly aj neexistujúceho membershipu vráti rovnaké `422 COMMENT_MENTION_NOT_ALLOWED`; `<script>` v tele je `422`, ten istý text vo fenced bloku prejde; cudzí komentár nevie iný člen upraviť ani zmazať (`403`), moderátor áno; člen bez projektového prístupu nevidí komentáre ani históriu (`403`); komentár inej úlohy je `404 COMMENT_NOT_FOUND`. Beh odhalil **rozpor v návrhu histórie**: `issue_history` mala `UNIQUE (issue_id, issue_version)`, čo platilo, kým históriu písali len prechody meniace verziu. Špecifikácia §6.7 však komentáre do histórie vyžaduje a komentár verziu meniť nesmie – bumpol by optimistický zámok každému rozpísanému editorovi. Namiesto zrušenia ochrany je výnimka explicitná: nový stĺpec `changes_issue` a parciálny unikátny index `WHERE changes_issue`, takže prechod sa naďalej nedá zapísať dvakrát. Druhá chyba: DBAL bez explicitného typu posiela PHP `false` ako prázdny reťazec a PostgreSQL ho pre boolean odmietne – doplnené `ParameterType::BOOLEAN`. |
| 2026-07-28 | F6.2 sledovatelia a väzby úloh | Migrácia `Version20260728160000` (`issue_watchers`, `issue_links`, `uniq_issues_tenant_id`), doména `IssueLinkType`, `IssueLinkService` a porty, `DoctrineWatcherRepository`/`DoctrineIssueLinkRepository`, automatické pravidlá v `IssueService` a `CommentService`, 4 nové routy, rozsvietené SovaQL pole `watcher`, `tests/Api/IssueCollaborationApiTest.php` (12), OpenAPI (67 ciest, 136 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **310/310 testov, 4 992 assertions** (predtým 298/4 444), 12 nových na prvý beh. Overené: autor sleduje, čo založil (`source: AUTHOR`), komentujúci sa prihlási (`COMMENT`); **explicitné odhlásenie prežije komentovanie** a opätovné prihlásenie funguje; obe akcie sú idempotentné. Väzba `BLOCKS` sa z druhej strany číta ako `IS_BLOCKED_BY` s rovnakým `id` a `outward: false`; self-link `422 ISSUE_LINK_SELF`, duplicita aj zrkadlový pár `409 ISSUE_LINK_EXISTS`, neznámy typ `422 ISSUE_LINK_INVALID`; väzba sa dá odstrániť z ktoréhokoľvek konca a zapíše `ISSUE_LINK_REMOVED` do histórie; väzba inej úlohy je `404`. Bezpečnostne podstatné: úloha v projekte mimo `issue.view` rozsahu čitateľa sa v zozname väzieb vôbec neobjaví a pokus o väzbu na ňu vráti rovnaké `404 ISSUE_NOT_FOUND` ako na neexistujúce UUID; člen bez projektovej roly dostane `403` na sledovateľov aj väzby. Pole `watcher` v SovaQL je odteraz podporované – `watcher = currentUser()`, `NOT watcher = currentUser()` aj `watcher IN (user("…"))` vracajú správne množiny a metadata endpoint ho už inzeruje, kým `labels` ostáva skryté. |
| 2026-07-28 | F6.4 outbox worker a in-app notifikácie | Generický `OutboxDispatcher` (`Sova\Shared`), porty `OutboxEvent`/`OutboxHandler`, nový modul `Sova\Notifications` (`IssueEventNotifier`, `DoctrineNotificationRepository`, 2 HTTP akcie), migrácia `Version20260728170000`, `bin/outbox-worker.php`, 2 nové routy, `tests/Api/NotificationApiTest.php` (12), OpenAPI (69 ciest, 140 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **322/322 testov, 5 359 assertions** (predtým 310/4 992). Overené: notifikácia nevznikne v požiadavke, ktorá udalosť spôsobila, ale až po behu workera; **replay spracovanej udalosti nezduplikuje schránku** (overené vynulovaním `processed_at`); aktér nedostane notifikáciu o vlastnom komentári ani prechode; zmienka prebíja bežnú notifikáciu o komentári; označenie prečítaného mení iba vlastnú schránku a cudzí identifikátor sa ticho preskočí (`updated: 0`) namiesto priznania existencie; `unread=true` filter a hromadné označenie fungujú; člen tenantu bez projektovej roly nedostane nič; dispatcher nechá udalosti bez registrovaného handlera na pokoji, takže neberie riadky e-mailovým workerom. Beh odhalil **chybu v návrhu publika**: handler zisťoval príjemcov v čase doručenia, nie v čase udalosti, takže člen, ktorý začal sledovať až komentárom, dostal notifikáciu o vytvorení úlohy, s ktorou nemal nič spoločné. Oprava: `ISSUE_CREATED` notifikuje výhradne riešiteľa uvedeného v udalosti (a nie, ak je totožný s autorom), nie aktuálnu množinu sledovateľov. Pri komentári a prechode je neskoré určenie publika ponechané ako vedomé a zdokumentované správanie. |
| 2026-07-28 | F6.3 privátne prílohy | Migrácia `Version20260728180000`, doména `AttachmentPolicy`/`ScanStatus`, porty `AttachmentStorage`/`AttachmentScanner`/`AttachmentRepository`, `AttachmentService`, `FilesystemAttachmentStorage`, `UnavailableAttachmentScanner`, 2 HTTP akcie, 2 nové routy, `tests/Domain/AttachmentPolicyTest.php` (25) a `tests/Api/IssueAttachmentApiTest.php` (10), OpenAPI (71 ciest, 143 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up, runtime overenie produkčnej poistky | Prešlo: **357/357 testov, 5 718 assertions** (predtým 322/5 359), 35 nových a všetky na prvý beh. Overené: PNG sa uloží, objaví v zozname a v histórii (`ATTACHMENT_ADDED`), stiahne sa byte-identicky s `nosniff` a `Content-Disposition: attachment`; **obsah rozhoduje o type, nie názov** – PHP skript pomenovaný `.png`, HTML v `.txt` aj skutočný PNG pomenovaný `.pdf` vrátia `422 ATTACHMENT_TYPE_NOT_ALLOWED` a na disku nezostane nič; súbor nad 25 MiB `422 ATTACHMENT_TOO_LARGE`; dva súbory v jednej požiadavke `422`; **uložený objekt sa nevolá podľa uploadu** a kľúč zodpovedá serverom generovanému tvaru; člen bez projektového prístupu ani nenahrá ani nestiahne (`403`); nahrávateľ zmaže vlastný súbor a bajty idú okamžite, cudzí súbor vyžaduje `attachment.moderate`; príloha inej úlohy je `404`. Produkčná poistka overená za behu: `APP_ENV=production` s `ATTACHMENT_SCANNER=none` odmietne zostaviť skener. |
| 2026-07-28 | F6.5 e-mailové notifikácie a nastavenia (uzavretie fázy) | Migrácia `Version20260728190000`, doména `NotificationKind`/`ChannelPreference`, zdieľaný `NotificationAudience`, porty `PreferenceRepository`/`MemberDirectory`/`NotificationMailer`, `NotificationEmailHandler`, `SymfonyNotificationMailer`, `NotificationPreferencesAction`, 1 nová route, 6 nových testov, OpenAPI (72 ciest, 145 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **363/363 testov, 5 889 assertions** (predtým 357/5 718). Overené: predvolene je in-app zapnutý a e-mail vypnutý pre všetky štyri typy; zamknutý in-app kanál pri pridelení a zmienke sa **neodmietne, ale opraví** (pravidlo je v doméne, nie v HTTP vrstve), odomknutý sa naozaj vypne a typ vynechaný z požiadavky sa vráti na predvolenú hodnotu; vypnutý in-app kanál zastaví doručenie, ale zmienka prejde aj tak; neznámy typ aj nebooleovská hodnota vrátia `422`; so zapnutým e-mailom beží druhý handler a udalosť sa aj tak potvrdí práve raz. Bezpečnostne najdôležitejšie: **sledovateľ, ktorý medzitým stratil projektovú rolu, notifikáciu nedostane** – audience znovu overuje `issue.view` v čase doručenia, lebo sledovanie stratu prístupu prežije. |
| 2026-07-28 | F7.1 zoznam a detail úlohy | 24 nových modelov a 11 metód v API klientovi, `IssueWorkspaceService`, nová `issue-list-page`, prepísaná `issue-detail-page` (skeleton s hardkódovanými dátami nahradený), 39 i18n kľúčov × 6 jazykov, `issue-workspace.service.spec.ts`, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **107 testov v 36 súboroch** (predtým 101/35), production build 538,82 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: vyhľadávanie ide cez `POST`, takže dotaz sa nedostane do URL ani proxy logu; kľúč sa normalizuje a prekladá cez `key = …` s `page_size: 1`; prechod nesie `expected_issue_version`; komentár sa posiela ako CommonMark source; sledovanie používa `PUT`/`DELETE` podľa zámeru. Zámerné rozhodnutia: zoznam úloh **je** SovaQL vyhľadávanie (žiadny druhý listovací endpoint, teda rovnaký rozsah aj limity), cursor sa pri novom hľadaní zahadzuje, sekcie detailu sa načítavajú nezávisle a text komentára sa maže až po potvrdení serverom. |
| 2026-07-28 | F7.2 prílohy a väzby v detaile | 12 nových modelov a 7 metód v API klientovi, rozšírený `IssueWorkspaceService` a `issue-detail-page` o dve sekcie, 25 i18n kľúčov × 6 jazykov, 4 nové testy, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **111 testov v 36 súboroch** (predtým 107/36), production build 539,57 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: upload je `FormData` a **klient nenastavuje `Content-Type`**, aby boundary doplnil prehliadač; sťahovanie ide cez HTTP klienta s `responseType: 'blob'`, teda rovnakou autentifikovanou cestou ako ostatné volania, nie cez obyčajný odkaz; väzba sa posiela ako identifikátor plus vzťah; odstránenie väzby ide na vnorenú routu. Zámerné rozhodnutia: dočasná object URL sa po kliknutí hneď uvoľňuje, stav skenu sa zobrazuje pravdivo (`SKIPPED` = „uložené bez skenu“, nie predstieraný `CLEAN`), tlačidlo Stiahnuť sa ponúka iba pri `downloadable`, a vzťah väzby sa zobrazuje z pohľadu otvorenej úlohy, takže smer nedomýšľa používateľ. |
| 2026-07-28 | F7.3 vytvorenie úlohy cez UI | 3 nové modely a 2 metódy v API klientovi, `IssueWorkspaceService.projects()`/`configuration()`/`create()`, inline formulár na zozname, 18 i18n kľúčov × 6 jazykov, 3 nové testy, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **114 testov v 36 súboroch** (predtým 111/36), production build 539,83 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: vytvorenie ide na projektovú routu a telo **neobsahuje počiatočný stav ani verziu workflowu** (obe určuje konfigurácia); typy sa čítajú z `…/configuration`. Beh odhalil vlastné porušenie architektonického pravidla: zoznam projektov som najprv ťahal cez `ProjectAdministrationService`, čo je import do internej časti inej feature. Opravené – `IssueWorkspaceService` má vlastnú metódu nad zdieľaným API klientom a pribudol test, ktorý to drží. Zámerné rozhodnutia: ponúkajú sa iba aktívne projekty a iba aktívne typy s publikovaným workflow, a po úspechu sa čistí len názov a opis. |
| 2026-07-28 | F7.4 Kanban nástenka | Doplnené `to_status`/`is_primary`/`position` do modelu prechodu a `statuses` do konfigurácie projektu, nová `issue-board-page`, route `issues/board/:projectId`, odkaz z detailu projektu, 11 i18n kľúčov × 6 jazykov, 2 nové testy, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **116 testov v 36 súboroch** (predtým 114/36), production build 539,83 kB – **nezmenený**, nástenka je lazy chunk. Overené testom: dotaz nástenky je zúžený na jeden projekt a dostupné presuny sa čítajú pre konkrétnu úlohu, nie pre každú kartu. Zámerné rozhodnutia: nástenka je per projekt (stĺpce sú stavy toho projektu), presun je prechod s `expected_issue_version` a karta sa usadí až po súhlase servera, prechod vyžadujúci `resolution` sa nespúšťa naslepo ale odkáže na detail, a route žije v issues feature s odkazom z projektu, aby projects feature neimportovala issues. **Nedodané a zapísané:** drag and drop (dnes tlačidlo „Presunúť“, čo je povinná klávesnicová cesta podľa WCAG; DnD je nadstavba nad tou istou akciou) a indikátor blokovania na karte (vyžadoval by N+1 načítanie väzieb). |
| 2026-07-28 | F7.5 textový editor SovaQL | 3 nové modely a 2 metódy v API klientovi, nový `QueryEditorComponent` (features/issues/components), zapojený do zoznamu namiesto obyčajného poľa, **21 i18n kľúčov × 6 jazykov vrátane všetkých 13 `query.errors.*`**, `query-editor.component.spec.ts` (3), `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **119 testov v 37 súboroch** (predtým 116/36), production build 542,48 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: editor sa **nepýta počas písania** (nič neodíde pred utíchnutím 400 ms), pošle presne to, čo používateľ napísal, hlášku vezme z katalógu cez serverový `message_key` a rozsah vyreže z dotazu; prázdny dotaz sa nekontroluje vôbec; referencia polí a limity prichádzajú z `/metadata`, nie z konštánt v klientovi. Beh odhalil, že tento zoneless setup nemá `zone-testing`, takže `fakeAsync`/`tick` nefungujú – debounce sa testuje cez Vitest `vi.useFakeTimers()`. Zámerné rozhodnutia: validácia zostáva serverová (klientská gramatika by sa od jazyka rozišla), neznámy `message_key` sa overuje proti katalógu, a rozsah sa vyrezáva cez spread operátor, lebo offsety sú kódové body a `substring` počíta UTF-16 jednotky. |
| 2026-07-28 | F7.6 vizuálny filter builder | **Backend:** `BasicFormProjector` + VO `BasicForm`/`BasicCondition`/`BasicSort`, `printValue()` sprístupnené v `CanonicalPrinter`, `basic_form` vo validačnej odpovedi, OpenAPI schéma `IssueQueryBasicForm`, 13 nových testov projekcie. **Frontend:** prepínač režimov v `QueryEditorComponent`, 6 i18n kľúčov × 6 jazykov, 3 nové testy. Obe brány | Prešlo: backend **376/376 testov, 5 944 assertions** (predtým 363/5 889); frontend **122 testov v 37 súboroch** (predtým 119/37), build 542,51 kB pod 1 MB limitom. Overené: konjunkcia sa rozloží na ploché podmienky vrátane `IN`, `NOT IN`, `IS EMPTY` a funkčných hodnôt; **`OR`, `NOT` aj zoskupenie vrátia `representable: false`** a UI ich ukáže len na čítanie – test priamo tvrdí, že sa nevykreslia žiadne editovateľné riadky a pôvodný text zostane nedotknutý; triedenie prežije aj pri nereprezentovateľnom filtri; odstránenie podmienky poskladá text z kanonických kúskov servera, zachová `ORDER BY` a nechá výsledok znovu zvalidovať. Beh odhalil krehký test: referenciu polí otváral podľa poradia tlačidiel, čo prepínač režimov rozbil – prepísaný na hľadanie podľa textu. Zámerné rozhodnutie: projekcia žije na serveri, lebo klient, ktorý by si text parsoval sám, by bol druhá gramatika s právom nesúhlasiť. |
| 2026-07-28 | F7.7a základ uložených dotazov | Presun `saved-query.share` z `PROJECT` na `TENANT` scope, nové `saved-query.create` a `saved-query.manage`, úprava matice rolí a katalógu, migrácia `Version20260728200000` (presun grantov + `saved_queries`, `saved_query_grants`, `saved_query_favourites`), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **376/376 testov, 5 963 assertions**. Migrácia sa čisto vracia aj aplikuje. **Beh odhalil dve chyby, obe moje.** Prvá je vážna: odstránením riadku `self::SavedQueryShare => PermissionScope::Project` stratili *všetky* projektové oprávnenia svoju vetvu `match` a prepadli do workgroup scope – vytvorenie úlohy začalo vracať `403`. Chytila to existujúca sada (`IssueApiTest`), nie statická analýza: PHPStan takú zmenu vidí ako platný kód. Poučenie do budúcna: pri mazaní vetvy `match` s viacerými prípadmi treba skontrolovať, či posledný prípad nesie `=>`. Druhá bola drobná – down migrácia mala nesprávny `ON CONFLICT` cieľ, lebo `project_role_permissions` má kľúč `(project_id, role_id, permission_code)` bez `tenant_id`. Počet non-system oprávnení v `TenantRoleApiTest` upravený z 33 na 35. |
| 2026-07-28 | F7.7b API uložených dotazov | Doména `SavedQueryAccess`/`SavedQueryVisibility`/`SavedQueryName`, port `SavedQueryRepository`, `SavedQueryService`, `DoctrineSavedQueryRepository`, `SavedQuerySerializer`, `SavedQueryContext`, 5 HTTP akcií, 5 nových ciest, DI, `tests/Api/SavedQueryApiTest.php` (12), OpenAPI (77 ciest, 156 schém), PHPStan `max`, CS Fixer, plný `composer check` aj `npm run check` | Prešlo: backend **388/388 testov, 6 331 assertions** (predtým 376/5 963), 12 nových a všetky na prvý beh; frontend nedotknutý, **122 testov v 37 súboroch**, build 542,51 kB. Overené testom: uloží sa **serverová kanonická forma**, nie klientov zápis, a surový text ostáva vedľa nej; neplatný dotaz vráti `422 SAVED_QUERY_INVALID` a neuloží sa nič; meno koliduje aj pri inej veľkosti písmen a medzerách, ale archivácia ho uvoľní; **cudzí súkromný dotaz nie je zakázaný, je neviditeľný** – v zozname chýba a detail vráti `404`; grant `VIEW` dotaz sprístupní, ale editáciu odmietne `403`; grant `EDIT` obsah zmení, archiváciu však nie; `PUT /grants` vynechaného principála naozaj odoberie a prázdna množina vráti dotaz do `PRIVATE`; grant na neexistujúceho alebo dvojitého principála vráti `422 SAVED_QUERY_GRANT_INVALID`; stará verzia `409`; obľúbenie je osobné a idempotentné; `saved-query.manage` vidí zdieľané dotazy bez vlastného grantu. Bezpečnostne najdôležitejšie: **zdieľanie neprenáša prístup k úlohám** – vlastník cez svoj dotaz úlohu nájde, ale obdarovaný člen bez projektovej roly dostane na ten istý dotaz `422 QUERY_INVALID`, teda presne to, čo by dostal pri preklepe v kóde projektu, takže sa cez dotaz nedá zistiť, že projekt existuje. Beh odhalil, že `docs/openapi.json` je formátovaný Prettierom so **šírkou 80** (nie 100 ako frontend); doplnenie ciest cez `json.dump` súbor preformátovalo a muselo sa vrátiť späť. |
| 2026-07-28 | F7.7c UI uložených dotazov | 11 nových modelov a 7 metód v API klientovi, 8 metód v `IssueWorkspaceService` (vrátane `members()`/`workgroups()` nad zdieľaným klientom), nové komponenty `SavedQueryPanelComponent` a `SavedQueryGrantsComponent` zapojené pod editorom na zozname úloh, **37 i18n kľúčov × 6 jazykov**, 12 nových testov v 2 súboroch, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **134 testov v 39 súboroch** (predtým 122/37), production build 543,35 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: načítanie dotazu vloží **surový text**, nie kanonickú formu; uloženie posiela iba názov a dotaz – žiadnu kanonickú formu ani viditeľnosť, obe sú serverove; prepísanie nesie `expected_version`, ktoré panel videl, a v tele **nie je viditeľnosť**, takže editor cudzí dotaz nezverejní; `SAVED_QUERY_NAME_TAKEN` má vlastnú hlášku, nie generickú; **držiteľ grantu `EDIT` vidí „Prepísať“, ale nie „Archivovať“ ani „Zdieľanie“** – `viewer_access` opisuje volajúceho, nie riadok; bez `saved-query.create` sa ukladanie nezobrazí vôbec; archivované dotazy sú mimo bežného zoznamu, ale za rozbaľovacím počtom, nie zamlčané; editor zdieľania posiela **celú množinu** (odobratý principál sa neposiela ako mazanie, jednoducho v novej množine nie je), grant skupiny nesie `membership_id: null`, deaktivovaný člen sa medzi kandidátmi neponúka, a text nahlas hovorí, že zdieľanie nedáva prístup k úlohám. Beh odhalil, že jsdom formuláre sám neodosiela – submit sa v teste vyvolá udalosťou tam, kde by ho vyvolal prehliadač. Zámerné rozhodnutie: volajúci bez členstva panel nedostane vôbec (`SAVED_QUERY_MEMBERSHIP_REQUIRED` nie je chyba na hlásenie, len neaplikovateľnosť). |
| 2026-07-28 | F7.8a osobné dashboardy | Tri nové oprávnenia (`dashboard.create`, `dashboard.update-own`, `dashboard.delete-own`) + katalóg + matica rolí, migrácia `Version20260728210000` (`dashboards`, `dashboard_widgets`, `membership_dashboard_preferences`), modul `Dashboards` (doména, `DashboardService`, `DoctrineDashboardRepository`, serializer, `DashboardContext`, `DashboardInput`, 5 akcií), 6 nových ciest, DI, `tests/Api/DashboardApiTest.php` (11), OpenAPI (82 ciest, 161 schém), PHPStan `max`, CS Fixer, plný `composer check`, migračný down/up | Prešlo: **399/399 testov, 6 683 assertions** (predtým 388/6 331), 11 nových a všetky na prvý beh. Migrácia sa čisto vracia aj aplikuje. Overené testom: prvý dashboard sa stane predvoleným, druhý už nie; meno koliduje aj pri inej veľkosti písmen a medzerách, ale menný priestor je **vlastníkov**, takže iný člen rovnaké meno použije; **cudzí dashboard je neviditeľný** – `GET`, `PATCH`, `DELETE`, `default`, `active` aj `copy` vrátia zhodne `404 DASHBOARD_NOT_FOUND`; presun predvoleného nechá práve jeden, nikdy dva ani žiadny; posledný dashboard sa nedá zmazať (`409 LAST_DASHBOARD_REQUIRED`); zmazanie predvoleného povýši ďalší v poradí; premenovanie nesie verziu a stará vráti `409`; **čítanie nemení aktívny dashboard**, mení ho iba `PUT …/active`; a keď cieľ preferencie zanikne, zoznam spadne späť na predvolený. Zámerné odchýlky od §12 špecifikácie, obe zapísané: aktívny dashboard dostal vlastný `PUT …/active` (zoznam endpointov ho nemal, ale zápis vedľajším účinkom `GET` by prefetchu dovolil presunúť človeku landing page) a identifikátory widgetov pri kopírovaní razí PHP ako UUIDv7, nie `gen_random_uuid()`, lebo stabilné poradie widgetov padá späť na `id`. |
| 2026-07-29 | F7.8b widget registry a layout | Doména `WidgetType`, `WidgetDimension`, `WidgetDefinition`, `WidgetRegistry`, `WidgetConfigurationValidator`, `WidgetPlacement`, `DashboardLayout`; `WidgetService` + `DoctrineWidgetRepository`; port `SavedQueryUsageProbe` so `WidgetSavedQueryUsageProbe`; `WidgetSerializer`, 4 akcie, 4 nové cesty, DI; `tests/Api/DashboardWidgetApiTest.php` (10), OpenAPI (86 ciest, 169 schém), PHPStan `max`, CS Fixer, plný `composer check` | Prešlo: **409/409 testov, 7 031 assertions** (predtým 399/6 683), 10 nových. Overené testom: registry vracia **kľúče katalógu, nie text**, a žiadne pole nepomenúva komponent; uloží sa iba normalizovaná konfigurácia – `onclick` aj `component` z požiadavky do úložiska nedorazia, chýbajúca `visualization` sa doplní na `BAR`; dimenzia mimo typu, rovnaké pole na oboch osiach matice, rozsah `4000` dní aj neznámy `type_key` vrátia `422 WIDGET_CONFIGURATION_INVALID`; **widget nemôže ukazovať na nedosiahnuteľný dotaz** – cudzí súkromný aj vymyslený identifikátor vrátia zhodne `404 WIDGET_DATA_SOURCE_NOT_FOUND`, ale zdieľaný dotaz s grantom widget nakŕmi; layout odmietne prekrytie, podmieru, presah mriežky aj čiastočné rozloženie, stará verzia vráti `409` a **nič sa nepohne** – odmietnuté rozloženie sa neaplikuje spolovice; nový widget pristane pod všetkým, čo už na dashboarde je; widgety cudzieho dashboardu sú nedosiahnuteľné (`404 DASHBOARD_NOT_FOUND`, teda ešte pred akýmkoľvek pravidlom widgetu); kópia dashboardu duplikuje widget, ale zoznam dotazov ostáva jeden; a dotaz, ktorý widget používa, sa nedá archivovať (`409 SAVED_QUERY_IN_USE`), po odstránení widgetu áno. Beh odhalil, že `DomainProblemException` dovoľuje field errors iba pri validačných problémoch – počet závislostí preto cestuje v `detail`, nie v `errors`. |
| 2026-07-29 | F7.8c dáta widgetov | `QueryPlan` + `IssueSearchService::plan()` (vyňatá bezpečnostná postupnosť), `AggregationField`/`AggregationBucket`/`AggregationCell`/`TimeSeriesEvent`/`TimeSeriesBucket`/`TimeSeriesPoint`, port `IssueAggregationRepository` a `DoctrineIssueAggregationRepository`, verejná `IssueAggregationService`, `WidgetDataService`, `WidgetDataAction`, 1 nová cesta, DI, `tests/Api/WidgetDataApiTest.php` (9), OpenAPI (87 ciest, 175 schém), PHPStan `max`, CS Fixer, plný `composer check` | Prešlo: **418/418 testov, 7 449 assertions** (predtým 409/7 031), 9 nových. Overené testom: počet zodpovedá počtu úloh; **ten istý widget spočíta iba to, na čo čitateľ dosiahne** – vlastník vidí 1, obdarovaný člen bez projektovej roly 0, pretože rozsah sa aplikuje pred agregáciou, nie na jej výsledok; rozdelenie zoskupuje podľa nastavenej dimenzie a radí podľa počtu; **prázdny bucket sa hlási**, kým sa nevypne (graf, ktorý ticho zahodí nepridelené, dá menej než celok a zavádza); matica počíta obe osi naraz; zoznam vracia prvú stránku v poradí **samotného dotazu**, widget netriedi za chrbtom autora; časový rad dopĺňa prázdne buckety nulou (8 bodov pre 7-dňové okno), takže medzera je nula, nie chýbajúci bod na interpoláciu; porovnanie vráti dve série; a keď vlastník odoberie grant, widget vráti `404 WIDGET_DATA_SOURCE_NOT_FOUND` — zdroj sa číta pri každom načítaní, nie z cache — pričom zvyšok dashboardu sa naďalej načíta. Zámerné rozhodnutia: agregácia plánuje dotaz cez `IssueSearchService::plan()`, takže bezpečnostná postupnosť žije na jednom mieste a nemôže sa rozísť; `CLOSED` sa z časových radov vypustil, kým úlohy nemajú `closed_at`; strop matice je na **bunkách**, nie na osi zvlášť, lebo limit na os by aj tak dovolil súčin oboch. Beh odhalil vlastnú chybu v teste: `?? 'missing'` skolabuje práve to `null`, ktoré má tvrdiť. |
| 2026-07-29 | F7.8d štartovacia predloha dashboardu | Doména `StarterTemplate`/`TemplateQuery`/`TemplateWidget`, `StarterDashboardProvisioner`, `SavedQueryService::availableName()` a `DashboardService::availableName()`, provisioning v `DashboardsAction` (GET), `DashboardTemplateAction`, 1 nová cesta, `tests/Domain/StarterTemplateTest.php` (6) a `tests/Api/DashboardTemplateApiTest.php` (8), OpenAPI, PHPStan `max`, CS Fixer, plný `composer check` | Prešlo: **432/432 testov, 7 788 assertions** (predtým 418/7 449), 14 nových. Overené testom: manifest je platný a už kanonický SovaQL, nenesie UUID ani osobu, nepoužíva pole bez úložiska, každý widget ukazuje na deklarovaný dotaz a žiadny dotaz nezostane nevykreslený, rozloženie prejde tým istým validátorom ako klientske a preset sa uloží presne tak, ako je napísaný; prvé načítanie zoznamu vytvorí dashboard so 4 widgetmi nad 4 **súkromnými** dotazmi člena, ďalšie načítania už nič (žiadna druhá dávka dotazov) a **preferencia „aktívny“ ostane nezapísaná**; každý widget predlohy sa naozaj načíta (`200`), takže manifest je nielen uložiteľný, ale aj spustiteľný cez compiler a rozsah; obnova vytvorí `My work 2` s **novými** dotazmi (prienik zdrojov s originálom prázdny), pôvodný dashboard si nechá widgety aj predvolený príznak; člen bez `dashboard.create` (REPORTER) nedostane nič – ani dashboard, ani dotaz – a explicitná obnova mu vráti `403 PERMISSION_DENIED`. Zámerné rozhodnutia: predloha sa kopíruje cez tie isté služby a kontroly oprávnení ako ručná práca; súbeh rieši unikátny index namiesto zámku; obnova nikdy nepreberá predvolený príznak. |
| 2026-07-29 | Rekonštrukcia `docs/openapi.json` | Nepotvrdená pracovná verzia kontraktu (87 ciest) bola omylom zahodená príkazom `git checkout docs/openapi.json`; commit aj `origin` mali iba starších 45 ciest a nikde na disku neexistovala kópia. Kontrakt bol znovu zostavený zo `config/routes.php`, akcií, serializerov a validátorov vstupu | Prešlo: `OpenApiContractTest` páruje **88/88 ciest a metód**, všetkých 173 `$ref` sa rozlíši a žiadna schéma nezostala nepoužitá. Pri rekonštrukcii sa navyše doplnilo 8 schém projektových endpointov (`CreateProjectRequest`, `ProjectList`, `ProjectResponse`, `ProjectRoleList`, `ProjectMemberList`, `ProjectWorkgroupLinkList`, `ChangeProjectStatusRequest`, `UpsertProjectWorkgroupRequest`), ktoré chýbali **už v commite** – dokument sa na ne odvolával, ale nedefinoval ich. Popisy a členenie schém sú nové znenie odvodené z kódu, nie doslovná kópia stratenej verzie. |
| 2026-07-29 | F7.8e UI dashboardov | 20 nových modelov a 5 type guardov v `api.models.ts`, 5 metód v API klientovi, `DashboardWorkspaceService`, nový `DashboardEntryComponent` a `DashboardWidgetComponent`, prepísaná `dashboard-page` (skeleton s hardkódovanými dátami nahradený), kanonická route `dashboards/:dashboardId` + presmerovanie z `dashboard`, dva overené tokeny sérií v `styles.scss` a §3.4 dizajnového manuálu, **30 i18n kľúčov × 6 jazykov** (7 vzorových odstránených), 22 nových testov v 4 súboroch, `npm run check` na Node 24.15.0 | Prešlo: Prettier, strict typecheck, **156 testov v 43 súboroch** (predtým 134/39), production build 545,03 kB – nad 500 kB warningom, pod 1 MB limitom. Overené testom: preferencia „posledný aktívny“ sa **nezapíše otvorením stránky** a zapíše sa prepnutím; prepínač sa neukazuje, kým nie je medzi čím prepínať; widgety sa radia `y`, `x`, `id` a na mriežku sadajú podľa vlastných súradníc; nedostupný dashboard hlási „už nie je dostupný“, nie „zakázané“; stĺpce rozdelenia sa merajú voči najväčšiemu, nie voči súčtu; bunka matice vždy nesie číslo; legenda pribudne až s druhou sériou a vedľa grafu stojí skrytá tabuľka; widget ohlási vlastnú chybu a zopakuje sa bez reloadu; neznámy typ ani nedosiahnuteľný zdroj nespustia požiadavku. Dizajn: dvojica farieb sérií overená validátorom pre oba režimy zvlášť (light `ΔE 22,1`, dark `ΔE 18,9` deutan), preto má tmavý režim vlastný indigo stupeň. |
