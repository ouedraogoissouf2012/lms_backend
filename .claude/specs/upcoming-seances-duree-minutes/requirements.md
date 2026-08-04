# Requirements — `duree_minutes` sur le listing « séances à venir » (#487)

## Contexte

`UpcomingSeancesFetcher::enrichWithVisio()` contient un bloc de calcul de
`duree_minutes` **inerte** : il lit `$seance['date_seance']`,
`$seance['heure_debut']`, `$seance['heure_fin']` **à la racine**, alors que
`UpcomingSeanceMapper::mapRow()` ne produit ces valeurs que sous
`programmation.*` (`programmation.date`, `programmation.heure_debut`,
`programmation.heure_fin`). La condition de garde est donc **toujours fausse**
sur ce chemin → `duree_minutes` n'est jamais ajouté.

Le champ est **légitime** : `SeanceDetailQueryService` (détail d'une séance)
calcule et sert `duree_minutes` depuis `programmation.heure_debut/heure_fin`
(`:90-92`). Le listing « à venir » est donc **incohérent** avec le détail.

## Portée

- **IN** : réparer le calcul dans `enrichWithVisio` pour lire depuis
  `programmation.*` ; verrouiller le contrat de sortie par un test.
- **OUT** : le chemin **manager** (`ManagerSeancesLocalFetcher`) qui n'appelle
  pas `enrichWithVisio` et n'a jamais exposé `duree_minutes` — inchangé.
- **OUT** : `SeanceDetailQueryService` / `SeanceVisioEnricher` — déjà corrects.

## Exigences (EARS)

**REQ-1 — Calcul depuis la bonne source**
WHEN `enrichWithVisio` traite une séance mappée dont
`programmation.heure_debut` ET `programmation.heure_fin` sont des datetimes
non nuls, THE SYSTEM SHALL ajouter `duree_minutes` = différence en minutes
entière (≥ 0) entre les deux, calculée comme `SeanceDetailQueryService`
(`Carbon::parse(heure_fin)->diffInMinutes(...)` sur les valeurs
`programmation.*` déjà alignées sur la date de séance, SANS reconcaténer
la date).

**REQ-2 — Résilience aux données incomplètes**
IF `programmation.heure_debut` OU `programmation.heure_fin` est absent ou non
convertible en datetime, THEN THE SYSTEM SHALL omettre `duree_minutes` (pas de
clé, pas d'exception) — comportement identique à l'actuel « absence
silencieuse ».

**REQ-3 — Cohérence de type**
WHERE `duree_minutes` est ajouté, THE SYSTEM SHALL l'exposer comme entier
(minutes), cohérent avec `SeanceDetailQueryService` et le contrat existant.

**REQ-4 — Non-régression du reste du contrat**
THE SYSTEM SHALL ne modifier AUCUNE autre clé de la sortie (`id`,
`programmation`, `matiere`, `classe`, `enseignant`, `visio_*`) : seule
`duree_minutes` est ajoutée.

**REQ-5 — Pas de code mort**
THE SYSTEM SHALL ne laisser AUCUNE lecture des clés racine mortes
`date_seance` / `heure_debut` / `heure_fin` dans `enrichWithVisio`
(PRODUCTION_STANDARDS.md §1.1).

## Critères d'acceptation (mesurables)

1. Test de contrat : une séance mappée avec `programmation.heure_debut` =
   `...T08:00:00` et `programmation.heure_fin` = `...T09:30:00` → sortie
   contient `duree_minutes === 90`.
2. Test résilience : séance sans `programmation.heure_fin` → clé
   `duree_minutes` **absente**, aucune exception.
3. Aucune occurrence de `['date_seance']`, `['heure_debut']`, `['heure_fin']`
   racine dans `enrichWithVisio` après correction.
4. `php artisan test` = 100 % ; PHPStan level 9 vert ; garde 300 lignes OK.

## Q15 — Critères d'invalidation (ce qui ferait échouer la solution)

- ❌ `duree_minutes` calculé par reconcaténation `date . ' ' . heure` alors que
  `programmation.heure_*` sont **déjà** des datetimes ISO complets → double date,
  parse erroné.
- ❌ Une clé de sortie autre que `duree_minutes` change (régression contrat).
- ❌ `duree_minutes` ajouté sur le chemin manager (qui ne l'a jamais eu).
- ❌ Exception levée sur données partielles au lieu d'omission silencieuse.
- ❌ `duree_minutes` négatif (heure_fin < heure_debut mal géré) exposé au client.
