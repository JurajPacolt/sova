# ADR 0005: Permission model, SUPERADMIN a impersonácia

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

Tenantové, projektové a skupinové roly majú rozdielny rozsah. Autorizácia podľa
názvu roly v controllery by sa časom rozchádzala. Zároveň bolo potrebné uzavrieť
rozsah systémového administrátora, registráciu, význam pracovných skupín a
impersonáciu.

## Rozhodnutie

- Backend autorizuje stabilné pomenované oprávnenia v explicitnom system, tenant,
  project alebo workgroup scope. Rola je verzovaná sada oprávnení, nie podmienka
  natvrdo v HTTP handlery.
- Efektívne povolenia sa sčítajú; explicitné deny pravidlá nie sú v MVP. Bez
  preukázaného povolenia platí deny by default.
- `SUPERADMIN` je oddelená systémová rola a má všetky oprávnenia vo všetkých
  existujúcich aj budúcich tenantových kontextoch vrátane čítania a zmeny obsahu.
  Nepotrebuje tenantové členstvo.
- Vstup `SUPERADMIN` do tenantu je vždy explicitný v route/UI a auditovaný.
  Systémové zoznamy nemajú potichu agregovať obsah úloh zo všetkých tenantov.
- Impersonácia patrí do MVP. Vyžaduje `SUPERADMIN`, MFA alebo čerstvé opätovné
  overenie, cieľového používateľa, tenant, povinný dôvod a maximálne 15-minútový
  kontext. UI trvalo zobrazuje banner a možnosť okamžitého ukončenia.
- Impersonovaná požiadavka eviduje skutočného aj efektívneho aktéra. V režime
  impersonácie sa vyhodnocujú oprávnenia cieľového používateľa; plnú systémovú moc
  môže administrátor použiť až po návrate do vlastného explicitného kontextu.
- Verejná registrácia neexistuje. Nový účet vzniká iba prijatím platnej jednorazovej
  pozvánky; tenant zakladá `SUPERADMIN`.
- Pracovná skupina je nositeľ projektového prístupu. Úloha môže mať súčasne
  nezávisle voliteľného konkrétneho riešiteľa aj zodpovednú pracovnú skupinu.
- Zmeny rolí zneplatnia autorizačnú cache a bezpečnostne významné povolenia,
  zamietnutia, vstupy `SUPERADMIN` a celá impersonácia sa zapisujú do append-only
  auditu.

## Dôsledky

### Pozitívne

- rovnaká autorizačná politika sa dá používať v API, workeri aj budúcich integráciách,
- podpora môže diagnostikovať ľubovoľný tenant bez nejasného členstva,
- skupinový prístup a individuálne pridelenie zostávajú oddelené doménové pojmy.

### Náklady a obmedzenia

- `SUPERADMIN` je vysoko rizikový účet a pred produkciou musí mať povinné MFA,
  alerty a prísny audit,
- každá akcia musí niesť scope a pri impersonácii dve identity,
- katalóg oprávnení a cache revízie sú súčasťou bezpečnostného kontraktu.
