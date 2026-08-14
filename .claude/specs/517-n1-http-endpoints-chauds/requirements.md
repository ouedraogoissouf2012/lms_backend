# Requirements — #517 N+1 HTTP sur endpoints chauds

## Contexte

Trois callsites KLASSCI non migrés vers le pool batch (`KlassciBatchFetcher` / issue #135), sur des
chemins requête chauds (pas des jobs cron) :

- H3 — `KlassciSeanceLookupService` (détail d'une séance) : scan linéaire séquentiel des matières,
  jusqu'à M appels HTTP `matieres/{id}` pour trouver UNE séance. Pire cas coordinateur : centaines
  de matières scannées séquentiellement.
- H4 — `MatiereSeancesFetcher::enrichSeances` : 1 HTTP `classes/{id}` séquentiel par séance + 1
  SELECT `seances` par séance (dans `enrichSeances` ET dans `filterHiddenAndArchivedForStudent`).
- H5 — `KlassciClassesFetcher` : 1 HTTP `classes/{id}` séquentiel par classe (`fetchAllClassesWithDetails`)
  et 1 HTTP `matieres/{id}` séquentiel par matière (`fetchTeacherClasses`).

## Requirements (EARS)

**R1** — WHEN un utilisateur (enseignant, étudiant, coordinateur) demande le détail d'UNE séance
via `KlassciSeanceLookupService::lookup()`, THE SYSTEM SHALL résoudre la matière associée sans
dépasser 2 appels HTTP séquentiels dans le cas nominal (séance déjà synchronisée localement).

**R2** — IF la séance n'est pas résolvable localement (pas encore synchronisée), THEN THE SYSTEM
SHALL basculer sur un scan de secours dont les appels HTTP `matieres/{id}` restants sont
parallélisés via le pool batch (`fetchManyMatieresDetails`), jamais séquentiels.

**R3** — WHERE la résolution locale est utilisée, THE SYSTEM SHALL vérifier que la matière résolue
appartient à l'ensemble des matières accessibles par le rôle de l'utilisateur (dashboard
enseignant/étudiant, ou liste complète pour coordinateur) AVANT d'utiliser son id — aucune
régression d'autorisation (IDOR) n'est acceptable.

**R4** — WHEN `MatiereSeancesFetcher::fetchSeancesForUser` enrichit une liste de N séances, THE
SYSTEM SHALL émettre au plus 1 appel HTTP batché (`fetchManyClassesDetails`) pour tous les
effectifs de classe, et au plus 1 SELECT local groupé (`whereIn`) pour l'état visio/archivage/masquage,
quel que soit N.

**R5** — WHEN `KlassciClassesFetcher::fetch()` résout les classes détaillées (chemin `/classes` ou
fallback enseignant), THE SYSTEM SHALL utiliser le pool batch (`fetchManyClassesDetails` /
`fetchManyMatieresDetails`) au lieu d'une boucle séquentielle.

**R6** — THE SYSTEM SHALL préserver le comportement fonctionnel existant à l'identique (mêmes
séances/classes retournées, mêmes fallbacks sur échec HTTP partiel) — non-régression stricte,
vérifiée par tests avant/après.

**R7** — THE SYSTEM SHALL respecter PRODUCTION_STANDARDS.md : fichiers ≤300 lignes, méthodes ≤40
lignes, DI stricte (aucun `new` ni Facade en code métier), zéro N+1 SQL résiduel introduit par le
fix lui-même.

## Non-objectifs

- Pas de changement de schéma DB (la colonne `seances.klassci_matiere_id` existe déjà —
  migration `2025_10_20_153241_create_seances_table.php`).
- Pas de changement du comportement des jobs cron (#515, #516 — hors périmètre de #517).
