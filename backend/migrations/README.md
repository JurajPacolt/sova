# Databázové migrácie

Adresár obsahuje verzované Doctrine migrácie pre PostgreSQL.

Základné príkazy:

```powershell
composer db:status
composer db:migrate
composer db:generate
```

Každá zmena schémy musí byť vykonaná migráciou a overená na prázdnej aj existujúcej
databáze. Produkčné migrácie nesmú predpokladať možnosť bezpečného deštruktívneho
rollbacku.
