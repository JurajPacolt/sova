# ADR 0006: UUIDv7 a UTC

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

Verejné sekvenčné čísla uľahčujú enumeráciu a koordinácia databázových sekvencií
komplikuje importy či budúce delenie dát. Náhodné UUIDv4 majú slabšiu indexovú
lokalitu. Časy používateľov pochádzajú z viacerých časových zón a musia zostať
jednoznačné aj počas zmeny letného času.

## Rozhodnutie

- Primárne a verejné technické identifikátory používa aplikáciou generované UUIDv7
  podľa RFC 9562.
- UUID sa stále považuje za nedôveryhodný identifikátor; vlastníctvo a oprávnenie sa
  overuje samostatne. Čitateľné issue keys zostávajú tenantovo/projektovo scopené
  business identifikátory.
- Udalosti a entity ukladajú okamih ako PostgreSQL `TIMESTAMP(6) WITH TIME ZONE`.
  Aplikácia aj worker pracujú interne v UTC a API posiela RFC 3339 s offsetom,
  prednostne `Z`.
- Frontend zobrazuje čas v zóne používateľa. Ak business pravidlo znamená miestny
  kalendárny čas, uloží sa okrem UTC okamihu aj IANA názov časovej zóny; samotný
  offset nestačí.
- Čisto kalendárne hodnoty, napríklad termín bez času, používajú databázový `DATE`.
- Databázový čas je autoritatívny pre transakčný audit a expiráciu. Testy používajú
  injektovateľné hodiny.

## Dôsledky

### Pozitívne

- bezpečne generovateľné identifikátory bez centrálnej sekvencie a s dobrou
  indexovou lokalitou,
- jednoznačné porovnávanie, expirácia a audit naprieč časovými zónami.

### Náklady a obmedzenia

- poradie UUIDv7 je približné poradie vytvorenia, nie autoritatívna business
  sekvencia,
- API a testy musia dôsledne rozlišovať okamih, miestny čas a kalendárny dátum.

## Referencie

- [RFC 9562 – Universally Unique IDentifiers, UUID Version 7](https://www.rfc-editor.org/rfc/rfc9562.html)
