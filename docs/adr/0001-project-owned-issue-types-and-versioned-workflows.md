# ADR 0001: Projektové typy úloh a verzované workflow

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

SOVA má fungovať ako Jira-like issue tracker. Pôvodná analýza počítala s predvolenými
typmi a základným workflow, ale neurčovala, či budú konfigurovateľné na úrovni tenantu
alebo projektu. Bez jasného vlastníctva by zmena zdieľanej konfigurácie mohla
nepredvídateľne ovplyvniť viac projektov a existujúce úlohy.

## Rozhodnutie

- Typy úloh, stavy a workflow vlastní konkrétny projekt.
- Systémová alebo tenantová šablóna sa pri vytvorení projektu kopíruje; nejde o živú
  zdieľanú väzbu.
- `EPIC`, `STORY`, `TASK`, `BUG` a `SUBTASK` sú predvolené, ale projekt môže vytvoriť
  aj vlastné typy.
- EPIC je typ úlohy v spoločnej tabuľke úloh.
- Každý aktívny typ je mapovaný na jedno publikované workflow; jedno workflow môže
  používať viac typov v rovnakom projekte.
- Publikované verzie workflow sú nemenné. Zmena prebieha cez draft, validáciu,
  vyhodnotenie dopadu a atomické publikovanie s migráciou stavov.
- Použité typy, stavy a workflow sa archivujú, nie fyzicky odstraňujú.
- Prvá implementácia podporuje formulárový alebo tabuľkový editor. Grafický
  drag-and-drop editor nie je podmienkou funkčnej konfigurovateľnosti.

Úplný doménový, dátový, API a testovací návrh je v
[`WORKFLOW-A-TYPY-ULOH.md`](../WORKFLOW-A-TYPY-ULOH.md).

## Dôsledky

### Pozitívne

- každý projekt sa môže vyvíjať nezávisle,
- zmena konfigurácie je predvídateľná a auditovateľná,
- existujúce úlohy zostanú viazané na konkrétnu verziu workflow,
- tenantová a projektová integrita sa dá chrániť kompozitnými väzbami,
- dátový model umožní neskôr doplniť vizuálny editor a ďalšie hierarchické úrovne.

### Náklady a obmedzenia

- vytvorenie projektu musí kopírovať úplnú predvolenú konfiguráciu,
- publikovanie vyžaduje impact analýzu, migračné mapovanie a riadenie súbehu,
- podobná konfigurácia sa môže duplikovať vo viacerých projektoch,
- hromadná centrálna zmena viacerých projektov nie je súčasťou prvej verzie.
