# ADR 0010: Rozsah tenantu pre Row-Level Security

- Stav: prijaté
- Dátum: 2026-07-29
- Spresňuje: [ADR 0003](./0003-postgresql-shared-schema-multitenancy.md)

## Kontext

ADR 0003 rozhodlo, že tenantové tabuľky budú pred produkciou chránené aj
PostgreSQL Row-Level Security s `FORCE ROW LEVEL SECURITY`, a predpokladalo, že
kontext nastaví transakcia cez `SET LOCAL`. Pri zapínaní RLS sa ukázalo, že ten
predpoklad nesedí s tým, ako aplikácia skutočne beží:

- **Nie každá požiadavka má transakciu.** SOVA otvára transakciu len okolo
  viacriadkových prípadov použitia; obyčajné čítanie žiadnu nemá, takže
  `SET LOCAL` by pri väčšine požiadaviek nenastavil nič.
- **Časť kódu musí vidieť naprieč tenantmi.** Prihlásenie, výber tenantu,
  systémová administrácia, outbox workery aj samotné migrácie sú legitímne
  bez tenantového kontextu.
- **Dve tabuľky patria aj systému.** `security_audit_events` a `outbox_events`
  majú `tenant_id` nullable, lebo bezpečnostná udalosť či správa môže patriť
  systému, nie tenantovi.

## Rozhodnutie

- Politika číta tenant z **session nastavenia `sova.tenant_id`**, ktoré
  aplikácia nastaví okolo tenantovej požiadavky a v `finally` zase zruší.
  Nastavenie sa zapisuje cez `set_config` s **viazaným parametrom**, nie
  interpoláciou do `SET` — `SET` parametre nepozná a identifikátor, ktorý sa
  cestou do SQL zmení na text, prestáva byť identifikátorom.
- **Nenastavený rozsah znamená „bez tenantového rozsahu", nie „žiadne riadky".**
  Politika bez nastaveného `sova.tenant_id` prepúšťa. Inak by odmietla presne
  ten kód, ktorý má vidieť naprieč tenantmi, a prvá prevádzková nepríjemnosť by
  ju odstránila.
- Politika platí `FOR ALL` s `USING` aj `WITH CHECK`, takže pod rozsahom sa cudzí
  riadok nedá ani prečítať, ani zapísať.
- Pri dvoch tabuľkách s nullable `tenant_id` je politika **zámerne asymetrická**:
  čítanie pod rozsahom systémový riadok nevráti, zápis ho stále dovolí. Tenantová
  požiadavka smie zaznamenať systémovú udalosť; odmietnuť ten zápis by znamenalo
  zlyhanie z dôvodu, ktorý s tenantom nesúvisí.
- `FORCE ROW LEVEL SECURITY` zostáva povinné: aplikácia sa pripája ako vlastník
  tabuliek a vlastník je bez `FORCE` z vlastných politík vyňatý.
- Prevádzková rola aplikácie a workerov zostáva podľa ADR 0003 `NOSUPERUSER` a
  `NOBYPASSRLS`. RLS nie je vypínateľná konfiguráciou aplikácie.
- **Readiness endpoint kontroluje, či rola politiky vôbec podlieha.** Rola, ktorá
  RLS obchádza, robí politiky ticho neúčinnými — a „ticho neúčinná" je najhorší
  stav, v akom môže posledná vrstva obrany byť. Dôvod ide do logu; odpoveď ho
  nehovorí, lebo endpoint odpovedá každému, kto naň dosiahne. V produkcii je to
  `not_ready`, mimo nej iba upozornenie: vývojová databáza býva vlastnená
  superuserom a sonda, ktorá by tam padala, by sa vypla namiesto opravy.

## Čím RLS **nie je**

Nie je náhradou aplikačného filtra. Repozitáre naďalej píšu `tenant_id` do každého
dotazu a testy cross-tenant odmietnutia zostávajú povinné. RLS je posledná vrstva,
ktorá chytí deň, keď na predikát niekto zabudne — nie prvá, na ktorú sa spolieha.

## Dôsledky

### Pozitívne

- dotaz, ktorý stratí svoj predikát, vráti pod tenantovým rozsahom prázdno
  namiesto cudzích riadkov,
- ochrana platí aj pre kód, ktorý ešte nie je napísaný,
- rozsah je jedno miesto, nie kontrola roztrúsená po repozitároch.

### Náklady a obmedzenia

- session nastavenie musí byť zrušené aj pri výnimke, inak by sa pri znovupoužitom
  spojení prenieslo na ďalšiu požiadavku; drží to `finally` a test,
- politika pridáva `current_setting` do plánu každého dotazu nad tenantovou
  tabuľkou,
- nová tenantová tabuľka potrebuje vlastnú politiku — migrácia, ktorá na ňu
  zabudne, ju nechá chránenú iba aplikačne.
