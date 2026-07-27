# SOVA – Problem Details

API používa pre chybové odpovede formát RFC 9457 s media typom
`application/problem+json`. Každá odpoveď obsahuje stabilný typ a kód, bezpečný
detail, HTTP stav, cestu výskytu a correlation ID z hlavičky `X-Request-ID`.

## Základná taxonómia

| HTTP | Problem type                               | Predvolený kód            |
| ---: | ------------------------------------------ | ------------------------- |
|  400 | `urn:sova:problem:invalid-request`         | `INVALID_REQUEST`         |
|  401 | `urn:sova:problem:authentication-required` | `AUTHENTICATION_REQUIRED` |
|  403 | `urn:sova:problem:permission-denied`       | `PERMISSION_DENIED`       |
|  404 | `urn:sova:problem:resource-not-found`      | `RESOURCE_NOT_FOUND`      |
|  405 | `urn:sova:problem:method-not-allowed`      | `METHOD_NOT_ALLOWED`      |
|  406 | `urn:sova:problem:not-acceptable`          | `NOT_ACCEPTABLE`          |
|  409 | `urn:sova:problem:conflict`                | `RESOURCE_CONFLICT`       |
|  410 | `urn:sova:problem:gone`                    | `RESOURCE_GONE`           |
|  413 | `urn:sova:problem:payload-too-large`       | `PAYLOAD_TOO_LARGE`       |
|  415 | `urn:sova:problem:unsupported-media-type`  | `UNSUPPORTED_MEDIA_TYPE`  |
|  422 | `urn:sova:problem:validation-failed`       | `VALIDATION_FAILED`       |
|  429 | `urn:sova:problem:rate-limit-exceeded`     | `RATE_LIMIT_EXCEEDED`     |
|  500 | `urn:sova:problem:internal-server-error`   | `INTERNAL_SERVER_ERROR`   |
|  503 | `urn:sova:problem:service-unavailable`     | `SERVICE_UNAVAILABLE`     |

Doménová chyba používa typ z tejto taxonómie, ale predvolený kód nahradí
konkrétnym stabilným kódom, napríklad `ISSUE_VERSION_CONFLICT` alebo
`WORKFLOW_INVALID`. Kódy používajú upper snake case a po zverejnení sa ich význam
nesmie spätne meniť.

## Tvar odpovede

```json
{
  "type": "urn:sova:problem:validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The project input is invalid.",
  "instance": "/api/v1/tenants/tenant-id/projects",
  "request_id": "17c607e69f694416ba0173fce4fb6bf5",
  "code": "PROJECT_INPUT_INVALID",
  "errors": {
    "name": ["Enter a project name."]
  }
}
```

Pole `errors` je voliteľné a používa sa iba pri type `validation-failed`. Kľúč je
stabilná cesta poľa z API payloadu; hodnotou je neprázdny zoznam bezpečných správ.

## Použitie v backend module

Aplikačná alebo doménová vrstva vyhodí `DomainProblemException` a neposkladá HTTP
odpoveď sama:

```php
throw new DomainProblemException(
    ProblemType::Conflict,
    'ISSUE_VERSION_CONFLICT',
    'The issue was changed by another user.',
);
```

HTTP middleware prevedie výnimku cez jedinú centrálnu mapu. Neočakávané výnimky
nikdy v produkcii nevracajú message, stack trace, SQL text, názov triedy ani secret.
Celá výnimka sa zaznamená iba do serverového logu spolu s `request_id`,
`problem_code`, metódou, cestou a HTTP stavom.

Pri tenantovo citlivom zdroji sa používa rovnaký typ 404 a rovnaký bezpečný detail
pre neexistujúci aj neprístupný objekt, aby odpoveď nepotvrdila existenciu cudzieho
záznamu.

Tenantový context middleware používa kód `TENANT_NOT_FOUND` pre neplatné UUID,
neexistujúci alebo odstránený tenant, chýbajúce či deaktivované členstvo aj
pozastavený tenant. Výnimkou je explicitný `SUPERADMIN` kontext, ktorý môže otvoriť
každý neodstránený tenant a zapisuje bezpečnostný audit.

## Publikované identity a tenancy kódy

| Kód                                           | HTTP | Význam                                                         |
| --------------------------------------------- | ---: | -------------------------------------------------------------- |
| `LOGIN_INPUT_INVALID`                         |  422 | Neplatný tvar prihlasovacích údajov                            |
| `INVALID_CREDENTIALS`                         |  401 | Jednotný výsledok neúspešného prihlásenia                      |
| `LOGIN_RATE_LIMITED`                          |  429 | Prekročený login account alebo IP limit                        |
| `SESSION_REQUIRED`                            |  401 | Chýbajúca, expirovaná, revokovaná alebo neaktívna relácia      |
| `CSRF_TOKEN_INVALID`                          |  403 | Chýbajúci alebo nezhodný double-submit CSRF token              |
| `SESSION_NOT_FOUND`                           |  404 | Relácia neexistuje alebo nepatrí aktuálnemu používateľovi      |
| `PASSWORD_RESET_REQUEST_INVALID`              |  422 | Neplatný tvar požiadavky na obnovu hesla                       |
| `PASSWORD_RESET_INPUT_INVALID`                |  422 | Neplatné nové heslo alebo potvrdenie                           |
| `PASSWORD_POLICY_VIOLATION`                   |  422 | Heslo nespĺňa serverovú politiku                               |
| `PASSWORD_RESET_TOKEN_INVALID`                |  410 | Jednotný výsledok neplatného resetovacieho tokenu              |
| `EMAIL_VERIFICATION_REQUEST_INVALID`          |  422 | Neplatný tvar požiadavky na overenie e-mailu                   |
| `EMAIL_VERIFICATION_TOKEN_INVALID`            |  410 | Jednotný výsledok neplatného verifikačného tokenu              |
| `INVITATION_INPUT_INVALID`                    |  422 | Neplatný e-mail pri vytváraní pozvánky                         |
| `INVITATION_TENANT_UNAVAILABLE`               |  409 | Stav tenantu nepovoľuje nové pozvánky                          |
| `INVITATION_ALREADY_MEMBER`                   |  409 | Pozvaný e-mail už má aktívne členstvo                          |
| `INVITATION_ALREADY_PENDING`                  |  409 | Pre tenant a e-mail už existuje platná pozvánka                |
| `INVITATION_TOKEN_INVALID`                    |  410 | Jednotný výsledok neplatnej alebo nepoužiteľnej pozvánky       |
| `INVITATION_ACCEPTANCE_INPUT_INVALID`         |  422 | Neplatný profil alebo heslo nového pozvaného účtu              |
| `INVITATION_ACCOUNT_EXISTS`                   |  409 | Pozvaný e-mail už patrí existujúcemu účtu                      |
| `INVITATION_ACCOUNT_MISMATCH`                 |  403 | Prihlásený účet sa nezhoduje s pozvaným e-mailom               |
| `INVITATION_MEMBERSHIP_BLOCKED`               |  409 | Pozvánka nesmie reaktivovať zakázané alebo odstránené členstvo |
| `TENANT_NOT_FOUND`                            |  404 | Jednotný výsledok neexistujúceho alebo neprístupného tenantu   |
| `TENANT_ROLE_NOT_FOUND`                       |  404 | Rola neexistuje v aktuálnom tenantovi                          |
| `TENANT_ROLE_INPUT_INVALID`                   |  422 | Neplatná definícia vlastnej tenantovej roly                    |
| `TENANT_ROLE_CODE_CONFLICT`                   |  409 | Kód tenantovej roly už v tenantovi existuje                    |
| `TENANT_ROLE_REVISION_CONFLICT`               |  409 | Tenantovú rolu medzitým zmenila iná požiadavka                 |
| `TENANT_ROLE_IMMUTABLE`                       |  409 | Rezervovanú systémovú tenantovú rolu nemožno zmeniť            |
| `TENANT_ROLE_ASSIGNED`                        |  409 | Priradenú tenantovú rolu nemožno archivovať                    |
| `TENANT_MEMBERSHIP_NOT_FOUND`                 |  404 | Členstvo neexistuje v aktuálnom tenantovi                      |
| `TENANT_MEMBERSHIP_INPUT_INVALID`             |  422 | Neplatný požadovaný stav členstva                              |
| `TENANT_MEMBERSHIP_TRANSITION_INVALID`        |  409 | Stavový prechod členstva nie je povolený                       |
| `TENANT_MEMBERSHIP_SELF_MANAGEMENT_FORBIDDEN` |  409 | Správca nesmie týmto tokom meniť vlastné členstvo              |
| `TENANT_MEMBERSHIP_OPERATION_UNAVAILABLE`     |  409 | Stav tenantu nepovoľuje zmenu členstva                         |
| `TENANT_MEMBERSHIP_INACTIVE`                  |  409 | Rolu možno priradiť iba aktívnemu členstvu                     |
| `TENANT_ROLE_INACTIVE`                        |  409 | Archivovanú rolu nemožno priradiť                              |
| `TENANT_ROLE_OPERATION_UNAVAILABLE`           |  409 | Stav tenantu nepovoľuje zmenu rolí                             |
| `TENANT_LAST_OWNER_REQUIRED`                  |  409 | Operácia by odstránila posledného aktívneho vlastníka          |
| `IDEMPOTENCY_KEY_INVALID`                     |  422 | Chýba platný UUID idempotency kľúč systémovej operácie         |
| `IDEMPOTENCY_KEY_REUSED`                      |  409 | Idempotency kľúč už bol použitý s iným payloadom               |
| `SYSTEM_TENANT_INPUT_INVALID`                 |  422 | Neplatný názov, slug alebo e-mail prvého vlastníka             |
| `SYSTEM_TENANT_LIFECYCLE_INPUT_INVALID`       |  422 | Neplatný cieľový stav, revision alebo dôvod lifecycle zmeny    |
| `SYSTEM_TENANT_NOT_FOUND`                     |  404 | Tenant neexistuje v systémovom administračnom kontexte         |
| `TENANT_SLUG_TAKEN`                           |  409 | Slug už patrí inému tenantovi                                  |
| `TENANT_REVISION_CONFLICT`                    |  409 | Tenant medzitým zmenila iná požiadavka                         |
| `TENANT_STATUS_TRANSITION_INVALID`            |  409 | Požadovaný lifecycle prechod tenantu nie je povolený           |
| `TENANT_ACTIVE_OWNER_REQUIRED`                |  409 | Aktivácia vyžaduje aktívneho `TENANT_OWNER`                    |
| `AUDIT_QUERY_INVALID`                         |  422 | Neplatný auditný filter, časový rozsah alebo keyset cursor     |
| `IMPERSONATION_INPUT_INVALID`                 |  422 | Neplatný tenant, cieľ, dôvod alebo reautentifikačné heslo      |
| `IMPERSONATION_REAUTHENTICATION_FAILED`       |  401 | Aktuálne heslo administrátora nebolo overené                   |
| `IMPERSONATION_TARGET_NOT_FOUND`              |  404 | Aktívny cieľ v aktívnom tenantovi neexistuje                   |
| `IMPERSONATION_ALREADY_ACTIVE`                |  409 | Relácia už má otvorenú impersonáciu                            |
| `IMPERSONATION_NOT_ACTIVE`                    |  409 | Relácia nemá impersonáciu, ktorú možno ukončiť                 |
| `IMPERSONATION_EXPIRED`                       |  409 | Kontext prekročil maximálnu 15-minútovú platnosť               |
| `IMPERSONATION_INVALIDATED`                   |  409 | Účet, členstvo, tenant alebo systémová rola zanikli            |
| `IMPERSONATION_OPERATION_FORBIDDEN`           |  403 | Identitná operácia nie je počas impersonácie povolená          |
