# Versioning de l'API (#217)

## Stratégie : versioning par path

L'API est versionnée par **préfixe de chemin** (`/api/v1/...`), pas par en-tête.
Raisons :

- **Lisibilité / cache** : la version est visible dans l'URL, les caches et les
  logs la distinguent naturellement.
- **Simplicité client** : un client épingle une version en changeant son base
  URL, sans manipuler d'en-têtes personnalisés.
- **Outillage** : OpenAPI, SDKs et tests raisonnent sur des chemins stables.

## Chemins servis

| Chemin | Sert | Statut |
|---|---|---|
| `/api/...` | routes actuelles | **conservé** (rétrocompatibilité) |
| `/api/v1/...` | **mêmes** routes (alias) | recommandé pour les nouveaux clients |
| `/api/v2/...` | `routes/v2.php` | réservé aux futurs breaking changes (vide) |

Le path non-versionné `/api/...` est **délibérément conservé** (pas de 301) :
le frontend en production l'utilise, une redirection le casserait. Il restera
un alias permanent de v1 tant que des clients l'utilisent.

### Implémentation

Montage dans `bootstrap/app.php` (`withRouting`) :

- `api:` monte `routes/api.php` sous `/api` (non-versionné).
- `then:` remonte `routes/api.php` sous `/api/v1` (noms de routes préfixés
  `v1.` pour éviter les collisions) et `routes/v2.php` sous `/api/v2`.

## Quand créer v2 ?

Un nouvel endpoint v2 n'est nécessaire QUE pour un **breaking change** (cf.
`docs/BREAKING_CHANGES.md`) : suppression de champ, renommage, changement de
type/sémantique, retrait d'endpoint.

Procédure :

1. Définir dans `routes/v2.php` UNIQUEMENT les endpoints qui divergent de v1.
2. Les endpoints inchangés restent servis par v1 (pas de duplication).
3. Documenter le diff v1→v2 et la date de dépréciation de v1 si applicable.
4. Mettre à jour l'OpenAPI et les SDKs.

## Migration du frontend

Le frontend (dépôt séparé `frontend_lms`) devrait migrer progressivement de
`/api/...` vers `/api/v1/...` pour épingler explicitement la version. Aucune
urgence : le path non-versionné reste un alias de v1.

> Tâche de suivi côté frontend : remplacer le base URL `…/api` par `…/api/v1`.
