# SOVA – threat model a bezpečnostná revízia

- Verzia: 1.1
- Dátum: 2026-07-29
- Rozsah: implementovaný stav backendu a frontendu k tomuto dátumu

Tento dokument nie je zoznam želaní. Každá **Obrana** opisuje mechanizmus, ktorý v
repozitári existuje a dá sa ukázať; čo hotové nie je, je v sekcii
[Zostatkové riziká](#8-zostatkové-riziká) a v pláne, nie schované medzi obranami.

## 1. Čo chránime

| Aktívum                 | Prečo je cenné                                     | Kde žije                                         |
| ----------------------- | -------------------------------------------------- | ------------------------------------------------ |
| Heslá                   | prístup k účtu a cez neho ku všetkým jeho tenantom | `users.password_hash` (Argon2id)                 |
| TOTP a recovery faktory | privilegovaný prístup `SUPERADMIN`                 | šifrovaný secret a hashe kódov v DB              |
| Session tokeny          | prevzatie relácie bez hesla                        | cookie u klienta, **hash** v `user_sessions`     |
| Jednorazové tokeny      | prevzatie účtu cez pozvánku, reset alebo overenie  | odkaz v e-maile, **hash** v `user_action_tokens` |
| Tenantový obsah         | obchodné tajomstvo zákazníka                       | tenantové tabuľky                                |
| Prílohy                 | môžu obsahovať čokoľvek, vrátane osobných údajov   | disk mimo `public/`, metadáta v DB               |
| Bezpečnostný audit      | dôkaz o tom, kto čo urobil                         | `security_audit_events`                          |
| Citlivé outbox payloady | obsahujú tokeny a adresy pred odoslaním            | `outbox_sensitive_payloads`, šifrované           |
| Konfigurácia workflowu  | mení, čo kto smie s úlohou urobiť                  | `project_workflow_*`                             |

## 2. Hranice dôvery

```text
prehliadač ──1── API ──2── PostgreSQL
                  ├──3── súborový systém (prílohy)
                  ├──4── SMTP
                  └──8── clamd (malware skener)
API ──5── tenant vs. tenant (v jednej databáze)
API ──6── člen vs. SUPERADMIN (systémová moc)
workery ──7── tie isté dáta bez HTTP kontextu
```

Za dôveryhodné sa **nepovažuje** nič, čo príde z prehliadača — vrátane
tenantového identifikátora v ceste, čísla verzie v tele a Angular guardov, ktoré
sú výhradne UX.

## 3. Hranica 1 – prehliadač ↔ API

| Hrozba (STRIDE)                        | Obrana                                                                                                                                                                                                               |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **S** – prevzatie relácie              | serverová relácia v `HttpOnly` cookie, v databáze iba hash tokenu; odhlásenie a reset hesla reláciu revokujú                                                                                                         |
| **S** – hádanie hesla                  | Argon2id, rate limit na účet aj IP (`AUTH_RATE_LIMIT_*`), jednotná `401` bez rozlíšenia „neexistuje" a „zlé heslo"                                                                                                   |
| **S** – prevzatie privilegovaného účtu | produkčný `SUPERADMIN` vyžaduje TOTP na každej novej relácii; TOTP krok sa nedá zopakovať, recovery kód sa spotrebuje pod zámkom a neplatný faktor vstupuje do login rate limitu                                     |
| **T** – CSRF                           | `CsrfProtectionMiddleware` na každej mutácii: token z hlavičky `X-CSRF-Token` sa porovnáva s **hashom uloženým v relácii**, nie s cookie — samotné poslanie cookie teda nestačí; `GET`, `HEAD` a `OPTIONS` sú vyňaté |
| **T** – XSS cez obsah používateľa      | API **nikdy nevracia HTML**: komentáre aj opis sú CommonMark _source_, raw HTML sa odmieta na hranici (`CommentBodyValidator`), klient ho nerenderuje; CSP `default-src 'none'`                                      |
| **T** – XSS cez prílohu                | stiahnutie vždy `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`, takže bajty používateľa sa nikdy nevykreslia inline                                                                           |
| **I** – únik detailu chyby             | RFC 7807 s bezpečným `detail`; správa výnimky sa pripojí len pri `app.debug`                                                                                                                                         |
| **I** – clickjacking, referrer, cache  | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, HSTS pri HTTPS                                                                                         |
| **D** – drahé dotazy                   | SovaQL limity (bajty, uzly, hĺbka, `IN` hodnoty, veľkosť stránky), statement timeout, rate limit na tenant+používateľa                                                                                               |
| **E** – oprávnenia z klienta           | autorizuje sa **na oprávnenia, nie na názvy rolí**, výhradne na serveri; guard v Angulare rozhoduje iba o tom, čo sa nakreslí                                                                                        |

## 4. Hranica 2 – API ↔ PostgreSQL

| Hrozba                 | Obrana                                                                                                                                                                                                                                                      |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **T** – SQL injection  | Doctrine DBAL s viazanými parametrami; SovaQL prekladá **štrukturálne**: názov stĺpca pochádza z konštánt, hodnota je vždy parameter `q<N>`, `title ~` je escapované `ILIKE` a `text ~` `websearch_to_tsquery` — nikdy `LIKE` ani regulárny výraz zo vstupu |
| **I** – cudzí tenant   | pozri hranicu 5                                                                                                                                                                                                                                             |
| **D** – dlhý dotaz     | `SET LOCAL statement_timeout`, SQLSTATE `57014` sa mapuje na `QUERY_TIMEOUT`                                                                                                                                                                                |
| **R** – popretie akcie | `security_audit_events` s aktérom, efektívnym používateľom, IP, `request_id` a dôvodom                                                                                                                                                                      |

## 5. Hranica 5 – tenant vs. tenant

Toto je hlavná hrozba produktu. Bráni sa **vo vrstvách**, nie jedným filtrom:

1. overená relácia,
2. explicitný tenantový kontext z cesty, nikdy z tela požiadavky,
3. aktívne členstvo (alebo výslovná systémová moc),
4. kontrola oprávnenia v správnom rozsahu (`TENANT`, `PROJECT`, `WORKGROUP`),
5. repozitár, ktorý bez tenantu nevie byť zavolaný,
6. kompozitné `FOREIGN KEY` a `UNIQUE` vrátane `tenant_id`, takže cross-tenant
   referencia nie je reprezentovateľná,
7. **Row-Level Security** (ADR 0010) — posledná vrstva, ktorá chytí zabudnutý
   predikát.

Cudzí identifikátor sa navonok správa ako neexistujúci zdroj: odpoveď je `404`,
nie `403`, takže sa ňou nedá zisťovať, čo existuje. Drží to
`tests/Api/TenantIsolationApiTest.php`, ktorý **číta tabuľku ciest z aplikácie** a
zavolá každú s cudzím tenantom (90 kombinácií cesta × metóda); nameraná realita je
1× `403` a 89× `404`.

Miesta, kde tenant **musí** byť prítomný aj mimo `WHERE`: cesta prílohy na disku,
kľúč rate limitu, väzba cursora, outbox udalosť, notifikácia, audit.

## 6. Hranica 6 – člen vs. SUPERADMIN

| Hrozba                              | Obrana                                                                                                                                                           |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| tichý prístup do tenantu            | vstup `SUPERADMIN`-a do tenantového kontextu sa **auditne zapíše** (`SUPERADMIN_TENANT_CONTEXT_ENTERED`)                                                         |
| zneužitie impersonácie              | vyžaduje čerstvú reautentifikáciu heslom a dôvod, je časovo ohraničená, viditeľne indikovaná v UI a auditovaná pri štarte, pri každej požiadavke aj pri ukončení |
| systémová moc ako notifikačný kanál | kontrola zmienok **vedome nepoužíva SUPERADMIN bypass** — systémová moc je na explicitný auditovaný prístup, nie na tiché doručenie obsahu                       |
| systémová moc v dotazoch            | `SUPERADMIN` má vo vyhľadávaní bypass iba vo vlastnom kontexte; **pri impersonácii nie**                                                                         |
| heslo bez druhého faktora           | produkčný účet dostane iba enrollment reláciu; middleware povoľuje len MFA setup, session status a logout, `is_superadmin=false` až do potvrdenia                |
| krádež TOTP secretu z databázy      | secret je šifrovaný libsodium `secretbox`; recovery kódy sa ukladajú iba ako hashe a čitateľná sada sa vracia iba raz                                            |

## 7. Hranice 3, 4, 7 a 8 – disk, pošta, workery, skener

**Prílohy.** Typ rozhoduje **obsah, nie názov**: `finfo` odsniffuje bajty a
allowlist ich porovná aj s príponou (prípona smie typ _zúžiť_, nikdy rozšíriť).
OOXML prípona nestačí: ZIP balík musí mať bezpečné názvy, rozumnú rozbalenú
veľkosť a počet položiek, žiadne šifrovanie ani symlink a povinnú časť pre svoj
typ dokumentu. Ľubovoľný ZIP premenovaný na DOCX/XLSX/PPTX sa odmietne.
Kľúč úložiska generuje server z UUID a adaptér ho validuje aj pri čítaní, takže z
triedy sa nedá spraviť path-traversal primitívum. Veľkosť sa meria **na disku**,
nie berie z requestu. Sťahovanie sa autorizuje pri každom volaní — žiadna verejná
ani predpodpísaná URL. Produkcia streamuje karanténny temp súbor do samostatného
`clamd` ešte pred trvalým uložením; iba explicitné `OK` ho sprístupní a infikované
bajty sa neuložia. Výpadok alebo neznámy verdikt je `503` bez uviaznutého
`PENDING` záznamu. Keď skener nie je nakonfigurovaný, vývoj zapíše `SKIPPED`, nie
predstieraný `CLEAN`, a produkcia s `ATTACHMENT_SCANNER=none` odmietne naštartovať.

**Pošta.** E-mail nesie iba kľúč, názov a odkaz späť do aplikácie — nikdy text
komentára, lebo e-mail opúšťa kontrolu systému; všetky interpolované hodnoty sú
HTML-escapované. Citlivé payloady čakajú v outboxe **šifrované**.

**Workery.** Bežia bez HTTP kontextu, teda aj bez tenantového rozsahu — vidia
naprieč tenantmi zámerne a vlastný tenant nesú v každej udalosti. Doručenie je
at-least-once, takže handler musí byť idempotentný; zlyhanie ide do exponenciálneho
backoffu a po `OUTBOX_MAX_ATTEMPTS` sa vzdá, aby poison message nezablokovala
frontu. Notifikačné publikum **znovu overí `issue.view` v čase doručenia**:
sledovanie prežije stratu prístupu k projektu, takže bez tejto kontroly by sa
odobratý člen ďalej dozvedal kľúč a názov úlohy.

**Zálohy.** Lokálny backup zapisuje s `umask 077`, odmieta prepísanie cieľa a
zahŕňa databázu aj prílohy s inventárom a SHA-256 súčtami. Restore najprv
kontroluje súčty a cesty v tar archíve a smie vytvoriť iba novú databázu s
vyhradeným prefixom. Súčty však nedokazujú pôvod voči útočníkovi, ktorý ovláda
celý backup adresár; produkčná ochrana preto vyžaduje oddelenú identitu,
šifrovanie, nemennú kópiu a pravidelnú obnovu podľa `docs/OPERATIONS.md`.

## 8. Zostatkové riziká

Zoznam je úmyselne konkrétny. Každá položka má vlastníka v pláne.

| Riziko                                                                           | Dopad   | Stav                                                                                                                    |
| -------------------------------------------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------- |
| Kompromitovaný druhý faktor alebo oba uložené recovery materiály `SUPERADMIN`    | vysoký  | TOTP je povinné v produkcii, replay sa odmieta, recovery kód je jednorazový; ostáva riziko kompromitovaného zariadenia  |
| Produkčné PITR a spoločná obnova databázy s prílohami nemajú produkčný drill     | vysoký  | lokálny dump/restore prešiel; RPO/RTO ostávajú nepreukázané do staging/produkčného drillu                               |
| Skener príloh je v neprodukčnom prostredí `none` (stav `SKIPPED`)                | stredný | vedomé; produkcia s `none` neštartuje                                                                                   |
| Rotácia `SENSITIVE_PAYLOAD_KEY` nemá zdokumentovaný postup                       | stredný | otvorené                                                                                                                |
| Nová tenantová tabuľka môže vzniknúť bez RLS politiky                            | stredný | otvorené; migrácia to dnes nekontroluje automaticky                                                                     |
| **Databázová rola, ktorá RLS obchádza, robí politiky ticho neúčinnými**          | vysoký  | vývojová databáza dnes beží pod superuserom; readiness to hlási a v produkcii odmieta nábeh                             |
| `npm audit` hlási dev-only moderate (Windows path traversal cez `@angular/cli`)  | nízky   | sledované, upstream oprava, downgrade je nekompatibilný                                                                 |
| RLS bráni pushdownu non-leakproof fulltext/trigram GIN operátorov                | stredný | uložený search vector a existujúci tenant/project unique B-tree znižujú cenu; veľkoobjemové staging p95 ostáva otvorené |
| Lokálny výkonový smoke nepreukazuje produkčné p95 ani veľké nepaginačné katalógy | stredný | query plány a N+1 budgety pre kritické cesty prešli; staging meranie a stránkovanie ostávajú otvorené                   |

## 9. Čo by som skúšal ako útočník

Poradie je podľa toho, kde je najviac zisku za najmenej práce:

1. **Zámena tenantu v ceste** pri každom endpointe — pokrýva
   `TenantIsolationApiTest`, ale nový endpoint bez `TenantContextMiddleware` by
   suite prešiel bez povšimnutia, lebo tá číta iba cesty, ktoré tenant v ceste
   majú. _Kontrola pri review: má nová tenantová cesta middleware?_
2. **Zdieľaný uložený dotaz ako čítačka cudzích úloh** — bráni sa prienikom s
   vlastným `issue.view` rozsahom pri každom behu, nie pri uložení.
3. **Zmienka ako pozvánka** — zmienka neudeľuje prístup; adresát musí mať
   `issue.view` už predtým.
4. **Príloha ako spustiteľný alebo kompresný obsah** — sniffovanie typu,
   kontrola OOXML balíka, limity rozbalenia, ClamAV pred uložením, `nosniff` a
   vždy `attachment`.
5. **Cursor ako univerzálny kľúč** — cursor je podpísaný a viazaný na tenant,
   efektívneho používateľa, autorizačnú revíziu, hash dotazu a triedenie;
   nesúhlas je `422`, nie tichý návrat na prvú stránku.
6. **Impersonácia bez stopy** — audit pri štarte, pri každej požiadavke aj pri
   konci; UI to musí viditeľne indikovať.
7. **Widget ako agregátor cudzích riadkov** — rozsah sa aplikuje **pred**
   agregáciou; súčet nad neviditeľnými riadkami by prezradil ich existenciu
   rovnako spoľahlivo ako ich vrátenie.
8. **Nasadenie s rolou, ktorá RLS obchádza** — nie útok na aplikáciu, ale na
   prevádzku: superuser alebo `BYPASSRLS` zruší celú siedmu vrstvu bez jediného
   chybového hlásenia. Preto to kontroluje readiness a preto je to napísané v
   `.env.example` vedľa údajov o pripojení, nie iba v ADR.

## 10. Kedy tento dokument prestáva platiť

Pri každej z týchto zmien treba threat model prejsť znova:

- nová hranica dôvery (nová integrácia, nový worker, nový klient),
- nové úložisko obsahu od používateľa,
- zmena autentifikácie alebo modelu oprávnení,
- prvý tenant s vlastnou reguláciou (napr. spracovanie osobných údajov nad rámec
  MVP),
- zapnutie čohokoľvek, čo obchádza aplikačnú vrstvu (priamy prístup do databázy,
  reporting replika, export).
