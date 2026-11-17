# ADR-1154-01 — Une porte par espace pour désigner une séance

**Date :** 2026-11-17  
**Statut :** accepté  
**Issue :** #1154 · applique [ADR-760-01](2026-09-19-760-01-deux-portes-deux-espaces.md) (une porte par espace) ; prolonge #933 (PR #1155)

## Décision

Une séance se désigne par **une porte par espace d'identifiants**, livrée en trois étapes, dans cet ordre :

- **A (back, cette PR).** `DELETE /api/lms/seances/local/{seanceId}` désigne la séance par son **id local**, et seulement par lui, dans l'établissement du compte, quel que soit son mode. La règle de suppression est celle de la porte historique, partagée par un trait (`AuthorizesSeanceDeletion`). Les portes existantes ne changent pas.
- **B (front).** L'historique (`useSeanceAttendanceHistory.js`) passe sur la porte locale.
- **C (back, après le déploiement de B).** Dans une école KLASSCI, la porte historique devient **stricte en espace KLASSCI** (`Seance::localIdForKlassciId`), pour la suppression comme pour le masquage. `HIDE` et `UNHIDE` passent par `SeanceDesignee`, le résolveur lié au mode, comme la suppression : leur `orWhere` sur les deux espaces (`SeanceHideService::resolveSeance`) disparaît, ce qui corrige aussi l'école autonome. L'étape C ferme #1154.

Entre A et C, la porte historique garde « le local d'abord » (#933) : la dette reste nommée, sans aggravation.

## Pourquoi

### Deux appelants, deux espaces, une porte

Dans une école KLASSCI, `DELETE /api/lms/seances/{id}` reçoit l'id KLASSCI du calendrier (`useSeanceManagement.js:164`) et l'id local de l'historique (`useSeanceAttendanceHistory.js:227`, alimenté par `SeancesHistoryQueryService`). La porte ne peut pas savoir lequel elle reçoit. Si l'id local d'une séance égale l'id KLASSCI d'une autre de la même école, le calendrier peut supprimer la mauvaise.

### La collision reste possible depuis #1182

Avec #1182 (PR #1220), une école KLASSCI ne crée plus de séance locale. Mais les **miroirs locaux** des séances KLASSCI (`klassci_seance_id` non nul, créés à l'activation de la visio) ont toujours un **id local**. Les deux numérotations coexistent donc toujours dans la table `seances` : la collision reste possible, et la pile garde sa raison d'être.

### Le masquage n'a pas besoin de porte locale

Mesuré sur `lms` et le front `dev` le 2026-11-17 : dans une école KLASSCI, les deux appelants du masquage envoient l'id KLASSCI.

- La page matière lit `seances_programmees` (`MatiereDetailsQueryService`), rempli par `MatiereSeancesFetcher::enrichSeances` : la séance est la charge KLASSCI brute, dont l'`id` est l'id KLASSCI (il sert de `klassci_seance_id` pour retrouver le miroir).
- Le détail (`SeanceDetailQueryService::getSeanceDetailsArray`) rend la séance KLASSCI ; son repli local rend `klassci_seance_id ?? id` (`SeanceVisioEnricher::loadFromLocalDbFallback`).

Le seul cas qui enverrait un id local est une séance locale **sans** `klassci_seance_id` dans une école KLASSCI. Mesuré en production le 2026-11-17, en lecture seule, par la superviseure (pour #1182) : `seances` × `institutions`, `klassci_seance_id IS NULL`, `deleted_at IS NULL`, `mode = 'klassci'` : **aucune ligne**. L'étape C n'attend donc rien.

### D439 est remplacée, pas tranchée

D439 demandait l'ordre à appliquer sur une collision (l'id local ou le `klassci_seance_id` d'abord). Avec une porte par espace, aucun ordre n'est à choisir : chaque porte désigne la séance de son espace. À l'issue de l'étape C, `DesignationHorsCollisionTest` (aujourd'hui incomplet, en attente de D439) sera réécrit en « chaque porte désigne la séance de son espace ».

## Conséquences

- La porte locale est déclarée dans `docs/openapi.yaml`, avec ses refus : 403 pour une séance qu'elle ne désigne pas (le contrat de la porte historique), 422 pour une visio en cours.
- L'école autonome n'a qu'un espace : la porte locale et la porte historique y désignent la même séance (verrou de #933).
