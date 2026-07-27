# ADR 0008: Transactional outbox

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

Vytvorenie úlohy, komentára alebo zmena oprávnenia môže vyvolať notifikáciu, e-mail,
indexáciu alebo webhook. Pri priamom zápise do databázy a následnom odoslaní správy
vzniká dual-write okno: databáza môže commitnúť a správa sa stratiť, alebo správa
odísť po rollbacku.

## Rozhodnutie

- Business zmena a príslušný outbox záznam vzniknú v jednej PostgreSQL transakcii.
- Záznam obsahuje UUIDv7, tenantový kontext alebo explicitný system scope, typ a ID
  agregátu, názov a verziu udalosti, minimálny JSON payload, čas vzniku, poradie
  agregátu a stav doručenia.
- Samostatný worker číta dostupné záznamy v dávkach so zámkom
  `FOR UPDATE SKIP LOCKED`, vykonáva handler a až potom označí záznam za spracovaný.
- Doručenie je at-least-once. Každý handler je idempotentný podľa event ID a
  zachováva poradie tam, kde ho business pravidlo vyžaduje.
- Dočasné zlyhanie používa obmedzený exponenciálny backoff s jitterom. Trvalé
  zlyhanie prejde do dead-letter stavu, vytvorí metriku a alert; nestratí sa tichým
  odstránením.
- Payload neobsahuje secrets ani zbytočný tenantový obsah. Worker znovu vytvorí
  tenantový kontext a používa rovnakú autorizačnú a RLS disciplínu ako API.
- Spracované outbox záznamy sa štandardne čistia po 30 dňoch; bezpečnostný audit je
  samostatná append-only evidencia.

## Dôsledky

### Pozitívne

- commitnutá doménová udalosť sa nestratí pri výpadku workeru,
- pomalé externé služby neblokujú hlavnú HTTP transakciu,
- handler možno bezpečne opakovať a prevádzku monitorovať.

### Náklady a obmedzenia

- príjemcovia musia počítať s duplicitou a prípadnou oneskorenou konzistenciou,
- outbox potrebuje cleanup, metriky, retry politiku a dead-letter obsluhu.

## Referencie

- [AWS Prescriptive Guidance – Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)
