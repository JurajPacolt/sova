# Projektová pamäť SOVA

Tento dokument zachytáva záväzné technické a produktové rozhodnutia, ktoré majú
zostať zachované pri ďalšom vývoji. Pri zmene rozhodnutia aktualizujte tento súbor a
podľa významu vytvorte ADR v `docs/adr/`.

## Produkt a architektúra

- SOVA je multitenantný issue tracker a task manager.
- Aplikácia začína ako modulárny monolit: PHP 8.3+ / Slim 4 REST API, Angular 22
  klient, PostgreSQL 17 a samostatný background worker.
- Používateľská identita je globálna; členstvá, roly, oprávnenia a dáta sú oddelené
  podľa tenantu.
- Backend je autoritatívny pre autentifikáciu, autorizáciu aj tenantovú izoláciu.
  Frontendové guardy sú iba súčasť používateľského rozhrania.

## Produktové rozhodnutia MVP

- Verejná registrácia neexistuje. Používateľský účet vzniká iba cez platnú,
  jednorazovú pozvánku; nový tenant vytvára `SUPERADMIN`.
- `SUPERADMIN` je oddelená systémová rola s úplným prístupom ku všetkým tenantom,
  ich nastaveniam aj obsahu. Tenantové členstvo nepotrebuje, ale vstup do
  tenantového kontextu musí byť explicitný a auditovaný.
- Impersonácia patrí do MVP. Vyžaduje dôvod, čerstvé overenie/MFA, krátku expiráciu,
  viditeľný banner a audit skutočného aj efektívneho aktéra.
- Pracovná skupina je nositeľom projektového prístupu. Úloha môže mať súčasne
  nezávisle voliteľného konkrétneho riešiteľa aj zodpovednú pracovnú skupinu.
- Opisy úloh a komentáre sa ukladajú ako CommonMark Markdown source. Raw HTML je
  zakázané; renderovaný výstup sa sanitizuje allowlistom. Zmienky a odkazy na úlohy
  sa validujú ako štruktúrované referencie a samy neudeľujú prístup.

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
- Tajomstvá, heslá, session tokeny, osobné dáta ani citlivý obsah sa nesmú dostať do
  repozitára, URL, bežných logov, analytiky alebo chybových odpovedí. Jednorazový
  resetovací, verifikačný alebo pozývací token smie byť iba v príslušnom
  `no-referrer` odkaze a po spracovaní sa odstráni z histórie URL; nikdy sa neloguje
  ani neposiela do analytiky. Citlivé hodnoty sa v logoch redigujú a produkčné
  tajomstvá sa spravujú mimo kódu.
- Reset, overenie e-mailu a pozvánky používajú 256-bitové URL-safe jednorazové
  tokeny, v databáze iba SHA-256 hash. Predvolené expirácie sú 30 minút, 24 hodín
  a 7 dní; spotrebovanie je atómové a nové vydanie zruší starší token rovnakého
  účelu.
- Verejná požiadavka na obnovu hesla vždy vráti rovnaké prijatie. E-mail sa do
  outboxu uloží iba ako autentifikovaný libsodium ciphertext, worker ho po
  spracovaní purguje a až worker overí existenciu aktívneho účtu. Reset vyžaduje
  aspoň 15 znakov, blokuje bežné a kontextové heslá, nepoužíva kompozičné pravidlá
  a po úspechu zruší všetky relácie účtu.
- Verejná požiadavka na overenie e-mailu rovnako neprezrádza existenciu ani stav
  účtu a má oddelené HMAC rate-limit buckety. Worker odošle 24-hodinový token iba
  účtu `PENDING_VERIFICATION`; jeho spotrebovanie atomicky nastaví
  `email_verified_at`, aktivuje účet a zapíše audit. Opakovanie už úspešne
  spotrebovaného tokenu je idempotentné.
- Tenantová pozvánka platí predvolene 7 dní a API nikdy nevracia jej plaintext
  token. Vytvorenie vyžaduje centrálne oprávnenie `tenant.members.invite`;
  `SUPERADMIN` ho získava úplným bypassom a tenantový člen cez priradenú rolu.
  E-mailový odkaz preukazuje kontrolu nad pozvanou schránkou:
  nový účet vznikne aktívny a overený, existujúci účet musí mať presne zhodný
  normalizovaný e-mail. Prijatie je atómové, auditované a nesmie reaktivovať
  zakázané alebo odstránené členstvo. Bežná pozvánka nepriraďuje rolu; systémová
  pozvánka prvého vlastníka priradí `TENANT_OWNER` a aktivuje `PENDING` tenant.
- Systémové vytvorenie tenantu vyžaduje UUID idempotency kľúč a atomicky vytvorí
  `PENDING` tenant, štyri rezervované roly, owner pozvánku, šifrovaný outbox,
  audit a idempotency záznam. Lifecycle je optimistický cez `revision`, vyžaduje
  dôvod a pred odstránením používa 30-dňový zrušiteľný stav
  `DELETION_PENDING`; priame `DELETED` API neexistuje.
- Bezpečnostne významné akcie sa auditujú append-only spôsobom. Audit musí obsahovať
  aktéra, tenantový a projektový kontext, akciu, cieľ, výsledok, čas a request ID,
  ale nie citlivé tajomstvá.
- `security_audit_events` aj `authentication_events` chránia PostgreSQL triggery
  proti `UPDATE` aj `DELETE`. Čítanie bezpečnostných udalostí vyžaduje
  `system.audit.view` alebo `tenant.audit.view`; tenantový rozsah vynucuje
  repository filter a výsledok používa bezpečnú redakciu metadata a keyset cursor
  `(occurred_at, id)`. Tenantový export beží pod samostatným oprávnením
  `tenant.audit.export` a vracia jeden CSV súbor s rovnakou redakciou a filtrami,
  interne obmedzený na 5000 najnovších udalostí.
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

## Dáta, prílohy a prevádzka

- Príloha má v MVP najviac 25 MiB, jedna upload požiadavka obsahuje jeden súbor,
  úloha najviac 20 aktívnych príloh a tenant má predvolenú kvótu 20 GiB.
- Povolené sú PNG, JPEG, WebP, PDF, UTF-8 text, CSV a OOXML dokumenty DOCX, XLSX a
  PPTX. Súbor je privátny a nedostupný až do úspešného skenu v karanténe; download
  vyžaduje aktuálnu autorizáciu a URL platnú najviac 5 minút.
- Soft-deleted prílohy a odstránené identity majú štandardne 30-dňovú ochrannú
  lehotu. Odstránenie tenantu má 30-dňovú zrušiteľnú lehotu a následný purge
  primárnych dát do 7 dní; legal hold môže lehotu riadene predĺžiť.
- Aplikačné logy sa držia 30 dní, spracovaný outbox 30 dní a bezpečnostný,
  administrátorský a impersonačný audit 400 dní.
- Produkčné ciele sú dostupnosť 99,9 % mesačne po GA, `RPO ≤ 15 minút` a
  `RTO ≤ 4 hodiny`. PostgreSQL a objektové dáta majú 35-dňové obnovovacie okno a
  úplný restore drill sa vykonáva minimálne štvrťročne.
- Cieľom je spravovaná kontajnerová platforma v jednom regióne a najmenej dvoch
  zónach dostupnosti: statický Angular frontend, aspoň dve API repliky, samostatný
  worker, spravovaný PostgreSQL 17 s HA/PITR, privátne objektové úložisko, správca
  secrets a centrálna observabilita. Kubernetes nie je súčasťou MVP.
- Úplný kontrakt je v
  [`ADR 0009`](./adr/0009-deployment-data-retention-and-recovery.md).

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

## Architektonické rozhodnutia

Register prijatých rozhodnutí je v [`docs/adr`](./adr/README.md). Záväzné sú najmä:

- modulárny monolit s oddeleným API a worker procesom,
- PostgreSQL shared-schema multitenancy s kompozitnými väzbami a RLS,
- serverové relácie cez Secure/HttpOnly cookie a CSRF ochranu,
- permission-based autorizácia s úplným, auditovaným `SUPERADMIN` prístupom,
- UUIDv7 pre technické identifikátory a UTC pre časové okamihy,
- OpenAPI 3.1 ako autoritatívny HTTP kontrakt,
- transactional outbox s at-least-once a idempotentnými handlermi.

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
- Jednorazové tokeny verejných access obrazoviek sa po načítaní odstránia z
  browser URL cez `replaceState`, neukladajú sa do web storage a API ich prijíma
  iba v JSON tele. Kritické recovery a invitation toky pokrývajú Playwright
  browser E2E testy.

## Autorizácia

- Autoritatívny permission katalóg a predvolená matica rolí sú v
  [`AUTHORIZATION.md`](./AUTHORIZATION.md) a backend module `Authorization`.
- Každé rozhodnutie používa stabilný permission kód a explicitný system, tenant,
  project alebo workgroup scope. Bez preukázaného grantu platí deny by default;
  názov roly sa nesmie kontrolovať v HTTP action alebo aplikačnej službe.
- `SUPERADMIN` bypass platí iba vo vlastnom explicitnom kontexte. Počas
  impersonácie sa vyhodnocuje iba efektívny používateľ.
- Tenantové roly, granty a membership priradenia majú kompozitné tenantové cudzie
  kľúče. Každá ich zmena, zmena členstva alebo stavu identity/tenantu zvyšuje
  monotónnu tenantovú autorizačnú revíziu; provider pri zmene revízie okamžite
  zahodí lokálny decision cache a nespolieha sa na TTL.
- Priradenie a odobratie tenantovej roly je idempotentné a auditované. Operácia s
  `TENANT_OWNER` vyžaduje okrem `tenant.roles.assign` aj
  `tenant.roles.manage`, aby `TENANT_ADMIN` nemohol eskalovať oprávnenia. Tenant sa
  pri owner zmene transakčne zamkne a posledného aktívneho vlastníka nemožno
  odobrať; rovnaký guard musí použiť budúca deaktivácia členstva.
- Vlastná tenantová rola má po vytvorení nemenný a v tenantovi navždy rezervovaný
  kód. Môže obsahovať iba non-system permissions so všetkými katalógovými
  závislosťami. Úprava nahrádza celú definíciu a používa optimistickú `revision`.
  Rezervované systémové roly sú nemenné; vlastnú rolu možno archivovať až po
  odobratí zo všetkých členstiev. Create, update a prvá archive operácia sú
  auditované a okamžite invalidujú autorizačný cache cez tenantovú revíziu.
- Tenantové členstvo používa `ACTIVE ↔ DISABLED` a terminálny soft stav `REMOVED`;
  fyzické mazanie ani automatické prepisovanie historických autorov sa nevykonáva.
  Vlastné členstvo sa všeobecným administračným tokom meniť nesmie. Lifecycle
  člena s `TENANT_OWNER` vždy vyžaduje aj `tenant.roles.manage` a používa rovnaký
  transakčný `TenantOwnershipGuard` ako odobratie owner roly. Globálna session
  zostáva platná pre iné tenanty, ale membership trigger okamžite invaliduje
  prístup do zmeneného tenantu.
- Systémová rola sa pre aktuálnu reláciu načítava dynamicky z databázy. Samostatný
  `/system` layout a frontendový guard znižujú riziko omylu, ale backend vždy
  vyžaduje konkrétny system permission. Systémová správa tenantov a globálny
  bezpečnostný audit sú implementované.
- Kontrolovaná impersonácia je viazaná na jednu serverovú reláciu, jedného
  aktívneho používateľa a jeden aktívny tenant. Vyžaduje `system.impersonate`,
  dôvod a čerstvé heslo, platí najviac 15 minút, vypína `SUPERADMIN` bypass,
  audituje obe identity a pri expirácii alebo invalidácii sa musí explicitne
  ukončiť.
- Systémová správa používateľov je implementovaná: zoznam všetkých globálnych
  účtov, zmena stavu na `ACTIVE`/`DISABLED` cez existujúci stavový automat
  `UserStatus` a idempotentné priradenie/odobratie role `SUPERADMIN`. Zmena
  vlastného účtu je vždy zakázaná; odobratie `SUPERADMIN` navyše zakazuje
  vlastnú rolu a posledného aktívneho superadmina, rovnaká ochrana platí pri
  deaktivácii jeho účtu. Priame vytvorenie ani zmazanie účtu nie sú súčasťou
  tohto API. Globálne systémové nastavenia (predvolené limity, feature flags,
  maintenance mód) zostávajú vedome odložené — `docs/webflow/04-ADMINISTRACIA.md`
  §14 ich sám označuje za „minimalizované“ a žiadna z uvedených kategórií
  zatiaľ nemá reálny backing systém, ktorý by konfigurovala.
- Pracovné skupiny (Fáza 4) majú jednoduché dvojhodnotové členstvo `MEMBER`/
  `MANAGER` v `workgroup_members`, nie vlastný CRUD katalóg rolí ako tenant.
  `MANAGER` získava všetky workgroup oprávnenia na danej skupine, `MEMBER` iba
  `workgroup.view`; zmena hociktorej tabuľky zvyšuje tenantovú autorizačnú
  revíziu rovnakým triggerom ako tenantové roly. Každý workgroup endpoint
  akceptuje tenantové `tenant.workgroups.manage` ALEBO workgroup-scoped
  oprávnenie na konkrétnej skupine, takže manažér skupiny ju spravuje bez
  tenantového administrátorského oprávnenia, ale nedosiahne na cudzie skupiny.
  Podrobnosti a endpoint tabuľka sú v `AUTHORIZATION.md`.

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
