# SOVA frontend

Angular 22 klient pre multitenantný issue tracker SOVA.

## Technológie

- Angular 22,
- TypeScript 6 v strict režime,
- standalone komponenty,
- Angular signals,
- lazy-loaded feature routes,
- runtime lokalizácia pre SK, CS, EN, DE, PL a HU,
- Bootstrap 5,
- sémantické light/dark UI tokeny a voľba Systém/Svetlá/Tmavá,
- SCSS,
- Vitest,
- Playwright browser E2E testy.

## Požiadavky

Angular 22 vyžaduje jednu z podporovaných Node verzií:

```text
Node ^22.22.3
Node ^24.15.0
Node >=26.0.0
```

Odporúčaná verzia projektu je zapísaná v `.nvmrc` a `.node-version`.

## Inštalácia

```powershell
npm install
```

## Spustenie

Najskôr spustite backend na `http://127.0.0.1:8080`, potom frontend:

```powershell
npm start
```

Aplikácia bude dostupná na `http://localhost:4200`. Vývojový server používa
`proxy.conf.json`, takže relatívne požiadavky na `/api` smeruje na lokálny backend a
prehliadač môže bezpečne používať session a CSRF cookies bez vývojového CORS
workaroundu.

Hlavné routy:

```text
/login
/forgot-password
/reset-password/:token
/verify-email/:token
/accept-invitation/:token
/select-tenant
/t/:tenantSlug/dashboard
/t/:tenantSlug/projects
/t/:tenantSlug/issues/:issueKey
/t/:tenantSlug/admin
/system/tenants
/system/audit
```

## Kontroly

```powershell
npm run typecheck
npm test
npm run build
npm run format:check
npm run check
npx playwright install chromium
npm run e2e
```

Automatické formátovanie:

```powershell
npm run format
```

## Architektúra

```text
src/app/
├── core/
│   ├── api/                    # Typovaný REST klient a HTTP interceptory
│   ├── auth/                   # Obnova relácie, auth stav a route guards
│   ├── layout/                 # Aplikačné layouty
│   ├── navigation/             # Bezpečná navigácia po prihlásení
│   └── tenancy/                # Dostupné tenanty, aktívny tenant a guard
├── shared/
│   └── components/             # Zdieľateľné prezentačné komponenty
├── features/
│   ├── authentication/
│   ├── tenant-selection/
│   ├── dashboard/
│   ├── projects/
│   ├── issues/
│   └── administration/
│   └── system-administration/
├── app.config.ts
├── app.routes.ts
└── app.ts
```

### Standalone komponenty

Každý komponent je standalone a používa `ChangeDetectionStrategy.OnPush`.
`angular.json` nastavuje tieto hodnoty aj pre nové komponenty generované cez Angular
CLI.

Komponent importuje iba svoje priame závislosti:

```typescript
@Component({
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExampleComponent {}
```

### Signals

Signály sa používajú pre lokálny a odvodený stav:

```typescript
readonly issues = signal<readonly Issue[]>([]);
readonly openIssueCount = computed(
  () => this.issues().filter((issue) => issue.status !== 'closed').length,
);
```

Vstupy nových komponentov majú preferovať signal inputs cez `input()` a
`input.required()`.

### Lazy loading

Feature oblasti nepoužívajú klasické `NgModule`. Každá exportuje vlastné `Routes`,
ktoré koreňový router načíta cez dynamický import:

```typescript
{
  path: 'projects',
  loadChildren: () =>
    import('./features/projects/projects.routes').then(
      (routesModule) => routesModule.PROJECT_ROUTES,
    ),
}
```

### Impersonácia

Systémová správa tenantu načíta aktívnych členov, vyžiada dôvod a aktuálne heslo a
po úspešnom serverovom prepnutí otvorí pripnutý tenant. `AuthSessionStore` drží
efektívneho používateľa a samostatný impersonačný kontext. Tenantový shell počas
celej impersonácie zobrazuje aktéra, efektívneho používateľa, dôvod, odpočet a
tlačidlo ukončenia; po ukončení obnoví pôvodnú identitu zo servera a vyčistí
tenantovú cache.

### Shared a core

- `shared` obsahuje znovupoužiteľné komponenty bez závislosti od konkrétnej feature,
- `core` obsahuje aplikačný shell a singleton infraštruktúru,
- `features` obsahuje samostatné funkčné oblasti,
- feature nesmie importovať interné súbory inej feature; spoločný kód sa presunie do
  `shared` alebo vhodnej zdieľanej doménovej vrstvy.

### API, relácia a tenantový kontext

Klient volá relatívne `/api/v1` endpointy typovanými modelmi odvodenými z
`docs/openapi.json`. API interceptor posiela cookies iba pre `/api/` požiadavky a
pri stav meniacich metódach kopíruje cookie `sova_csrf` do hlavičky
`X-CSRF-Token`. Session token je `HttpOnly` a frontend ho nečíta ani neukladá do
`localStorage`.

Pri prvom chránenom route guard obnoví stav relácie cez `GET /api/v1/tenants`.
Tenantový guard pri každej navigácii na `/t/:tenantSlug` znova načíta zoznam
dostupných tenantov a potvrdí konkrétny tenant cez
`GET /api/v1/tenants/{tenantId}`. Frontendový stav je iba UX cache; autoritatívnu
kontrolu členstva a tenantového stavu vždy vykonáva backend.

`returnUrl` po prihlásení prijíma iba interný `/select-tenant` alebo
`/t/:tenantSlug/...` cieľ, ku ktorému má aktuálna relácia prístup. Odpoveď
`401 SESSION_REQUIRED` vyčistí autentifikačný aj tenantový stav a presmeruje na
login. Úspešné odhlásenie vyčistí tenantovú cache.

Verejné obrazovky obnovy hesla, overenia e-mailu a prijatia pozvánky držia
jednorazový token iba v pamäti komponentu. Hneď po načítaní ho odstránia z adresného
riadku a browser histórie cez `Location.replaceState`; do API ho posielajú iba
v JSON tele. Token sa neukladá do `localStorage`, `sessionStorage` ani cookies.
Globálny session-expiry handler tieto verejné routy nepresmeruje na login pri
anonymnom `401 SESSION_REQUIRED`.

### Lokalizácia

Pri prvom načítaní sa vyberie prvý podporovaný jazyk z preferencií prehliadača.
Regionálne varianty ako `sk-SK` sa normalizujú na základný kód; ak sa podporovaný
jazyk nenájde, použije sa angličtina. Jazyk možno následne zmeniť prepínačom bez
obnovenia stránky.

```text
src/app/core/i18n/
├── language.ts               # Podporované jazyky, detekcia a EN fallback
├── i18n.service.ts           # Signal-based aktívny jazyk a preklad
├── translate.pipe.ts
└── translations/             # Úplné katalógy sk, cs, en, de, pl a hu
```

Anglický katalóg definuje typ `TranslationKey`, preto chýbajúci kľúč v inom jazyku
zastaví typecheck. Používateľské texty nevkladajte priamo do komponentov alebo
šablón; každý nový text doplňte pod rovnakým kľúčom do všetkých šiestich katalógov.

## Bootstrap

Bootstrap CSS je pripojený globálne v `angular.json`. Bootstrap JavaScript pluginy sa
nepoužívajú priamou manipuláciou DOM. Interaktívne prvky sa majú implementovať cez
Angular stav a signály alebo cez Angular-kompatibilný komponentový wrapper.

## Aktuálny rozsah

Prihlásenie, obnova session, odhlásenie, výber tenantu, ochrana tenantových rout,
forgot/reset hesla, overenie e-mailu a invite-only prijatie novým aj existujúcim
účtom sú napojené na REST API. Verejné access toky pokrývajú komponentové aj
Playwright Chromium E2E testy.

`SUPERADMIN` má samostatný lazy `/system` layout s dynamickým session guardom.
Systémový zoznam tenantov podporuje idempotentné vytvorenie, pozvánku prvého
vlastníka a lifecycle zmeny s revision a dôvodom. `/system/audit` zobrazuje
filtrované append-only bezpečnostné udalosti a postupne načítava keyset stránky.
Tenantová auditná obrazovka, členovia, roly a ostatná tenantová administrácia
zostávajú rozpracované. Dashboard, projekty a úlohy zatiaľ obsahujú ukážkové dáta.
