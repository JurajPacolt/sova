# ADR 0004: Serverové relácie cez bezpečné cookies

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

SOVA je webová first-party aplikácia, ktorá potrebuje okamžitú revokáciu relácie,
prehľad aktívnych zariadení a bezpečnú zmenu oprávnení. Dlhodobý bearer token v
`localStorage` zväčšuje dopad XSS a stateless JWT komplikuje okamžitú revokáciu.

## Rozhodnutie

- Autentifikácia používa nepriehľadnú serverovú reláciu s náhodným tokenom s
  minimálne 256 bitmi entropie.
- Prehliadač dostane session token iba v cookie `HttpOnly`, `Secure` v produkcii,
  `SameSite=Lax`, `Path=/`. Databáza obsahuje iba SHA-256 hash tokenu.
- Stav meniace požiadavky používajú samostatný náhodný CSRF token viazaný na reláciu,
  cookie a hlavičku; server porovnáva ich hash konštantným spôsobom.
- Predvolená absolútna životnosť relácie je 8 hodín. Odhlásenie, zmena hesla,
  bezpečnostná udalosť alebo administratívna revokácia ju ukončia serverovo.
- Login vytvorí nový token, používateľ vidí svoje aktívne relácie a môže ich
  jednotlivo zrušiť. Token ani citlivé request body sa nelogujú.
- Privilegované operácie môžu vyžadovať čerstvé opätovné overenie a MFA.
- API odpovede obsahujúce autentifikačný kontext používajú `Cache-Control: no-store`.

## Dôsledky

### Pozitívne

- token nie je dostupný bežnému JavaScriptu,
- relácie a zmenené oprávnenia možno okamžite zneplatniť,
- server má auditovateľný zoznam aktívnych relácií.

### Náklady a obmedzenia

- server udržiava stav a musí čistiť expirované relácie,
- cookie autentifikácia vyžaduje CSRF ochranu a úzky CORS režim,
- prípadní budúci neprehliadačoví klienti budú potrebovať osobitný OAuth/OIDC
  kontrakt, nie opätovné použitie webovej cookie.

## Referencie

- [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- [OWASP CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
