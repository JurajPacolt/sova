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

## Frontend

- Všetky komponenty sú explicitne `standalone`, používajú
  `ChangeDetectionStrategy.OnPush` a preferujú signals, `computed()`, `input()` a
  readonly stav.
- Funkčné oblasti sú v `frontend/src/app/features/` a načítavajú sa lazy loadingom.
- Zdieľateľné prezentačné komponenty patria do `shared/`, singleton infraštruktúra do
  `core/`. Feature nesmie importovať interné súbory inej feature.
- TypeScript `strict` a Angular `strictTemplates` zostávajú zapnuté.

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
