# Requirements — #500 · Chaîne de dispatchers de notifications morte

## 0. Constat vérifié (grep exécuté, pas repris de l'issue)

```
grep -rn "notify{LessonPublished,LessonUpdated,ForumReply,ForumSolution,
          QuizAvailable,GradeReceived,QuizDeadline}" app/ tests/ routes/ database/
→ 0 appelant réel (uniquement des @see en docblock)

grep -rn "{Lesson,Forum,Quiz}NotificationDispatcher" app/ tests/
→ uniquement le câblage DI de NotificationService + des @see
```

La chaîne est bien morte. **Mais l'issue propose un A/B qui ne colle pas au terrain** : les
trois domaines ne sont pas dans le même état.

| Domaine | Qui émet réellement aujourd'hui | Statut du dispatcher |
|---|---|---|
| Leçons | job `DispatchLessonPublishedNotifications`, déclenché par `LessonCrudOperationsService:170-175`. Restaure le tenant (`TenantManager::set`) | **redondant** — et le job fait mieux |
| Forum | `ForumPostService` en inline (`:74`, `:97`, `:162`), avec `institution_id` renseigné | **redondant** — divergence sémantique *délibérée*, documentée `ForumPostService:26-35` |
| Quiz | **personne** | **seule implémentation existante** |

Conséquences :
- **« Tout câbler » produirait des doublons** : une publication de leçon émettrait 2
  notifications (job + dispatcher), une réponse forum 1 + n (le dispatcher notifie tous les
  participants du topic, pas seulement l'auteur).
- **« Tout retirer » supprimerait la seule implémentation des notifications quiz**, alors que
  `quiz_available`, `grade_received` et `quiz_deadline` ont déjà icône, couleur et URL
  d'action dans `NotificationPresenter:86-113`.

## 1. Décision produit (tranchée par l'utilisateur, 2026-08-23)

> **Câbler le quiz uniquement ; retirer les dispatchers Leçon/Forum redondants.**

Question posée : « veut-on que les étudiants reçoivent des notifications de quiz (note
reçue, quiz disponible, échéance) ? » → **oui**.

## 2. Bugs bloquants DANS le dispatcher quiz (à corriger AVANT de le câbler)

Le câbler tel quel diffuserait ses défauts à des utilisateurs réels. Vérifiés contre
`DESCRIBE quiz_attempts` :

| # | Ligne | Défaut | Effet une fois câblé |
|---|---|---|---|
| B1 | `QuizNotificationDispatcher.php:66,72` | `$attempt->max_score` n'existe pas (ni colonne, ni accessor) | message rendu : « note de 80.00**/** » |
| B2 | `:73` | `$attempt->percentage` n'existe pas ; le pourcentage 0-100 **est** `score` (`QuizGradingService.php:177,229`) | `data.percentage = null` côté client |
| B3 | `:91` | `status = 'completed'`, absent de `enum('in_progress','submitted','graded','abandoned')` | l'exclusion « a déjà participé » est MORTE → rappel d'échéance envoyé aussi aux étudiants ayant déjà rendu |

B1/B2/B3 sont la même famille que #608 (schéma fantôme), déjà signalée dans la PR #609 et
laissée hors périmètre à l'époque. #609 étant mergée (`f3ed62e6`),
`QuizAttempt::COMPLETED_STATUSES` / `scopeCompleted()` existent dans `lms` : B3 se corrige
en consommant cette définition unique, sans redupliquer le littéral.

## 3. Exigences (EARS)

- **R1** — WHEN un enseignant corrige manuellement une tentative, THEN l'étudiant SHALL
  recevoir une notification `grade_received` exploitable (note réelle, pas « 80.00/ »).
- **R2** — WHEN un quiz est publié, THEN les étudiants de sa classe SHALL recevoir une
  notification `quiz_available`.
- **R3** — WHERE une échéance de quiz approche, THEN seuls les étudiants **n'ayant pas
  terminé** SHALL être rappelés, et jamais deux fois le même jour pour le même quiz.
- **R4** — Après ce lot, **aucune** méthode de `NotificationService` ni aucun dispatcher
  SHALL rester sans appelant (critère de fermeture de l'issue).
- **R5** — Le comportement existant des notifications leçon et forum SHALL rester
  **inchangé** : aucune notification en double, aucun libellé modifié.
- **R6** — Toute notification créée par ce lot SHALL être visible par son destinataire, y
  compris hors contexte HTTP (contexte tenant explicitement rétabli — le correctif de fond
  est #579, mais ce lot ne SHALL PAS introduire de chemin dépendant de #579 pour être
  correct).
- **R7** — La suppression SHALL emporter les entrées `phpstan-baseline.neon` des fichiers
  supprimés (sinon `ignore.unmatched` → CI rouge).

## 3bis. Défauts activés par le câblage, trouvés en revue et corrigés

Ces six défauts dormaient : ils étaient sans effet tant que la chaîne n'était appelée par
personne. Les câbler les aurait mis en production. Chacun est corrigé avec un test rouge
d'abord.

| # | Fichier | Défaut | Effet s'il était passé |
|---|---|---|---|
| C1 | `QuizNotificationDispatcher:62` | `$attempt->user` est un `belongsTo` nu et `User` porte `SoftDeletes` → `null` | **500 permanent** sur l'endpoint de notation dès qu'on corrige la copie d'un étudiant désactivé. PHPStan l'avait en baseline (`$user … User\|null given`) — l'entrée est devenue morte après correction, ce qui **prouve** le défaut |
| C2 | `QuizCrudService::publish()` | pas de garde de transition | republier renotifiait toute la classe |
| C3 | `QuizNotificationDispatcher:47` | `available_from` futur ignoré | « est maintenant disponible » factuellement faux |
| C4 | `QuizNotificationDispatcher:36,86` | pivot `classe_etudiant.statut` ignoré | étudiants **désinscrits** notifiés — incohérent avec `VisioNotificationDispatcher:152` et `Classe::etudiantsActifs()` |
| C5 | `NotifyQuizDeadlines` | pas d'isolation par quiz | une exception affamait tous les quiz suivants (fenêtre de rappel perdue pour la journée) et laissait fuir le tenant |
| C6 | `NotifyQuizDeadlines` | `--days` (fenêtre de balayage) passé comme délai restant | « se termine dans 7 jours » pour un quiz fermant dans 30 min |

### Deux findings de revue écartés, avec raison

- **« Re-notation → notification en double annonçant une note périmée ».** La note n'est pas
  périmée : `notifyGradeReceived($attempt)` est appelé **après** `manualGradeAttempt()`, sur
  la même instance, donc avec la nouvelle note. Et renotifier quand la note change est le
  comportement attendu, pas un défaut.
- **« Fan-out synchrone à N INSERT, comme #538 ».** L'analogie ne tient pas : #538 déplaçait
  en job un fan-out qui faisait du **N+1 HTTP** vers KLASSCI. Ici : une requête + N inserts
  pour une classe (~20-60 élèves). Le gain réel serait un insert groupé dans
  `NotificationDispatcher::sendToMany` — qui profiterait aussi à la visio — mais il
  contourne les événements de modèle (donc `institution_id`), ce qui l'articule avec #579.
  **Dette tracée**, pas improvisée ici.

## 4. Hors périmètre

- `lesson_updated` n'aura toujours aucun émetteur : c'est la conséquence assumée du choix
  « retirer le dispatcher Leçon ». Le type et son rendu frontend restent en place.
- Les notifications de correction **automatique** ne sont pas câblées : à la soumission,
  l'étudiant reçoit déjà son score dans la réponse HTTP — notifier serait du bruit. Seule
  la correction **manuelle** par un enseignant est asynchrone du point de vue de l'étudiant.
- `NotifyUpcomingEvaluations.php:68` crée une notification sans `institution_id` — même
  famille que #579, hors des fichiers de ce lot.
