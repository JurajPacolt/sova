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
- SCSS,
- Vitest.

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

```powershell
npm start
```

Aplikácia bude dostupná na `http://localhost:4200`.

Ukážkové routy:

```text
/login
/select-tenant
/t/demo/dashboard
/t/demo/projects
/t/demo/issues/SOVA-1
/t/demo/admin
```

## Kontroly

```powershell
npm run typecheck
npm test
npm run build
npm run format:check
npm run check
```

Automatické formátovanie:

```powershell
npm run format
```

## Architektúra

```text
src/app/
├── core/
│   └── layout/                 # Aplikačné layouty používané jednou časťou aplikácie
├── shared/
│   └── components/             # Zdieľateľné prezentačné komponenty
├── features/
│   ├── authentication/
│   ├── tenant-selection/
│   ├── dashboard/
│   ├── projects/
│   ├── issues/
│   └── administration/
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

### Shared a core

- `shared` obsahuje znovupoužiteľné komponenty bez závislosti od konkrétnej feature,
- `core` obsahuje aplikačný shell a budúcu singleton infraštruktúru,
- `features` obsahuje samostatné funkčné oblasti,
- feature nesmie importovať interné súbory inej feature; spoločný kód sa presunie do
  `shared` alebo vhodnej zdieľanej doménovej vrstvy.

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

Frontend zatiaľ obsahuje architektonický a vizuálny základ s ukážkovými dátami.
Autentifikácia, načítanie tenantov, projekty a úlohy ešte nie sú napojené na REST API.
