# Politique des breaking changes (#216)

Cette politique définit ce qu'est un changement cassant de l'API LMS, comment
il est communiqué, et la période de dépréciation garantie aux clients.

## 1. Définition — qu'est-ce qu'un breaking change ?

Est considéré comme **breaking** tout changement qui peut casser un client
existant sans action de sa part :

- **Route** : suppression ou renommage d'un endpoint, changement de méthode HTTP.
- **Payload de requête** : nouveau champ requis, suppression d'un champ accepté,
  changement de type/format, règle de validation plus stricte.
- **Réponse** : suppression ou renommage d'un champ, changement de type, de
  structure (objet ↔ tableau), ou de sémantique d'un champ existant.
- **Codes HTTP** : changement du code de succès/erreur d'un endpoint.
- **Authentification / autorisation** : durcissement des rôles requis sur un
  endpoint existant.

N'est **PAS** breaking (changement additif, autorisé en mineur) :

- Ajout d'un nouvel endpoint.
- Ajout d'un champ **optionnel** en requête.
- Ajout d'un champ en réponse (les clients doivent ignorer l'inconnu).
- Ajout d'une valeur d'énumération documentée comme extensible.

## 2. Versioning

Les breaking changes passent par une **nouvelle version majeure de path**
(`/api/v2/...`), cf. `docs/API_VERSIONING.md`. La version précédente (`/api/v1`,
et le path non-versionné `/api/...`) continue de fonctionner pendant la période
de dépréciation.

## 3. Période de dépréciation

- **Minimum 1 version majeure** de chevauchement : `v1` reste servi tant que
  `v2` n'a pas atteint maturité, et au moins **90 jours** après l'annonce de
  dépréciation de `v1`.
- Aucune suppression de `v1` sans annonce préalable explicite.

## 4. Notification

Un endpoint déprécié signale sa dépréciation via les en-têtes HTTP standard
(**RFC 8594**) :

- `Deprecation: true` (ou une date `Deprecation: <http-date>`).
- `Sunset: <http-date>` — date à partir de laquelle l'endpoint peut disparaître.
- `Link: <url>; rel="deprecation"` — lien vers la note de migration.

## 5. Changelog

Tout breaking change est consigné dans `CHANGELOG.md` sous une entrée de version
majeure, avec :

1. La description du changement.
2. La raison.
3. Le guide de migration `v(N) → v(N+1)`.
4. La date de `Sunset` de l'ancienne version.

## 6. Process de release

1. Implémenter le changement sous `/api/v(N+1)` (ne pas toucher v(N)).
2. Ajouter les en-têtes `Deprecation` / `Sunset` sur les endpoints v(N) concernés.
3. Mettre à jour l'OpenAPI, les SDKs et le `CHANGELOG.md`.
4. Annoncer (release notes + communication aux intégrateurs).
5. Après la période de dépréciation, retirer v(N) dans une release majeure dédiée.
