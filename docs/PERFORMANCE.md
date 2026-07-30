# Výkon a databázové query plány

Tento dokument opisuje opakovateľnú lokálnu výkonovú bránu. Produkčné SLO
nenahrádza: p95 sa dá pravdivo potvrdiť až na stagingu s produkčne podobným
objemom, databázovou rolou, hardvérom a súbehom.

## Ciele

Počiatočné ciele z analýzy sú:

- bežné API čítanie do 300 ms na p95 bez externých služieb,
- bežná mutácia do 500 ms na p95,
- pevne obmedzené alebo stránkované hlavné zoznamy,
- žiadne N+1 databázové dotazy v hlavných zoznamoch.

Lokálna brána chráni prenosné štrukturálne vlastnosti:

1. kritické predikáty a radenia majú použiteľný index,
2. pri štrukturálnej kontrole so zámerne odradeným `Seq Scan` každý plán použije
   presne očakávaný index,
3. počet SQL statementov pri hydratácii zoznamu nerastie s počtom riadkov,
4. jeden surový kontrolný dotaz neprekročí 500 ms.

Limit 500 ms je regresný smoke, nie tvrdenie o p95 API. Zahŕňa iba jeden SQL
statement na lokálnom stroji; nezahŕňa middleware, serializáciu, sieť ani súbeh.

## Opakovateľný dataset

`QueryPerformanceTest` vytvára v jednej rollback transakcii:

| Entita            |  Počet |
| ----------------- | -----: |
| úlohy             | 20 000 |
| členovia          |    100 |
| projekty          |     50 |
| osobné dashboardy |     30 |
| auditné udalosti  |    250 |

Časy `created_at` a `updated_at`, reportéri, priority a zriedkavý fulltextový
výraz sú rozptýlené. Planner preto nedostane umelý dataset, kde majú všetky
riadky rovnakú hodnotu a vhodný index vyzerá zbytočne. Po teste sa transakcia
vráti a štatistiky `issues` sa znovu analyzujú nad skutočným obsahom.

## Kontrolované plány

Test používa `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` a rekurzívne kontroluje
celý plán. Pred touto kontrolou nastaví v rollback transakcii
`enable_seqscan=off`: 20 000 riadkov je dosť na zmysluplný plán, ale stále tak
málo, že PostgreSQL môže pri teplej cache a `LIMIT 100` legitímne uprednostniť
paralelný sekvenčný scan. Cieľom testu nie je prepisovať produkčný cost model,
ale dokázať, že výraz z compilera sa s indexom stále zhoduje. Ak index chýba
alebo sa výraz rozíde, PostgreSQL aj tak použije `Seq Scan` s prohibičnou cenou
a test zlyhá.

| Cesta                              | Očakávaný index                    |
| ---------------------------------- | ---------------------------------- |
| filter podľa reportéra             | `idx_issues_project_reporter`      |
| významové `priority DESC` + keyset | `idx_issues_project_priority_rank` |
| najnovšie aktualizované úlohy      | `idx_issues_project_updated`       |
| fulltext názvu a opisu             | fulltext GIN alebo search scope    |
| case-insensitive contains v názve  | trigram GIN alebo search scope     |

Migrácia `Version20260729400000` dopĺňa prvé dva indexy. Pred auditom filter
`reporter` nemal vlastný index vôbec. Starší index nad textovým `priority`
pomáhal equality filtru, ale nemohol obslúžiť skutočné doménové radenie
`LOW < NORMAL < HIGH < CRITICAL`, ktoré compiler zapisuje cez `CASE`. Nový
expression index používa presne ten istý výraz a stabilný `id ASC` tie-breaker.

PostgreSQL nepovažuje fulltext ani trigram operátory za `LEAKPROOF`. Pri
`FORCE RLS` ich preto zámerne neposunie pred bezpečnostnú politiku a
špecializovaný GIN nemusí použiť ani vtedy, keď je sekvenčný scan odradený.
Test akceptuje dve presné vetvy:

- bez RLS bariéry `idx_issues_fulltext` alebo `idx_issues_title_trigram`;
- s vynúteným RLS niektorý B-tree s indexovanou podmienkou `tenant_id` aj
  `project_id` (vrátane indexu existujúceho unikátneho constraintu).

Migrácia `Version20260729500000` pridáva generovaný uložený `search_vector`.
Fulltext pod RLS sa tak neprepočítava pre každý kandidátsky riadok; diagnostický
20k plán klesol približne zo 43 ms na 1,4 ms, hoci toto číslo je iba vysvetlenie
nálezu, nie výkonové SLO. RLS sa kvôli GIN indexu neobchádza ani neoslabuje.

## N+1 budgety

Doctrine logging middleware v teste počíta vykonané SQL statementy, nie
transakčné lifecycle správy:

| Operácia                      | Dataset vo výsledku | SQL budget |
| ----------------------------- | ------------------: | ---------: |
| zoznam projektov              |                  50 |          1 |
| zoznam osobných dashboardov   |                  30 |          1 |
| stránka vyhľadávania úloh     |                 100 |          2 |
| stránka bezpečnostného auditu |                 100 |          1 |

Vyhľadávanie má dva statementy zámerne: `SET LOCAL statement_timeout` a jeden
stránkovaný `SELECT`. Ostatné zoznamy hydratujú väzby v jednom dotaze. Zmena,
ktorá začne načítavať detail osobitne pre každý výsledný riadok, tento budget
okamžite poruší.

## Spustenie

Po aplikovaní migrácií na lokálnu PostgreSQL:

```bash
cd backend
RUN_DATABASE_TESTS=true vendor/bin/phpunit \
  tests/Infrastructure/Persistence/QueryPerformanceTest.php
```

Test je súčasťou bežného PHPUnit suite a v CI sa spúšťa, pretože workflow
nastavuje `RUN_DATABASE_TESTS=true`.

## Čo ostáva potvrdiť

- staging p50/p95/p99 pre HTTP routy a widgetové typy,
- správanie pri reálnom súbehu a studenej cache,
- správanie na spravovanej produkčnej databáze s finálnym cost modelom,
- stránkovanie katalógov, ktoré dnes vracajú celý zoznam (najmä systémoví
  používatelia, systémové tenanty, členovia a projekty) pred pilotom s veľkým
  objemom.

Tieto body sa nesmú zameniť so zeleným lokálnym smoke testom. Prevádzkové
latencie sa sledujú z `duration_ms` podľa `OPERATIONS.md`; produkčne podobný
staging je podmienkou uzavretia pilotnej výkonovej brány.
