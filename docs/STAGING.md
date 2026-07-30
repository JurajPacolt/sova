# SOVA – opakovateľné staging nasadenie

- Verzia: 1.0
- Dátum: 2026-07-29
- Rozsah: jedna staging inštalácia na Docker Compose hoste

Tento runbook vytvorí použiteľný staging z jedného release tagu. Staging zámerne
spúšťa `APP_ENV=production`, preto overuje produkčné poistky: secure cookies,
povinné MFA pre `SUPERADMIN`, reálny mail transport, malware sken a databázovú
rolu, ktorá neobchádza RLS.

Compose topológia je referenčné staging prostredie na jednom hoste. Nie je
produkčnou topológiou z ADR 0009: produkcia potrebuje spravovanú kontajnerovú
platformu, najmenej dve API repliky, spravovaný PostgreSQL s PITR, objektové
úložisko a správcu tajomstiev.

## 1. Čo sa nasadí

| Služba          | Úloha                                                        |
| --------------- | ------------------------------------------------------------ |
| `web`           | Angular statické súbory a Slim API cez Apache                |
| `outbox-worker` | bežné doménové udalosti                                      |
| `email-worker`  | pozvánky, verifikácia, obnova hesla a notifikačné e-maily    |
| `migrate`       | jednorazové Doctrine migrácie pred spustením novej aplikácie |
| `postgres`      | PostgreSQL 17 so samostatnou bootstrap a aplikačnou rolou    |
| `clamav`        | karanténny malware sken príloh cez streamovaný `INSTREAM`    |
| `mailpit`       | neverejný staging SMTP sink a náhľad odoslaných správ        |

PostgreSQL, ClamAV ani SMTP port nie sú publikované na hoste. Web a Mailpit sa
viažu iba na `127.0.0.1`; verejnú hranu musí tvoriť TLS reverse proxy.
Prílohy sú na samostatnom privátnom volume mimo webového document rootu.

## 2. Predpoklady

- Linux host s Docker Engine a Docker Compose v2;
- najmenej 6 GiB voľnej RAM; ClamAV potrebuje približne 3–4 GiB vrátane
  krátkeho súbehu databáz pri aktualizácii;
- DNS meno a TLS certifikát pre staging;
- reverse proxy na tom istom hoste alebo na presne známom privátnom rozsahu;
- odchádzajúce HTTPS/DNS pre stiahnutie image a aktualizáciu ClamAV signatúr.

Klon repozitára musí byť na konkrétnom commite. Release tag používajte v tvare
Git SHA alebo nemenného release identifikátora, napríklad
`2026.07.29-a1b2c3d`. Deploy skript odmietne prepísať už existujúci lokálny
image s rovnakým tagom.

## 3. Konfigurácia a tajomstvá

```bash
cp deploy/staging/.env.example deploy/staging/.env
chmod 600 deploy/staging/.env
```

Všetky `CHANGE_ME` hodnoty musia byť nahradené. Náhodné hodnoty možno vytvoriť:

```bash
openssl rand -base64 36
openssl rand -hex 32
openssl rand -base64 32
```

Použitie:

- prvý výstup samostatne pre PostgreSQL bootstrap heslo a aplikačné heslo;
- hex výstup pre `SOVA_AUTH_RATE_LIMIT_SECRET`;
- presne 32 náhodných bajtov zakódovaných Base64 pre
  `SOVA_SENSITIVE_PAYLOAD_KEY`.

`SOVA_SENSITIVE_PAYLOAD_KEY_ID` identifikuje kľúč v šifrovaných outbox a MFA
dátach. Zmena ID alebo kľúča bez migračného/rotačného postupu zneprístupní
nevybavené citlivé payloady a TOTP secrety.

`SOVA_PUBLIC_URL` musí presne zodpovedať používateľskej HTTPS origin vrátane
neštandardného portu. `SOVA_TRUSTED_PROXY_CIDRS` obsahuje iba zdrojové adresy
reverse proxy, ktorým Apache smie veriť `X-Forwarded-For`.

## 4. TLS hrana a klientská IP

Reverse proxy musí:

1. ukončiť TLS a HTTP presmerovať na HTTPS;
2. poslať `Host` a `X-Forwarded-Proto: https`;
3. **prepísať**, nie pripojiť klientom dodané `X-Forwarded-For`;
4. pripájať sa na `127.0.0.1:SOVA_HTTP_PORT`;
5. obmedziť request body najmenej na aplikačný limit 25 MiB a najviac na
   26 MiB ochranný limit Apache.

Príklad jadra Nginx konfigurácie pri proxy na rovnakom hoste:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

V tomto prípade nastavte `SOVA_TRUSTED_PROXY_CIDRS=127.0.0.1/32`. Pri vzdialenom
load balanceri použite jeho presný privátny rozsah a host firewallom povoľte port
len z neho. Rozsahy typu `0.0.0.0/0` rušia dôveryhodnosť IP-based rate limitu a
bezpečnostného auditu.

## 5. Nasadenie

Z koreňa repozitára:

```bash
./scripts/staging-deploy.sh deploy 2026.07.29-a1b2c3d
```

Skript postupne:

1. odmietne chýbajúce tajomstvá a neplatný alebo už existujúci tag;
2. validuje výslednú Compose konfiguráciu;
3. zostaví testovací image a spustí celý backend `composer check` nad
   migrovanou PostgreSQL s neprivilegovanou aplikačnou rolou;
4. Angular image build spustí celý `npm run check`, nie iba kompiláciu;
5. zostaví nemenný produkčný image, ale web/workery spustí až po úspešných
   testoch;
6. čaká na health stav a overí liveness, readiness, vynútené RLS aj frontend;
7. zapíše posledný úspešný a predchádzajúci release lokálne mimo Gitu.

Prvý ClamAV štart môže trvať niekoľko minút, kým načíta signatúry. Nasadenie
neskracujte obídením health podmienky.

Stav a opätovné overenie:

```bash
./scripts/staging-deploy.sh status 2026.07.29-a1b2c3d
./scripts/staging-deploy.sh verify 2026.07.29-a1b2c3d
```

## 6. Prvý SUPERADMIN

Čistá databáza nemá účet a verejná registrácia zámerne neexistuje. Po prvom
úspešnom deployi vytvorte prvého administrátora lokálnym CLI. Heslo sa číta zo
štandardného vstupu, takže nie je v argumentoch procesu ani v shell histórii:

```bash
read -rsp "Bootstrap password: " SOVA_BOOTSTRAP_PASSWORD
printf '\n'
release="$(< deploy/staging/.last-successful-release)"
printf '%s\n' "$SOVA_BOOTSTRAP_PASSWORD" |
  SOVA_IMAGE_TAG="$release" docker compose \
    --env-file deploy/staging/.env \
    --file compose.staging.yml \
    exec -T web php bin/bootstrap-superadmin.php \
      --email=admin@example.test \
      --display-name="Initial Administrator" \
      --locale=sk
unset SOVA_BOOTSTRAP_PASSWORD
```

Bootstrap je atómový, auditovaný a fail-closed:

- používa rovnaký Argon2id hasher a password policy ako onboarding;
- odmietne povýšiť už existujúci účet;
- databázovým lockom vyrieši súbežné spustenie;
- po vzniku prvého `SUPERADMIN` grantu sa natrvalo odmieta.

Pri prvom prihlásení produkčný režim vynúti nastavenie TOTP a zobrazenie
jednorazových recovery kódov. Až potvrdená MFA relácia dostane systémové
oprávnenia. Ďalší správcovia sa pridávajú cez auditované systémové UI.

## 7. Funkčný smoke

Po technickom `verify` manuálne overte cez verejnú HTTPS adresu:

1. prihlásenie prvého administrátora a MFA enrollment;
2. vytvorenie tenantovej pozvánky;
3. doručenie pozvánky v Mailpit;
4. prijatie pozvánky v anonymnom okne;
5. vytvorenie projektu, úlohy, komentára a čistej prílohy;
6. vyhľadanie úlohy cez SovaQL a zobrazenie notifikácie;
7. odhlásenie a nové prihlásenie s TOTP.

Mailpit UI nie je verejná služba. Otvorte ho cez SSH tunel:

```bash
ssh -L 8025:127.0.0.1:8025 staging-host
```

Potom použite `http://127.0.0.1:8025`. Pred pilotom musí byť Mailpit buď takto
izolovaný, alebo nahradený skutočným staging SMTP účtom.

## 8. Rollback aplikácie

Rollback prepína iba web a workery na už zostavený image. Databázové migrácie sa
nevracajú; všetky release migrácie preto musia byť expand/contract kompatibilné.

```bash
cat deploy/staging/.previous-release
./scripts/staging-deploy.sh rollback PREDCHADZAJUCI_TAG
```

Ak predchádzajúci image nie je na hoste, skript rollback odmietne. Nestavajte ho
nanovo z pohyblivej vetvy pod rovnakým tagom. Pri nekompatibilnej schéme,
poškodených dátach alebo storage incidente nejde o aplikačný rollback; použite
obnovovací postup v `docs/OPERATIONS.md`.

## 9. Logy a riešenie chýb

```bash
release="$(< deploy/staging/.last-successful-release)"
SOVA_IMAGE_TAG="$release" docker compose \
  --env-file deploy/staging/.env \
  --file compose.staging.yml \
  logs --since=15m web outbox-worker email-worker migrate
```

Najčastejšie bezpečné diagnostiky:

| Stav                                      | Kontrola                                                    |
| ----------------------------------------- | ----------------------------------------------------------- |
| `migrate` skončil chybou                  | jeho log; nová aplikácia sa nespustí                        |
| readiness `tenant_isolation: failed`      | aplikačná rola musí byť `NOSUPERUSER NOBYPASSRLS`           |
| `web` sa nespustí                         | scanner, mailer a povinné secrets v produkčnom režime       |
| upload prílohy vráti `503`                | zdravie/signatúry ClamAV a sieť `backend`; upload zopakovať |
| e-mail neprišiel                          | `email-worker`, outbox a Mailpit                            |
| všetky login pokusy majú rovnakú proxy IP | presný trusted proxy rozsah a prepísanie `X-Forwarded-For`  |

Tajomstvá, `.env`, heslá ani recovery kódy nevkladajte do ticketu alebo logu.
Prevádzkové signály, alerty, incidenty, backupy a restore sú v
`docs/OPERATIONS.md`.

## 10. Zastavenie a dáta

Bežné zastavenie ponechá databázu, prílohy aj ClamAV signatúry:

```bash
release="$(< deploy/staging/.last-successful-release)"
SOVA_IMAGE_TAG="$release" docker compose \
  --env-file deploy/staging/.env \
  --file compose.staging.yml \
  down
```

Nepoužívajte `down --volumes`, pokiaľ nie je explicitným cieľom nenávratne
zmazať celé staging dáta. Pilotné alebo inak hodnotné staging dáta musia mať
backup a overenú obnovu podľa prevádzkového runbooku.
