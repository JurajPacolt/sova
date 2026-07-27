# SOVA – technický kontrakt autentifikácie

Webová aplikácia používa nepriehľadné serverové relácie. Autentifikačný token sa
nikdy neukladá do `localStorage`, databázy ani logu v čitateľnej podobe.

## Endpointy

| Metóda   | Route                                      | Ochrana                         | Výsledok                                |
| -------- | ------------------------------------------ | ------------------------------- | --------------------------------------- |
| `POST`   | `/api/v1/auth/login`                       | Verejný, account/IP rate limit  | Používateľ, relácia a dve cookies       |
| `POST`   | `/api/v1/auth/password/forgot`             | Verejný, account/IP rate limit  | Vždy všeobecné prijatie                 |
| `POST`   | `/api/v1/auth/password/reset`              | Verejný, jednorazový token      | Nové heslo a revokácia relácií          |
| `POST`   | `/api/v1/auth/email/verification/request`  | Verejný, account/IP rate limit  | Vždy všeobecné prijatie                 |
| `POST`   | `/api/v1/auth/email/verify`                | Verejný, jednorazový token      | Aktivácia overeného účtu                |
| `POST`   | `/api/v1/auth/invitations/inspect`         | Verejný, jednorazový token      | Náhľad tenantovej pozvánky              |
| `POST`   | `/api/v1/auth/invitations/accept`          | Verejný, jednorazový token      | Nový overený účet a členstvo            |
| `POST`   | `/api/v1/auth/invitations/accept-existing` | Session cookie + CSRF + token   | Členstvo existujúceho účtu              |
| `POST`   | `/api/v1/auth/logout`                      | Session cookie + CSRF           | Revokácia aktuálnej relácie             |
| `GET`    | `/api/v1/auth/session`                     | Session cookie                  | Efektívna identita, rola a impersonácia |
| `GET`    | `/api/v1/auth/sessions`                    | Session cookie                  | Aktívne relácie používateľa             |
| `DELETE` | `/api/v1/auth/sessions/{sessionId}`        | Session cookie + CSRF           | Revokácia vlastnej relácie              |
| `POST`   | `/api/v1/tenants/{tenantId}/invitations`   | Session + CSRF + tenant context | Vytvorenie pozvánky                     |
| `POST`   | `/api/v1/system/impersonations`            | Session + CSRF + SUPERADMIN     | Spustenie kontrolovanej impersonácie    |
| `DELETE` | `/api/v1/system/impersonations/current`    | Session + CSRF                  | Okamžité ukončenie impersonácie         |

Úplné request a response schémy sú v [`openapi.json`](./openapi.json).

## Cookies a CSRF

| Cookie         | Obsah                                    | Vlastnosti                                                                 |
| -------------- | ---------------------------------------- | -------------------------------------------------------------------------- |
| `sova_session` | Náhodný 256-bitový session token         | `HttpOnly`, `Secure` v produkcii, `SameSite=Lax`, `Path=/`                 |
| `sova_csrf`    | Samostatný náhodný 256-bitový CSRF token | čitateľný Angular klientom, `Secure` v produkcii, `SameSite=Lax`, `Path=/` |

Databáza obsahuje iba SHA-256 hashe oboch tokenov. Pri `POST`, `PUT`, `PATCH` a
`DELETE` chránenom cookie reláciou klient skopíruje hodnotu `sova_csrf` do hlavičky
`X-CSRF-Token`. Server porovná hash hlavičky s hashom viazaným na aktuálnu reláciu.

Odhlásenie a revokácia aktuálnej relácie vrátia expirované cookies s
`Max-Age=0`. Expirovaná, revokovaná alebo neaktívnemu používateľovi patriaca relácia
vráti `401 SESSION_REQUIRED`.

## Prihlásenie

E-mail sa pred vyhľadaním oreže a normalizuje na lowercase. Databáza kompozíciu
`email` a `normalized_email` kontroluje constraintom. Heslo sa overuje cez Argon2id
a po úspechu sa podľa `password_needs_rehash()` bezpečne prehashuje.

Neexistujúci účet, nesprávne heslo aj neaktívny stav používateľa vykonajú overenie
Argon2id a vrátia rovnakú odpoveď:

```text
401 INVALID_CREDENTIALS
```

Tým API neposkytuje rozdiel, podľa ktorého by sa dal enumerovať účet. Neplatný tvar
vstupu vracia `422 LOGIN_INPUT_INVALID`.

## Rate limiting

Neúspech sa započíta do dvoch nezávislých bucketov:

- HMAC normalizovaného účtu,
- HMAC zdrojovej IP adresy.

HMAC kľúče bránia tomu, aby tabuľka rate limitu obsahovala e-mail alebo IP v
čitateľnej podobe. Kľúč `AUTH_RATE_LIMIT_SECRET` musí mať aspoň 16 znakov a v
produkcii musí byť náhodný secret mimo repozitára.

Predvolené limity sú 5 pokusov na účet a 20 pokusov na IP v 15-minútovom okne.
Blokovanie trvá 15 minút a vracia `429 LOGIN_RATE_LIMITED`. Úspešné prihlásenie
vyčistí account bucket; IP bucket zostáva zachovaný proti skúšaniu viacerých účtov
z jedného zdroja.

## Audit a súkromie

Tabuľka `authentication_events` zaznamenáva login, logout a revokáciu relácie s:

- UUIDv7 udalosti,
- známym používateľom a session ID, ak sú bezpečne známe,
- výsledkom a stabilným reason kódom,
- request ID,
- zdrojovou IP,
- UTC časom.

Heslo, session token, CSRF token, e-mail z neúspešného neznámeho loginu ani request
body sa do auditu alebo aplikačného logu nezapisujú.

## Konfigurácia

Lokálne hodnoty sú uvedené v `backend/.env.example`. Produkcia musí použiť:

- `AUTH_COOKIE_SECURE=true`,
- náhodný `AUTH_RATE_LIMIT_SECRET`,
- TLS na celej verejnej trase,
- primerane kalibrované Argon2id parametre,
- retenciu a prístupové pravidlá pre autentifikačný audit.

## Frontendový tok

Angular klient používa iba relatívne `/api/v1` URL a pre API požiadavky nastavuje
`withCredentials`. Pri `POST`, `PUT`, `PATCH` a `DELETE` prečíta verejnú
`sova_csrf` cookie a pošle ju ako `X-CSRF-Token`; `HttpOnly` session cookie zostáva
pre JavaScript nedostupná. Žiadny autentifikačný token sa neukladá do web storage.

`AuthGuard` obnoví neznámy stav cez `GET /api/v1/auth/session`. Odpoveď obsahuje
aktuálnu identitu a `is_superadmin`, ktoré sa pri každom volaní znovu načíta z
`user_system_roles`; odobratie systémovej roly sa preto prejaví bez nového loginu.
`AnonymousGuard` nepustí aktívnu reláciu späť na login, `SuperadminGuard` chráni
oddelený `/system` kontext a `TenantGuard` pred
zobrazením tenantového shellu vždy nanovo overí dostupnosť tenantového slugu aj
konkrétneho tenant ID. Frontendová cache preto nikdy nenahrádza backendovú
autorizáciu.

Presmerovanie po prihlásení používa allowlist interných ciest
`/select-tenant`, `/t/:tenantSlug/...` a pre aktuálneho `SUPERADMIN` aj
`/system/...`. Externé, protokolovo relatívne, neplatné a neprístupné ciele sa
zahodia. Globálny 401 handler reaguje iba na stabilný kód
`SESSION_REQUIRED`, vyčistí auth aj tenantový stav a presmeruje na login s bezpečným
`returnUrl`. Verejné recovery, verification a invitation routy sú z tohto
presmerovania vyňaté, aby anonymné overenie relácie neprerušilo jednorazový tok.

Počas impersonácie vracia aktuálna relácia efektívneho používateľa,
`is_superadmin=false` a samostatný objekt so skutočným aktérom, tenantom, dôvodom,
stavom a expiráciou. Expirácia alebo invalidácia kontextu nereaktivuje plný bypass
potichu; bežné operácie sa zablokujú, kým klient impersonáciu explicitne neukončí.
Úplný kontrakt je v [`IMPERSONATION.md`](./IMPERSONATION.md).

## Jednorazové tokeny

Reset hesla a overenie e-mailu používajú tabuľku `user_action_tokens`; pozvánky
používajú tenantovo viazanú tabuľku `tenant_invitations`. Všetky tri druhy tokenov
majú 256 bitov náhodnej entropie, URL-safe reprezentáciu a databáza ukladá iba ich
SHA-256 hash. Hashovanie je pri takto dlhom náhodnom tajomstve určené na ochranu
hodnoty v databáze, nie na hashovanie používateľských hesiel.

| Účel               | Predvolená platnosť | Ďalšie pravidlo                                           |
| ------------------ | ------------------- | --------------------------------------------------------- |
| Reset hesla        | 30 minút            | nové vydanie zruší starší aktívny reset token             |
| Overenie e-mailu   | 24 hodín            | nové vydanie zruší starší aktívny verifikačný token       |
| Tenantová pozvánka | 7 dní               | jedna čakajúca pozvánka pre tenant a normalizovaný e-mail |

Spotrebovanie používateľského tokenu je jeden podmienený `UPDATE ... RETURNING`;
súbežný druhý pokus preto už nemôže získať platný token. Použitý, zrušený,
expirovaný, nesprávny a syntakticky neplatný token dostane rovnaký verejný výsledok,
aby stav účtu nebolo možné odvodiť.

Návrh sleduje odporúčanie
[OWASP Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html):
odpoveď na požiadavku nesmie prezradiť existenciu účtu a token musí byť
kryptograficky náhodný, bezpečne uložený, časovo obmedzený a jednorazový. API aj
frontend používajú `Referrer-Policy: no-referrer`; token sa po úspechu odstráni z
histórie URL.

## Obnova hesla

`POST /api/v1/auth/password/forgot` pre každý syntakticky platný e-mail vráti
rovnaké `202` a rovnaký text. Endpoint existenciu účtu nevyhľadáva. E-mail vloží do
citlivého payloadu šifrovaného cez libsodium `secretbox`; bežný outbox záznam
neobsahuje e-mail ani token. Rate limit používa iba HMAC account/IP buckety a po
prekročení požiadavku potichu potlačí bez zmeny verejnej odpovede. Predvolene
povolí tri požiadavky na účet a dvadsať na IP za hodinu.

E-mailový worker zamkne identity udalosti cez `FOR UPDATE SKIP LOCKED`. Až po
odpovedi API rozšifruje e-mail a:

- pre neexistujúci alebo neaktívny účet udalosť bezpečne uzavrie bez odoslania,
- pre aktívny účet vytvorí 30-minútový token a odošle e-mail cez Symfony Mailer,
- po úspechu okamžite prepíše šifrovaný payload hodnotou `PURGED`,
- pri dočasnom zlyhaní rollbackne token, použije exponenciálny backoff a najviac
  päť pokusov.

SMTP odoslanie prebieha v transakcii so zápisom tokenu. Doručenie je at-least-once:
pri páde po prijatí správy SMTP môže používateľ dostať ďalší e-mail, ale nové
spracovanie zruší predchádzajúci token, takže platný je iba najnovší odkaz.

`POST /api/v1/auth/password/reset` overí token a v jednej transakcii:

1. atómovo spotrebuje token,
2. overí aktívny účet a heslovú politiku,
3. uloží nový Argon2id hash,
4. zruší všetky existujúce relácie s dôvodom `PASSWORD_RESET`,
5. vyčistí account login rate-limit bucket,
6. zapíše autentifikačný audit.

Chyba heslovej politiky rollbackne aj spotrebovanie tokenu. Použitý, expirovaný,
zrušený alebo neplatný token vráti jednotné `410 PASSWORD_RESET_TOKEN_INVALID`.
Po úspechu nevznikne automatická relácia; používateľ sa prihlási bežným login tokom.

Heslo má najmenej 15 Unicode znakov a najviac 1024 bajtov. SOVA nepoužíva povinnú
kombináciu veľkých písmen, číslic a symbolov; blokuje bežné a kontextové heslá a
podporuje passphrase. Toto zodpovedá aktuálnemu odporúčaniu
[NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html), ktoré
uprednostňuje dĺžku a blocklist pred kompozičnými pravidlami.

## Overenie e-mailu

`POST /api/v1/auth/email/verification/request` používa pre syntakticky platný e-mail
rovnakú `202` odpoveď bez ohľadu na existenciu, stav účtu alebo rate limit. Má
vlastné HMAC account/IP buckety oddelené od obnovy hesla. Bežný outbox záznam
neobsahuje e-mail a worker odošle správu iba účtu v stave
`PENDING_VERIFICATION`.

Worker vydá 24-hodinový `EMAIL_VERIFICATION` token; novým vydaním zruší starší
aktívny token. `POST /api/v1/auth/email/verify` token atómovo spotrebuje a v rovnakej
transakcii:

1. zmení `PENDING_VERIFICATION` na `ACTIVE`,
2. zapíše UTC `email_verified_at`,
3. vytvorí autentifikačný audit `EMAIL_VERIFIED`.

Prvé použitie vráti `{"status":"VERIFIED"}`. Opakovanie toho istého úspešne
spotrebovaného tokenu je idempotentné a vráti
`{"status":"ALREADY_VERIFIED"}`. Náhodný, zrušený, expirovaný alebo k inému stavu
účtu patriaci token vráti jednotné `410 EMAIL_VERIFICATION_TOKEN_INVALID`.
Zablokovaný alebo deaktivovaný účet sa tokenom neaktivuje.

## Invite-only onboarding

Verejný registračný endpoint neexistuje. V základnej F2.5 verzii môže pozvánku
vytvoriť iba `SUPERADMIN` v explicitnom tenantovom kontexte cez
`POST /api/v1/tenants/{tenantId}/invitations`. Od F3.1 endpoint používa centrálnu
permission službu a oprávnenie `tenant.members.invite`; fail-closed provider zatiaľ
ponecháva bootstrap dostupný iba `SUPERADMIN`. Databázové priradenie tenantových a
projektových rolí alebo pracovných skupín patrí do F3.2 a F4.

Vytvorenie pozvánky:

- je povolené len pre tenant v stave `PENDING`, `ACTIVE` alebo `SUSPENDED`,
- odmietne aktívne členstvo aj inú neexpirovanú pozvánku pre rovnaký tenant a
  normalizovaný e-mail,
- uloží sedemdňovú pozvánku iba s SHA-256 hashom tokenu,
- vloží plaintext token výhradne do krátkodobého libsodium payloadu pre worker,
- nikdy token nevráti v API odpovedi,
- zapíše audit `TENANT_INVITATION_CREATED`.

Spoločný e-mailový worker spracúva identity aj invitation udalosti cez
`FOR UPDATE SKIP LOCKED`. Pred odoslaním znovu overí aktuálny stav pozvánky; po
úspechu, expirácii delivery požiadavky alebo trvalom zlyhaní citlivý payload
prepíše hodnotou `PURGED`. SMTP retry používa rovnaký obmedzený exponenciálny
backoff ako recovery e-maily.

Frontend načíta náhľad cez `POST /api/v1/auth/invitations/inspect`; token preto nie
je v query stringu ani v serverovom access logu API. Po načítaní ho okamžite
odstráni aj z browser URL histórie pomocou `replaceState`. Držiteľ e-mailového
odkazu tým preukazuje kontrolu nad pozvanou schránkou:

- nový používateľ odošle token, zobrazované meno, preferovaný jazyk a heslo cez
  `POST /api/v1/auth/invitations/accept`; v jednej transakcii vznikne `ACTIVE`
  účet s `email_verified_at`, aktívne členstvo a spotrebovaná pozvánka,
- existujúci používateľ sa musí prihlásiť presne účtom s pozvaným normalizovaným
  e-mailom a prijíma cez CSRF-chránený
  `POST /api/v1/auth/invitations/accept-existing`,
- pozvánka nikdy automaticky nereaktivuje `DISABLED` alebo `REMOVED` členstvo,
- druhé alebo súbežné použitie, expirovaný a náhodný token dostanú rovnaké
  `410 INVITATION_TOKEN_INVALID`,
- úspech zapíše audit `TENANT_INVITATION_ACCEPTED`; heslo ani token sa neauditujú.

Pozvánku pre pozastavený tenant možno prijať, ale bežný tenantový kontext zostáva
zablokovaný pravidlami v [`TENANCY.md`](./TENANCY.md), kým sa tenant znovu
neaktivuje.
