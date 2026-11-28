# Les tables tenant-scopées neuves (#1868)

Une table neuve qui porte `institution_id` se déclare ICI, dans son propre fragment, jamais dans la liste partagée
de `config/tenancy.php` (elle rouvrait les conflits de toutes les PR ouvertes).

- Un fichier par fonctionnalité : `<issue>-<sujet>.php`, par exemple `1657-dossier-eleve.php`.
- Il rend une LISTE de noms de tables : `return ['dossiers_eleves', 'tuteurs'];`
- La table reçoit sa clé étrangère `institution_id -> institutions(id)` en RESTRICT, comme toute table déclarée.

`config/tenancy.php` ajoute les fragments, triés par nom de fichier, à `institution_scoped_tables`.
`LesFragmentsDeTenancyTest` vérifie chaque fragment (une liste de noms, aucune table déclarée deux fois) et la mise en
cache de la configuration. `LaConfigDesTenantsSuitLeSchemaTest` compare toujours la liste au schéma, dans les deux sens.
