# SOVA – prevádzka, monitoring a incidenty

- Verzia: 1.2
- Dátum: 2026-07-29
- Rozsah: signály aplikácie, incidenty, zálohovanie a obnova

Príručka opisuje **existujúce** signály. Kde niečo chýba, je to napísané ako
chýbajúce, nie obídené odporúčaním, ktoré sa nedá splniť.

Konkrétny Compose staging deploy, jeho technické overenie, prvý `SUPERADMIN`,
funkčný smoke a aplikačný rollback opisuje `docs/STAGING.md`.

## 1. Čo aplikácia vydáva

### 1.1 Logy

Jeden JSON riadok na záznam, na `stderr` (alebo `LOG_PATH`). Každý riadok nesie
`message`, `level`, `context`, a v `extra` aj `service` a `environment`.

**Prístupový log** – `message: "http_request"`, jeden riadok na požiadavku:

| Pole          | Význam                                                                |
| ------------- | --------------------------------------------------------------------- |
| `method`      | HTTP metóda                                                           |
| `path`        | skutočná cesta – na dohľadanie jednej požiadavky                      |
| `route`       | cesta s identifikátormi zloženými na `{id}` a `{key}` – na agregáciu  |
| `status`      | stav, ktorý klient naozaj dostal (`0` = požiadavka skončila výnimkou) |
| `duration_ms` | trvanie celej požiadavky vrátane spracovania chyby                    |
| `request_id`  | to isté, čo je v hlavičke `X-Request-ID` a v Problem Details          |
| `tenant_id`   | tenant z cesty, alebo `null` pri systémovej a auth ceste              |

Liveness sonda (`/api/v1/health` a `/live`) sa **neloguje** – beží stále a
nehovorí nič. Readiness áno.

**Chyby** – `ApiErrorMiddleware` píše jeden `warning` (klientská chyba) alebo
`error` (`5xx`) s `problem_code`, `request_id`, `method`, `path` a výnimkou.
Prístupový log tú istú požiadavku **nehlási druhýkrát ako chybu**: dva chybové
riadky na jednu udalosť rozsvietia alarm dvakrát a čitateľa nechajú hádať, či sa
pokazili dve veci.

### 1.2 Bezpečnostný audit

Tabuľka `security_audit_events` – kto, kedy, odkiaľ, v akom tenantovi a s akým
výsledkom. Nie je to log, je to dôkaz: číta sa cez `tenant.audit.view` (tenantový
rozsah) alebo systémovú obrazovku a dá sa exportovať.

### 1.3 Health endpointy

| Endpoint               | Význam                                                 |
| ---------------------- | ------------------------------------------------------ |
| `/api/v1/health/live`  | proces beží                                            |
| `/api/v1/health/ready` | databáza odpovedá **a** tenantová izolácia je vynútená |

`ready` vráti `503`, keď databáza nie je dostupná, a v produkcii aj vtedy, keď
databázová rola RLS obchádza (`tenant_isolation: failed`). Dôvod ide do logu,
nie do odpovede – endpoint odpovedá každému, kto naň dosiahne.

## 2. Redakcia citlivých hodnôt

`SensitiveDataProcessor` beží nad každým záznamom pred zápisom:

- **kľúče** obsahujúce `password`, `token`, `secret`, `csrf`, `cookie`,
  `authorization`, `credential`, `signature`, `hmac`, `api_key`, `private_key`,
  `payload_key`, `signing_key`, `passphrase` sa nahradia `[redacted]` – aj keď
  je hodnota prázdna, lebo „heslo bolo prázdne" je tiež výpoveď o hesle,
- **e-maily** (`email`, `normalized_email`, `owner_email`, `recipient`) sa
  maskujú na `j***@example.test` – doména a približný účet zostanú čitateľné,
  samotná adresa nie,
- v reťazcoch sa maskuje `Bearer …` a `?token=…`, `?code=…`, `?state=…`.

**Čo redakcia nedosiahne:** správu výnimky z cudzej knižnice. Monolog normalizuje
`Throwable` až vo formátovači, po všetkých procesoroch. Preto:

- vlastné doménové výnimky nesú **statické vety**, nie hodnoty,
- stack trace sa loguje iba pri `APP_DEBUG=true`, takže produkcia ho nemá,
- databázová výnimka **môže** obsahovať SQL; ak sa taký prípad objaví, patrí do
  incidentu, nie do bežnej rotácie logov.

## 3. Čo sledovať

Tri signály z prístupového logu, agregované podľa `route`:

| Signál    | Zdroj                          | Prečo                               |
| --------- | ------------------------------ | ----------------------------------- |
| prevádzka | počet `http_request` za minútu | výpadok sa najprv prejaví ako ticho |
| chybovosť | podiel `status >= 500`         | priama miera zdravia                |
| latencia  | p95 a p99 `duration_ms`        | priemer skryje presne to, čo bolí   |

Ďalej:

| Signál                | Zdroj                                                                    |
| --------------------- | ------------------------------------------------------------------------ |
| readiness             | `/api/v1/health/ready`                                                   |
| nespracovaný outbox   | `SELECT count(*) FROM outbox_events WHERE processed_at IS NULL`          |
| vzdané správy         | `attempts >= OUTBOX_MAX_ATTEMPTS` (predvolene 5)                         |
| neúspešné prihlásenia | `security_audit_events` s `event_type = 'LOGIN'` a `outcome = 'FAILURE'` |
| impersonácia          | `IMPERSONATION_STARTED` – nie alarm, ale udalosť na vedomie              |
| zamietnuté prílohy    | `ATTACHMENT_INFECTED` v histórii úlohy                                   |
| timeouty dotazov      | `problem_code = 'QUERY_TIMEOUT'` v chybových riadkoch                    |

## 4. Návrh alertov

Prahy sú východiskový bod pre pilot, nie zjavená pravda – po prvom mesiaci
prevádzky sa prepočítajú z nameraných hodnôt.

| Alert                         | Podmienka                                       | Závažnosť |
| ----------------------------- | ----------------------------------------------- | --------- |
| Služba nedostupná             | `ready` zlyháva 2 minúty                        | kritická  |
| Tenantová izolácia nevynútená | `tenant_isolation: failed`                      | kritická  |
| Chybovosť                     | `5xx` > 2 % požiadaviek počas 5 minút           | vysoká    |
| Latencia                      | p95 `duration_ms` > 2000 počas 10 minút         | stredná   |
| Ticho                         | žiadny `http_request` 5 minút v pracovnom čase  | vysoká    |
| Outbox rastie                 | nespracované > 500 alebo najstaršie > 15 minút  | vysoká    |
| Vzdané správy                 | čokoľvek s `attempts >= max`                    | stredná   |
| Brute force                   | > 100 `LOGIN`/`FAILURE` z jednej IP za 10 minút | vysoká    |
| Infikovaná príloha            | akýkoľvek výskyt                                | stredná   |

Impersonácia sa **nealertuje**, ale patrí do denného bezpečnostného prehľadu:
alarm na legitímnu operáciu ľudí naučí alarmy ignorovať.

## 5. Prvé kroky pri incidente

1. **Nájdi požiadavku.** Klient dostal `request_id` v tele Problem Details aj v
   hlavičke `X-Request-ID`. `request_id` je v prístupovom riadku aj v chybovom
   riadku tej istej požiadavky.
2. **Zisti rozsah.** `route` + `tenant_id` v prístupovom logu povedia, či ide o
   jednu obrazovku, jeden tenant, alebo celú aplikáciu.
3. **Over readiness.** Rozlíši výpadok databázy od chyby aplikácie.
4. **Pozri audit**, ak je podozrenie na prístup: `security_audit_events` podľa
   `request_id` spojí HTTP požiadavku s tým, čo sa v nej autorizovalo.
5. **Pri podozrení na únik dát** postupuj podľa `docs/THREAT_MODEL.md` §8 a
   ADR 0009 (retencia a obnova); prvý krok je zastaviť rozsah, nie hľadať vinu.

## 6. Prevádzkové invarianty

- Databázová rola aplikácie a workerov je `NOSUPERUSER` a `NOBYPASSRLS`. Rola,
  ktorá RLS obchádza, robí tenantové politiky ticho neúčinnými (ADR 0010).
- `ATTACHMENT_SCANNER` v produkcii nesmie byť `none` – aplikácia s ním
  odmietne naštartovať.
- `SENSITIVE_PAYLOAD_KEY` a `AUTH_RATE_LIMIT_SECRET` sa spravujú mimo kódu.
- `APP_DEBUG` je v produkcii `false`; inak sa do odpovedí aj logov dostanú
  správy výnimiek.
- Workery (`bin/outbox-worker.php`, `bin/email-worker.php`) bežia **bez**
  tenantového rozsahu zámerne – vidia naprieč tenantmi a tenant nesú v každej
  udalosti.

## 7. Čo ešte nie je

| Chýba                                          | Dôsledok                                                   |
| ---------------------------------------------- | ---------------------------------------------------------- |
| Metriky ako číselné rady (Prometheus a spol.)  | signály sa počítajú z logu, nie zo scrapovaného endpointu  |
| Produkčný PITR a úplný produkčný restore drill | RPO ≤ 15 min a RTO ≤ 4 h z ADR 0009 ešte nie sú preukázané |
| Rotácia `SENSITIVE_PAYLOAD_KEY`                | postup nie je zdokumentovaný                               |
| Distribuované trasovanie                       | koreláciu drží iba `request_id`                            |

Metriky vedome nie sú „skoro hotové": počítadlá v PHP-FPM potrebujú zdieľané
úložisko (APCu, Redis), lebo pamäť workera zomiera s požiadavkou. To je
infraštruktúrne rozhodnutie, a vymyslieť ho v kóde by znamenalo vyrobiť čísla,
ktoré nikto nescrapuje. Prístupový log dáva tie isté tri signály z niečoho, čo
každé nasadenie už teraz zbiera.

## 8. Lokálna záloha a restore drill

Tieto skripty overujú formát zálohy a mechaniku obnovy. Nie sú náhradou za
produkčný WAL archív ani dôkazom produkčného RPO/RTO.

Predpoklady:

- PostgreSQL klient (`pg_dump`, `pg_restore`, `psql`, `createdb`, `dropdb`) má
  rovnakú hlavnú verziu ako server,
- pripojenie je v premenných `DATABASE_*` alebo v `backend/.env`; iný súbor sa
  nastaví cez `SOVA_ENV_FILE`,
- prílohy sú v `ATTACHMENT_STORAGE_PATH`; relatívna cesta sa vyhodnotí voči
  `backend/`,
- databázová rola vie prečítať všetky zálohované tabuľky a pre drill vytvoriť a
  odstrániť samostatnú databázu.

Záloha odmietne existujúci cieľ:

```bash
SOVA_PG_BIN_DIR=/usr/lib/postgresql/17/bin \
  ./scripts/backup-local.sh /secure/backups/sova-2026-07-29T120000Z
```

Výsledok obsahuje:

| Súbor                       | Obsah                                                        |
| --------------------------- | ------------------------------------------------------------ |
| `database.dump`             | PostgreSQL custom dump bez vlastníkov a grantov              |
| `attachments.tar`           | privátne súbory príloh                                       |
| `database-inventory.tsv`    | počty kritických riadkov, migrácií a vynútených RLS tabuliek |
| `attachments-inventory.tsv` | počet súborov a celkový počet bajtov                         |
| `metadata.tsv`              | čas a hlavné verzie servera a klienta                        |
| `SHA256SUMS`                | kontrolné súčty všetkých uvedených artefaktov                |

`SHA256SUMS` zisťuje poškodenie, nie úmyselnú výmenu útočníkom. Celý adresár
preto musí byť v prístupovo chránenom, šifrovanom úložisku.

Restore drill:

```bash
SOVA_PG_BIN_DIR=/usr/lib/postgresql/17/bin \
  ./scripts/restore-drill-local.sh /secure/backups/sova-2026-07-29T120000Z
```

Skript najprv overí kontrolné súčty a odmietne absolútnu cestu alebo `..` v tar
archíve. Obnovuje výhradne do novej databázy s prefixom
`sova_restore_drill_`; existujúcej databázy sa nedotkne. Po obnove porovná
databázový inventár, počet tabuliek s `FORCE ROW LEVEL SECURITY`, počet a bajty
príloh a vykoná smoke dotaz. Dočasnú databázu a rozbalené prílohy odstráni aj pri
chybe.

Dňa 2026-07-29 prešiel úplný lokálny drill proti lokálnemu PostgreSQL 16.14.
Projektové nasadenie cieli na PostgreSQL 17; rovnaký postup sa musí v stagingu
spustiť klientom a serverom verzie 17.

## 9. Produkčná backup politika

Produkcia musí splniť ADR 0009:

1. PostgreSQL má kontinuálny WAL archív a pravidelný base backup, aby bolo možné
   obnoviť ľubovoľný bod v posledných 35 dňoch.
2. Prílohové objektové úložisko má zapnuté verzie alebo nemenné snapshoty a
   nezávislú šifrovanú kópiu s rovnakým 35-dňovým oknom.
3. Kľúče a účty, ktoré môžu mazať primárne dáta, nesmú samy zmazať aj nezávislú
   zálohu. Backup účet je oddelený od aplikačnej role.
4. Každý job zaznamená čas najstaršieho obnoviteľného bodu, veľkosť, kontrolný
   súčet alebo providerom potvrdenú integritu a výsledok. Zlyhanie alebo
   obnoviteľné okno kratšie než 35 dní je alert.
5. Úplný restore drill prebehne minimálne štvrťročne a po zmene databázovej,
   storage alebo migračnej vrstvy. Meria sa skutočný dosiahnutý RPO aj RTO.

Úspešný backup job bez úspešnej obnovy nie je dôkaz obnoviteľnosti.

## 10. Produkčný restore a cutover

1. Vyhlás incident, zapisuj časovú os a urč posledný dôveryhodný okamih. Ak
   pokračujúce zápisy zväčšujú škodu, zastav write traffic; zdroj nemaž.
2. V izolovanom účte alebo projekte vytvor **novú** databázu z posledného base
   backupu a prehraj WAL po zvolený bod. Nikdy neobnovuj cez poškodenú produkčnú
   databázu.
3. Z nezávislej kópie obnov verzie príloh platné v tom istom čase. Objekty
   vytvorené po zvolenom bode nesmú byť pripojené k staršej databáze.
4. Použi produkčné migrácie iba vpred a len ak obnovená schéma zaostáva za
   nasadzovanou aplikáciou. Neopravuj schému ručným SQL bez záznamu.
5. Over:
   - počet a stav Doctrine migrácií,
   - inventár tenantov, používateľov, úloh a príloh,
   - `ENABLE` aj `FORCE ROW LEVEL SECURITY` a politiky na každej tenantovej
     tabuľke,
   - aplikačnú rolu `NOSUPERUSER NOBYPASSRLS`,
   - kontrolné súčty alebo providerom potvrdenú integritu príloh a malware scan,
   - readiness, prihlásenie, otvorenie tenantového projektu, čítanie úlohy a
     stiahnutie prílohy.
6. Zmeraj rozdiel medzi zvoleným bodom a posledným potvrdeným zápisom (RPO) a čas
   od vyhlásenia po úspešné overenie (RTO). Pri prekročení cieľa incident
   nezatváraj bez nápravného opatrenia.
7. Cutover vykonaj zmenou pripojenia alebo podporovaným provider failoverom.
   Starú databázu a storage zachovaj read-only pre forenznú analýzu.
8. Po cutoveri znovu over readiness a kritickú cestu, sleduj chybovosť, latenciu
   a outbox. Až potom povoľ write traffic.
9. Pre zmazané tenanty alebo prílohy znovu aplikuj tombstones podľa ADR 0009,
   aby obnova nevrátila dáta určené na vymazanie do aktívnej prevádzky.

Ak obnovený cieľ kontrolami neprejde, cutover sa nevykoná. Ak už prebehol,
write traffic sa znovu zastaví a smerovanie sa vráti na posledný overený,
nezmenený zdroj; nevykonáva sa spätné zlievanie dvoch databáz bez osobitného
plánu konzistencie.
