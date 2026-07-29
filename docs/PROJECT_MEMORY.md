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
- Raw HTML sa odmieta **na hranici**, nie až pri renderovaní — uložený tag je
  jeden zle nakonfigurovaný renderer od spustenia. `CommentBodyValidator` však
  vedome ignoruje fenced bloky a inline code spans: vložiť `<div>` do bloku
  kódu je v issue trackeri bežné a CommonMark z toho markup neurobí. Autolinky
  `<https://…>` a `<meno@doména>` ostávajú platné. Viacriadkový code span sa
  nesleduje, takže HTML v ňom sa odmietne — konzervatívny smer.
- Zmienka je Markdown odkaz `[@Meno](sova:user/<membership uuid>)`. Referencia
  je priamo v zdroji, takže text a adresáti sa nikdy nerozídu a meno v štítku je
  kozmetické — premenovanie člena starý komentár neprepíše. Backend zmienky pri
  každom zápise znovu autorizuje: člen musí byť aktívny v tenantovi a už mať
  `issue.view` na projekte, inak `422 COMMENT_MENTION_NOT_ALLOWED`. Kontrola
  vedome **nepoužíva `SUPERADMIN` bypass** — systémová moc je na explicitný a
  auditovaný prístup k tenantovému obsahu, nie na tichý notifikačný kanál.
- Úpravu vlastného komentára povoľuje `COMMENT_EDIT_WINDOW_SECONDS` (predvolene
  15 minút, provizórna hodnota na potvrdenie vlastníkom produktu); po jej
  uplynutí a pri cudzom komentári rozhoduje `comment.moderate`. Odstránenie je
  soft a idempotentné: komentár si nechá miesto a autora v diskusii, ale nie
  text ani zmienky.
- Sledovanie úlohy je stĺpec `watching`, **nie prítomnosť riadku**. Explicitné
  odhlásenie musí prežiť automatické pravidlá, takže „nesledujem“ je uložené
  rozhodnutie a auto-prihlásenie používa `ON CONFLICT DO NOTHING` — nikdy
  neprepíše to, čo si používateľ zvolil. Automaticky sa prihlási autor úlohy,
  jej riešiteľ pri vytvorení a autor komentára; dôvod (`source`) sa uchováva a
  vracia v API, aby boli pravidlá viditeľné, nie neviditeľná mágia. Člen
  spravuje iba vlastné sledovanie, preto v ceste nie je cudzí identifikátor.
- Väzba medzi úlohami sa ukladá **raz**, na zdrojovej úlohe, a číta sa z oboch
  koncov s odvodeným inverzným názvom (`BLOCKS` → `IS_BLOCKED_BY`). Odvodenie
  namiesto druhého riadku je to, čo robí obojsmernú nekonzistentnosť
  nemožnou. Hierarchia rodič/dieťa **nie je** typ väzby — zostáva na
  `issues.parent_issue_id` s vlastnými pravidlami úrovní, inak by vznikli dva
  zdroje pravdy. Cross-tenant väzba nie je reprezentovateľná, lebo dvojica
  zdieľa jeden `tenant_id`. Zrkadlový pár sa odmieta rovnako ako duplicita:
  „A blokuje B“ spolu s „B blokuje A“ je protirečenie a druhé „súvisí s“ je
  nadbytočné. Obe strany sa filtrujú cez `issue.view` rozsah volajúceho, takže
  väzba nikdy neprezradí kľúč ani názov úlohy mimo jeho dosahu.
- Používateľská história úlohy (`GET …/issues/{id}/history`, `issue.view`) je
  oddelená od bezpečnostného auditu: vysvetľuje vývoj úlohy, netvorí dôkazný
  záznam. `issue_history` mala pôvodne `UNIQUE (issue_id, issue_version)`, čo
  platilo, kým ju písali len prechody meniace verziu. Komentár verziu meniť
  **nesmie** — bumpol by optimistický zámok každému rozpísanému editorovi za
  niečo, čo sa úlohy nedotklo. Výnimka je preto explicitná: stĺpec
  `changes_issue` a parciálny unikátny index `WHERE changes_issue`, takže
  prechod sa naďalej nedá zapísať dvakrát.

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
- **Rozhodnutie vlastníka 2026-07-28:** bajty príloh sa ukladajú do adresára na
  disku, cesta je v konfigu (`ATTACHMENT_STORAGE_PATH`, predvolene
  `backend/var/attachments`). Adresár **musí** ostať mimo toho, čo web server
  priamo servíruje — inak by sa dal súbor stiahnuť bez prejdenia autorizáciou.
  `AttachmentStorage` je port, takže privátne objektové úložisko z ADR 0009 sa
  neskôr vymení bez zásahu do pravidiel uploadu. Databáza drží iba metadáta.
- O type súboru rozhoduje **obsah, nie názov**: `finfo` odsniffuje bajty a
  allowlist je kľúčovaný detegovaným typom. Prípona smie výsledok iba *zúžiť*
  (CSV sniffnuté ako `text/plain`, OOXML sniffnuté ako ZIP), nikdy rozšíriť, a
  nesúhlas obsahu s príponou je odmietnutie, nie tichá oprava. Allowlist
  kľúčovaný klientskym vstupom nie je allowlist.
- Kľúč úložiska generuje server z UUID (`<tenant>/<aa>/<bb>/<uuid>`) a adaptér
  ho validuje regulárnym výrazom aj pri čítaní — nič odvodené z nahraného mena
  sa nikdy nedostane do cesty na disku. Pôvodné meno je výhradne zobrazovacie
  metadáta. Veľkosť sa **meria na disku**, neberie sa z požiadavky.
- Sken je port. Keď skener nie je nakonfigurovaný, zapíše sa `SKIPPED` — nie
  predstieraný `CLEAN` — takže medzera je vidieť v dátach, a **produkcia s
  `ATTACHMENT_SCANNER=none` odmietne naštartovať** (rovnaká poistka ako pri null
  mail transporte). Stiahnuteľné sú iba `CLEAN` a `SKIPPED`; `PENDING` aj
  `INFECTED` ostávajú nedostupné.
- Stiahnutie sa autorizuje pri každom volaní — žiadna verejná ani predpodpísaná
  URL — a odpoveď vždy nesie `Content-Disposition: attachment` a
  `X-Content-Type-Options: nosniff`. Používateľské bajty sa nikdy nerenderujú
  inline, inak by príloha bola vektor pre uložené XSS z API originu.
- Soft-deleted prílohy a odstránené identity majú štandardne 30-dňovú ochrannú
  lehotu. Pri odstránení prílohy prežije **záznam**, ale bajty idú okamžite —
  niet dôvodu držať súbor, ktorý si nikto nesmie stiahnuť. Odstránenie tenantu má 30-dňovú zrušiteľnú lehotu a následný purge
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
- Draft je izolovaný od publikovanej verzie a projekt má práve jeden draft na
  workflow. Stavy sú projektové zdieľané entity, nie forkované kópie: draft nesie
  `initial_status_code`, členstvo stavov vo verzii a definíciu prechodov. Publikovanie
  zachová existujúce stavy podľa kódu (identity mapping) a pre nové kódy založí nový
  projektový stav. Duplicitný draft vráti `409 WORKFLOW_DRAFT_EXISTS`, zastaraná verzia
  draftu `409 WORKFLOW_DRAFT_CONFLICT`.
- Publikovanie je jedna DB transakcia strážená optimistickým zámkom na revízii
  konfigurácie projektu (`expected_config_version`). Zvýši revíziu, predošlú verziu
  označí `RETIRED`, zapíše `project_configuration_history` a outbox
  `PROJECT_WORKFLOW_PUBLISHED`; migrácia dotknutých úloh bumpuje `issues.version` a
  zapíše `issue_history`/outbox `ISSUE_MIGRATED`. Chybové kódy: zastaraná revízia →
  `409 PROJECT_CONFIG_VERSION_CONFLICT`, chýbajúci draft → `409 WORKFLOW_DRAFT_MISSING`,
  neplatný graf → `422 WORKFLOW_INVALID`, odobraný použitý stav bez mapovania →
  `409 WORKFLOW_MIGRATION_REQUIRED`, cieľ mapovania mimo novej verzie →
  `422 WORKFLOW_MIGRATION_TARGET_INVALID`.
- Register pravidiel prechodov (`workflow_transition_rules`) sa ukladá a štrukturálne
  validuje cez `TransitionRuleCatalog` (typ, kľúč, konfigurácia) **a vykonáva sa v
  runtime** cez `TransitionRuleEvaluator` (modul `Sova\Issues`). Podmienky
  `permission` a `assignee_or_manager` filtrujú ponuku aj vykonanie prechodu
  (fail-closed; „manager“ je oprávnenie `issue.assign`, ktoré rozhoduje prezentačná
  vrstva cez `TransitionActor`, nikdy sa nehardkóduje v službe). Validátor
  `resolution_required` bez akcie `set_resolution` vynúti klientom dodanú
  `resolution` (v ponuke prechodu ako `required_fields`, pri vykonaní cez
  `fields.resolution`; chýbajúca → `422 ISSUE_TRANSITION_INVALID`). Akcie
  `set_resolution`, `clear_resolution`, `set_resolved_at` a `clear_resolved_at`
  menia stĺpce `issues.resolution` a `issues.resolved_at` (migrácia
  `Version20260728120000`, CHECK zakazuje prázdny reťazec). Issue modul číta pravidlá
  iba cez `ProjectConfigurationRepository` (`RuleView`), nikdy tabuľky konfigurácie —
  hranica modulov ostáva. Validátor `required_field` je zdokumentovaná hranica, kým
  neexistujú vlastné polia typu (§5.3).
- Zmena typu úlohy (§5.4) je `POST …/issues/{issueId}/type`
  (`IssueService::changeType`, oprávnenie **`issue.change-type`**). Názvy oprávnení
  sú kebab-case bez podčiarkovníkov kvôli CHECK-u
  `^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$` na `tenant_role_permissions` aj
  `project_role_permissions`, takže konceptuálny `issue.change_type` zo špecifikácie
  je v kóde `issue.change-type` (precedens `saved-query.share`). Cieľový typ musí byť
  aktívny s publikovaným workflow (číta sa cez `findCreationTarget`, takže
  chýbajúci/archivovaný/nepublikovaný typ vráti rovnaké `422 ISSUE_TYPE_INVALID`);
  rodič aj existujúce deti (`childHierarchyLevels`) sa overia proti hierarchii cieľa
  (`422 HIERARCHY_INVALID`); aktuálny stav sa mapuje do cieľového workflowu cez
  `versionContainsStatus`, inak API žiada `target_status_id`
  (`409 ISSUE_TYPE_STATUS_MAPPING_REQUIRED`, cudzí stav `422
  ISSUE_TYPE_STATUS_INVALID`). Ostatné kódy: `422 ISSUE_TYPE_UNCHANGED`, `409
  ISSUE_VERSION_CONFLICT`. Zápis je jedna transakcia s `issue_history
  ISSUE_TYPE_CHANGED` (JSONB metadáta starý/nový typ) a outbox udalosťou; granty pre
  existujúce tenanty dopĺňa backfill migrácia `Version20260728130000`.
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
- viacslovná (multi-verb) route na rovnakom vzore je jeden Slim `->map([...], vzor,
  Action)` s jednou akciou vetviacou podľa HTTP metódy — `OpenApiContractTest` mapuje
  vzor→metódy a duplicitný vzor prepíše, takže oddelené `->post()`+`->put()` na tej
  istej ceste rozbijú route/OpenAPI paritu,
- transactional outbox s at-least-once a idempotentnými handlermi.

## Outbox a notifikácie

- `OutboxDispatcher` v `Sova\Shared` je jediný generický konzument outboxu.
  Claimuje cez `FOR UPDATE … SKIP LOCKED` (viac workerov sa nikdy nestretne na
  tej istej udalosti) a handler beží **v jednej transakcii** so zápisom
  `processed_at`: efekt aj potvrdenie commitnú spolu, alebo ani jedno. Preto je
  doručenie at-least-once a **idempotencia je povinnosťou handlera**.
- Dispatcher claimuje iba udalosti s registrovaným handlerom. E-mailoví workeri
  vlastnia svoje názvy udalostí a šifrované jednorazové payloady s vlastnou
  expiráciou a purge pravidlami — generický dispatcher im ich nesmie vziať.
  Handlery sa registrujú explicitne v `dependencies.php`, nie autodiscovery.
- Zlyhaná udalosť ide do exponenciálneho backoffu a po `OUTBOX_MAX_ATTEMPTS` sa
  vzdá s dôvodom. Do `last_error` ide **názov triedy výnimky, nie správa** —
  správa môže niesť detail payloadu.
- Idempotenciu notifikácií nesie úložisko: unikátny kľúč
  `(event_id, recipient_membership_id, kind)` s `ON CONFLICT DO NOTHING`.
  Replay udalosti tak nechá schránku nezmenenú.
- Príjemcovia vyplývajú zo **sledovania**, nie z členstva v tenantovi ani z
  blízkosti k úlohe. Aktér je vždy vylúčený a zmienka prebíja bežnú notifikáciu
  o komentári, aby oslovenie neprišlo dvakrát.
- Publikum sledovateľov sa určuje **v čase behu workera**, nie v čase
  publikovania udalosti, takže niekto, kto začne sledovať medzitým, dostane aj
  tú udalosť. Pri komentári a prechode je to prijateľné a vedomé. Pri vytvorení
  úlohy nie — neskorý odberateľ sa nesmie dozvedieť o založení úlohy, s ktorou
  nemal nič spoločné — preto `ISSUE_CREATED` notifikuje výhradne riešiteľa
  uvedeného priamo v udalosti.
- Schránku číta iba jej vlastník: žiadny identifikátor v ceste a každý príkaz je
  kľúčovaný membershipom. Cudzí identifikátor pri označení prečítaného sa ticho
  preskočí namiesto chyby, inak by endpoint bol orákulum na existenciu.
- Publikum určuje jediná zdieľaná trieda `NotificationAudience`, ktorú používa
  in-app aj e-mailový handler. Dve kópie pravidiel by sa rozišli a tá rozídená
  by poslala názov úlohy niekomu, kto naň nemá nárok.
- Audience **znovu overuje `issue.view` v čase doručenia**. Sledovanie prežije
  stratu prístupu k projektu — riadok ostáva — takže bez tejto kontroly by sa
  odobratý člen ďalej dozvedal kľúč a názov úlohy.
- E-mail nesie iba kľúč úlohy, názov a odkaz späť do aplikácie, **nikdy text
  komentára**: e-mail opúšťa kontrolu systému vo chvíli odovzdania transportu,
  preto ukazuje na materiál namiesto toho, aby ho obsahoval. Otvorenie odkazu
  prejde bežnou autorizáciou. Interpolované hodnoty sa HTML-escapujú — názov
  úlohy je používateľský vstup a mail klient je rovnako dobré miesto na
  injektovanú značku ako prehliadač.
- Nastavenia notifikácií sú per člen a typ udalosti. Ukladajú sa **iba skutočné
  voľby**; zvyšok dopĺňa predvolená hodnota z domény, takže nový typ udalosti
  nepotrebuje backfill a „nikdy som to nenastavil“ ostáva odlíšiteľné od
  „vedome som to vypol“. In-app kanál je pri pridelení a zmienke zamknutý a
  vynucuje to `ChannelPreference`, nie HTTP vrstva — pravidlo patrí doméne, nie
  tomu, ktorý klient práve poslal požiadavku. E-mail je predvolene vypnutý pre
  všetky typy, aby rušný projekt nezačal rozposielať poštu len preto, že sa
  nikto nepozrel do nastavení.

## SovaQL, uložené dotazy a dashboardy

- Rozšírené vyhľadávanie používa bezpečný Jira-like doménový jazyk `SovaQL`, nie SQL.
  Text sa parsuje do typovaného AST a prekladá whitelist compilerom s
  parametrizovanými hodnotami.
- Tenant určuje výhradne autentifikovaný route kontext. Výsledok dotazu alebo
  agregácie je vždy prienikom SovaQL podmienky, tenantového rozsahu, projektového
  prístupu a `issue.view`.
- Uložený dotaz je tenantová verzovaná entita. Môže byť súkromný alebo explicitne
  zdieľaný, ale zdieľanie nikdy neudeľuje prístup k výsledným úlohám.
- Preto sú aj **oprávnenia uložených dotazov tenantové**, nie projektové:
  `saved-query.create`, `saved-query.share` a `saved-query.manage`. Jeden dotaz
  môže odkazovať na viac projektov naraz, takže oprávnenie sa nemá na ktorý z
  nich zavesiť. (Pôvodne bolo `saved-query.share` `PROJECT` scope — opravené
  2026-07-28 migráciou, ktorá grant presúva, nie duplikuje.)
- Názov uloženého dotazu je unikátny na vlastníka **medzi živými** dotazmi;
  archivácia meno uvoľní, preto je unikátny index parciálny.
- Grant menuje **práve jedného** principála — člena alebo pracovnú skupinu,
  nikdy oboch ani žiadneho — a vynucuje to CHECK, nie aplikačná vrstva.
  Obľúbenie je osobná väzba na membership, nie vlastnosť dotazu.
- Viditeľnosť aj úroveň prístupu volajúceho počíta **SQL v tom istom dotaze,
  ktorý číta riadok** (`DoctrineSavedQueryRepository::selectSql()`). Dotaz, na
  ktorý volajúci nemá, z databázy vôbec nevyjde, takže neexistuje filtračný krok
  v PHP, ktorý by sa dal pri ďalšom volaní zabudnúť. Cudzí súkromný dotaz preto
  nevracia `403`, ale `404` — nie je zakázaný, je neviditeľný. `viewer_access`,
  `viewer_is_owner` a `favourite` opisujú **volajúceho**, nie riadok: ten istý
  dotaz odpovie inak vlastníkovi, držiteľovi grantu a administrátorovi.
- Viditeľnosť sa **odvodzuje z grantov** (aspoň jeden grant = `SHARED`), nikdy sa
  nenastavuje priamo. `PATCH` uloženého dotazu preto pole viditeľnosti vôbec
  nemá — editor s grantom `EDIT` nesmie cudzí dotaz potichu zverejniť.
  `PUT /grants` nahrádza celú množinu, lebo čiastočná úprava nevie zaručiť, že
  vynechaný principál naozaj stratí prístup.
- Archivovať smie iba vlastník alebo `saved-query.manage`. Držať `EDIT` stačí na
  zmenu dotazu, nikdy na jeho stiahnutie ostatným zo zoznamu. Unikátnosť mena sa
  kontroluje voči menám **vlastníka**, nie editora, inak by grant držiteľ narazil
  na vlastné mená v cudzom mennom priestore.
- Grant smie menovať iba **aktívneho** principála daného tenanta a čokoľvek iné
  odpovie rovnako (`422 SAVED_QUERY_GRANT_INVALID`), takže sa cez endpoint nedá
  zisťovať, kto existuje inde.
- Každý používateľ si v každom tenantovi spravuje viac osobných dashboardov, práve
  jeden predvolený a jednu preferenciu posledného aktívneho dashboardu.
- **Dashboard je osobný.** Patrí jednému `owner_membership_id` a nikto iný naň
  nedosiahne, takže cudzí dashboard vracia `404`, nie `403`. Vlastníctvo je
  súčasťou `WHERE` každého príkazu, nie kontrolou dodatočne v PHP —
  `DashboardRepository` nemá metódu, ktorá by dovolila siahnuť inam, lebo každá
  berie členstvo. Tímové dashboardy sú budúce rozšírenie a nesmú sa simulovať
  prepísaním vlastníka.
- Oprávnenia dashboardov sú tenantové: `dashboard.create`,
  `dashboard.update-own`, `dashboard.delete-own`. Dostáva ich aj `VIEWER` —
  dashboard je osobný, takže aj člen len na čítanie má vlastný, ktorý si
  usporiada.
- Práve jeden dashboard je predvolený (parciálny unique index nad
  `owner_membership_id WHERE is_default`) a presun príznaku beží v jednej
  transakcii, takže nikdy nenastane okamih s dvoma ani so žiadnym. Člen má vždy
  aspoň jeden dashboard: posledný sa nedá zmazať, dá sa vyprázdniť. Zmazanie
  predvoleného povýši ďalší v poradí.
- Posledný aktívny dashboard sa nastavuje **explicitným `PUT …/active`**, nikdy
  vedľajším účinkom `GET`. Zápis pri čítaní by dovolil prefetchu alebo náhľadu
  odkazu presunúť človeku to, kam nabudúce pristane. Preferencia je per členstvo
  a zaniknutý cieľ spadne späť na predvolený dashboard.
- Kópia dashboardu duplikuje widgety, **nie uložené dotazy**, na ktoré
  ukazujú. Duplikovať aj tie by pri každom kopírovaní zdvojilo zoznam dotazov a
  dva identické dotazy pod dvoma menami sú horšie než jeden.
- Identifikátory sa aj pri hromadnom kopírovaní razia v PHP ako UUIDv7, nie cez
  `gen_random_uuid()`: stabilné poradie widgetov je `y`, `x`, potom `id`, takže
  náhodné v4 by sa zoradilo ľubovoľne.
- Widget je inštancia aplikáciou registrovaného typu a povinne odkazuje na
  `saved_query_id`; nesmie obsahovať inline SQL, SovaQL kópiu ani spustiteľný kód.
- Registry widgetov je **dátová**: kľúč, verzia schémy, veľkosti a agregačné
  dimenzie. Popisky sú **lokalizačné kľúče, nie text** — server neposiela žiadny
  reťazec pre používateľa, inak by jeden jazyk stál mimo typovaného kontraktu.
  Nenesie názov komponentu; `type_key` mapuje na komponent klient sám, takže
  uložený reťazec nikdy nemôže pomenovať niečo spustiteľné.
- `configuration` sa **skladá kľúč po kľúči z toho, čo typ deklaruje**, nie
  filtruje po prijatí. Neznámy kľúč sa preto do úložiska nedostane bez ohľadu na
  to, čo klient poslal — a to je to, čo drží konfiguráciu bez HTML a bez názvov
  komponentov. Chýbajúca hodnota je **predvolená**, nie odmietnutá, aby uložená
  konfigurácia prežila rozšírenie schémy; prítomná ale nesprávna sa odmietne.
- Neznámy `type_key` sa nikdy nepreloží na susedný typ. Widget sa označí ako
  nedostupný a ponúkne na odstránenie — riadok sa stále vráti, lebo skrytý
  widget by sa nedal zmazať.
- Typ widgetu je **nemenný počas života inštancie**. Zmena by reinterpretovala
  konfiguráciu napísanú proti inej schéme.
- Widget smie renderovať iba uložený dotaz, na ktorý jeho vlastník **už dosiahne**
  (vlastný alebo zdieľaný s ním). Bez toho by sa dashboard stal spôsobom, ako
  spustiť cudzí súkromný dotaz vložením identifikátora. Neexistujúci,
  archivovaný aj cudzí zdroj odpovedajú zhodne `404
  WIDGET_DATA_SOURCE_NOT_FOUND`.
- Rozloženie sa aplikuje **celé naraz** proti `dashboard.version`: presun dvoch
  widgetov cez seba je legálny iba ako dvojica, takže endpoint na jeden widget
  by musel odmietnuť prvú polovicu legálneho ťahu. Telo musí umiestniť každý
  widget dashboardu — čiastočné rozloženie by vynechané nechalo tam, kde boli,
  a práve tak sa dva ocitnú na sebe. Prekrytie a minimálnu veľkosť podľa typu
  nevie vysloviť `CHECK` (nevidí ostatné riadky), preto žijú v doméne.
- Archiváciu uloženého dotazu blokuje `409 SAVED_QUERY_IN_USE`. Port
  `SavedQueryUsageProbe` žije v module dotazov a implementáciu dodáva modul
  dashboardov, takže šípka závislosti smeruje **k** dotazom. Počet cestuje v
  `detail`, nie v `errors`: `DomainProblemException` dovoľuje field errors iba
  pri validačných problémoch a toto je konflikt.
- **Widget je iba ukazovateľ.** Pomenúva uložený dotaz a spôsob zhrnutia; dotaz
  potom beží **ako volajúci**, cez tie isté verejné služby modulu Issues ako
  ručné hľadanie. Ten istý zdieľaný dashboard preto právom ukazuje rôznym ľuďom
  rôzne čísla a widget sa nikdy nestane spôsobom, ako čítať za vlastný rozsah.
- Agreguje sa až **po** aplikovaní tenantového a projektového predikátu. Súčet
  nad riadkami, na ktoré čitateľ nemá, by prezradil ich existenciu rovnako
  spoľahlivo ako ich vrátenie — celkové číslo je rovnaký únik ako riadok.
- Bezpečnostná postupnosť (rate limit → analýza → rozsah → referencie v tomto
  rozsahu → kompilácia) žije v `IssueSearchService::plan()` a agregácia ju
  **volá**, nekopíruje. Druhá kópia poradia je druhé miesto, kde sa môže
  rozísť.
- Zdroj widgetu sa číta pri **každom** načítaní, nie z cache na widgete. Grant
  sa dá odobrať a dotaz archivovať medzi dvoma obnoveniami a widget sa to musí
  dozvedieť — vtedy vráti `404 WIDGET_DATA_SOURCE_NOT_FOUND`, zvyšok dashboardu
  sa načíta ďalej.
- Prázdny bucket rozdelenia (nepridelené úlohy, úlohy bez skupiny) sa **hlási**,
  kým ho konfigurácia nevypne. Graf, ktorý ho ticho zahodí, dá menej než celok a
  nenápadne zavádza. Časový rad dopĺňa prázdne buckety nulou cez
  `generate_series`, takže medzera je nula, nie chýbajúci bod na interpoláciu.
- Strop matice je na **bunkách**, nie na osi zvlášť: limit na jednu os by aj tak
  dovolil súčin oboch.
- `CLOSED` v časových radoch neexistuje, kým úlohy nemajú stĺpec `closed_at` —
  rovnako, ako je pole `closed` v SovaQL nepodporované. Ponúkať udalosť, ktorú
  server nevie vypočítať, je sľub, ktorý nevie dodržať.
- Základné typy sú počet, zoznam úloh, jednorozmerné rozdelenie, dvojrozmerná matica
  a časový priebeh. Každý widget sa autorizuje a načítava s vlastným chybovým stavom.
- Analyzátor jazyka je čisto doménový modul `Sova\Issues\Domain\QueryLanguage`
  bez akéhokoľvek prístupu k databáze: `SovaQlAnalyzer` = limit dĺžky → lexer →
  parser → typované AST → sémantická validácia → kanonizácia. Existenciu a
  dostupnosť konkrétneho projektu, stavu, používateľa či skupiny rieši až
  compiler v autorizovanom rozsahu volajúceho, preto analyzátor nikdy nevydá
  `QUERY_VALUE_NOT_AVAILABLE` ani `QUERY_VALUE_AMBIGUOUS`. Táto hranica je
  zámerná — držať ju znamená, že validáciu dotazu možno spustiť bez tenantového
  kontextu a bez rizika, že chybová hláška prezradí cudziu konfiguráciu.
- Kanonizácia musí byť pevný bod: `analyze(canonical).canonical === canonical`.
  Na hash kanonického textu je viazaný cursor, takže akýkoľvek drift by potichu
  zneplatnil živé stránkovanie. Chráni to test
  `SovaQlAnalyzerTest::testCanonicalFormIsStableUnderReanalysis`.
- Pole, ktoré jazyk pozná, ale ešte nemá vlastné úložisko (`labels`, `due`,
  `estimate`, `closed`), je v katalógu prítomné a označené ako nepodporované →
  `QUERY_FIELD_NOT_SUPPORTED`. Rozsvieti sa vo svojej fáze bez zmeny verzie
  jazyka — `watcher` túto cestu absolvoval vo Fáze 6 a je odvtedy podporovaný.
  Rezervovaný priestor `cf.<key>` sa v v1 odmieta rovnakým kódom, aby nevznikla
  nekompatibilná dočasná interpretácia.
- Nie každé pole je stĺpec. `watcher` sa prekladá na `EXISTS` nad
  `issue_watchers` a negácia obaľuje **celý** test: `NOT watcher = X` musí
  vrátiť aj úlohy, ktoré nesleduje nikto, čo by `NOT IN` cez join zahodilo.
  Rovnaký vzor platí pre budúce `labels`.
- Limity §4.12 „100 AST uzlov“ a „100 hodnôt v jednom `IN`“ si protirečia, lebo
  každá hodnota je AST uzol: `IN` so 100 hodnotami stojí 101 uzlov. Rozhoduje
  prísnejší uzlový limit (deny by default), takže praktický strop jedného `IN`
  je 99 hodnôt. Zmena patrí prevádzke cez konštruktor `QueryLimits`, nie do
  vyhodnocovacieho kódu; nesmie však umožniť obísť tenantový rozsah ani
  databázový statement timeout.
- Poradie krokov vykonania je bezpečnostný kontrakt a nesmie sa preusporiadať:
  rate limit → statická analýza → autorizačný rozsah → rozlíšenie referencií
  **v tomto rozsahu** → kompilácia → databáza. Nič sa nedostane do PostgreSQL
  pred validáciou a nič sa nefiltruje v PHP až po načítaní riadkov.
- Rozsah vyhľadávania je zoznam projektov, kde má **efektívny** používateľ
  `issue.view` v `PROJECT` scope (priama projektová rola alebo prepojená
  pracovná skupina). Odvodzuje ho `DoctrineSearchScopeProvider` rovnakým SQL
  vzorom ako `loadProjectDecision` – zmena projektovej autorizácie sa musí
  premietnuť do oboch, inak sa rozídu. `SUPERADMIN` bypass platí iba vo
  vlastnom kontexte, pri impersonácii nie. Predikát `tenant_id` +
  `project_id IN (…)` zapisuje repozitár **pred** pripojením skompilovaného
  filtra, takže ho žiadny fragment nevie zhodiť. Prázdny rozsah vracia prázdnu
  stránku, nie `403`.
- Compiler je whitelist, nie textová náhrada. Názov stĺpca smie pochádzať iba z
  konštánt `IssueQueryCompiler::COLUMNS`/`SORT_EXPRESSIONS`, hodnota je vždy
  viazaný parameter pomenovaný z počítadla (`q0`, `q1`, …). `title ~` je
  `ILIKE` s escapovanými `%`/`_`, `text ~` je `websearch_to_tsquery('simple', …)`
  – nikdy `LIKE` nad fulltextom a nikdy regulárny výraz. `priority` a
  `statusCategory` sa triedia konštantným `CASE` podľa významu, nie abecedne.
- `user("…")` identifikuje člena jeho **tenantovým membership id** – rovnakou
  stabilnou verejnou identitou, akú už vydáva issue API ako `membership_id`.
  `group("…")` prijme id alebo tenantovo jednoznačný názov; viacnásobná zhoda
  je `QUERY_VALUE_AMBIGUOUS`, nie tiché vybratie prvej. Nedostupná referencia
  vracia rovnaké `QUERY_VALUE_NOT_AVAILABLE` ako neexistujúca.
- Cursor je podpísaný keyset token viazaný na tenant, efektívneho používateľa,
  autorizačnú revíziu, hash kanonického dotazu a špecifikáciu triedenia. Kľúč
  sa odvodzuje z `SENSITIVE_PAYLOAD_KEY` doménovou separáciou, takže nepribudlo
  ďalšie produkčné tajomstvo. Overenie je fail-closed: nesúhlas vráti
  `422 QUERY_CURSOR_INVALID` a nikdy sa nezačne potichu odznova, lebo reštart
  stránkovania by mohol vydať riadky, ktoré mala zmena oprávnení odobrať.
- Vyhľadávanie odpovedá Problem Details, kam sa štruktúrované rozsahy §4.11
  nezmestia. `POST /issues/search` preto pri neplatnom dotaze vráti `422`
  s kódom `QUERY_INVALID`/`QUERY_TOO_LONG`/`QUERY_TOO_COMPLEX` a zoznamom
  odlišných kódov, zatiaľ čo `POST /issue-query/validate` vracia `200` s
  `valid:false` a úplnými rozsahmi pre editor. Statement timeout je `503`
  `QUERY_TIMEOUT` – dotaz bol platný a prijatý, prerušil ho server.
- Index musí zodpovedať výrazu, ktorý compiler naozaj generuje. Fulltext GIN je
  nad `to_tsvector('simple', title || ' ' || description)`, trigramový GIN nad
  `title` (nie nad `LOWER(title)`, lebo `pg_trgm` rieši case folding `ILIKE`
  sám). Bez trigramu robilo `title ~` sekvenčný scan – zmerané, nie odhadnuté.
- Záväzná syntax, dátový model, API, bezpečnostné limity a akceptačné kritériá sú v
  [`SOVAQL-A-DASHBOARDY.md`](./SOVAQL-A-DASHBOARDY.md).

## Frontend

- Všetky komponenty sú explicitne `standalone`, používajú
  `ChangeDetectionStrategy.OnPush` a preferujú signals, `computed()`, `input()` a
  readonly stav.
- Funkčné oblasti sú v `frontend/src/app/features/` a načítavajú sa lazy loadingom.
- Zdieľateľné prezentačné komponenty patria do `shared/`, singleton infraštruktúra do
  `core/`. Feature nesmie importovať interné súbory inej feature. Keď jedna
  feature potrebuje dáta inej, ide na zdieľaný API klient cez **vlastnú**
  službu, nie cez cudziu — presne to sa raz stalo pri vytváraní úlohy
  (zoznam projektov cez `ProjectAdministrationService`) a bolo to opravené.
- Formulár na vytvorenie úlohy ponúka iba **aktívne projekty** a v nich iba
  **aktívne typy s publikovaným workflow**; klient neposiela počiatočný stav
  ani verziu workflowu — obe určuje projektová konfigurácia.
- Kanban nástenka je **nutne per projekt**: stĺpce sú stavy toho projektu a dva
  projekty môžu mať úplne odlišné workflow. Z rovnakého dôvodu, z akého
  neexistuje cross-project workflow, neexistuje ani cross-project nástenka.
- Presun karty je **prechod**, nie priamy zápis stavu: klient posiela
  `transition_id` a verziu, ktorú videl. Karta sa usadí v novom stĺpci až po
  súhlase servera, takže odmietnutý presun nikdy nenechá nástenku tvrdiť
  nepravdu. Prechod vyžadujúci ďalšie pole sa nespúšťa naslepo — nástenka
  odkáže na detail, kde je miesto sa naň spýtať.
- Dostupné presuny sa načítavajú **až na vyžiadanie** pre konkrétnu kartu; inak
  by každé otvorenie nástenky znamenalo jednu požiadavku na úlohu.
- Presun tlačidlom je **povinná klávesnicová cesta** podľa WCAG, nie dočasná
  náhrada za drag and drop. DnD je nadstavba nad tou istou akciou a nesmie ju
  nahradiť.
- TypeScript `strict` a Angular `strictTemplates` zostávajú zapnuté.
- Jednorazové tokeny verejných access obrazoviek sa po načítaní odstránia z
  browser URL cez `replaceState`, neukladajú sa do web storage a API ich prijíma
  iba v JSON tele. Kritické recovery a invitation toky pokrývajú Playwright
  browser E2E testy.
- Základný aj textový režim editora používajú **jeden serverový AST**. Validačná
  odpoveď preto nesie `basic_form` — projekciu AST na to, čo vie základný režim
  nakresliť. Klient si text neparsuje; robil by to druhá gramatika s právom
  nesúhlasiť s prvou.
- Projektuje sa iba **konjunkcia jednoduchých podmienok**. `OR`, `NOT` a
  zátvorky nesú význam, ktorý základný režim nemá ako zobraziť, preto sa dotaz
  označí `representable: false` a UI ho ukáže **len na čítanie** s návratom do
  SovaQL. Potichu ho zjednodušiť je zakázané — zahodiť polovicu cudzieho filtra
  a potom ho spustiť je horšie než odmietnuť ho nakresliť. Triedenie je ploché v
  oboch režimoch, takže prežije aj vtedy, keď filter nie.
- Úprava v základnom režime skladá text z **kanonických kúskov od servera** a
  nechá ho znovu zvalidovať; klient si o význame výsledku nič nedomýšľa.
- Validácia SovaQL zostáva **serverová**. Klientská kópia gramatiky by sa od
  jazyka rozišla a začala by tvrdiť niečo iné o tom, čo je platné — presne
  preto `/issue-query/validate` existuje oddelene od vyhľadávania. Editor sa
  pýta po utíchnutí písania a prázdny dotaz nekontroluje vôbec, lebo je legálny.
- **`message_key` z validačnej odpovede sú i18n kľúče frontendu.** Katalógy
  musia obsahovať všetkých 13 `query.errors.*`; kľúč prichádzajúci zo servera sa
  pred použitím overí proti katalógu, inak by sa vykreslil sám sebou.
- Rozsahy chýb sú **kódové body**, nie UTF-16 jednotky. Text sa z dotazu
  vyrezáva cez spread operátor (`[...text].slice(start, end)`), nie cez
  `substring`, ktorý by po emoji ukázal na nesprávne znaky.
- Panel uložených dotazov vkladá do editora **surový text** (`raw_query`), nie
  kanonickú formu: znovuotvorenie má ukázať, čo autor napísal, a server si
  kanonizuje znova pri ďalšom uložení. Vkladá sa do **toho istého** boxu, ktorý
  sa spúšťa, takže beží vždy to, čo je na obrazovke.
- Ponuka akcií nad uloženým dotazom sa riadi `viewer_access` a
  `viewer_is_owner` z odpovede, nie rolou v klientovi. Sú to vlastnosti
  **volajúceho**, nie riadku, takže ich nesmie nič cachovať naprieč identitami
  a ten istý dotaz právom ponúka iné tlačidlá dvom ľuďom.
- Editor zdieľania posiela **celú množinu grantov**, lebo endpoint nahrádza, nie
  patchuje. Odobratý principál sa neposiela ako mazanie — jednoducho v novej
  množine nie je. UI nahlas hovorí, že zdieľanie dotaz iba sprístupní na
  spustenie a nikdy nedáva prístup k úlohám.
- Volajúci bez tenantového členstva (čistá systémová moc) panel uložených
  dotazov vôbec nedostane. `SAVED_QUERY_MEMBERSHIP_REQUIRED` nie je chyba na
  hlásenie používateľovi, ale neaplikovateľnosť: nemá čo vlastniť ani dostať
  pridelené.
- jsdom formuláre sám neodosiela, takže test odosielanie vyvolá udalosťou
  `submit` tam, kde by ju vyvolal prehliadač — nie obídením `ngSubmit`.
- Referencia polí a limity v editore pochádzajú z `/issue-query/metadata`, nie
  z konštánt v klientovi. Pole bez úložiska sa v odpovedi nenachádza, takže UI
  ho nikdy neinzeruje, a zmena limitu prevádzkou sa prejaví bez nasadenia UI.
- Zoznam úloh **je** SovaQL vyhľadávanie. Druhý listovací endpoint neexistuje,
  takže platí rovnaký autorizovaný rozsah a rovnaké limity, či je pole prázdne
  alebo nesie dotaz. Stránkovanie pridáva (cursor kráča iba dopredu) a nové
  hľadanie vždy začína bez cursora — token z predošlého dotazu by bol právom
  odmietnutý. `422` sa hlási inak než nedostupný server: prvé si opraví
  používateľ, druhé nie.
- Kľúč úlohy zostáva v URL, lebo to je to, čo ľudia čítajú a zdieľajú; na
  identifikátor ho preloží jedno SovaQL vyhľadanie `key = …`. Backend nemá
  „nájdi podľa kľúča“ endpoint a nepotrebuje ho.
- Sekcie detailu úlohy sa načítavajú **nezávisle**, takže `403` na jednej časti
  dát nezhodí celú obrazovku — rovnaké pravidlo ako pri detaile projektu.
  Prechod posiela verziu, proti ktorej bola ponuka vypočítaná, takže súbežná
  zmena sa ohlási a neprepíše sa potichu. Text komentára sa vymaže až po
  potvrdení serverom, aby používateľ pri chybe neprišiel o napísané.
- Opis a komentáre klient zobrazuje ako **CommonMark source**, nie ako HTML.
  API rendered markup nikdy nevracia; keď pribudne renderer, musí ísť cez
  allowlist s vypnutým raw HTML.
- Upload prílohy posiela `FormData` a klient **nesmie nastaviť `Content-Type`** —
  multipart boundary si musí doplniť prehliadač, inak server požiadavku
  neprečíta.
- Sťahovanie prílohy ide cez HTTP klienta ako blob, nie cez obyčajný
  `<a href>`. Prejde tak rovnakou autentifikovanou cestou ako každé iné volanie
  a dostane sa k nemu credentials interceptor. Dočasná object URL sa po
  kliknutí hneď uvoľňuje, inak by si súbor držal pamäť po celú reláciu.
- Stav skenu sa v UI zobrazuje pravdivo: `SKIPPED` je „uložené bez skenu“, nie
  predstieraný čistý verdikt, a tlačidlo na stiahnutie sa ponúka iba pri
  `downloadable`. Nedopĺňať tu optimizmus — backend nedostupný súbor aj tak
  odmietne, ale UI nemá tvrdiť viac, než vie.
- Vzťah väzby sa zobrazuje **z pohľadu otvorenej úlohy** (`IS_BLOCKED_BY`
  namiesto `BLOCKS`), takže smer nemusí domýšľať používateľ; API ho dodáva
  hotový v poli `relation`.

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
  **Archivovaná skupina nedáva žiadne workgroup-scoped oprávnenie** — rovnako
  ako archivovaný projekt. Archivácia je preto pre manažéra skupiny jednosmerná:
  reaktivovať ju vie iba držiteľ tenantového `tenant.workgroups.manage`.
  Podrobnosti a endpoint tabuľka sú v `AUTHORIZATION.md`.
- Projekty (Fáza 4) majú na rozdiel od skupín vlastný katalóg projektových rolí
  (`project_roles`, `project_role_permissions`,
  `project_membership_role_assignments`) provisionovaný ako nezávislá kópia
  predvolených rolí pri vytvorení projektu. `PROJECT` scope je od 2026-07-28
  vyhodnocovaný a projektový grant vzniká dvoma cestami: priamym priradením role
  tenantovému členstvu, alebo cez `project_workgroups`, kde prepojená skupina
  dáva svoju projektovú rolu všetkým aktívnym členom.
- `visibility` projektu sa vynucuje pri čítaní, nielen zapisuje.
  `GET /tenants/{tenantId}/projects` vracia s `tenant.projects.manage` všetky
  projekty tenantu, inak iba `TENANT` viditeľné plus `PRIVATE`, kde má
  **efektívny** používateľ (pri impersonácii cieľ, nie aktér) aktívnu rolu
  priamo alebo cez prepojenú skupinu. Položka zoznamu nesie `viewer_roles` s
  vlastnými rolami volajúceho, aby PRJ-01 vedel zobraziť „používateľovu rolu“.
  Tenantová viditeľnosť je iba právo vidieť, že projekt existuje — členov, roly
  a skupiny projektu vracia API až pri `project.view` na konkrétnom projekte,
  preto detail projektu rieši `403` po sekciách a nie celou obrazovkou.
- Agregovaný zoznam hodnôt v SQL sa číta cez `STRING_AGG(..., ',')` a `explode()`,
  nie cez `ARRAY_AGG`. PDO vracia PostgreSQL pole ako reťazcový literál
  (`{a,b}`), takže `is_array()` na takom stĺpci vždy zlyhá — presne to zhodilo
  hydratáciu projektových rolí, kým sa modul prvýkrát nespustil. Kódy rolí aj
  permissions majú CHECK constraint bez čiarky, preto je oddeľovač bezpečný.
- Efektívne oprávnenia dostáva frontend z `GET /tenants/{tenantId}` ako pole
  `permissions`, nie zo session – sú tenantovo špecifické. `TenantStore` ich drží
  a zahodí pri zmene alebo strate tenantu; `permissionGuard()` a navigácia sú
  jediní spotrebitelia. Zoznam je **výhradne UX afordancia**: každý endpoint sa
  autorizuje znovu, takže zastaraný zoznam nikdy nerozšíri prístup.
- Výpis oprávnení sa musí filtrovať cez `AuthorizationScope::supports()`.
  Tenantové roly totiž legitímne nesú aj projektové kódy (`project.view`,
  `issue.*`) podľa predvolenej matice; bez filtra by UI tvrdilo, že člen má
  projektové oprávnenia už na úrovni tenantu. Impersonácia nedostane
  `SUPERADMIN` zoznam rovnako, ako nedostane bypass.
- Projektová konfigurácia (Fáza 5) je vlastný modul `Sova\ProjectConfiguration`;
  `Sova\Issues` k jej tabuľkám nesmie pristupovať priamo, iba cez
  `ProjectConfigurationRepository`. Predvolená šablóna (5 typov, 4 stavy, jedno
  publikované workflow, mapovanie typov) sa kopíruje v tej istej transakcii ako
  projekt – projekt nikdy nesmie existovať bez použiteľnej konfigurácie.
- Klient pri vytvorení úlohy neposiela stav ani verziu workflow; obe určí
  konfigurácia. Pri prechode posiela iba `transition_id` a
  `expected_issue_version`. Číslo úlohy sa rezervuje jedným
  `INSERT … ON CONFLICT DO UPDATE … RETURNING`, takže je atomické a bez medzier;
  kľúč má tvar `KÓD-číslo`.
- Autorizácia úlohy je čisto projektová – tenantová rola ju neobchádza (viď
  `WORKFLOW-A-TYPY-ULOH.md` §10). Oprávnenie sa navyše overuje **pred** verziou a
  dostupnosťou prechodu, inak by chybové kódy prezradili stav workflow
  používateľovi bez prístupu k projektu.

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

`docs/openapi.json` je formátovaný Prettierom so **šírkou 80**, nie 100 ako
frontend (`frontend/.prettierrc` naň nedosiahne, lebo súbor leží mimo `frontend/`).
Ak sa dokument upravuje skriptom, treba ho potom prehnať cez
`npx prettier --print-width 80 --parser json --write docs/openapi.json`, inak sa
preformátuje celý súbor a skutočná zmena zanikne v šume.
