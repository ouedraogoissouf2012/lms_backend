# ADR-886-01 — Le miroir de la classe d'un élève, sans second écrivain

**Date :** 2026-11-17  
**Statut :** accepté  
**Issue :** #886 · prolonge [ADR-878-01](2026-09-21-878-01-institution-du-miroir-de-classe.md), qui a nommé la limitation sans la trancher

## Décision

Au login, quand `StudentClassSynchronizer` écrit la classe KLASSCI d'un élève et qu'aucune classe miroir n'existe pour ce `(institution_id, klassci_id)`, il demande le job **existant** `SyncUserClasses`, sur la file `low`, **après le commit**. Il passe par un collaborateur, `MiroirDeLaClasseDeLEleve`.

`ClasseSyncService` reste le **seul écrivain** du miroir KLASSCI dans `classes`. Une garde d'architecture le fige (`ClasseMiroirEcrivainUniqueTest`), avec `LocalClasseCreator`, l'écrivain des classes d'une école autonome.

Pour le rôle `etudiant`, `KlassciClassesFetcher::fetch` lit **`GET me/dashboard` → `[data.classe]`**, la forme mesurée au login (#969), sans le bloc `etudiants`, et rien d'autre.

## Pourquoi

### Le manque

`KlassciEnrollmentSource::localClasseIdsFor` traduit `user_classes.klassci_classe_id` en `classes.id` par la classe miroir, et rend `[]` sans elle. Or rien, dans le parcours d'un élève, ne crée ce miroir : `StudentClassSynchronizer` n'écrit que `user_classes`, `SyncUserClasses` n'était demandé que pour un enseignant (`MyMatieresQueryService`), et `SyncKlassciClasse` que par les séances d'un enseignant (`TeachingSeancesFetcher`). Un élève dont aucun enseignant ne s'est connecté n'avait jamais de miroir.

### Le fait qui tranche

Le seul écrivain savait **déjà** traiter un élève : `SyncUserClasses` passe le rôle du compte à `ClasseSyncService::syncUserClasses`, et `KlassciClassesFetcher` avait une branche `etudiant`. Ce chemin n'avait simplement aucun appelant pour un élève. Le brancher ne crée aucun écrivain.

### Les trois voies de l'issue

1. **Le synchroniseur étudiant crée le miroir** : écartée. Ce serait un second écrivain sur `classes`, ce qu'ADR-878-01 refuse (deux sources concurrentes sur une table sont la cause racine de #673).
2. **Un job miroite les classes des élèves** : retenue, **sans nouveau job** : `SyncUserClasses` existe, il porte déjà le jeton hors du payload, le tenant (`SyncTenantContext`), la file `low` et ses réessais.
3. **`EnrollmentSource` expose l'espace KLASSCI** : écartée. Elle change le contrat de tous les appelants pour un manque qu'un dispatch comble.

### Pourquoi seulement sans miroir

Rejouer la synchro à chaque login ajouterait des appels à une cible qui filtre sur le volume (le filtre anti-abus de KLASSCI, mesuré le 2026-09-04, docblock de `SyncKlassciClasse`). Le miroir est cherché dans l'école de l'élève, par un filtre explicite : au login, aucun tenant n'est résolu et le scope global est ouvert.

### Pourquoi après le commit

`KlassciUserSynchronizer` écrit le compte dans une transaction, et le job relit le compte et son jeton. Mis en file avant le commit, il pourrait s'exécuter sur un compte encore invisible.

### Pourquoi `me/dashboard`, et pas `me/student-dashboard` ni `GET /classes`

- `me/dashboard` → `data.classe` est la forme **prouvée** : c'est celle que le login lit depuis #969.
- `me/student-dashboard` → `data.classes` était la branche historique de l'élève dans le fetcher. Elle n'a **jamais été mesurée** : aucun appelant ne l'atteignait pour un élève. Rien ne prouve que KLASSCI expose cet endpoint, ni qu'il rend cette forme. On ne bâtit rien dessus.
- `GET /classes` était tenté d'abord pour tout rôle. Son effet avec le jeton d'un élève n'a jamais été mesuré non plus, et la liste des classes de l'école, avec leurs élèves, n'est pas la classe de cet élève. Le rôle `etudiant` ne le tente plus.
- Le bloc `etudiants` est retiré de la classe reçue : la liste vue par un élève ne fait pas autorité sur l'effectif de sa classe.

## Les deux volets

L'élève d'une école autonome se connecte en local : son inscription est `classe_etudiant`, et il ne passe jamais par `StudentClassSynchronizer`. Un témoin le prouve (`MiroirDeLaClasseDeLEleveTest`).

## Ce que cet ADR ne décide pas

- Le rattrapage des élèves déjà connectés sans miroir : leur prochain login demande le miroir.
