# ADR 0002: Modulárny monolit

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

SOVA potrebuje konzistentne meniť identity, oprávnenia, projekty, úlohy, históriu a
outbox udalosti. Predčasné rozdelenie na sieťové služby by pridalo distribuované
transakcie, zložitejšie nasadenie a viac bezpečnostných hraníc skôr, než sú známe
reálne škálovacie potreby.

## Rozhodnutie

- Backend začne ako jeden modulárny monolit v jednom repozitári a jednej verzii.
- Doménové moduly sú minimálne Identity, Tenancy, Authorization, Workgroups,
  Projects, Issues, Collaboration, Notifications a Audit.
- Modul zverejňuje iba svoje aplikačné kontrakty a doménové udalosti. Iný modul
  nesmie pristupovať priamo do jeho interných HTTP handlerov alebo persistence
  implementácie.
- Synchrónne zmeny, ktoré musia byť konzistentné, používajú jednu PostgreSQL
  transakciu. Asynchrónne následky sa odovzdajú cez transactional outbox.
- HTTP API a background worker sa spúšťajú ako oddelené procesy z rovnakého
  verzovaného backendového artefaktu. Angular frontend je samostatný statický
  artefakt.
- Nová sieťová služba vznikne iba na základe meraného dôvodu, napríklad nezávislého
  škálovania, izolácie zlyhania, osobitného bezpečnostného režimu alebo autonómneho
  tímu. Pred oddelením musí mať modul stabilný kontrakt, vlastníctvo dát,
  pozorovateľnosť a idempotentnú integračnú hranicu.

## Dôsledky

### Pozitívne

- jednoduché lokálne prostredie, transakcie, testovanie a nasadzovanie,
- jasné hranice bez prevádzkovej ceny mikroservisov,
- možnosť neskôr oddeliť preukázateľne problematický modul.

### Náklady a obmedzenia

- hranice modulov musí vynucovať štruktúra kódu a automatické kontroly,
- jedna chybná verzia môže ovplyvniť celý backend,
- moduly zatiaľ zdieľajú škálovací a release cyklus.
