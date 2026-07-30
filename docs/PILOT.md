# SOVA – plán pilotného tenantu a spracovania spätnej väzby

- Verzia: 1.0
- Dátum: 2026-07-29
- Checkpoint: F9.2
- Stav: príprava hotová; reálny pilot ešte neprebehol

Tento dokument mení všeobecný bod „pilotný tenant“ na opakovateľný validačný
postup. Pilot nie je produkčné nasadenie ani náhrada bezpečnostného,
prístupnostného alebo výkonového testu. Je to časovo obmedzené použitie SOVA
malou skupinou, z ktorého musí vzniknúť dohľadateľný zoznam dôkazov, spätnej
väzby, rozhodnutí a opráv.

Reálny pilot sa nesmie označiť ako hotový iba preto, že vznikol tenant alebo sa
niekto prihlásil. Checkpoint sa uzavrie až po splnení výstupných kritérií v
kapitole 10.

## 1. Vlastníci a rozsah

Pred štartom musia byť menovite určení:

| Zodpovednosť               | Povinnosť počas pilota                                   |
| -------------------------- | -------------------------------------------------------- |
| product owner              | rozsah, priority, prijatie alebo odmietnutie feedbacku   |
| pilotný tenant owner       | používatelia, roly, projekty a pracovné dohody           |
| technický vlastník         | deploy, databáza, workery, výkon a rollback              |
| bezpečnostný vlastník      | incidenty, audit, tenantová izolácia a secrets           |
| podpora/triage             | príjem hlásení, request ID, reprodukcia a komunikácia    |
| vlastník prístupnosti      | manuálny NVDA/VoiceOver posudok a evidovanie bariér      |
| zodpovedná osoba za obnovu | backup pred pilotom a restore drill cieľového prostredia |

Odporúčaný prvý rozsah:

- jeden reálny pilotný tenant a samostatný syntetický canary tenant na
  negatívne testy izolácie;
- 5 až 15 používateľov počas dvoch pracovných týždňov;
- 2 až 4 projekty s rozdielnym workflow;
- zastúpenie rolí `TENANT_OWNER`, `TENANT_ADMIN`, `PROJECT_MANAGER`, `MEMBER`
  a `VIEWER`;
- aspoň jeden používateľ v inom podporovanom jazyku než slovenčina;
- desktopový aj mobilný viewport a aspoň dve browserové rodiny.

Pilot nepoužíva produkčne kritické dáta. Obsah musí byť syntetický,
pseudonymizovaný alebo výslovne schválený pre staging. Secrets, session cookies,
MFA QR kódy, recovery kódy a osobné údaje nepatria do feedback ticketov ani
screenshotov.

## 2. Vstupná go/no-go brána

Každý bod musí mať odkaz na dôkaz a zodpovednú osobu:

- [ ] cieľový commit a nemenný release tag sú schválené;
- [ ] `scripts/staging-deploy.sh deploy RELEASE_TAG` a následný `verify` prešli
      na cieľovom hoste;
- [ ] verejná adresa používa TLS, správnu origin a presný trusted-proxy rozsah;
- [ ] tajomstvá sú mimo Gitu a lokálny `.env` má práva iba pre prevádzkovú
      identitu;
- [ ] PostgreSQL readiness potvrdzuje `tenant_isolation: enforced` a aplikačná
      rola je `NOSUPERUSER NOBYPASSRLS`;
- [ ] ClamAV je zdravý a clean/EICAR smoke v cieľovom prostredí prešiel;
- [ ] SMTP smeruje do izolovaného Mailpit alebo schváleného staging účtu;
- [ ] backup databázy aj príloh vznikol a restore drill cieľového prostredia
      prešiel;
- [ ] alerty z `OPERATIONS.md` majú príjemcu a otestovanú doručovaciu cestu;
- [ ] nie je otvorené kritické bezpečnostné zistenie ani známa strata dát;
- [ ] prvý `SUPERADMIN` má potvrdené TOTP a recovery kódy uložené oddelene;
- [ ] manuálny posudok na reálnom zariadení s NVDA aj VoiceOver nemá otvorenú
      kritickú bariéru;
- [ ] účastníci poznajú hranice MVP z `USER_GUIDE.md` a spôsob hlásenia chýb;
- [ ] incidentný vlastník, komunikačný kanál a stop rozhodnutie sú dostupné
      počas celého pilotného okna.

Nesplnený bezpečnostný, obnovovací, RLS, TLS alebo prístupnostný bod je
`NO-GO`. Produktový vlastník ho nemôže prehlasovať za kozmetickú výnimku.

## 3. Bezpečné založenie pilotného tenantu

1. `SUPERADMIN` sa prihlási s potvrdeným MFA a vytvorí nový tenant cez
   systémovú administráciu.
2. Ako owner sa použije pracovná adresa pilotného vlastníka. Owner vznikne
   prijatím jednorazovej pozvánky; nedostane globálnu systémovú rolu.
3. Owner pozve ostatných účastníkov a priradí iba nevyhnutné tenantové roly.
4. Projektový prístup sa udeľuje cez projektové roly a pracovné skupiny.
   Súkromný projekt sa použije ako negatívny autorizačný scenár.
5. Canary tenant používa iné účty alebo členstvá a výlučne syntetické dáta.
6. Pred začiatkom práce sa uloží inventár tenantov, členstiev, projektov,
   počtu príloh a aplikovanej verzie migrácií.

Bežní účastníci nikdy nepoužívajú `SUPERADMIN` účet. Impersonácia je dovolená
iba pri podpore konkrétneho hlásenia, s dôvodom, reautentifikáciou a oznámením
pilotnému vlastníkovi.

## 4. Pilotné scenáre

Výsledok každého scenára je `PASS`, `FAIL`, `BLOCKED` alebo `NOT RUN`.
`BLOCKED` musí mať vlastníka a termín.

| Oblasť           | Povinný dôkaz                                                              |
| ---------------- | -------------------------------------------------------------------------- |
| onboarding       | pozvánka nového aj existujúceho účtu, expirácia/resend, výber tenantu      |
| identity         | login, TOTP, recovery kód, logout, zrušenie inej relácie                   |
| roly             | owner/admin/member/viewer pozitívne aj negatívne API/UI správanie          |
| izolácia         | cudzí tenant/project ID v URL a requeste nič nezmení ani neodhalí          |
| pracovné skupiny | vytvorenie skupiny, členstvo a projektový prístup                          |
| projekty         | aktívny/súkromný/archivovaný projekt a projektové roly                     |
| workflow         | vlastný typ, draft, impact, publikovanie, konflikt revízie a archivácia    |
| úlohy            | create, edit, transition, assignee, skupina, hierarchia a súbežný konflikt |
| spolupráca       | Markdown, zmienka, komentár, watch/unwatch, väzba a história               |
| prílohy          | čistý povolený súbor, neplatný OOXML, EICAR, kvóta, download a delete      |
| SovaQL           | validácia, fulltext, uložený dotaz, zdieľanie, cursor a timeout            |
| dashboardy       | viac dashboardov, widgety, zmena poradia, empty/error/stale stav           |
| notifikácie      | in-app, označenie ako prečítané a e-mail v staging sinku                   |
| administrácia    | členstvá, pozvánky, role, nastavenia, audit a export                       |
| systémová správa | tenant lifecycle, používateľ, audit a kontrolovaná impersonácia            |
| obnova           | backup inventár, restore drill, app-only rollback a návrat na release      |
| prístupnosť      | klávesnica, zoom, reflow, focus, NVDA, VoiceOver a reduced motion          |

Podrobné kroky používateľských tokov sú v `USER_GUIDE.md`; bezpečnostné
negatívne scenáre v `THREAT_MODEL.md` a prevádzkové kroky v `OPERATIONS.md`.

## 5. Výkonová brána

Meranie sa robí na cieľovom staging hoste z udalostí `http_request`,
agregovaných podľa stabilného poľa `route`, nie podľa surového `path`.

Pre každý reprezentatívny route sa zaznamená:

- počet požiadaviek a časové okno;
- p50, p95 a p99 `duration_ms`;
- podiel 4xx a 5xx;
- použitý dataset, súbeh, warm/studená cache a release tag;
- samostatne úspešné odpovede, aby rýchle chybové odpovede umelo neznížili p95.

Route s menej než 100 reprezentatívnymi úspešnými vzorkami sa označí
`INSUFFICIENT DATA`, nie `PASS`. Počiatočné ciele sú:

- bežné API čítanie p95 do 300 ms bez externej služby;
- bežná mutácia p95 do 500 ms;
- žiadne trvalé N+1 správanie ani neobmedzený rast hlavného zoznamu;
- žiadny 10-minútový interval s p95 nad 2 s;
- žiadny 5-minútový interval s 5xx nad 2 %.

Upload so skenom a e-mailové doručenie sa reportujú oddelene od bežnej DB
mutácie. Výsledok musí obsahovať aj veľké administračné katalógy a fulltext pod
reálnou `FORCE RLS` rolou. Lokálny `QueryPerformanceTest` tieto staging p95
nenahrádza.

Agregovaný report nesmie obsahovať session token, request body, e-mail,
`tenant_id`, surový path ani používateľský obsah. `request_id` sa použije iba
na dohľadanie konkrétnej chyby v chránenom logovacom systéme.

## 6. Prístupnostný posudok

Manuálna brána minimálne pokrýva:

- NVDA s klávesnicou na Windows desktope;
- VoiceOver s klávesnicou na macOS a dotykové použitie na iOS;
- 200 % zoom, úzky reflow, svetlú/tmavú tému a reduced motion;
- login/MFA, tenant switch, zoznam a detail úloh, formulár, Kanban,
  dashboard, notifikácie a administráciu;
- poradie focusu, skip link, názvy polí, chyby, live regiony, tabuľky,
  dialógy a alternatívy k drag-and-drop.

Nález, ktorý znemožní autentifikáciu, základnú prácu s úlohou alebo pochopenie
bezpečnostnej chyby bez myši či zraku, je stop podmienka pilota.

## 7. Formát spätnej väzby

Každé hlásenie používa rovnakú šablónu:

```text
ID:
čas a release tag:
obrazovka alebo route:
rola/scenár (bez mena používateľa):
browser, zariadenie, jazyk a asistenčná technológia:
kroky reprodukcie:
očakávané správanie:
skutočné správanie:
dopad a počet dotknutých používateľov:
frekvencia:
request_id, ak existuje:
príloha po redakcii citlivých údajov:
navrhovaná závažnosť:
```

Triage doplní vlastníka, reprodukovateľnosť, bezpečnostný/tenantový dopad,
rozhodnutie, cieľový release a dôkaz retestu. Duplicitné hlásenia sa prepájajú;
neuzatvárajú sa bez vysvetlenia.

## 8. Závažnosť a reakcia

| Úroveň | Príklady                                                                  | Reakcia                                                         |
| ------ | ------------------------------------------------------------------------- | --------------------------------------------------------------- |
| S0     | cross-tenant únik, auth bypass, strata dát, uniknuté secret, RLS vypnuté  | okamžite zastaviť pilot, izolovať systém a spustiť incident     |
| S1     | core tok nefunguje bez bezpečného workaroundu, opakované 5xx, a11y blok   | pozastaviť dotknutý tok; opraviť a retestovať pred pokračovaním |
| S2     | obmedzená funkcia má bezpečný workaround, výrazná UX alebo výkon regresia | zaradiť do pilotného release plánu s vlastníkom a termínom      |
| S3     | text, kozmetika alebo návrh bez funkčného dopadu                          | backlog s produktovým rozhodnutím                               |

Podozrenie na bezpečnostný incident sa netriaguje iba ako bežný bug. Použije
sa incidentný postup z `OPERATIONS.md`, zachovajú sa auditné dôkazy a prístup
sa obmedzí podľa rozsahu.

## 9. Denný rytmus

Každý pracovný deň:

1. overiť live/ready, workery, ClamAV, outbox a posledný backup;
2. skontrolovať 5xx, p95/p99, timeouty, login failures a bezpečnostný audit;
3. triagovať nové hlásenia a potvrdiť S0/S1 s bezpečnostným vlastníkom;
4. zverejniť účastníkom známe obmedzenia a retestované opravy;
5. uložiť agregované metriky, nie export osobných alebo tenantových dát.

Každá oprava ide cez nový nemenný release tag, celý staging gate a cielený
retest. Tag sa neprepisuje a databázová migrácia sa pri app-only rollbacku
nevracia.

## 10. Výstupná go/no-go brána

F9.2 možno označiť ako hotové iba ak:

- [ ] pilot bežal dohodnuté obdobie s reprezentatívnymi rolami a scenármi;
- [ ] všetky povinné scenáre majú výsledok a dôkaz;
- [ ] spätná väzba je deduplikovaná, klasifikovaná a má rozhodnutie;
- [ ] nie je otvorené S0 ani S1 a všetky bezpečnostné nálezy majú retest;
- [ ] tenantová a projektová izolácia prešla aj manuálnym canary testom;
- [ ] NVDA aj VoiceOver posudok je uzavretý bez kritickej bariéry;
- [ ] staging p50/p95/p99 a chybovosť sú zdokumentované proti cieľom;
- [ ] workery, e-mail, ClamAV, audit, monitoring a alerty preukázateľne fungujú;
- [ ] backup/restore a rollback boli na cieľovom prostredí nacvičené;
- [ ] produktový, technický a bezpečnostný vlastník podpísali výsledok.

Výstupom je krátky pilotný report: rozsah, release, účastníci podľa rolí,
výsledky scenárov, agregované metriky, prístupnostný posudok, zoznam feedbacku,
vyriešené nálezy, otvorené S2/S3 s vlastníkom a konečné `GO`, `CONDITIONAL GO`
alebo `NO-GO`.

`CONDITIONAL GO` nikdy neakceptuje otvorené S0/S1, cross-tenant riziko,
neobnovenú zálohu, nevynútené RLS alebo kritickú prístupnostnú bariéru.

## 11. Čo ešte vyžaduje externý vstup

Lokálne možno pripraviť proces, automatizáciu a referenčný staging, nie však
predstierať reálnu spätnú väzbu. Pred pokračovaním treba dodať:

- cieľový staging host, DNS/TLS a správu tajomstiev;
- mená zodpovedných osôb a incidentný komunikačný kanál;
- pilotnú organizáciu, ownera, účastníkov a schválené testovacie dáta;
- pilotné časové okno a pravidlá spracovania osobných údajov;
- zariadenia alebo hodnotiteľov pre NVDA a VoiceOver.

Kým tieto vstupy neexistujú, F9.2 ostáva rozpracované aj pri zelenej technickej
príprave.
