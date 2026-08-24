# Requirements — #579 · `institution_id` NULL rend les notifications asynchrones invisibles

## 0. Bug reproduit (test exécuté, pas repris de l'issue)

`tests/Feature/Notifications/AsyncNotificationVisibilityTest.php`, écrit avant tout
correctif, sur `lms` @55f09255 :

```
1) test_notification_emitted_without_tenant_carries_the_recipient_institution
   La notification a été écrite sans institution : son destinataire ne la verra jamais.
   Failed asserting that null is identical to 1.

2) test_notification_emitted_without_tenant_is_visible_to_its_recipient
   Failed asserting that 0 is identical to 1.
```

Le second est le vrai symptôme : la ligne **existe en base**, et une lecture en contexte
HTTP authentifié en compte **zéro**.

## 1. Mécanisme

`NotificationDispatcher::send()` (`:34-41`) ne renseigne pas `institution_id`.

- **À l'écriture** (worker, cron) : aucun tenant résolu → le hook `creating` de
  `BelongsToInstitution` (`:100-110`) journalise un avertissement et laisse la colonne à
  `NULL`. L'insertion réussit.
- **À la lecture** (requête HTTP authentifiée) : le tenant EST résolu → le scope global
  ajoute `WHERE institution_id = X` → la ligne `NULL` est exclue.

Asymétrie écriture/lecture : la notification est écrite, jamais vue.

## 2. Émetteurs concernés (vérifiés)

| Émetteur | Contexte | Tenant restauré ? |
|---|---|---|
| `DispatchVisioNotification` → `notifyVisioScheduled` / `notifyVisioStarting` | worker | ❌ |
| `NotifyUpcomingEvaluations:68` (`Notification::create` direct) | cron | ❌ |
| `DispatchLessonPublishedNotifications:76-88` | worker | ✅ `TenantManager::set()` |
| `NotifyQuizDeadlines` (ajouté par #500) | cron | ✅ `TenantManager::set()` |

La solution existait donc déjà dans le dépôt ; elle n'avait pas été appliquée partout.

## 3. Exigences (EARS)

- **R1** — WHEN une notification est créée hors contexte tenant, THEN elle SHALL porter
  l'`institution_id` de son **destinataire**.
- **R2** — WHEN le destinataire consulte ses notifications en HTTP, THEN il SHALL voir
  celles émises par un worker ou un cron.
- **R3** — Le compteur `unread-count` et le bloc `recent` du dashboard SHALL les voir aussi
  (ce sont des chemins de lecture distincts de la liste paginée).
- **R4** — WHERE le destinataire n'a pas d'institution (supradmin), THEN le comportement
  SHALL rester inchangé (`NULL` assumé, pas de régression).
- **R5** — Une migration de rattrapage SHALL réparer les lignes `NULL` existantes depuis
  `users.institution_id`, en **journalisant le décompte**.
- **R6** — La migration SHALL être réversible sans perte : elle ne SHALL PAS toucher les
  lignes dont le destinataire n'a pas d'institution.

## 4. Pourquoi dériver du destinataire plutôt que restaurer le tenant

Restaurer le `TenantManager` dans chaque émetteur est le pattern déjà employé deux fois
(job leçon, commande quiz) — mais il faut y penser à **chaque** nouvel émetteur, et l'oubli
est silencieux. Le porter dans `send()` rend la propriété **structurelle** : il n'existe
plus de chemin par lequel une notification puisse naître sans tenant.

C'est aussi plus **juste** : une notification appartient à l'institution de son
destinataire, pas à celle qui se trouvait résolue au moment de l'écriture. Les deux
coïncident en pratique ; en cas de divergence, c'est le destinataire qui a raison.

Les deux approches ne s'excluent pas : la restauration de tenant reste nécessaire dans les
émetteurs pour que **leurs requêtes de lecture** soient scopées.

## 5. `evaluation_approaching` : ne PAS réparer — la réparation serait une fuite

Premier réflexe : ajouter `institution_id` à `NotifyUpcomingEvaluations:68` aussi, sinon la
migration de rattrapage serait auto-défaite. **Ce réflexe était faux, et dangereux.** La
revue de code l'a rattrapé ; vérification faite, elle a raison.

`extractStudents()` (`:105-124`) lit `data[].id` du payload KLASSCI et l'écrit tel quel dans
`notifications.user_id` — **une FK locale**. C'est un id KLASSCI, pas un `users.id` :

- `TeacherEvaluationResultsService:161` traite le même champ comme un id KLASSCI
  (`$submissionsByKlassci->get(KlassciPayload::toInt($etudiant['id']))`) ;
- `DispatchLessonPublishedNotifications:102` fait la traduction qui manque ici
  (`User::whereIn('klassci_id', …)->pluck('id')`).

Ces notifications sont donc **déjà mal adressées**. Tant que `institution_id` reste `NULL`,
le scope global les rend inertes — invisibles pour tout le monde. Y poser une institution
les rendrait **visibles à l'utilisateur local qui porte par hasard cet identifiant**,
potentiellement dans une autre institution : le titre d'une évaluation d'un établissement A
apparaîtrait chez un utilisateur de l'établissement B.

> **Réparer la visibilité d'une ligne mal adressée n'est pas un correctif, c'est une fuite.**

Décisions qui en découlent :

- **R7** — `NotifyUpcomingEvaluations` SHALL rester inchangé (aucun `institution_id`), avec
  un commentaire expliquant pourquoi l'absence est délibérée.
- **R8** — La migration SHALL **exclure** le type `evaluation_approaching`, avec un test qui
  le prouve.
- L'adressage lui-même (`klassci_id` → `users.id`) est un **bug distinct et antérieur**,
  hors de ce lot : il mérite son issue, avec un test qui stube `getClasseEtudiants` sur un
  id KLASSCI différent de l'id local.

Le périmètre transmis (services de notification, jobs de notif, et les seules lignes
d'émission de `LessonCrudOperationsService` / `ForumPostService`) est donc **respecté** :
aucun fichier hors liste n'est modifié fonctionnellement.
