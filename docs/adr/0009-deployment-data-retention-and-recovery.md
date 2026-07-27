# ADR 0009: Nasadenie, prílohy, retencia a obnova

- Stav: prijaté
- Dátum: 2026-07-26

## Kontext

MVP potrebuje konkrétny bezpečný upload kontrakt, predvídateľné mazanie a
obnoviteľnú produkciu. Kubernetes a multi-region active-active prevádzka by boli
bez preukázaného zaťaženia neprimerane zložité, no jedna virtuálna mašina bez PITR
nespĺňa požadovanú ochranu tenantových dát.

## Rozhodnutie

### Cieľové nasadenie

- Prostredia sú local, CI, staging a production. Produkcia používa spravovanú
  kontajnerovú platformu v jednom regióne a najmenej dvoch zónach dostupnosti, nie
  vlastný Kubernetes cluster.
- Verejnú hranu tvorí spravovaný TLS reverse proxy/load balancer s WAF a rate
  limitom. Angular je verzovaný statický artefakt; Slim API má najmenej dve
  bezstavové repliky a worker je oddelený proces.
- PostgreSQL 17 je spravovaný, šifrovaný, vysoko dostupný a má automatický failover,
  WAL archív a PITR. Prílohy sú v privátnom, šifrovanom S3-kompatibilnom objektovom
  úložisku. Secrets patria do správcu tajomstiev.
- Artefakty sú nemenné. Nasadenie je rolling alebo blue/green, migrácie používajú
  expand/contract kompatibilitu a rollback aplikácie nesmie vyžadovať deštruktívny
  rollback databázy.
- Produkčný cieľ po GA je 99,9 % mesačná dostupnosť bez vopred oznámenej údržby.

### Upload a prístup k prílohám

- Predvolený limit je 25 MiB na súbor, jeden súbor na upload request, najviac 20
  aktívnych príloh na jednu úlohu a 20 GiB logických príloh na tenant. `SUPERADMIN`
  môže tenantový quota limit znížiť alebo zvýšiť.
- MVP povoľuje PNG, JPEG, WebP, PDF, UTF-8 plain text, CSV a OOXML dokumenty DOCX,
  XLSX a PPTX. Zakázané sú spustiteľné súbory, skripty, HTML, SVG, všeobecné
  archívy a typy mimo allowlistu.
- Server overí veľkosť, príponu, deklarovaný MIME aj obsah/signature; názov objektu
  generuje sám. Pôvodný názov je iba bezpečne kódované metadata.
- Nový súbor ide do izolovanej karantény. Dostupný je až po malware skene a podľa
  typu po bezpečnej transformácii. Zamietnutý obsah sa odstráni najneskôr do
  24 hodín.
- Bucket nie je verejný. Download vždy znovu overí tenant, objekt a oprávnenie a
  vydá jednorazovú alebo najviac 5-minútovú podpísanú URL. Nebezpečné inline
  renderovanie je zakázané.

### Retencia a mazanie

- Aktívne úlohy, komentáre, história a ich metadata sa držia počas životnosti
  tenantu. Odstránený používateľ sa po 30-dňovej ochrannej lehote pseudonymizuje,
  pričom história si zachová neidentifikujúce autorstvo.
- Soft-deleted príloha sa stane okamžite nedostupnou a fyzicky sa odstráni po 30
  dňoch, ak ju neblokuje legal hold.
- Odstránenie tenantu má 30-dňovú zrušiteľnú ochrannú lehotu. Po jej uplynutí sa
  primárne dáta a objekty odstránia do 7 dní. Kópie v zálohách vypršia najneskôr po
  skončení 35-dňového backup okna; po restore sa znovu aplikujú tombstones.
- Aplikačné logy sa držia 30 dní. Autentifikačný, autorizačný, administrátorský a
  impersonačný audit 400 dní. Spracovaný outbox 30 dní.
- Legal hold, zmluva alebo zákonná povinnosť môže retenciu predĺžiť iba
  zdokumentovaným rozhodnutím s vlastníkom, rozsahom a dátumom revízie.

### Zálohy a obnova

- Produkčný cieľ je `RPO ≤ 15 minút` a `RTO ≤ 4 hodiny`.
- PostgreSQL používa kontinuálny WAL archív a denné/base backupy s 35-dňovým PITR
  oknom. Objektové úložisko má versioning/ochranu odstránenia a nezávislú
  šifrovanú kópiu v rovnakom retenčnom okne.
- Zálohy sú šifrované, prístupovo oddelené od produkčnej identity a monitorované.
  Úspech backup jobu bez obnovy sa nepovažuje za dôkaz obnoviteľnosti.
- Úplný restore drill sa vykoná minimálne štvrťročne a po významnej zmene storage
  alebo migračného mechanizmu. Výsledok meria skutočné RPO/RTO a eviduje nápravné
  kroky.

## Dôsledky

### Pozitívne

- MVP má konkrétny bezpečnostný a kapacitný upload kontrakt,
- spravované služby znižujú prevádzkové riziko bez predčasného Kubernetes,
- mazanie, zálohy a ciele obnovy sú testovateľné.

### Náklady a obmedzenia

- multi-region katastrofa môže prekročiť 4-hodinové RTO; pred vyšším SLA treba
  samostatný geo-recovery návrh,
- malware sken a karanténa znamenajú, že príloha nie je dostupná okamžite,
- garantované RPO/RTO platí až po úspešnom produkčnom restore teste.

## Referencie

- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
- [PostgreSQL 17 – Continuous Archiving and PITR](https://www.postgresql.org/docs/17/continuous-archiving.html)
