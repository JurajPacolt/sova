# SOVA – permission katalóg a centrálna autorizácia

Tento dokument je záväzný kontrakt F3.1. Backend rozhoduje podľa stabilného
permission kódu a explicitného scope; názov roly sa nesmie používať ako podmienka
v HTTP action ani aplikačnej službe.

## Rozhodovací model

Každá kontrola obsahuje:

- skutočného a efektívneho používateľa,
- jedno pomenované oprávnenie,
- explicitný `SYSTEM`, `TENANT`, `PROJECT` alebo `WORKGROUP` scope,
- konkrétny tenant a podľa scope aj projekt alebo pracovnú skupinu.

Ak sa scope nezhoduje s katalógom alebo provider nepreukáže grant, výsledok je
deny by default. `SUPERADMIN` má plný bypass iba vo vlastnom kontexte a stále musí
otvoriť správny explicitný scope. Počas impersonácie sa bypass vypne a vyhodnocujú
sa iba oprávnenia efektívneho používateľa.

Chýbajúce oprávnenie vracia jednotné:

```text
403 PERMISSION_DENIED
```

Odpoveď neprezrádza názvy rolí ani interný zdroj grantu.

## Autoritatívny katalóg

Kódy sú definované v `Authorization\Domain\Permission`; metadata, ľudské názvy,
citlivosť a závislosti sú súčasťou rovnakého kódového katalógu.

| Scope       | Permission kódy                                                                                                                                                                                                                                                                                                                   |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SYSTEM`    | `system.tenants.view`, `system.tenants.create`, `system.tenants.manage`, `system.users.manage`, `system.superadmins.manage`, `system.audit.view`, `system.impersonate`                                                                                                                                                            |
| `TENANT`    | `tenant.view`, `tenant.settings.manage`, `tenant.members.view`, `tenant.members.invite`, `tenant.members.manage`, `tenant.roles.view`, `tenant.roles.manage`, `tenant.roles.assign`, `tenant.workgroups.manage`, `tenant.projects.create`, `tenant.projects.manage`, `tenant.audit.view`, `tenant.audit.export`                   |
| `PROJECT`   | `project.view`, `project.settings.manage`, `project.members.manage`, `project.workflow.manage`, `project.workflow.publish`, `issue.view`, `issue.create`, `issue.edit`, `issue.assign`, `issue.transition`, `issue.delete`, `comment.create`, `comment.moderate`, `attachment.upload`, `attachment.moderate`, `saved-query.share` |
| `WORKGROUP` | `workgroup.view`, `workgroup.manage`, `workgroup.members.manage`                                                                                                                                                                                                                                                                  |

Citlivé oprávnenia sú v katalógu označené samostatne, aby budúci administračný tok
mohol vyžadovať reauthentication/MFA. Závislosti sú explicitné; napríklad
`tenant.members.invite` vyžaduje `tenant.view` aj `tenant.members.view` a
`project.workflow.publish` vyžaduje view aj editovanie workflow.

## Predvolená matica rolí

Rola je pomenovaná sada oprávnení. Efektívne granty sa sčítajú; explicitné deny
pravidlá v MVP neexistujú.

| Rola              | Priraditeľný scope       | Predvolený význam                                                               |
| ----------------- | ------------------------ | ------------------------------------------------------------------------------- |
| `SUPERADMIN`      | `SYSTEM`                 | Celý aktuálny aj budúci katalóg; bez tenantového členstva                       |
| `TENANT_OWNER`    | `TENANT`                 | Všetky tenantové, projektové a workgroup oprávnenia                             |
| `TENANT_ADMIN`    | `TENANT`                 | Prevádzková správa bez editácie role definícií, exportu auditu a mazania issues |
| `PROJECT_MANAGER` | `PROJECT`                | Všetky projektové oprávnenia                                                    |
| `GROUP_MANAGER`   | `WORKGROUP`              | View, nastavenia a členovia jednej pracovnej skupiny                            |
| `MEMBER`          | `TENANT` alebo `PROJECT` | Bežná práca s issues, komentármi, prílohami a zdieľanými dotazmi                |
| `REPORTER`        | `PROJECT`                | View a vytvorenie issue, komentára a prílohy                                    |
| `VIEWER`          | `TENANT` alebo `PROJECT` | Read-only vstup a čítanie issues                                                |

Presný strojovo testovaný zoznam je v `Authorization\Domain\DefaultRole`.
`TENANT_OWNER` ochrana posledného aktívneho vlastníka sa uplatní pri priradeniach
vo F3.2, nie zmenou tejto matice.

## Databázový model tenantových rolí

Tenantové roly a granty ukladajú tabuľky:

- `tenant_roles` – tenantovo viazaná definícia roly; rezervované predvolené roly
  majú `is_system = true` a `is_editable = false`,
- `tenant_role_permissions` – sada stabilných permission kódov roly,
- `tenant_membership_role_assignments` – priradenie jednej alebo viacerých rolí
  aktívnemu členstvu,
- `tenant_authorization_revisions` – monotónna revízia autorizačného stavu tenantu.

Kompozitné cudzie kľúče obsahujú `tenant_id`, takže členstvo a rolu z rôznych
tenantov nemožno spojiť ani priamym SQL zápisom. Migrácia doplní štyri predvolené
tenantové roly aj existujúcim tenantom. Pri vytvorení nového tenantu sa
`TenantRoleProvisioner` volá v rovnakej transakcii ako tenant.

Zmena členstva, roly, grantov alebo priradení zvýši tenantovú autorizačnú revíziu
databázovým triggerom. To isté platí pri zmene stavu tenantu; zmena stavu
používateľa zvýši revízie všetkých tenantov, v ktorých má členstvo. Provider pred
každým použitím lokálneho decision cache načíta revíziu. Pri jej zmene zahodí všetky
cached rozhodnutia daného tenantu, preto odobratie prístupu nezávisí od TTL.

## Stav implementácie

`AuthorizationService` je jediný vstup pre rozhodnutie a vytvorenie tenantovej
pozvánky vyžaduje `tenant.members.invite`. Doctrine provider vyhodnocuje aktívne
tenantové členstvo, používateľa, tenant, rolu, priradenie a grant; bez úplnej zhody
vráti deny. Vďaka tomu môže pozvánku vytvoriť vlastný `SUPERADMIN` bypass aj člen s
priradenou rolou `TENANT_ADMIN` alebo `TENANT_OWNER`.

Tenantový provider je dokončený. `WORKGROUP` scope je od 2026-07-27 a `PROJECT`
scope od 2026-07-28 implementovaný (viď nižšie). Všetky štyri scopes sú tak
vyhodnocované; bez preukázaného grantu naďalej platí deny by default.

## Workgroup role a oprávnenia

Na rozdiel od tenantových rolí nemá workgroup vlastný CRUD katalóg rolí.
`workgroup_members` viaže aktívne tenantové členstvo na skupinu s dvojhodnotovým
`member_role` (`MEMBER` alebo `MANAGER`); `MANAGER` získava všetky workgroup
oprávnenia (`workgroup.view`, `workgroup.manage`, `workgroup.members.manage`) na
danej skupine, `MEMBER` iba `workgroup.view`. Zmena `workgroup_members` aj
`workgroups` zvyšuje tenantovú autorizačnú revíziu rovnakým triggerom ako
tenantové roly, takže zneplatnenie cache je okamžité.

Každý endpoint akceptuje **buď** tenantové `tenant.workgroups.manage` (typicky
`TENANT_OWNER`/`TENANT_ADMIN`, platí naprieč všetkými skupinami tenantu),
**alebo** workgroup-scoped oprávnenie na konkrétnej skupine – manažér skupiny
tak môže spravovať svoju skupinu bez tenantového administrátorského oprávnenia,
ale nedosiahne na cudzie skupiny.

| Metóda   | Route                                                                    | Oprávnenie                                                    |
| -------- | ------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `GET`    | `/api/v1/tenants/{tenantId}/workgroups`                                 | `tenant.workgroups.manage`                                    |
| `POST`   | `/api/v1/tenants/{tenantId}/workgroups`                                 | `tenant.workgroups.manage`                                    |
| `PATCH`  | `/api/v1/tenants/{tenantId}/workgroups/{workgroupId}`                   | `tenant.workgroups.manage` ALEBO `workgroup.manage`            |
| `GET`    | `/api/v1/tenants/{tenantId}/workgroups/{workgroupId}/members`           | `tenant.workgroups.manage` ALEBO `workgroup.view`              |
| `PUT`    | `/api/v1/tenants/{tenantId}/workgroups/{workgroupId}/members/{membershipId}` | `tenant.workgroups.manage` ALEBO `workgroup.members.manage`    |
| `DELETE` | `/api/v1/tenants/{tenantId}/workgroups/{workgroupId}/members/{membershipId}` | `tenant.workgroups.manage` ALEBO `workgroup.members.manage`    |

Skupina má stavy `ACTIVE`/`ARCHIVED` s obojsmerným prechodom; opakovanie
aktuálneho stavu je idempotentné. **Archivovaná skupina nedáva žiadne
workgroup-scoped oprávnenie** – rovnako ako archivovaný projekt.
`loadWorkgroupDecision` aj `workgroupPermissionCodes` vyžadujú
`workgroup.status = 'ACTIVE'`, takže archivácia je pre manažéra skupiny
jednosmerná: reaktivovať ju vie iba držiteľ tenantového
`tenant.workgroups.manage`. Overuje to
`WorkgroupApiTest::testMemberAddedAsManagerCanManageTheirOwnWorkgroupWithoutTenantPermission`.
Pridanie člena vyžaduje aktívne tenantové
členstvo a aktívnu skupinu; `PUT` na existujúceho člena nahradí jeho rolu.
Odstránenie chýbajúceho člena je idempotentné (`204`). Vytvorenie, archivácia,
reaktivácia a zmeny členstva sa auditujú (`WORKGROUP_CREATED`,
`WORKGROUP_ARCHIVED`, `WORKGROUP_REACTIVATED`, `WORKGROUP_MEMBER_ADDED`,
`WORKGROUP_MEMBER_ROLE_CHANGED`, `WORKGROUP_MEMBER_REMOVED`).

Unit a databázové integračné testy overujú úplnosť katalógu, závislosti každej
predvolenej roly, nesprávny scope, deny by default, úplný `SUPERADMIN` bypass,
vypnutie bypassu pri impersonácii, presnú predvolenú maticu, idempotentný
provisioning, okamžitú revision invalidáciu a cross-tenant cudzie kľúče.

## Projektové role a oprávnenia

Projekt má na rozdiel od skupiny vlastný katalóg rolí. Pri vytvorení projektu sa
predvolené projektové roly (`PROJECT_MANAGER`, `MEMBER`, `REPORTER`, `VIEWER`)
provisionujú ako **nezávislá kópia** do `project_roles` a
`project_role_permissions`; neskoršia zmena predvolenej matice už existujúci
projekt nemení. Zmena hociktorej projektovej tabuľky zvyšuje tenantovú
autorizačnú revíziu rovnakým triggerom ako tenantové roly.

Projektový grant vzniká dvoma cestami, ktoré sa zjednocujú:

1. priame priradenie role tenantovému členstvu
   (`project_membership_role_assignments`),
2. prepojená pracovná skupina (`project_workgroups`), ktorá dáva svoju
   projektovú rolu všetkým aktívnym členom skupiny.

Obe cesty vyžadujú aktívneho používateľa, aktívne tenantové členstvo, aktívny
tenant, aktívny projekt a aktívnu rolu; skupinová cesta navyše aktívnu skupinu.

| Metóda   | Route                                                                              | Oprávnenie                                                |
| -------- | ---------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| `GET`    | `/api/v1/tenants/{tenantId}/projects`                                             | aktívne členstvo; rozsah podľa `tenant.projects.manage`   |
| `POST`   | `/api/v1/tenants/{tenantId}/projects`                                             | `tenant.projects.create`                                  |
| `PATCH`  | `/api/v1/tenants/{tenantId}/projects/{projectId}`                                 | `tenant.projects.manage` ALEBO `project.settings.manage`  |
| `GET`    | `/api/v1/tenants/{tenantId}/projects/{projectId}/roles`                           | `tenant.projects.manage` ALEBO `project.view`             |
| `GET`    | `/api/v1/tenants/{tenantId}/projects/{projectId}/members`                         | `tenant.projects.manage` ALEBO `project.view`             |
| `PUT`    | `/api/v1/tenants/{tenantId}/projects/{projectId}/members/{membershipId}/roles/{roleId}` | `tenant.projects.manage` ALEBO `project.members.manage`   |
| `DELETE` | `/api/v1/tenants/{tenantId}/projects/{projectId}/members/{membershipId}/roles/{roleId}` | `tenant.projects.manage` ALEBO `project.members.manage`   |
| `GET`    | `/api/v1/tenants/{tenantId}/projects/{projectId}/workgroups`                      | `tenant.projects.manage` ALEBO `project.view`             |
| `PUT`    | `/api/v1/tenants/{tenantId}/projects/{projectId}/workgroups/{workgroupId}`        | `tenant.projects.manage` ALEBO `project.members.manage`   |
| `DELETE` | `/api/v1/tenants/{tenantId}/projects/{projectId}/workgroups/{workgroupId}`        | `tenant.projects.manage` ALEBO `project.members.manage`   |

Zoznam projektov je jediný endpoint bez vlastného permission gate, pretože jeho
**rozsah** je autorizáciou: `tenant.projects.manage` vracia všetky projekty
tenantu, každý iný aktívny člen dostane `TENANT` viditeľné projekty plus
`PRIVATE` projekty, kde má rolu priamo alebo cez prepojenú skupinu. Rozhoduje
efektívny používateľ, takže počas impersonácie vidí volajúci presne to, čo
cieľová identita. Položka zoznamu nesie `viewer_roles` s vlastnými rolami
volajúceho.

Tenantová viditeľnosť je iba právo vidieť, že projekt existuje. Členov, roly a
prepojené skupiny vracia API až pri `project.view` na konkrétnom projekte, preto
tenantovo verejný projekt bez projektovej role odpovie na tieto tri endpointy
`403`.

Projekt má stavy `ACTIVE`/`ARCHIVED` s obojsmerným prechodom a idempotentným
opakovaním. Archivovaný projekt nedáva žiadne projektové oprávnenia, pretože
provider vyžaduje `project.status = 'ACTIVE'`. Kód projektu je unikátny v rámci
tenantu (`409 PROJECT_CODE_TAKEN`), súkromný projekt vyžaduje vedúceho, ktorý sa
v tej istej transakcii stáva prvým `PROJECT_MANAGER`. Vytvorenie, archivácia a
reaktivácia sa auditujú (`PROJECT_CREATED`, `PROJECT_ARCHIVED`,
`PROJECT_REACTIVATED`).

## Efektívne oprávnenia pre UI

`AuthorizationService::grantedPermissions()` vracia oprávnenia, ktoré subjekt v
danom scope skutočne má. `SUPERADMIN` bez impersonácie dostane všetky oprávnenia
scope; ostatní dostanú výsledok `EffectivePermissionProvider::listPermissions()`.
Oba prípady sa filtrujú cez `AuthorizationScope::supports()` — tenantová rola
podľa predvolenej matice nesie aj projektové kódy (`project.view`, `issue.*`) a
tie na tenantovej úrovni nič neznamenajú.

`GET /api/v1/tenants/{tenantId}` preto vracia pole `permissions` s tenantovo
scopovanými kódmi volajúceho. Frontend ich drží v `TenantStore` a používa pre
`permissionGuard()` a navigáciu. Je to **iba UX afordancia**: každý endpoint sa
autorizuje samostatne, takže zastaraný alebo podvrhnutý zoznam prístup
nerozšíri.

## Issue tracking a workflow prechody

Podľa `WORKFLOW-A-TYPY-ULOH.md` §10 tenantová rola **neobchádza** projektový
kontext úlohy. Všetky issue endpointy vyžadujú projektovo scopované oprávnenie;
`tenant.projects.manage` na ne nestačí (na rozdiel od čítania projektovej
konfigurácie, kde alternatíva platí kvôli konzistencii s ostatnými projektovými
read endpointmi).

| Metóda | Route                                                                  | Oprávnenie                                            |
| ------ | ---------------------------------------------------------------------- | ------------------------------------------------------ |
| `GET`  | `/api/v1/tenants/{tenantId}/projects/{projectId}/configuration`       | `tenant.projects.manage` ALEBO `project.view`         |
| `GET`  | `/api/v1/tenants/{tenantId}/projects/{projectId}/issues`              | `issue.view` na projekte                              |
| `POST` | `/api/v1/tenants/{tenantId}/projects/{projectId}/issues`              | `issue.create` na projekte                            |
| `GET`  | `/api/v1/tenants/{tenantId}/issues/{issueId}`                         | `issue.view` na projekte úlohy                        |
| `GET`  | `/api/v1/tenants/{tenantId}/issues/{issueId}/transitions`             | `issue.view` na projekte úlohy                        |
| `POST` | `/api/v1/tenants/{tenantId}/issues/{issueId}/transitions/{transitionId}` | `issue.transition` plus oprávnenie samotného prechodu |

Poradie kontrol pri vykonaní prechodu je záväzné: najprv `issue.transition` na
projekte, až potom verzia úlohy a dostupnosť prechodu. Opačné poradie by cez
rozdiel medzi `409`, `422` a `403` prezradilo stav workflow používateľovi, ktorý
na projekt nemá prístup. Prechod môže vyžadovať ďalšie oprávnenie cez
`permission_code`; neznámy kód je fail-closed.

Úloha sa vytvára výhradne z projektových metadát — klient neposiela počiatočný
stav ani verziu workflow, a pri prechode posiela iba `transition_id` a
`expected_issue_version`. Cudzí rodič alebo úloha z iného tenantu vracia `404`,
aby odpoveď nepotvrdila ich existenciu.

## Systémový kontext

Rola `SUPERADMIN` je uložená oddelene v `user_system_roles` a frontend ju obnovuje
cez aktuálny session kontrakt. `/system` používa samostatný layout a guard, ale
autoritatívne kontroly zostávajú na backendových endpointoch:

| Metóda   | Route                                       | Oprávnenie                   |
| -------- | -------------------------------------------- | ----------------------------- |
| `GET`    | `/api/v1/system/tenants`                    | `system.tenants.view`        |
| `POST`   | `/api/v1/system/tenants`                    | `system.tenants.create`      |
| `PATCH`  | `/api/v1/system/tenants/{tenantId}`         | `system.tenants.manage`      |
| `GET`    | `/api/v1/system/audit`                      | `system.audit.view`          |
| `POST`   | `/api/v1/system/impersonations`             | `system.impersonate`         |
| `GET`    | `/api/v1/system/users`                      | `system.users.manage`        |
| `PATCH`  | `/api/v1/system/users/{userId}`             | `system.users.manage`        |
| `PUT`    | `/api/v1/system/users/{userId}/superadmin`  | `system.superadmins.manage`  |
| `DELETE` | `/api/v1/system/users/{userId}/superadmin`  | `system.superadmins.manage`  |

V aktuálnom modeli všetky systémové permission získava výhradne `SUPERADMIN`
bypass. Rola sa pri každej session požiadavke overuje z databázy; frontendový stav
ani pôvodná login odpoveď nie sú trvalým grantom.

Zoznam vracia každý globálny účet vrátane `is_superadmin` príznaku. Zmena stavu
prijíma iba ciele `ACTIVE` a `DISABLED` (existujúci stavový automat
`UserStatus::canTransitionTo()` naďalej rozhoduje o legalite prechodu vrátane
`PENDING_VERIFICATION`/`LOCKED` vstupov) a odmieta zmenu vlastného účtu kódom
`SYSTEM_USER_SELF_MANAGEMENT_FORBIDDEN`. Priradenie aj odobratie role
`SUPERADMIN` sú idempotentné; odobratie navyše odmieta vlastnú rolu
(`SYSTEM_SUPERADMIN_SELF_MANAGEMENT_FORBIDDEN`) a posledného aktívneho
superadmina (`SYSTEM_LAST_SUPERADMIN_REQUIRED`), rovnaká ochrana sa uplatní aj
pri deaktivácii účtu posledného aktívneho superadmina. Každá operácia sa
zapisuje do bezpečnostného auditu. Priame vytvorenie účtu ani mazanie nie sú
súčasťou tohto API — účty vznikajú iba pozvánkou a mazanie bude mať vlastný
chránený tok podobný odstráneniu tenantu.

Počas session-bound impersonácie vytvorí `AuthorizationSubject::contextual()`
odlišného aktéra a efektívneho používateľa. Tým sa `SUPERADMIN` bypass vypne aj
vtedy, keď ho skutočný aktér naďalej drží, a provider rozhoduje iba podľa
tenantových grantov cieľa. Kontext je obmedzený na jeden tenant; podrobnosti sú v
[`IMPERSONATION.md`](./IMPERSONATION.md).

## Tenantové role API

Aktuálny F3.2 rez publikuje:

| Metóda   | Route                                                                  | Oprávnenie            |
| -------- | ---------------------------------------------------------------------- | --------------------- |
| `GET`    | `/api/v1/tenants/{tenantId}/roles`                                     | `tenant.roles.view`   |
| `POST`   | `/api/v1/tenants/{tenantId}/roles`                                     | `tenant.roles.manage` |
| `PUT`    | `/api/v1/tenants/{tenantId}/roles/{roleId}`                            | `tenant.roles.manage` |
| `DELETE` | `/api/v1/tenants/{tenantId}/roles/{roleId}`                            | `tenant.roles.manage` |
| `PUT`    | `/api/v1/tenants/{tenantId}/memberships/{membershipId}/roles/{roleId}` | `tenant.roles.assign` |
| `DELETE` | `/api/v1/tenants/{tenantId}/memberships/{membershipId}/roles/{roleId}` | `tenant.roles.assign` |

Zoznam vracia role s počtom priradení a celý non-system permission katalóg vrátane
scope, závislostí a označenia citlivosti. Priradenie aj odobratie sú idempotentné,
vyžadujú aktívny tenant, tenantovo zhodné identifikátory a pri `PUT` aj aktívne
členstvo a aktívnu rolu. Skutočná zmena sa zapíše do auditu; idempotentné
opakovanie nevytvára duplicitnú auditnú udalosť.

`TENANT_ADMIN` má `tenant.roles.assign`, ale nie `tenant.roles.manage`. Preto môže
spravovať bežné priradenia, no nemôže udeliť ani odobrať `TENANT_OWNER` a povýšiť
tak seba alebo iného člena. Owner operácia vyžaduje obe oprávnenia. Pri odobratí
owner roly služba zamkne tenantový riadok a v rovnakej transakcii skontroluje počet
aktívnych vlastníkov; posledného vlastníka odmietne kódom
`TENANT_LAST_OWNER_REQUIRED`.

Vlastná rola má tenantovo unikátny a po vytvorení nemenný kód. Create aj update
prijímajú iba non-system permission kódy a validujú celý zoznam vrátane explicitných
závislostí katalógu; prázdna sada je povolená. Update je úplná náhrada názvu,
opisu a grantov a vyžaduje poslednú známu kladnú `revision`. Zastaraná revízia
vráti `TENANT_ROLE_REVISION_CONFLICT`.

Rezervované štyri tenantové roly majú `is_system = true`,
`is_editable = false` a nemožno ich upraviť ani archivovať. Vlastnú rolu možno
archivovať až po odobratí zo všetkých členstiev; kód archivovanej roly sa znovu
nepoužíva. Opakovaná archivácia je idempotentná. Vytvorenie, skutočná úprava a prvá
archivácia zapisujú `TENANT_ROLE_CREATED`, `TENANT_ROLE_UPDATED` a
`TENANT_ROLE_ARCHIVED`; databázové triggery zároveň okamžite posunú autorizačnú
revíziu.

## Tenantové členstvo API

| Metóda  | Route                                                   | Oprávnenie              |
| ------- | ------------------------------------------------------- | ----------------------- |
| `GET`   | `/api/v1/tenants/{tenantId}/memberships`                | `tenant.members.view`   |
| `PATCH` | `/api/v1/tenants/{tenantId}/memberships/{membershipId}` | `tenant.members.manage` |

Administratívny zoznam vracia aktívne, deaktivované aj odstránené členstvá,
bezpečné identifikačné údaje a ich tenantové roly. Nevracia heslo ani hash hesla.

Lifecycle používa stavy `ACTIVE`, `DISABLED` a terminálny `REMOVED`. Povolené sú
prechody `ACTIVE → DISABLED|REMOVED` a `DISABLED → ACTIVE|REMOVED`; opakovanie
aktuálneho stavu je idempotentné. Fyzické mazanie sa nevykonáva, takže história a
referencie zostanú zachované. Vlastné členstvo nemožno meniť týmto všeobecným
tokom.

Ak členstvo nesie `TENANT_OWNER`, každá jeho lifecycle zmena navyše vyžaduje
`tenant.roles.manage`; samotné `tenant.members.manage` nestačí. Odobratie aktívneho
prístupu používa rovnaký transakčný `TenantOwnershipGuard` ako odobratie owner
roly, preto tenant vždy zachová aspoň jedného aktívneho vlastníka. Zmena členstva
sa auditne zapisuje ako `TENANT_MEMBERSHIP_DISABLED`,
`TENANT_MEMBERSHIP_REACTIVATED` alebo `TENANT_MEMBERSHIP_REMOVED` a trigger
okamžite invaliduje tenantový autorizačný cache.
