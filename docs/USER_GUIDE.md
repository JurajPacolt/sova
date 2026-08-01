# SOVA – používateľská príručka

> Príručka k aktuálne implementovanému MVP, stav k 29. júlu 2026.

Táto príručka vysvetľuje každodennú prácu v SOVA, tenantovú administráciu a
privilegovaný systémový kontext. Opisuje skutočné obrazovky aplikácie. Návrhové
webflow dokumenty obsahujú aj budúce možnosti, ktoré ešte nemusia byť v používateľskom
rozhraní.

## 1. Základné pojmy

- **Tenant** je samostatný pracovný priestor organizácie. Má vlastných členov,
  roly, projekty, pracovné skupiny, úlohy a audit.
- **Členstvo** spája globálny používateľský účet s jedným tenantom. Ten istý účet
  môže mať v každom tenantovi iné roly.
- **Projekt** zoskupuje úlohy a vlastní svoje typy úloh aj workflow.
- **Úloha** má čitateľný kľúč, napríklad `SOVA-123`. Kľúč používajte v odkazoch,
  komentároch aj pri prepájaní úloh.
- **Tenantová rola** je sada oprávnení v tenantovi. **Projektová rola** platí iba
  v konkrétnom projekte.
- **SUPERADMIN** je globálna, vysoko privilegovaná rola. Nie je náhradou bežného
  tenantového členstva a používa samostatný systémový kontext.

Frontend skrýva alebo blokuje obrazovky podľa oprávnení, ale konečné rozhodnutie
vždy robí server. Ak sa tlačidlo alebo sekcia uvedená v príručke nezobrazuje,
pravdepodobne ju vaša rola nepovoľuje.

## 2. Prvé prihlásenie

### 2.1 Prijatie pozvánky

V SOVA neexistuje verejná registrácia. Účet a členstvo vznikajú cez časovo
obmedzený odkaz z pozvánky.

Ak ešte účet nemáte:

1. Otvorte odkaz z e-mailu a skontrolujte názov tenantu, pozvaný e-mail,
   pozývajúceho a platnosť.
2. Zadajte zobrazované meno, preferovaný jazyk a nové heslo.
3. Použite heslovú frázu s aspoň 15 znakmi a potvrďte ju.
4. Zvoľte **Vytvoriť účet a prijať** a potom sa prihláste.

Ak už účet máte:

1. Prihláste sa presne tým účtom, na ktorého e-mail bola pozvánka odoslaná.
2. Znovu otvorte pôvodný odkaz z pozvánky.
3. Zvoľte **Prijať pozvánku**.

Pozvánka môže byť expirovaná, zrušená alebo nahradená novým odkazom. V takom
prípade požiadajte tenantového administrátora o nové odoslanie. Starý odkaz po
opätovnom odoslaní neplatí.

### 2.2 Prihlásenie a výber tenantu

Na obrazovke **Prihlásenie do SOVA** zadajte e-mail a heslo. Po úspechu:

- jeden dostupný tenant sa otvorí automaticky,
- pri viacerých tenantových kontextoch sa zobrazí výber tenantu,
- bez aktívneho členstva zostanete na výbere s pokynom kontaktovať administrátora.

Pozastavený alebo odstránený tenant nemožno otvoriť. Zmena aktívneho tenantu vždy
mení aj dátový a autorizačný kontext; URL preto obsahuje jeho slug.

### 2.3 Zabudnuté heslo a overenie e-mailu

Na prihlasovacej obrazovke zvoľte **Zabudnuté heslo?** a odošlite e-mail. SOVA
odpovie rovnakou vetou bez ohľadu na to, či účet existuje. Odkaz z e-mailu je
jednorazový a časovo obmedzený.

Po zmene hesla sa staré relácie zrušia. Neplatný alebo expirovaný resetovací či
overovací odkaz nemožno znovu použiť; na obrazovke si vyžiadajte nový.

## 3. Viacfaktorové overenie

Nastavenie otvoríte cez **Viacfaktorové overenie** v hlavičke na veľkej obrazovke
alebo priamo na adrese `/mfa/setup`. V produkcii je MFA povinné pre každý účet s
rolou `SUPERADMIN`; bez dokončeného nastavenia sa taký účet nedostane do
tenantového ani systémového API.

### 3.1 Zapnutie autentifikátora

1. Potvrďte aktuálne heslo.
2. Vytvorte nastavenie a prepíšte zobrazený tajný kľúč do autentifikačnej
   aplikácie kompatibilnej s TOTP/RFC 6238.
3. Zadajte aktuálny šesťmiestny kód a zvoľte **Zapnúť viacfaktorové overenie**.
4. Bezpečne si uložte desať zobrazených obnovovacích kódov.

Tajný kľúč ani celá sada obnovovacích kódov sa po opustení kroku znovu nezobrazia.
Každý obnovovací kód funguje iba raz.

### 3.2 Ďalšie prihlásenia a nové recovery kódy

Ak prihlasovanie vyžiada druhý faktor, zadajte aktuálny TOTP kód alebo jeden
nepoužitý obnovovací kód. Opätovné použitie rovnakého časového kroku alebo
recovery kódu je odmietnuté.

Na MFA obrazovke vidíte počet zostávajúcich kódov. Voľba **Nahradiť obnovovacie
kódy** vyžaduje aktuálne heslo aj overovací kód a okamžite zneplatní celú starú
sadu.

## 4. Orientácia v aplikácii

Tenantový shell zobrazuje:

- aktívny tenant,
- hlavnú navigáciu **Dashboard**, **Úlohy**, **Projekty** a podľa oprávnení
  **Administrácia**,
- zvonček s počtom neprečítaných notifikácií,
- prepínač jazyka a systémovej, svetlej alebo tmavej témy,
- nastavenie MFA a odhlásenie,
- pri `SUPERADMIN` účte vstup do systémovej administrácie.

Na menšej obrazovke otvoríte hlavnú navigáciu tlačidlom menu. Odkaz
**Preskočiť na obsah** sa zobrazí po zaostrení klávesnicou.

SOVA podporuje slovenčinu, češtinu, angličtinu, nemčinu, poľštinu a maďarčinu.
Ak prehliadač používa iný jazyk, fallback je angličtina.

### 4.1 Predvolené roly

| Rola              | Typické použitie                                                     |
| ----------------- | -------------------------------------------------------------------- |
| `TENANT_OWNER`    | Úplná správa tenantu, projektov a pracovných skupín                  |
| `TENANT_ADMIN`    | Bežná prevádzková správa bez najcitlivejších vlastníckych operácií   |
| `PROJECT_MANAGER` | Úplná správa jedného projektu                                        |
| `GROUP_MANAGER`   | Správa jednej pracovnej skupiny a jej členov                         |
| `MEMBER`          | Bežná práca s úlohami, komentármi, prílohami a uloženými dotazmi     |
| `REPORTER`        | Čítanie a vytváranie úloh, komentárov a príloh v konkrétnom projekte |
| `VIEWER`          | Čítanie dostupných projektov a úloh                                  |
| `SUPERADMIN`      | Systémová správa celej inštalácie; v produkcii vždy s povinným MFA   |

Roly sú iba predvolené sady oprávnení. Tenant môže vytvoriť vlastné roly, preto sa
skutočné možnosti dvoch používateľov s podobným názvom roly môžu líšiť.

## 5. Dashboardy

Dashboardy sú osobné. Každý člen spravuje svoje vlastné dashboardy, ich poradie,
predvolený dashboard a widgety.

### 5.1 Správa dashboardov

Z dashboardu otvorte **Spravovať dashboardy**. Podľa oprávnení môžete:

- vytvoriť dashboard,
- premenovať alebo duplikovať existujúci,
- zmeniť jeho poradie,
- nastaviť predvolený dashboard,
- odstrániť dashboard po potvrdení.

Posledný dashboard sa neodstraňuje; môžete z neho odobrať všetky widgety. Ak
nemáte žiadny dashboard, vstupná obrazovka ponúkne vytvorenie zo štartovacej
predlohy.

### 5.2 Widgety a rozloženie

Zvoľte **Usporiadať**. Môžete:

- pridať widget zo zdroja, ktorým je uložený dotaz,
- meniť nastavenia existujúceho widgetu,
- widget posunúť alebo zmeniť jeho rozmery ťahaním,
- použiť šípkové a rozmerové tlačidlá ako plnohodnotnú klávesnicovú alternatívu,
- widget odobrať a rozloženie uložiť.

K dispozícii sú počty, zoznamy, rozdelenia, matice a časové rady. Konkrétne
nastavenia závisia od typu widgetu. Typ existujúceho widgetu sa nemení; pre iný
typ vytvorte nový.

Prekrývajúce sa widgety nemožno uložiť. Pri súbežnej zmene sa zobrazí konflikt;
načítajte aktuálny stav a vlastné rozloženie znovu použite. Na mobilnej
jednostĺpcovej mriežke sa ťahanie zámerne ignoruje, tlačidlá však zostávajú.

Widget s nedostupným uloženým dotazom neodhalí cudzie dáta. Po zlyhanom obnovení
si ponechá posledné úspešné výsledky, označí ich ako neaktuálne a ponúkne ručné
opakovanie. Časový rad je vypočítaný nad úlohami, ktoré dotaz vracia teraz; nie je
to historická snímka minulého výsledku.

## 6. Úlohy a vyhľadávanie

Obrazovka **Úlohy** je zároveň globálnym tenantovým vyhľadávaním. Vráti iba úlohy
z projektov, ktoré môžete vidieť.

### 6.1 Vytvorenie úlohy

1. Otvorte **Úlohy** a zvoľte **Nová úloha**.
2. Vyberte aktívny projekt.
3. Vyberte aktívny typ úlohy s publikovaným workflow.
4. Zadajte názov, voliteľný opis a prioritu.
5. Zvoľte **Vytvoriť**.

SOVA určí počiatočný stav a verziu workflow na serveri. Ak formulár neponúkne
žiadny projekt, nemáte v dostupnom aktívnom projekte oprávnenie `issue.create`.
Ak projekt nemá typ, správca musí najprv nakonfigurovať a publikovať jeho workflow.

### 6.2 Základné a SovaQL vyhľadávanie

Editor má dva režimy:

- **Základný** skladá jednoduché podmienky cez polia, operátory a hodnoty.
- **SovaQL** prijíma celý textový výraz.

Oba režimy používa serverovo validovaný rovnaký dotaz. Zložitý výraz s `OR`,
`NOT` alebo zátvorkami môže byť v základnom režime iba na čítanie; pri úprave sa
vráťte do SovaQL, aby sa význam dotazu nestratil.

Užitočné príklady:

```text
assignee = currentUser() AND statusCategory != DONE ORDER BY priority DESC
```

```text
project = SOVA AND type = BUG AND priority IN (HIGH, CRITICAL)
```

```text
assignee IS EMPTY AND updated >= startOfDay("-7d") ORDER BY updated DESC
```

```text
text ~ "\"reset hesla\" timeout"
```

Prázdny dotaz vypíše všetko, čo smiete vidieť. `AND` má vyššiu prioritu než
`OR`; pri kombinácii používajte zátvorky. Textové hodnoty s medzerami píšte do
dvojitých úvodzoviek. Tenant sa do SovaQL nikdy nezadáva.

Výsledky sa stránkujú tlačidlom **Načítať ďalšie**. Zmena dotazu začne od prvej
strany. Neplatný dotaz opravte podľa zvýraznenej chyby; opakovanie bez zmeny
rovnakú validáciu nevyrieši.

### 6.3 Uložené dotazy

Platný dotaz môžete pomenovať a uložiť. Panel uložených dotazov umožňuje:

- načítať dotaz do editora,
- prepísať vlastný uložený dotaz,
- premenovať alebo archivovať dotaz,
- pridať ho medzi obľúbené,
- zdieľať ho s aktívnym členom alebo pracovnou skupinou s právom spustiť alebo
  upravovať.

Zdieľanie dotazu nikdy neposkytne prístup k úlohám. Každý príjemca dostane iba
prienik výsledku dotazu a vlastných projektových oprávnení. Archivovaný dotaz sa
už neupravuje a widget, ktorý ho nevie dosiahnuť, zobrazí nedostupný zdroj.

### 6.4 Detail úlohy

Kliknutím na kľúč otvoríte stabilnú adresu úlohy. Detail zobrazuje typ, stav,
prioritu, autora, riešiteľa, opis a časy vytvorenia a zmeny.

Podľa oprávnení a stavu môžete:

- vykonať jeden z ponúknutých workflow prechodov,
- doplniť riešenie, ak ho prechod vyžaduje,
- začať alebo prestať sledovať úlohu,
- pridať komentár,
- pripojiť súbor,
- prepojiť inú dostupnú úlohu vzťahom **blokuje**, **súvisí s** alebo
  **duplikuje**,
- pozrieť používateľskú históriu.

Prechody sa počítajú z aktuálneho stavu, workflow, verzie úlohy a vašich
oprávnení. Ak úlohu medzitým zmenil niekto iný, obnovte detail a zopakujte akciu
nad novou verziou.

Komentáre prijímajú CommonMark source bez raw HTML. Aktuálne MVP text bezpečne
zobrazuje ako source, nie ako vykreslené HTML. Autor môže komentár odstrániť v
časovom okne; moderátor podľa oprávnenia. Odstránený komentár nechá v diskusii
záznam bez pôvodného textu.

Príloha môže mať stav:

- **Čaká na bezpečnostný sken** – zatiaľ sa nedá stiahnuť,
- **Zamietnuté bezpečnostným skenom** – súbor sa nesprístupní,
- **Uložené bez bezpečnostného skenu** – skenovanie nebolo zapnuté, nejde o
  potvrdenie bezpečnosti.

Maximálna veľkosť súboru je 25 MiB a niektoré typy súborov server odmietne.
Stiahnutie vždy znovu overuje aktuálny prístup.

### 6.5 Projektová nástenka

Nástenku otvoríte z detailu projektu. Každý stĺpec je stav workflow daného
projektu.

Kartu môžete presunúť:

- ťahaním do stĺpca, do ktorého vedie dostupný prechod,
- cez tlačidlo **Presunúť** a výber cieľového stavu.

Tlačidlový spôsob je rovnocenná klávesnicová cesta. Stav sa zmení až po potvrdení
serverom. Prechod vyžadujúci doplňujúce pole nástenka nevykoná; otvorte detail
úlohy. Označenie **Blokovaná** znamená, že úlohu blokuje iná, ešte nedokončená
úloha.

## 7. Projekty

Zoznam **Projekty** podporuje textové hľadanie a filter stavu. Bežný člen vidí:

- aktívne tenantovo viditeľné projekty,
- súkromné projekty, kde má priamu projektovú rolu alebo prístup cez pracovnú
  skupinu.

Tenantový správca s príslušným oprávnením môže vidieť a spravovať všetky projekty
tenantu.

### 7.1 Vytvorenie projektu

Vo formulári **Nový projekt** zadajte:

- nemenný tenantovo unikátny kód s 2 až 10 písmenami alebo číslicami,
- názov a voliteľný opis,
- tenantovú alebo súkromnú viditeľnosť,
- voliteľného vedúceho.

Súkromný projekt vyžaduje vedúceho. Vedúci sa stane prvým `PROJECT_MANAGER`.

### 7.2 Detail a prístup projektu

Detail projektu obsahuje odkaz na nástenku a konfiguráciu workflowu. Používateľ s
oprávnením môže:

- archivovať alebo reaktivovať projekt,
- meniť tenantovú/súkromnú viditeľnosť,
- pridávať členov a prideľovať im jednu alebo viac projektových rolí,
- prepájať pracovné skupiny s projektovou rolou.

Tenantová viditeľnosť znamená, že člen smie projekt nájsť; sama osebe nemusí
sprístupniť jeho členov, roly ani úlohy. Súkromný projekt musí mať aspoň jedného
aktívneho správcu priamo alebo cez aktívnu skupinu. Archivovaný projekt prestáva
udeľovať projektové oprávnenia.

## 8. Notifikácie

Zvonček v hlavičke otvorí tenantové centrum notifikácií. Môžete:

- filtrovať všetky alebo iba neprečítané položky,
- otvoriť súvisiacu úlohu,
- označiť položku alebo všetky položky ako prečítané,
- zo stránky prejsť do nastavení notifikácií.

Centrum zobrazuje najnovších 100 notifikácií; filter zužuje tento zoznam.
Počítadlo v hlavičke sa pravidelne obnovuje.

Nastavenia sú osobitné pre každý tenant a typ udalosti. Pre každý podporovaný typ
volíte in-app a e-mailový kanál. Pridelenie úlohy a zmienka sú v aplikácii vždy
zapnuté. Bezpečnostné e-maily účtu sa riadia bezpečnostnými pravidlami, nie týmito
preferenciami.

E-mailová notifikácia obsahuje iba kľúč úlohy, názov a odkaz do SOVA. Text
komentára sa do e-mailu neposiela a otvorenie odkazu znovu prejde autorizáciou.

## 9. Tenantová administrácia

Položka **Administrácia** sa zobrazí, ak máte aspoň jedno administračné
oprávnenie. Prehľad ukáže iba karty, ktoré môžete otvoriť.

### 9.1 Nastavenia tenantu

Všeobecné údaje a lokalizácia sa ukladajú nezávisle:

- názov tenantu možno zmeniť,
- slug/adresa je po vytvorení nemenná,
- predvolený jazyk sa použije, ak člen nemá vlastnú preferenciu,
- časová zóna používa IANA názov, napríklad `Europe/Bratislava`.

Ak nastavenia medzitým zmení niekto iný, SOVA odmietne zastaranú revíziu.
Načítajte aktuálne hodnoty a zmenu zopakujte.

### 9.2 Členovia a pozvánky

Obrazovka **Členovia a pozvánky** umožňuje podľa oprávnení:

- pozvať člena e-mailom,
- zobraziť stav a koniec platnosti pozvánky,
- zmeniť jej platnosť,
- znovu ju odoslať s novým bezpečným odkazom,
- zrušiť čakajúcu pozvánku,
- prideľovať a odoberať tenantové roly,
- deaktivovať, znovu aktivovať alebo odstrániť členstvo.

Zmena roly alebo stavu členstva mení prístup okamžite. Posledného aktívneho
`TENANT_OWNER` nemožno odobrať ani deaktivovať; najprv priraďte vlastnícku rolu
inému aktívnemu členovi.

### 9.3 Tenantové roly

Vlastná rola má nemenný kód, názov, opis a sadu oprávnení. Závislé oprávnenia sa
pri výbere dopĺňajú. Citlivé oprávnenia sú označené.

Systémové predvolené roly sa neupravujú ani nearchivujú. Vlastnú rolu možno
archivovať až po odobratí zo všetkých členstiev. Oprávnenia sa sčítajú; MVP nemá
explicitné pravidlá „deny“.

### 9.4 Pracovné skupiny

Môžete vytvoriť a archivovať pracovnú skupinu, zobraziť jej členov a pridať ich
ako `MEMBER` alebo `MANAGER`. Manažér môže spravovať svoju aktívnu skupinu bez
prístupu k ostatným skupinám. Archivovaná skupina prestáva udeľovať prístup k
projektom; reaktivuje ju tenantový správca.

Pri prepojení skupiny s projektom dostanú všetci jej aktívni členovia zvolenú
projektovú rolu.

### 9.5 Tenantový audit

Audit zobrazuje bezpečnostne významné udalosti od najnovších. Filtrujte ich podľa
typu udalosti, výsledku, aktéra a ID požiadavky; ďalšie strany načítajte
kurzorom. Ak máte oprávnenie na export, použite export na tej istej obrazovke.

Audit je append-only. Citlivé metadata sú redigované a pri impersonácii sa
zobrazuje skutočný aj efektívny používateľ.

## 10. Konfigurácia typov úloh a workflowu

Konfiguráciu otvoríte z detailu projektu. Čítanie vyžaduje prístup k projektu
alebo správu projektov; zmeny a publikovanie majú samostatné oprávnenia.

### 10.1 Typy úloh

Typ úlohy má nemenný kód, názov, opis, poradie, hierarchiu, ikonu, farbu a
priradený publikovaný workflow. Hierarchia rozlišuje kontajner, štandardnú úlohu
a podúlohu.

Aktívny typ potrebuje publikovaný workflow. Archivácia zabráni vytváraniu nových
úloh daného typu, existujúce úlohy však typ nestratia. Používanú hierarchiu
nemožno zmeniť spôsobom, ktorý by porušil existujúce väzby rodiča a potomka.

### 10.2 Bezpečný postup zmeny workflowu

1. Vyberte workflow a vytvorte draft z publikovanej verzie.
2. V drafte pridajte alebo odoberte stavy a prechody.
3. Označte počiatočný stav a podľa potreby primárne prechody.
4. Zvoľte **Uložiť draft**.
5. Spustite **Zvalidovať** a opravte chyby grafu.
6. Otvorte **Zobraziť dopad**.
7. Ak odobratý stav ešte obsahuje úlohy, vyberte preň cieľový stav migrácie.
8. Publikujte uložený draft.

Publikovanie nepoužíva neuložené zmeny. Pri konflikte draftu alebo revízie
načítajte uloženú aktuálnu verziu a zmeny vedome zopakujte. Publikovaním sa
vytvorí nová nemenná verzia workflowu a dotknuté úlohy sa migrujú v jednej
kontrolovanej operácii.

## 11. Systémová administrácia

Systémový kontext je dostupný iba účtu `SUPERADMIN`. Má odlišný layout a trvalé
varovanie, že každá privilegovaná akcia a vstup do tenantu sa auditujú.

### 11.1 Tenanty

Systémový správca môže:

- vytvoriť tenant a pozvánku pre prvého vlastníka,
- meniť životný cyklus tenantu s povinným dôvodom,
- vstúpiť do tenantového kontextu,
- spustiť kontrolovanú impersonáciu.

Pri impersonácii vyberte aktívneho člena, zadajte dôvod a potvrďte svoje heslo.
Relácia trvá najviac 15 minút. V tenantovom shelle je stále viditeľný banner so
skutočnou a efektívnou identitou, dôvodom, zostávajúcim časom a tlačidlom
**Ukončiť impersonáciu**. Počas impersonácie sa nepoužíva superadmin bypass;
platí presne prístup cieľového člena.

### 11.2 Používatelia a systémové roly

Zoznam používateľov zobrazuje stav účtu, počet neúspešných prihlásení a príznak
`SUPERADMIN`. Správca môže inému účtu:

- udeliť alebo odobrať rolu `SUPERADMIN`,
- aktivovať alebo deaktivovať účet podľa povoleného životného cyklu.

Vlastný účet ani vlastnú systémovú rolu na tejto obrazovke meniť nemožno.
Posledného aktívneho superadmina nemožno deaktivovať ani mu odobrať rolu.

### 11.3 Systémový audit

Systémový audit obsahuje tenantové aj globálne bezpečnostné udalosti. Podporuje
filter typu, výsledku, aktéra a ID požiadavky a kurzorové načítanie ďalších
udalostí. Pri riešení incidentu použite ID požiadavky na spojenie udalosti s
prevádzkovým logom.

## 12. Chyby, konflikty a výpadky

| Stav                        | Čo znamená a čo urobiť                                                           |
| --------------------------- | -------------------------------------------------------------------------------- |
| Bez pripojenia              | Údaje môžu byť neaktuálne a nič sa neukladá. Overte sieť a akciu spustite ručne. |
| Relácia skončila            | Znovu sa prihláste; bezpečný návrat vás môže vrátiť na pôvodnú internú URL.      |
| Nemáte prístup (`403`)      | Požiadajte tenantového alebo projektového správcu o potrebné oprávnenie.         |
| Nenájdené (`404`)           | Objekt neexistuje alebo ho nesmiete vidieť; SOVA tieto prípady nerozlišuje.      |
| Konflikt (`409`)            | Niekto zmenil rovnaký objekt. Načítajte novú verziu a zmenu zopakujte.           |
| Neplatné údaje (`422`)      | Opravte označené polia alebo SovaQL; samotné opakovanie nepomôže.                |
| Priveľa požiadaviek (`429`) | Počkajte uvedený čas a potom požiadavku zopakujte.                               |
| Chyba služby (`5xx`)        | Skúste to neskôr. Podpore odovzdajte zobrazený identifikátor požiadavky.         |
| Neaktuálny widget           | Vidíte posledné úspešné dáta; použite ručné obnovenie.                           |

SOVA stav meniace požiadavky neopakuje automaticky. Pri prerušení spojenia najprv
overte výsledný stav a až potom akciu zopakujte, aby ste nevytvorili duplicitný
zápis.

## 13. Klávesnica a prístupnosť

- Klávesom `Tab` zobrazíte skip link a viditeľný focus ring.
- Po navigácii sa focus presunie na nadpis novej obrazovky alebo hlavný obsah.
- Povinné polia sú označené aj pre asistenčné technológie.
- Nástenka aj dashboard majú tlačidlovú alternatívu ku každému drag-and-drop
  presunu.
- Presun úlohy sa oznámi v živom regióne; význam nenesie iba farba.
- Pri preferencii obmedzeného pohybu sa vypnú animácie a prechody.
- Tabuľky sa na menších šírkach horizontálne posúvajú a dashboard prejde do
  jedného stĺpca.

Automatizované kontroly pokrývajú štruktúru, kontrast, klávesnicu a šírky
390/834/1440 px. Manuálny posudok na reálnych zariadeniach s NVDA a VoiceOver je
pred produkčným pilotom stále povinná validačná brána.

## 14. Aktuálne hranice MVP

V aktuálnom používateľskom rozhraní nie sú:

- verejná registrácia, SSO ani sociálne prihlásenie,
- samostatná obrazovka „Moja práca“, globálne rýchle návrhy v hlavičke ani
  používateľský profil; túto prácu pokrývajú dashboardy, SovaQL a hlavičkové
  nastavenia,
- všeobecný editor polí existujúcej úlohy a hromadné operácie,
- grafický drag-and-drop editor workflowu; používa sa tabuľkový draft,
- sprinty, SLA, automatizácie, billing, verejné integrácie a mobilná aplikácia,
- skutočný prstencový graf; voľba donut sa v tejto verzii vykreslí ako stĺpce.

## 15. Mapa aktuálnych adries

Adresy pripájajte k základnej URL svojej inštalácie:

| Adresa                                               | Obrazovka                     |
| ---------------------------------------------------- | ----------------------------- |
| `/login`                                             | Prihlásenie                   |
| `/forgot-password`                                   | Žiadosť o obnovu hesla        |
| `/mfa/setup`                                         | Nastavenie MFA                |
| `/select-tenant`                                     | Výber tenantového kontextu    |
| `/t/{tenantSlug}/dashboards`                         | Vstup do osobných dashboardov |
| `/t/{tenantSlug}/dashboards/manage`                  | Správa dashboardov            |
| `/t/{tenantSlug}/issues`                             | Úlohy, vytvorenie a SovaQL    |
| `/t/{tenantSlug}/issues/{issueKey}`                  | Detail úlohy                  |
| `/t/{tenantSlug}/issues/board/{projectId}`           | Projektová nástenka           |
| `/t/{tenantSlug}/projects`                           | Projekty                      |
| `/t/{tenantSlug}/projects/{projectId}`               | Detail projektu               |
| `/t/{tenantSlug}/projects/{projectId}/configuration` | Typy úloh a workflow          |
| `/t/{tenantSlug}/notifications`                      | Centrum notifikácií           |
| `/t/{tenantSlug}/notifications/preferences`          | Nastavenia notifikácií        |
| `/t/{tenantSlug}/admin`                              | Tenantová administrácia       |
| `/system/tenants`                                    | Systémová správa tenantov     |
| `/system/users`                                      | Systémová správa používateľov |
| `/system/audit`                                      | Systémový bezpečnostný audit  |

## 16. Súvisiaca dokumentácia

- [Autentifikácia a relácie](./AUTHENTICATION.md)
- [Permission katalóg a predvolené roly](./AUTHORIZATION.md)
- [SovaQL a dashboardy](./SOVAQL-A-DASHBOARDY.md)
- [Typy úloh a workflow](./WORKFLOW-A-TYPY-ULOH.md)
- [Webflow a návrhové používateľské toky](./webflow/README.md)
- [Prevádzkový a incidentný runbook](./OPERATIONS.md)
