# Projektová pamäť SOVA

Tento dokument zachytáva záväzné technické a produktové rozhodnutia, ktoré majú
zostať zachované pri ďalšom vývoji. Pri zmene rozhodnutia aktualizujte tento súbor a
podľa významu vytvorte ADR v `docs/adr/`.

## Produkt a architektúra

- SOVA je multitenantný issue tracker a task manager.
- Aplikácia začína ako modulárny monolit: PHP 8.3+ / Slim 4 REST API, Angular 22
  klient, PostgreSQL 17 a neskôr samostatný background worker.
- Používateľská identita je globálna; členstvá, roly, oprávnenia a dáta sú oddelené
  podľa tenantu.
- Backend je autoritatívny pre autentifikáciu, autorizáciu aj tenantovú izoláciu.
  Frontendové guardy sú iba súčasť používateľského rozhrania.

## Bezpečnosť je nevyjednateľná požiadavka

- SOVA musí byť navrhovaná a implementovaná ako bezpečnostne kritická multitenantná
  aplikácia. Pri každom produkte, architektonickom rozhodnutí, databázovej migrácii,
  API endpointe, UI toku, integrácii a code review sa musí explicitne posúdiť
  bezpečnostný dopad.
- Platí **secure by design**, **secure by default**, **deny by default**, princíp
  najmenších oprávnení a viacvrstvová ochrana. Pohodlie ani rýchlosť implementácie
  nesmú potichu oslabiť autentifikáciu, autorizáciu, tenantovú izoláciu, audit alebo
  ochranu dát.
- Každý vstup a každý identifikátor sa považuje za nedôveryhodný. Backend vždy
  validuje dáta a pri každej operácii znovu overí používateľa, tenant, projekt,
  oprávnenie, vlastníctvo referencií a aktuálny stav entity.
- Každý databázový dotaz nad tenantovými dátami musí byť obmedzený tenantovým
  kontextom; projektové dáta aj projektovým kontextom. Cudzie kľúče, kompozitné
  obmedzenia a automatizované negatívne testy musia brániť cross-tenant a
  cross-project prístupu aj pri ručne upravenej API požiadavke.
- Prihlasovanie, relácie a obnova prístupu musia používať aktuálne bezpečné
  mechanizmy: Argon2id, bezpečné a rotované session identifikátory, cookies
  `Secure`, `HttpOnly` a primerané `SameSite`, CSRF ochranu, rate limiting a ochranu
  proti enumerácii účtov a brute-force útokom.
- Výstup sa musí bezpečne kódovať podľa kontextu. Aplikácia musí chrániť pred XSS,
  SQL injection, CSRF, SSRF, path traversal, nebezpečným uploadom, mass assignment,
  open redirect a zneužitím deserializácie. Používateľský obsah ani konfigurácia
  workflow nesmú obsahovať spustiteľný kód.
- Tajomstvá, heslá, session tokeny, reset tokeny, osobné dáta ani citlivý obsah sa
  nesmú dostať do repozitára, URL, bežných logov, analytiky alebo chybových odpovedí.
  Citlivé hodnoty sa v logoch redigujú a produkčné tajomstvá sa spravujú mimo kódu.
- Bezpečnostne významné akcie sa auditujú append-only spôsobom. Audit musí obsahovať
  aktéra, tenantový a projektový kontext, akciu, cieľ, výsledok, čas a request ID,
  ale nie citlivé tajomstvá.
- Nová funkcia nie je hotová bez testov oprávnení, negatívnych scenárov a hraníc
  tenantovej izolácie. Kritické zmeny vyžadujú threat modeling alebo bezpečnostnú
  revíziu; závislosti, kontajnery a nasadenie sa pravidelne skenujú a aktualizujú.
- Produkcia musí používať TLS, bezpečnostné HTTP hlavičky, minimálne prístupové práva,
  šifrovanie a riadenú správu kľúčov podľa citlivosti dát, monitoring podozrivých
  udalostí, zálohy a pravidelne overený postup obnovy a reakcie na incident.
- „Ultra bezpečná“ neznamená tvrdiť, že systém je nezraniteľný. Znamená neustále
  znižovať riziko, opravovať zistenia podľa závažnosti a nikdy vedome neakceptovať
  kritickú zraniteľnosť bez explicitného, časovo obmedzeného rozhodnutia vlastníka
  rizika a náhradných ochranných opatrení.

## Projekty, typy úloh a workflow

- Každý projekt vlastní svoje typy úloh, stavy, workflow a mapovanie typu na
  workflow. Konfiguračné entity sa nesmú prepájať medzi projektmi.
- Systémová alebo tenantová šablóna sa pri vytvorení projektu kopíruje. Existujúci
  projekt nemá živú väzbu na šablónu.
- Predvolené typy sú `EPIC`, `STORY`, `TASK`, `BUG` a `SUBTASK`; projekt môže pridať
  vlastné typy. EPIC je typ úlohy, nie samostatná doménová entita.
- Prvá hierarchia je Epic → Story/Task/Bug alebo vlastný štandardný typ → Sub-task.
  Rodič a dieťa musia byť v rovnakom tenantovi a projekte.
- Každý aktívny typ má práve jedno publikované workflow. Publikovaná verzia je
  nemenná; zmena prebieha cez draft, validáciu dopadu a atomické publikovanie s
  migráciou existujúcich úloh.
- Použitý typ, stav ani workflow sa fyzicky neodstraňuje, ale archivuje.
- Backend vykonáva prechod podľa `transition_id`, nie priamym zápisom cieľového stavu,
  a vždy overí aktuálny stav, workflow verziu, oprávnenia a verziu úlohy.
- Záväzná implementačná špecifikácia je v
  [`WORKFLOW-A-TYPY-ULOH.md`](./WORKFLOW-A-TYPY-ULOH.md) a rozhodnutie v
  [`ADR 0001`](./adr/0001-project-owned-issue-types-and-versioned-workflows.md).

## SovaQL, uložené dotazy a dashboardy

- Rozšírené vyhľadávanie používa bezpečný Jira-like doménový jazyk `SovaQL`, nie SQL.
  Text sa parsuje do typovaného AST a prekladá whitelist compilerom s
  parametrizovanými hodnotami.
- Tenant určuje výhradne autentifikovaný route kontext. Výsledok dotazu alebo
  agregácie je vždy prienikom SovaQL podmienky, tenantového rozsahu, projektového
  prístupu a `issue.view`.
- Uložený dotaz je tenantová verzovaná entita. Môže byť súkromný alebo explicitne
  zdieľaný, ale zdieľanie nikdy neudeľuje prístup k výsledným úlohám.
- Každý používateľ si v každom tenantovi spravuje viac osobných dashboardov, práve
  jeden predvolený a jednu preferenciu posledného aktívneho dashboardu.
- Widget je inštancia aplikáciou registrovaného typu a povinne odkazuje na
  `saved_query_id`; nesmie obsahovať inline SQL, SovaQL kópiu ani spustiteľný kód.
- Základné typy sú počet, zoznam úloh, jednorozmerné rozdelenie, dvojrozmerná matica
  a časový priebeh. Každý widget sa autorizuje a načítava s vlastným chybovým stavom.
- Záväzná syntax, dátový model, API, bezpečnostné limity a akceptačné kritériá sú v
  [`SOVAQL-A-DASHBOARDY.md`](./SOVAQL-A-DASHBOARDY.md).

## Frontend

- Všetky komponenty sú explicitne `standalone`, používajú
  `ChangeDetectionStrategy.OnPush` a preferujú signals, `computed()`, `input()` a
  readonly stav.
- Funkčné oblasti sú v `frontend/src/app/features/` a načítavajú sa lazy loadingom.
- Zdieľateľné prezentačné komponenty patria do `shared/`, singleton infraštruktúra do
  `core/`. Feature nesmie importovať interné súbory inej feature.
- TypeScript `strict` a Angular `strictTemplates` zostávajú zapnuté.

## UI a dizajnový systém

- Záväzný vizuálny smer je **Nočná inteligencia**: indigo ako primárna farba,
  teal ako akcent a slate ako neutrálna škála.
- Úplná paleta, sémantické tokeny, light/dark téma, typografia, spacing,
  komponentové pravidlá a accessibility checklist sú v
  [`UI_DESIGN_MANUAL.md`](./UI_DESIGN_MANUAL.md).
- Komponenty používajú sémantické CSS custom properties, nie priame HEX hodnoty.
  Bootstrap premenné sa mapujú na SOVA tokeny, aby nevznikli dva farebné systémy.
- Primárna light-mode akcia používa `indigo-600` (`#4F46E5`), akcent
  `teal-700` (`#0F766E`), aplikačné pozadie `slate-50` (`#F8FAFC`) a hlavný text
  `slate-900` (`#0F172A`).
- UI podporuje režimy `Systém`, `Svetlý` a `Tmavý` cez Bootstrap
  `data-bs-theme`. Dark mode používa samostatné sémantické mapovanie, nie
  automatickú inverziu.
- Stav, priorita, chyba ani výber sa nesmú komunikovať iba farbou. Cieľom je
  WCAG 2.2 AA: text minimálne `4.5:1`, veľký text a významné netextové prvky
  minimálne `3:1`, vždy viditeľný focus.
- Rozostupy používajú 4 px raster, hlavné interaktívne ciele majú minimálne
  `44 × 44 px` a komponenty musia rešpektovať `prefers-reduced-motion`.

## Lokalizácia

- Podporované jazyky sú `sk`, `cs`, `en`, `de`, `pl` a `hu`.
- Pri prvom načítaní sa použije prvý podporovaný jazyk z `navigator.languages`.
  Regionálny kód, napríklad `sk-SK` alebo `de-AT`, sa mapuje na základný jazyk.
- Ak prehliadač neponúkne podporovaný jazyk, predvolený jazyk je vždy angličtina
  (`en`).
- Jazyk možno za behu zmeniť prepínačom; služba zároveň aktualizuje atribút
  `<html lang>`.
- Anglický katalóg v
  `frontend/src/app/core/i18n/translations/en.ts` definuje typ všetkých kľúčov.
  Katalógy ostatných piatich jazykov musia byť úplné a typovo kontrolované.
- Používateľské texty sa nesmú zapisovať priamo do šablón ani komponentov. Nový
  text znamená pridať rovnaký kľúč do všetkých šiestich katalógov a použiť
  `TranslatePipe` alebo `I18nService`.

## Kontroly pred odovzdaním

```powershell
Set-Location backend
composer check

Set-Location ../frontend
npm run check
```

Angular 22 vyžaduje Node `^22.22.3`, `^24.15.0` alebo `>=26.0.0`; projekt odporúča
verziu uvedenú vo `frontend/.nvmrc`.
