# ADR-921-01 — Le statut d'une adhésion a un seul écrivain par classe

**Date :** 2026-11-16  
**Statut :** accepté  
**Issue :** #921 · complète ADR-803-03 (trois portes, un service) et ADR-885-01 (porte authentifiée par code)

## Décision

`classe_etudiant.statut` est écrit par **un seul domaine par classe** :

| Classe | Écrivain du statut | Règle |
|---|---|---|
| **KLASSCI** (`classes.klassci_id` renseigné) | la synchronisation KLASSCI : `ClasseStudentsSynchronizer` et `ReconciliationKlassci` | **KLASSCI fait autorité** : un élève listé est remis à `actif`, même s'il était clos ; un élève absent d'une liste **prouvée complète** passe `inactif`. Jamais de suppression. Aucune écriture locale. |
| **Locale** (`klassci_id` nul) | les portes locales : le code (`StudentEnrolmentService::rejoindre`), l'import (`ImportApplyService`), l'établissement (`inscrire`) | **Un code ne rouvre jamais** une adhésion close (#885). L'établissement, lui, écrit le statut qu'il décide. |

**L'autorité suit le mode courant de l'école.** Une école passée de KLASSCI à autonome peut, par import, fermer une adhésion d'une classe d'origine KLASSCI. Si elle revient en KLASSCI, la synchronisation la rouvre, car KLASSCI fait de nouveau foi.

## Pourquoi c'est une décision existante, et non une nouvelle

Le relevé de tous les écrivains de `classe_etudiant.statut` dans `app/` (16/11, `origin/lms` 05e00810) :

- `ClasseStudentsSynchronizer:201-233` : insertion `actif`, puis remise à `actif` d'une ligne existante. Les classes sont retrouvées par `(klassci_id, institution_id)` (`ClasseSyncService:272`) : **uniquement des classes KLASSCI** ;
- `ReconciliationKlassci:56-65` : `inactif` pour l'absent, borné à la classe synchronisée ;
- `StudentEnrolmentService::rejoindre()` : classes ouvertes par code, que `ClasseOuverteParCode` limite aux **classes locales** ;
- `ImportApplyService:144` : école autonome seulement (#805).

Les deux règles qu'#921 jugeait opposées, « KLASSCI rouvre » et « un code ne rouvre pas », **ne touchent jamais la même classe**. Dans une école KLASSCI, aucun écrivain local ne ferme une adhésion. Il n'existe donc pas de « suspension locale » que la synchronisation pourrait écraser. KLASSCI était déjà l'autorité de fait, conformément à l'autorité du roster par mode (épique #697, `RosterAuthorityFactory`). Ce qui manquait, c'est qu'un document le dise.

## Comment on le vérifie

`tests/Feature/Enrollment/AutoriteDuStatutDAdhesionTest.php`, dans deux volets : une école KLASSCI, et une école revenue en KLASSCI après une période autonome, la seule qui porte à la fois des classes locales et des classes KLASSCI.
- la synchronisation ne touche aucune adhésion de classe locale, même close ;
- la réconciliation non plus ;
- un code ne rouvre pas une adhésion locale close ;
- KLASSCI rouvre une adhésion close de **sa** classe.

Les tests sont verts dès leur écriture, puisque la règle est déjà vraie. Leur rouge vient des falsifications : un synchroniseur qui écrit au-delà de sa classe, ou `rejoindre()` qui rouvre.

## Ce que cette décision ne règle pas

- **Une suspension locale dans une école KLASSCI.** Aucun écran ne la permet aujourd'hui. Si le produit la veut, il faudra un statut local qui survive à la synchronisation, et le synchroniseur devra le respecter. C'est une décision produit, à prendre dans un lot à part.
- **Les chaînes `'actif'` et `'inactif'`** écrites en dur par la synchronisation et la réconciliation : c'est #896.
