# ADR 0007: OpenAPI 3.1 ako API kontrakt

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

Angular klient a Slim API sa vyvíjajú oddelene. Bez jedného strojovo čitateľného
kontraktu sa môžu route, payloady, chyby a typy klienta rozísť.

## Rozhodnutie

- Autoritatívnym verejným HTTP kontraktom je verzovaný
  `docs/openapi.json` v OpenAPI 3.1.
- Každá implementovaná route musí mať operation, bezpečnostnú schému, request a
  response typy, štandardné chyby a stabilné `operationId`.
- Chyby používajú spoločný Problem Details kontrakt. Tenantové identifikátory a
  oprávnenia sú súčasťou route a security popisu, nie iba vo voľnom texte.
- Zmena endpointu a kontraktu patrí do jednej zmeny. CI validuje OpenAPI a porovnáva
  implementované pomenované route s operáciami v kontrakte.
- Frontendový typovaný klient sa generuje alebo overuje z rovnakého artefaktu.
  Generovaný kód sa neupravuje ručne.
- Breaking zmena vyžaduje novú API verziu alebo explicitné kompatibilné prechodné
  obdobie. OpenAPI opisuje skutočne dostupné API, nie vzdialený návrh.

## Dôsledky

### Pozitívne

- backend, frontend, testy a budúce integrácie zdieľajú jeden kontrakt,
- nekompatibilné zmeny a chýbajúce chybové stavy sa odhalia skôr.

### Náklady a obmedzenia

- každá HTTP zmena vyžaduje súbežnú údržbu špecifikácie,
- schéma sama nedokáže dokázať všetky business a autorizačné pravidlá.

## Referencie

- [OpenAPI Specification](https://spec.openapis.org/oas/)
