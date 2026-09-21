# ADR-878-01 — L'établissement d'une ligne écrite hors tenant

**Date :** 2026-09-21  
**Statut :** accepté  
**Issue :** #878 · corrige une conséquence de [ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md) et de la PR #877

## Décision

`StudentClassSynchronizer` écrit **`institution_id` explicitement**, depuis `$user->institution_id`, comme le fait déjà son jumeau `KlassciUserSynchronizer:258` pour le compte lui-même.

Les lignes existantes sont **rattrapées par migration**, depuis l'établissement de leur utilisateur. Aucune n'est supprimée.

La **classe miroir n'est PAS créée** par ce chemin, et `KlassciEnrollmentSource` **n'est pas modifiée**. Le cinquième lecteur reste donc limité — limitation nommée ci-dessous plutôt que contournée.

La **tolérance au `NULL`** introduite par la PR #877 dans `SeanceRecordingAccessService` est **retirée** : une fois la colonne écrite, elle ne protège plus rien, et une garde morte ment sur ce qu'elle protège.

## Pourquoi

### Ce n'est pas un oubli d'écriture, c'est un filet qui ne s'applique pas

`BelongsToInstitution` pose `institution_id` **automatiquement à la création**, depuis le tenant résolu. Il ne le fait pas ici parce que le synchroniseur tourne **pendant le login** : à cet instant, l'utilisateur n'est pas authentifié et aucun en-tête n'a été exigé, donc `ResolveInstitution` n'a rien posé. Le trait journalise un avertissement et laisse passer — c'est sa stratégie assumée, `log-and-no-op`.

L'asymétrie est alors visible : `KlassciUserSynchronizer:258` **passe l'établissement explicitement** et ne dépend pas du filet. `StudentClassSynchronizer`, lui, en dépend — et le perd.

**Écrire explicitement aligne les deux frères.** C'est la correction la plus petite qui existe, et elle ne change ni le moment du login, ni la sémantique du trait.

### Pourquoi ne PAS résoudre le tenant pendant le login

L'alternative — poser le tenant avant la synchronisation — corrigerait aussi le symptôme, et bien plus largement. C'est précisément ce qui la disqualifie ici : elle changerait le contexte de **toute** écriture du login, y compris celles qui dépendent aujourd'hui de son absence. Un correctif dont le rayon dépasse le défaut mesuré n'est pas un correctif, c'est un pari.

### Une seule ligne débloque QUATRE lecteurs sur cinq

`UserClass` porte le scope multi-tenant, qui filtre lui-même `institution_id`. Mesuré le 21/09 : **les cinq lecteurs sont aveugles**, y compris ceux qui ne filtrent rien explicitement — le scope suffit.

| Lecteur | Débloqué par `institution_id` seul |
|---|---|
| `SeanceRecordingAccessService:170` | oui |
| `VideoSessionAttendancesSyncer:244` | oui |
| `SeanceVisioEnricher:228` | oui, par le scope |
| `VisioParticipantsListService:252` | oui, par le scope |
| `KlassciEnrollmentSource:20` | **non** — il exige en plus la classe miroir |

### La classe miroir n'est pas créée, et la traduction devient facultative

Le cinquième lecteur traduit `klassci_classe_id` en `classes.id`. Rien dans le parcours d'un apprenant ne crée cette classe miroir : elle naît de `ClasseSyncService`, piloté par un job dont le docblock précise qu'il synchronise « les classes d'un **enseignant** à sa connexion ».

Deux voies s'offraient. Faire créer le miroir par le synchroniseur étudiant lui donnerait un second écrivain sur `classes` — une table dont le miroir est déjà alimenté ailleurs, et dont deux sources concurrentes sont la cause racine de #673.

> **Correction du 2026-09-21, faite en implémentant.** La première rédaction de cet ADR annonçait que « la traduction cesse d'être une condition » et que la source rendrait « ce qu'elle sait ». **C'est inapplicable** : `localClasseIdsFor` rend par contrat des identifiants LOCAUX, et sans classe miroir il n'en existe aucun à rendre. On ne peut pas en inventer.
>
> Ce lot **ne touche donc pas** `KlassciEnrollmentSource`. Rendre `[]` y est la réponse exacte à la question posée — « quelles classes locales ? » — et non un mensonge. Ce qui manque, c'est la classe miroir elle-même.

On ne retient donc **aucune des deux** : le cinquième lecteur reste limité tant qu'aucun miroir n'existe pour un apprenant. La limitation est **nommée** — elle appartient à une décision distincte sur la création des miroirs étudiants, que cet ADR se refuse à prendre au passage.

### Rattraper plutôt que tolérer

Les lignes déjà écrites portent `institution_id` nul. Corriger l'écrivain ne les répare pas.

Une tolérance en lecture — accepter le `NULL` — serait à écrire dans **cinq** lecteurs, et resterait à jamais. Une migration de rattrapage s'écrit une fois et restaure l'invariant : `user_classes.user_id` désigne un utilisateur qui porte, lui, son établissement.

Les lignes dont l'utilisateur n'a pas d'établissement restent nulles et sont **comptées dans le journal de migration** — elles signalent un compte hors établissement, pas une donnée à deviner.

### Retirer la tolérance de #877, et le dire

La PR #877 avait ajouté `whereNull('institution_id') OR institution_id = …` dans la porte du replay, pour tolérer la donnée réelle. **Mesuré : elle ne fonctionnait pas** — le scope global filtre avant elle. `canRead` rend `true` sans tenant résolu et `false` avec, c'est-à-dire faux en production.

Une fois la colonne écrite, cette clause ne protège plus rien. La conserver laisserait croire qu'une garde existe là où il n'y en a plus.

## Ce que cet ADR ne décide pas

Le **moment** de la synchronisation étudiante, ni le fait qu'elle se fasse pendant le login.

La création de classes miroirs pour les étudiants, qui reste absente — cet ADR la rend inutile aux lectures concernées, il ne la remplace pas.

## Conséquences

- Une ligne dans `StudentClassSynchronizer::persistStudentClasse`.
- Une migration de rattrapage, journalisant les lignes qu'elle ne peut pas résoudre.
- `KlassciEnrollmentSource` **reste inchangée** : quatre lecteurs sur cinq sont débloqués, et le cinquième attend une décision sur les miroirs étudiants.
- La clause tolérante de `SeanceRecordingAccessService` est retirée.
- Les tests touchant `UserClass` **résolvent un tenant** : sans cela, le scope passe en `log-and-no-op` et le test ne prouve rien. C'est la faute qui a masqué ce défaut dans la PR #877.

## Vérifié

`origin/lms` @ `5c4f40a3`, mesures exécutées en base isolée le 2026-09-21.
