# ADR-GHSA-gg7j-01 — Un élève ne reçoit aucune donnée sur un autre élève

**Date :** 2026-09-27  
**Statut :** accepté  
**Origine :** avis de sécurité GHSA-gg7j-v79q-cqxc · réalise enfin le point 4 de #617

## Décision

Un compte de rôle élève ne reçoit du LMS **aucune** donnée sur un autre élève : ni nom, ni matricule, ni e-mail, ni téléphone, ni photo, ni présence. Décision produit du 2026-09-27.

La règle s'applique **côté serveur**, sans dépendre de ce que KLASSCI accorde ou refuse à un jeton élève :

| Ressource | Où la règle vit | Forme |
|---|---|---|
| Les routes qui n'existent que pour lister des élèves : `/lms/classes/{id}/etudiants`, `/proxy/classes/{id}/etudiants`, `/lms/seances/{id}/participants`, `/lms/seances/{id}/visio-participants` | la route | garde `role:enseignant,coordinateur,admin`, comme `/seances/{id}/attendances` |
| La fiche de classe, par ses deux portes (ADR-760-01) | `ClasseDetailsQueryService::rosterVisiblePar()` | roster vide pour qui n'est pas du personnel ; l'effectif reste |
| La liste des évaluations de l'élève | `StudentEvaluationsListService` | `classe = {id, nom}` ; l'enveloppe `classes/{id}` n'est plus demandée |
| Les fichiers | `FileQueryService::auteurVisiblePar()` et `ListFilesRequest` | auteur `null` sur les fichiers d'autrui ; `?user_id=` d'un autre compte refusé (422) |

**Fail-closed** : le prédicat est `isStaff()`, jamais `! isStudent()`. Un rôle inconnu est traité comme un élève.

## Pourquoi

### Le LMS déléguait l'autorisation à KLASSCI

#617 l'avait écrit — « une autorisation qui ne s'exécute qu'en cas de cache miss n'est pas une autorisation » — et proposait un contrôle d'appartenance côté LMS. Seul le cache a été corrigé (#627). Or ce que KLASSCI accorde ou refuse à un jeton élève varie d'un endpoint à l'autre, et une partie des listes est construite depuis la base **locale**, sans même passer par KLASSCI : la règle ne peut vivre que dans le LMS.

### La règle vit dans le service quand deux portes le servent

La fiche de classe a deux portes. Une garde posée sur l'une laisserait l'autre ouverte ; la règle est donc dans `ClasseDetailsQueryService`. Les routes de liste, elles, n'ont aucun usage élève (le bouton « Participants » est déjà réservé au personnel, `VisioManager.vue`) : la garde de rôle suffit, et elle rend l'intention lisible dans `routes/`.

### Ce qui n'est pas retiré, et pourquoi

- L'effectif d'une classe (`statistiques.nombre_etudiants`) : un nombre n'est pas une donnée sur quelqu'un.
- Le nom de l'auteur d'un message de forum (`user:id,name,role`, jamais l'e-mail) : un forum sans auteurs n'en est pas un. Décision explicite, réversible.
- Les enregistrements vidéo de séance : contenu pédagogique.
- Les données des **enseignants** livrées aux élèves (e-mail dans les quiz, les leçons, `/lms/enseignants`) : hors périmètre de cet ADR.

## Conséquences

- Front (`lms-frontend`, lot séparé) : masquer l'onglet « Étudiants » et le compteur de la fiche de classe pour un élève, sinon il lira « 0 étudiant ».
- Tests : `RosterFermeAuxElevesTest` — dont un détecteur qui cherche les **clés** personnelles à toute profondeur, sans connaître la forme des réponses — et `FichierAuteurFermeAuxElevesTest`, avec un vrai jeton Bearer.

## Ce qui invaliderait cette décision

Un écran élève légitime qui aurait besoin d'un camarade nommément (travail de groupe, binôme). Il faudrait alors une **liste dédiée et minimale** (nom seul), sur une route à part — jamais rouvrir les routes de roster.
