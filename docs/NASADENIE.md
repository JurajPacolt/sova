# SOVA – nasadenie na klasický webhosting (PHP + PostgreSQL)

- Verzia: 2.0
- Dátum: 2026-08-01
- Rozsah: jedna produkčná inštalácia na jednej doméne, bez Dockera

Návod je písaný pre bežný webhosting typu cPanel / Plesk / DirectAdmin, kde
poskytovateľ dáva PHP, PostgreSQL databázu, FTP alebo SSH a cron. Pre nasadenie
na vlastný server s Dockerom slúži `docs/STAGING.md`.

SOVA nie je jeden PHP skript. Sú to tri veci naraz:

1. **API** – PHP aplikácia, ktorá potrebuje jeden vstupný bod na `/api`,
2. **frontend** – vopred skompilované statické súbory (HTML, JS, CSS),
3. **dve úlohy na pozadí** – bez nich sa neodošle žiadny e-mail.

Preto sa nedá len „nahrať cez FTP a hotovo“. Celý postup má deväť krokov a pri
prvom nasadení zaberie zhruba dve hodiny.

---

## Krok 0 – overte, či hosting stačí (urobte to ako prvé)

Toto rozhodne, či má zmysel pokračovať. Prihláste sa cez SSH a spustite:

```bash
php -v
php -m | grep -E 'pdo_pgsql|sodium|mbstring|fileinfo|zip|json'
```

Musíte vidieť **PHP 8.3 alebo novšie** a **všetkých šesť** rozšírení. Kritické sú
dve: bez `pdo_pgsql` sa aplikácia nepripojí k databáze, bez `sodium` nedokáže
zašifrovať jednorazové odkazy v e-mailoch. Ani jedno sa nedá obísť.

> **Pozor na dve verzie PHP.** Na väčšine hostingov je verzia pre web (nastavená
> v paneli) iná než verzia v SSH a v crone. `php -v` ukazuje tú druhú. Ak vypíše
> staršiu verziu, vypýtajte si od podpory presnú cestu k PHP 8.3 – býva to niečo
> ako `/usr/local/php83/bin/php` alebo `/opt/alt/php83/usr/bin/php`. Túto cestu
> budete potrebovať v krokoch 6, 7 a 8.

Potom otestujte databázu. Údaje nájdete v paneli hostingu:

```bash
psql "postgresql://pouzivatel:heslo@host:5432/databaza" -c "SELECT version();"
psql "postgresql://pouzivatel:heslo@host:5432/databaza" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
psql "postgresql://pouzivatel:heslo@host:5432/databaza" -c "SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user;"
```

Čo z toho musí vyjsť:

| Príkaz              | Očakávaný výsledok                                                       |
| ------------------- | ------------------------------------------------------------------------ |
| `SELECT version()`  | PostgreSQL **14 alebo novší**                                             |
| `CREATE EXTENSION`  | `CREATE EXTENSION` alebo `NOTICE: extension already exists` – nie chyba   |
| `SELECT rolsuper …` | obe hodnoty `f` (false)                                                   |

- **`pg_trgm` je povinné.** Používa ho index pre vyhľadávanie v úlohách a jedna
  z migrácií ho vytvára. Od PostgreSQL 13 si ho vlastník databázy smie vytvoriť
  sám, ale súbory rozšírenia musia byť na serveri nainštalované. Ak príkaz
  skončí chybou `permission denied` alebo `could not open extension control
  file`, napíšte podpore, nech `pg_trgm` sprístupní. Bez neho migrácie neprejdú.
- **`rolsuper` a `rolbypassrls` musia byť `f`.** Účet, ktorý obchádza riadkovú
  bezpečnosť, ticho vyradí z hry ochranu, ktorá oddeľuje dáta jednotlivých
  tenantov – bez akejkoľvek chybovej hlášky. Bežný účet z panela hostingu túto
  podmienku spĺňa; ak nie, vypýtajte si neprivilegovaný účet.

Ak `psql` na hostingu nie je, spustite tie isté príkazy zo svojho počítača
(pokiaľ databáza prijíma vzdialené pripojenia), alebo použite SQL konzolu
v paneli.

Ešte skontrolujte, či máte:

- možnosť nastaviť **cron** s intervalom jednej minúty,
- povolený **`.htaccess` s `mod_rewrite`** (Apache) alebo prístup ku konfigurácii nginxu,
- **HTTPS certifikát** pre doménu,
- **SMTP účet** – adresa servera, port, meno a heslo.

---

## Čo na klasickom hostingu fungovať nebude

Toto si prečítajte skôr, než začnete – nie je to chyba nastavenia, ale vlastnosť
aplikácie:

**Nahrávanie príloh bude vracať chybu.** V produkčnom režime aplikácia zámerne
odmieta bežať bez antivírusového skenera príloh a vyžaduje ClamAV démona
dostupného cez sieťový port. Klasický webhosting ho prakticky nikdy neponúka.
Zvyšok aplikácie – prihlásenie, tenanti, projekty, úlohy, komentáre,
vyhľadávanie, dashboardy, e-maily – funguje normálne.

Máte tri možnosti:

1. **Zmieriť sa s tým.** Aplikácia beží, prílohy sa nedajú nahrať. Ak ich
   nepotrebujete, netreba riešiť nič.
2. **Vlastný ClamAV inde.** Rozbehnite `clamav-daemon` na najlacnejšom VPS,
   otvorte port 3310 iba pre IP adresu hostingu a v `.env` nastavte
   `ATTACHMENT_SCANNER=clamav` spolu s adresou a portom toho servera.
3. **Zmeniť kód.** Zákaz je jedna podmienka v `backend/config/dependencies.php`.
   Je to vedomé bezpečnostné rozhodnutie projektu, takže jeho odstránenie je
   rozhodnutie, ktoré musíte urobiť vy – dôsledkom je, že sa na server dostanú
   nekontrolované súbory a ostatní používatelia tenanta si ich stiahnu.

> **Neriešte to prepnutím `APP_ENV` na `development`.** Vyzerá to ako
> najjednoduchšie riešenie a je najhoršie z nich: vypne to nielen kontrolu
> skenera, ale aj povinné MFA pre správcu systému a poistku, ktorá aplikáciu
> zastaví pri nefunkčnej izolácii tenantov.

---

## Krok 1 – príprava balíka na svojom počítači

Tieto príkazy spúšťate **doma, nie na hostingu**. Potrebujete PHP 8.3, Composer 2
a Node.js 24.

> **Jedným príkazom.** Skript `scripts/build-hosting-package.sh` urobí celý tento
> krok a navyše poskladá hotový adresárový strom aj so súbormi z krokov 3 a 4:
>
> ```bash
> ./scripts/build-hosting-package.sh          # --verify spustí aj kontroly kvality
> ```
>
> Vznikne `build/sova-<verzia>/` a rovnomenný ZIP. V balíku je `public_html/`
> (ide do document rootu), `sova/` (ide vedľa neho, mimo webu), hotové
> `.htaccess`, `api/index.php`, predpripravená šablóna `.env` a `CITAJ-MA.txt`.
> Pracovný strom repozitára skript nemení – `backend/vendor/` si ponechá
> vývojárske balíky. Ostatné kroky (databáza, `.env`, migrácie, cron) sa nemenia.

Ručne to isté:

```bash
# backend bez vývojárskych balíkov
cd backend
composer install --no-dev --optimize-autoloader

# frontend
cd ../frontend
npm ci
npm run build
```

Vzniknú dve veci, ktoré budete nahrávať:

- `backend/` vrátane priečinka `vendor/`,
- obsah `frontend/dist/sova-frontend/browser/` – to sú statické súbory pre web.

---

## Krok 2 – databáza

V paneli hostingu vytvorte PostgreSQL databázu a používateľa. Poznačte si názov
databázy, meno, heslo, hostiteľa a port – v kroku 5 ich zapíšete do `.env`.

Nič ďalšie tu nerobte, tabuľky vytvorí krok 6.

---

## Krok 3 – čo kam nakopírovať

**Kľúčové pravidlo:** backend, `.env` ani prílohy nesmú ležať v priečinku, ktorý
web server sprístupňuje. Inak si ktokoľvek stiahne konfiguráciu s heslami
a cudzie súbory priamo z prehliadača.

```
/home/uzivatel/
├── sova/                       ← MIMO webu
│   ├── backend/                ← celý priečinok backend/ vrátane vendor/
│   │   ├── bin/
│   │   ├── config/
│   │   ├── migrations/
│   │   ├── public/
│   │   ├── src/
│   │   ├── var/                ← sem sa píšu logy
│   │   ├── vendor/
│   │   └── .env                ← vytvoríte v kroku 5
│   └── attachments/            ← úložisko príloh
└── public_html/                ← document root, sem ide frontend
    ├── index.html
    ├── main-*.js, styles-*.css, …
    ├── .htaccess
    └── api/
        ├── index.php
        └── .htaccess
```

Prenos súborov:

| Odkiaľ (váš počítač)                         | Kam (hosting)                            |
| -------------------------------------------- | ---------------------------------------- |
| celý `backend/` aj s `vendor/`                | `~/sova/backend/`                        |
| **obsah** `frontend/dist/sova-frontend/browser/` | `~/public_html/`                      |

Ak ste použili skript z kroku 1, tento strom už máte poskladaný: nahrajte
`build/sova-<verzia>/sova/` do `~/sova/` a **obsah**
`build/sova-<verzia>/public_html/` do `~/public_html/`.

Do `public_html` kopírujte **obsah** priečinka `browser/`, nie priečinok samotný.
`index.html` musí ležať priamo v document roote.

Nekopírujte `backend/tests/`, `node_modules/` ani `.git/`.

Potom cez SSH vytvorte priečinky, do ktorých aplikácia zapisuje:

```bash
mkdir -p ~/sova/attachments ~/sova/backend/var
chmod 750 ~/sova/attachments ~/sova/backend/var
```

> Ak vám hosting nedovolí nastaviť document root a `public_html` je pevne dané,
> štruktúra vyššie funguje tak, ako je. Ak document root nastaviť viete, môžete
> všetko dať pod `~/sova/` a nasmerovať ho na `~/sova/webroot/`.

---

## Krok 4 – vstupné body pre web

> Balík zo skriptu v kroku 1 už všetky tri súbory obsahuje. Tento krok je pre
> ručné nasadenie – a ako popis toho, čo v balíku nájdete.

### 4.1 `~/public_html/api/index.php`

Jediný vstupný bod API. Cesta v `require` musí smerovať na
`backend/config/bootstrap.php`; pri inej štruktúre upravte počet `../`:

```php
<?php

declare(strict_types=1);

use Slim\App;

/** @var App $app */
$app = require __DIR__ . '/../../sova/backend/config/bootstrap.php';
$app->run();
```

### 4.2 `~/public_html/api/.htaccess`

Pošle každú požiadavku na `/api/...` do front controllera:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

### 4.3 `~/public_html/.htaccess`

Angular je jednostránková aplikácia: každá adresa, ktorá nie je reálnym súborom,
musí vrátiť `index.html`. Zároveň nesmie prekryť `/api`:

```apache
RewriteEngine On

# Vynútené HTTPS – musí byť ako prvé, inak sa k nemu pravidlá nižšie nedostanú
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

# /api si obsluhuje vlastný .htaccess
RewriteRule ^api/ - [L]

# Existujúce súbory a priečinky sa servírujú priamo
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Všetko ostatné obslúži Angular
RewriteRule ^ index.html [L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

Ak hosting ukončuje HTTPS na svojom reverznom proxy, premenná `%{HTTPS}` nemusí
byť nastavená a presmerovanie by sa zacyklilo. V takom prípade nahraďte prvú
podmienku za `RewriteCond %{HTTP:X-Forwarded-Proto} !=https`.

> **Frontend volá API na relatívnej adrese `/api/v1`.** Musí preto bežať na tej
> istej doméne ako API. Samostatná doména typu `api.mojadomena.sk` bez zásahu do
> kódu fungovať nebude.

---

## Krok 5 – konfigurácia aplikácie (`.env`)

Cez SSH:

```bash
cd ~/sova/backend
cp .env.example .env
chmod 600 .env
```

Kľúče a heslá si vygenerujte (na svojom počítači aj na hostingu, kdekoľvek máte
`openssl`):

```bash
openssl rand -base64 32    # pre SENSITIVE_PAYLOAD_KEY
openssl rand -hex 32       # pre AUTH_RATE_LIMIT_SECRET
```

V `.env` upravte tieto hodnoty; ostatné môžu zostať tak, ako sú:

| Kľúč                       | Čo tam patrí                                                        |
| -------------------------- | ------------------------------------------------------------------- |
| `APP_ENV`                  | `production`                                                         |
| `APP_DEBUG`                | `false` – inak sa v chybách zobrazia interné detaily                 |
| `APP_PUBLIC_URL`           | `https://sova.mojadomena.sk` – použije sa v odkazoch v e-mailoch     |
| `APP_VERSION`              | napr. `1.0.0`                                                        |
| `LOG_PATH`                 | `/home/uzivatel/sova/backend/var/app.log`                            |
| `DATABASE_HOST`            | z panela, často `localhost`                                          |
| `DATABASE_PORT`            | zvyčajne `5432`                                                      |
| `DATABASE_NAME`            | názov databázy z panela                                              |
| `DATABASE_USER`            | používateľ databázy z panela                                         |
| `DATABASE_PASSWORD`        | heslo z panela                                                       |
| `DATABASE_SERVER_VERSION`  | **skutočná** verzia z kroku 0, napr. `16` – nie natvrdo `17`         |
| `DATABASE_SSL_MODE`        | `require`, ak je databáza na inom serveri, inak `prefer`             |
| `AUTH_COOKIE_SECURE`       | `true` – povinné pri HTTPS                                           |
| `AUTH_RATE_LIMIT_SECRET`   | výstup `openssl rand -hex 32`                                        |
| `SENSITIVE_PAYLOAD_KEY_ID` | napr. `prod-v1`                                                      |
| `SENSITIVE_PAYLOAD_KEY`    | výstup `openssl rand -base64 32`                                     |
| `ATTACHMENT_STORAGE_PATH`  | `/home/uzivatel/sova/attachments`                                    |
| `MAILER_DSN`               | `smtp://meno:heslo@smtp.hosting.sk:587`                              |
| `MAILER_FROM`              | overená adresa odosielateľa, napr. `sova@mojadomena.sk`              |
| `CORS_ALLOWED_ORIGINS`     | `https://sova.mojadomena.sk`                                         |

Dve veci, ktoré sa ľahko prehliadnu:

- Ak sú v SMTP hesle znaky `@`, `:` alebo `/`, zakódujte ich percentovým
  kódovaním – `@` je `%40`.
- `MAILER_DSN` nesmie zostať `null://null`. Produkčný režim s ním odmietne
  odoslať poštu.

> **`SENSITIVE_PAYLOAD_KEY` si uložte do správcu hesiel.** Šifruje jednorazové
> payloady v e-mailových odkazoch. Nedá sa dodatočne odvodiť a jeho zmena
> znefunkční všetky už rozposlané pozvánky a odkazy na obnovu hesla.

---

## Krok 6 – vytvorenie databázovej schémy

```bash
cd ~/sova/backend
php vendor/bin/doctrine-migrations migrations:status
```

Ak ste v kroku 0 zistili, že SSH používa staršie PHP, píšte plnú cestu:
`/usr/local/php83/bin/php vendor/bin/doctrine-migrations …`

Príkaz musí vypísať stav databázy. Keď skončí chybou pripojenia, opravte
`DATABASE_*` v `.env` skôr, než pokračujete. Potom:

```bash
php vendor/bin/doctrine-migrations migrations:migrate --no-interaction
```

Migrácie bežia v jednej transakcii – buď prejdú všetky, alebo sa nezmení nič.

---

## Krok 7 – prvý administrátor

Verejná registrácia zámerne neexistuje, prvý účet sa vytvára z príkazového
riadku. Heslo sa číta zo štandardného vstupu, takže sa neuloží do histórie
shellu ani do zoznamu procesov:

```bash
cd ~/sova/backend
read -rsp "Heslo: " SOVA_PWD; printf '\n'
printf '%s\n' "$SOVA_PWD" | php bin/bootstrap-superadmin.php \
  --email=admin@mojadomena.sk \
  --display-name="Hlavný administrátor" \
  --locale=sk
unset SOVA_PWD
```

Heslo musí mať aspoň 15 znakov. Príkaz sa dá spustiť len raz – po vzniku prvého
`SUPERADMIN` účtu sa táto cesta natrvalo uzavrie.

Pri prvom prihlásení produkčný režim vynúti nastavenie TOTP (Google
Authenticator, 1Password a podobné) a zobrazí jednorazové recovery kódy.
**Recovery kódy si odložte** – bez nich a bez telefónu sa do systému už
nedostanete. Systémové oprávnenia dostane až relácia s potvrdenou MFA.

---

## Krok 8 – úlohy na pozadí (cron)

Bez nich sa neodošle **žiadny** e-mail – ani overenie adresy, ani pozvánka do
tenanta. V paneli hostingu pridajte dve cron úlohy, obe s intervalom **každú
minútu**:

```
* * * * * cd /home/uzivatel/sova/backend && /usr/local/php83/bin/php bin/outbox-worker.php --once >/dev/null 2>&1
* * * * * cd /home/uzivatel/sova/backend && /usr/local/php83/bin/php bin/email-worker.php --once >/dev/null 2>&1
```

Cestu k PHP nahraďte tou z kroku 0. Cron takmer vždy používa inú – zvyčajne
staršiu – verziu PHP než web, takže bez plnej cesty úlohy tíško padajú.

Prepínač `--once` spracuje jednu dávku a skončí; bez neho by proces bežal
donekonečna a hosting by ho po chvíli zabil.

Než sa spoľahnete na cron, spustite oba príkazy raz ručne bez
`>/dev/null 2>&1` – uvidíte prípadnú chybu.

---

## Krok 9 – overenie

```bash
curl https://sova.mojadomena.sk/api/v1/health/live
# {"status":"ok","service":"SOVA API","version":"1.0.0"}

curl https://sova.mojadomena.sk/api/v1/health/ready
# {"status":"ready","checks":{"database":"ok","tenant_isolation":"enforced"}}
```

Druhá odpoveď je tá dôležitá:

- `"status":"ready"` – aplikácia vidí databázu,
- `"tenant_isolation":"enforced"` – databázový účet neobchádza riadkovú
  bezpečnosť, takže oddelenie tenantov reálne platí.

Ak dostanete HTTP 503 alebo `"tenant_isolation"` iné než `"enforced"`, používate
príliš privilegovaný databázový účet. Vráťte sa ku kroku 0.

Potom v prehliadači prejdite celý reťazec:

1. prihlásenie prvého administrátora a nastavenie MFA,
2. vytvorenie tenanta a odoslanie pozvánky,
3. doručenie pozvánky do schránky,
4. prijatie pozvánky v anonymnom okne prehliadača,
5. vytvorenie projektu, úlohy a komentára,
6. vyhľadanie úlohy,
7. odhlásenie a opätovné prihlásenie s TOTP kódom.

---

## Najčastejšie problémy

| Príznak                                            | Príčina a riešenie                                                                                              |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Biela stránka, v konzole 404 na `.js` súbory        | Obsah `browser/` sa nakopíroval ako podpriečinok. `index.html` musí byť priamo v document roote.                   |
| Stránka sa načíta, ale všetko hlási chybu           | Nefunguje `/api`. Otvorte `https://…/api/v1/health/live` a skontrolujte oba `.htaccess` aj cestu v `api/index.php`. |
| `Composer detected issues in your platform`         | Web beží na PHP staršom než 8.3. Prepnite verziu v paneli hostingu.                                                |
| `could not find driver`                             | Chýba rozšírenie `pdo_pgsql`. Vyžiadajte si ho od podpory.                                                         |
| `Call to undefined function sodium_crypto_secretbox` | Chýba rozšírenie `sodium`. Bez neho aplikácia bežať nemôže.                                                       |
| `500` na každej požiadavke                          | Chýba `.env` alebo je v ňom zlé heslo. Pozrite `~/sova/backend/var/app.log` a error log hostingu.                  |
| Migrácie padnú na `pg_trgm`                         | Rozšírenie nie je na serveri dostupné. Riešenie je len cez podporu hostingu.                                       |
| Prihlásenie prebehne, ale hneď vypadne              | `AUTH_COOKIE_SECURE=true` bez funkčného HTTPS, alebo reverzné proxy neposiela `X-Forwarded-Proto`.                  |
| E-maily neprichádzajú                               | Nebeží cron alebo používa zlú verziu PHP. Spustite worker ručne a prečítajte si výpis.                              |
| Prílohy hlásia chybu servera                        | Očakávané na klasickom hostingu – pozrite kapitolu o obmedzeniach.                                                 |
| `Permission denied` pri zápise                      | Priečinok z `ATTACHMENT_STORAGE_PATH` alebo `backend/var` neexistuje, prípadne doň PHP nesmie zapisovať.            |

Každá chybová odpoveď obsahuje pole `request_id`. Podľa neho nájdete
zodpovedajúci riadok v `var/app.log` – to je najrýchlejšia cesta k príčine.

---

## Aktualizácia na novú verziu

Poradie je dôležité:

```bash
# 1. doma: znovu zostavte oba balíky podľa kroku 1

# 2. nahrajte nový backend (BEZ .env, var/ a attachments/)

# 3. na hostingu spustite migrácie
cd ~/sova/backend
php vendor/bin/doctrine-migrations migrations:migrate --no-interaction

# 4. až teraz vymeňte obsah public_html
```

Súbor `.env`, priečinok `attachments/` ani databázu nikdy neprepisujte.

Pred každou aktualizáciou si urobte zálohu:

```bash
PGPASSWORD='heslo-z-env' pg_dump \
  --host=localhost --port=5432 --username=sova_uzivatel \
  --format=custom --file="sova-$(date +%F).dump" nazov_databazy

tar -czf "attachments-$(date +%F).tar.gz" -C ~/sova attachments
```

Prílohy sa neukladajú do databázy, takže samotný dump ich neobsahuje – treba
zálohovať oboje.

---

## Bezpečnostný kontrolný zoznam

Pred spustením do ostrej prevádzky:

- [ ] `APP_ENV=production` a `APP_DEBUG=false`
- [ ] `.env` má práva `600` a leží mimo document rootu
- [ ] skúste `https://…/backend/.env` a `https://…/api/../.env` – ani jedno
      nesmie vypísať obsah konfigurácie
- [ ] `attachments/` a `var/` ležia mimo document rootu
- [ ] HTTPS funguje, presmerovanie z HTTP tiež, `AUTH_COOKIE_SECURE=true`
- [ ] `/api/v1/health/ready` hlási `tenant_isolation: enforced`
- [ ] `SENSITIVE_PAYLOAD_KEY` a `AUTH_RATE_LIMIT_SECRET` sú v správcovi hesiel
      a nie sú v gite
- [ ] prvý `SUPERADMIN` má nastavené TOTP a odložené recovery kódy
- [ ] obe cron úlohy bežia a e-maily reálne chodia
- [ ] beží pravidelná záloha databázy aj priečinka `attachments/`
- [ ] zálohu viete obnoviť – vyskúšajte to skôr, než ju budete potrebovať
      (`docs/OPERATIONS.md`, kapitola 8)

---

## Než začnete: vyskúšajte si to lokálne

Predtým, než sa pustíte do hostingu, spustite si celú aplikáciu u seba jedným
príkazom. Uvidíte, ako má výsledok vyzerať, a odlíšite chyby nasadenia od chýb
v aplikácii:

```bash
docker compose up --build
```

Aplikácia beží na `http://localhost:8080`, odchytené e-maily nájdete na
`http://localhost:8025` a prihlasovacie údaje vypíše kontajner `bootstrap` do
konzoly. Podrobnosti sú v komentároch v `docker-compose.yml`.
