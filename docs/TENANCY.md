# SOVA – tenantový kontext a izolácia

Tenantový kontext je bezpečnostná hranica vytvorená backendom. Klient neposiela
dôveryhodný `tenant_id` v request body ani vo voľnej hlavičke.

## Endpointy

| Metóda  | Route                                                   | Pravidlo                                                                       |
| ------- | ------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `GET`   | `/api/v1/tenants`                                       | Aktívne tenanty s aktívnym členstvom; `SUPERADMIN` všetky neodstránené tenanty |
| `GET`   | `/api/v1/tenants/{tenantId}`                            | Tenant z route po novej kontrole aktuálneho prístupu                           |
| `GET`   | `/api/v1/tenants/{tenantId}/memberships`                | Administratívny zoznam členstiev a rolí                                        |
| `PATCH` | `/api/v1/tenants/{tenantId}/memberships/{membershipId}` | Bezpečný lifecycle `ACTIVE/DISABLED/REMOVED`                                   |
| `GET`   | `/api/v1/tenants/{tenantId}/audit`                      | Tenantovo izolovaný bezpečnostný audit                                         |
| `GET`   | `/api/v1/system/tenants`                                | Systémový zoznam tenantov pre `SUPERADMIN`                                     |
| `POST`  | `/api/v1/system/tenants`                                | Atomické vytvorenie `PENDING` tenantu a pozvánky vlastníka                     |
| `PATCH` | `/api/v1/system/tenants/{tenantId}`                     | Optimistický a auditovaný lifecycle tenantu                                    |

Všetky endpointy vyžadujú aktívnu serverovú reláciu. Cudzí, neplatný, odstránený
alebo inak neprístupný tenantový identifikátor vráti rovnakú odpoveď:

```text
404 TENANT_NOT_FOUND
```

Tým API nepotvrdzuje existenciu tenantov používateľovi, ktorý k nim nemá prístup.

## Bežný používateľ

Prístup vznikne iba vtedy, keď sú súčasne splnené všetky podmienky:

- používateľská relácia a používateľ sú aktívni,
- membership používateľa v danom tenantovi je `ACTIVE`,
- tenant je `ACTIVE`.

Deaktivácia členstva alebo pozastavenie tenantu sa prejaví pri nasledujúcej
požiadavke; nestačí predchádzajúci výber vo frontende ani cache route.

Deaktivácia členstva neruší globálnu používateľskú reláciu, pretože tá môže naďalej
slúžiť pre iné tenanty. Tenant context a permission provider však pri nasledujúcej
požiadavke načítajú nový stav/revíziu a prístup do deaktivovaného tenantu odmietnu.
`REMOVED` je terminálny soft stav; historické autorstvo a väzby sa fyzicky nemažú.

## SUPERADMIN

`SUPERADMIN` je samostatná systémová rola v `user_system_roles`. Nie je tenantovým
členstvom a nepoužíva databázové `BYPASSRLS`. Môže explicitne vstúpiť do každého
tenantu okrem tombstonu v stave `DELETED`, kde už nemá existovať business obsah.

Odpoveď rozlišuje zdroj prístupu:

```json
{
  "access": {
    "type": "MEMBERSHIP",
    "membership_id": "019..."
  }
}
```

alebo:

```json
{
  "access": {
    "type": "SUPERADMIN",
    "membership_id": null
  }
}
```

Zobrazenie systémového zoznamu a každý úspešný alebo odmietnutý pokus o
`SUPERADMIN` tenantový kontext sa zapisuje do `security_audit_events` s aktérom,
request ID, IP a tenantom alebo požadovaným tenantovým ID.

Počas impersonácie je `SUPERADMIN` bypass vypnutý. Relácia je pripnutá k jedinému
tenantu a všetky rozhodnutia sa počítajú iba z členstva a rolí efektívneho
používateľa v tomto tenantovi. Pokus o iný tenant sa správa ako neprístupný
tenant. Expirácia alebo invalidácia kontextu blokuje chránené požiadavky bez
tichého návratu k systémovým oprávneniam. Podrobnosti sú v
[`IMPERSONATION.md`](./IMPERSONATION.md).

## Systémová správa tenantov

`POST /api/v1/system/tenants` vyžaduje UUID `Idempotency-Key`. V jednej transakcii
vytvorí tenant v stave `PENDING`, štyri rezervované tenantové roly, pozvánku s
počiatočnou rolou `TENANT_OWNER`, šifrovanú outbox udalosť, audit a idempotency
záznam. Rovnaký kľúč a payload bezpečne zopakujú výsledok; rovnaký kľúč s iným
payloadom vráti konflikt. Prijatie pozvánky prvého vlastníka atómovo priradí rolu
a aktivuje čakajúci tenant.

Lifecycle používa optimistickú `revision` a povinný dôvod. Podporuje
`ACTIVE ↔ SUSPENDED`, prechod do `ARCHIVED`, žiadosť `DELETION_PENDING` a jej
zrušenie späť na `ARCHIVED`. Žiadosť nastaví 30-dňovú ochrannú lehotu; priame
`DELETED` API nie je dostupné. Aktivácia vyžaduje aspoň jedného aktívneho
`TENANT_OWNER`. Každá skutočná zmena stavu sa auditne zaznamená.

## Vrstvy izolácie

Aktuálny checkpoint vynucuje autentifikáciu, route-derived kontext, membership
stav, tenant stav, repository filtre, databázové constraints a negatívne API aj
repository testy. PostgreSQL RLS sa pridá na tenantové business tabuľky pred
produkciou podľa [ADR 0003](./adr/0003-postgresql-shared-schema-multitenancy.md);
aplikačné filtre a testy zostanú povinné aj po jeho zapnutí.

## Frontendový tenantový tok

Výberová obrazovka načítava iba výsledok `GET /api/v1/tenants`. Pri navigácii na
`/t/:tenantSlug` klient nepovažuje predchádzajúci zoznam za autoritatívny: znova ho
načíta, nájde tenant ID pre slug a potvrdí ho cez
`GET /api/v1/tenants/{tenantId}`. Neprístupný tenant používateľa vráti na výber;
expirovaná relácia ho vráti na login.

Aktívny tenant je iba pamäťový signal store a po úspešnom odhlásení alebo
`SESSION_REQUIRED` sa vyčistí. Klient neposiela tenantový identifikátor vo voľnej
hlavičke ani ho nepersistuje ako zdroj oprávnenia.

Úplný HTTP kontrakt je v [`openapi.json`](./openapi.json).
Nemennosť, filtre a stránkovanie auditných endpointov popisuje
[`SECURITY_AUDIT.md`](./SECURITY_AUDIT.md).
