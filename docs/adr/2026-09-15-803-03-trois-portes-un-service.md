# ADR-803-03 — Trois portes d'inscription, un seul service, une seule table

**Date :** 2026-09-15  
**Statut :** accepté  
**Issue :** #803 (backend) · #391 (frontend)

## Décision

Un étudiant entre dans une classe par **trois portes** :

1. **saisie unitaire** — par le SuperAdmin (5), l'Admin (4) ou le Coordinateur (3) ;
2. **import CSV** — existant, `app/Services/Import/ImportApplyService.php` ;
3. **code d'inscription** — l'étudiant s'inscrit lui-même avec un code court.

Les trois aboutissent à **un seul service d'inscription**, qui écrit **`classe_etudiant`** et rien d'autre.

**`user_classes` n'est jamais écrite par une inscription locale.** Cette table conserve son unique écrivain légitime, `app/Services/Klassci/Auth/StudentClassSynchronizer.php:96`, alimenté par KLASSCI.

Le **code d'inscription appartient à la `Classe`**. Il est court, à casse insensible, et exclut les glyphes qu'on confond en le dictant au téléphone (`0`/`O`, `1`/`I`/`L`). Il est révocable et régénérable sans toucher aux inscriptions déjà faites.

Aucune lecture d'inscription ne contourne `app/Services/Enrollment/CompositeEnrollmentSource.php`.

## Pourquoi

### La Classe porte l'inscription — c'est déjà tranché

[ADR-711-05](2026-09-06-711-05-classe-reference.md), statut **accepté** : « La Classe **porte inscription**, progression, notes, présences et surcharges locales. »

Le code d'inscription se pose donc sur la `Classe`, sans décision nouvelle. Et comme la future `training_session` référencera sa classe (#800), elle exposera le code de celle-ci : c'est une délégation, pas une dette à repayer. **La voie 3 ne dépend pas de #800.**

### Trois écrivains, c'est trois règles qui divergent

C'est déjà arrivé dans ce dépôt, et le prix est visible : `user_classes` d'un côté, `classe_etudiant` de l'autre, et un `CompositeEnrollmentSource` pour réconcilier après coup. La cause n'était pas un mauvais choix de table, mais **deux chemins d'écriture indépendants**.

Trois portes avec trois services produiraient trois politiques divergentes sur les mêmes questions : que faire d'un doublon, d'un étudiant déjà inscrit ailleurs, d'un compte existant avec le même email, d'un code de classe inconnu. Un seul service répond une fois.

### La cible est `classe_etudiant`, et le code le dit déjà

`CompositeEnrollmentSource` lit le pivot local **d'abord**, le cache KLASSCI **en repli**, et son docblock porte la règle : « Aucune conditionnelle de mode ».

C'est le patron OCP du contrat #697 sous sa forme la plus aboutie du dépôt : une interface, deux implémentations, un point de liaison, zéro branchement sur le mode. Toute inscription locale qui écrirait ailleurs casserait ce patron.

`ImportApplyService::enroll` écrit déjà correctement — `$classe->etudiants()->attach($user->id, ['statut' => 'actif'])`. La voie 2 n'a pas à changer de cible, seulement à passer par le service commun.

### Le trou à refermer en même temps

`app/Services/Visio/Recording/SeanceRecordingAccessService.php:66-70` lit `user_classes` **sans** repli local :

```php
return UserClass::query()
    ->where('institution_id', $seance->institution_id)
    ->where('user_id', $user->id)
    ->where('klassci_classe_id', $seance->klassci_classe_id)
    ->exists();
```

Pour une école autonome, cette méthode rend **toujours faux**, et pour deux raisons indépendantes :

1. `user_classes` n'a qu'un seul écrivain, `StudentClassSynchronizer.php:96`, alimenté par KLASSCI. Un étudiant inscrit localement n'y a **aucune ligne**.
2. Même si l'on en écrivait une : `$seance->klassci_classe_id` vaut NULL, et Laravel traduit `where($col, null)` en `IS NULL` (`Builder.php:936-937`). Or `user_classes.klassci_classe_id` est **NOT NULL** par garde structurelle assumée — `2025_11_02_092816:17`, réaffirmé en `2026_09_06_162500:11` : « RESTE NOT NULL ». Aucune ligne ne peut donc satisfaire `IS NULL`.

Conséquence : tout étudiant d'une école autonome **n'accède à aucun enregistrement de séance**, alors qu'il assiste aux cours.

Ce n'est pas un défaut de l'inscription, c'est un défaut de **lecture**. Il appartient à cet ADR parce qu'il illustre la règle : toute lecture d'inscription passe par `CompositeEnrollmentSource`, sans exception.

### Un défaut de l'import, à corriger au passage

`ImportApplyService::enroll` se termine par `if ($classe === null) { return; }` : si le code de classe du fichier ne correspond à aucune classe, la ligne est **silencieusement ignorée**. L'import se déclare réussi et l'étudiant n'est dans aucune classe. Un service commun doit remonter ce cas comme un rejet de ligne, pas l'avaler.

### Pourquoi les trois portes, et pas seulement l'import

L'import CSV suppose que l'école détienne déjà la liste. C'est vrai d'un établissement scolaire, faux d'un formateur qui ouvre une session et diffuse son code par WhatsApp — le cas d'usage central du monde autonome (`docs/PLAN_AUTONOMIE_KLASSCI.md:171`).

Inversement, le code seul ne convient pas à un organisme qui reçoit une liste de stagiaires financés : il lui faut l'import, et une inscription nominative opposable.

Les deux populations coexistent. Les deux portes sont nécessaires. La saisie unitaire reste le recours pour le cas isolé.

### L'accès au compte

Un étudiant créé par import ou par saisie n'a pas de mot de passe utilisable — `ImportApplyService.php:69` en pose un aléatoire que personne ne lui transmet. Il reçoit un **lien d'activation à usage unique**, mécanisme défini par [ADR-803-02](2026-09-15-803-02-validation-atomique.md).

Un étudiant qui s'inscrit par code choisit son mot de passe au moment de l'inscription : il est présent, il n'a besoin d'aucun lien.

## Conséquences

- Un service d'inscription unique ; `ImportApplyService::enroll` devient l'un de ses appelants et cesse d'écrire directement.
- Colonnes de code sur `classes` : le code lui-même, sa date de révocation, et la contrainte d'unicité **portée par `institution_id`** — deux écoles peuvent tirer le même code.
- Une route d'inscription par code, authentification non requise pour lire la classe visée, mot de passe choisi à la création du compte. `throttle` obligatoire : c'est un endpoint d'énumération.
- `SeanceRecordingAccessService` est routé par `CompositeEnrollmentSource`.
- Le rejet de ligne pour code de classe inconnu devient explicite dans le rapport d'import.
- Aucune migration n'est requise sur `classe_etudiant` : la table convient telle quelle.
