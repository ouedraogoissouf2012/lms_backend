# Requirements — #544 [P2][SECURITY] PII : email d'autrui exposé via GET /forum/topics

## Contexte vérifié (code réel)

`email` est sélectionné et chargé sur la relation `user` à **5 endroits** cités
par l'issue, tous transmis tels quels au client (`successResponse($topic)` /
`successResponse($topics)` json-encode le modèle Eloquent brut, `email` n'étant
**pas** dans `User::$hidden` — `app/Models/User.php:41-43` ne masque que
`password`, `remember_token`, `klassci_token_encrypted`) :

| Fichier:ligne | Contexte |
|---|---|
| `app/Services/Forum/ForumTopicService.php:46` | `list()` — `GET /api/forum/topics` |
| `app/Services/Forum/ForumTopicService.php:107` | `create()` — réponse `POST /api/forum/topics` |
| `app/Services/Forum/ForumTopicService.php:120` | `showWithPosts()` — auteur du topic, `GET /api/forum/topics/{id}` |
| `app/Services/Forum/ForumTopicService.php:124` | `showWithPosts()` — auteur de chaque post ET de chaque réponse |
| `app/Services/Forum/ForumPostService.php:59` | réponse `POST /api/forum/topics/{id}/posts` |

**Scénario confirmé par l'issue** : un étudiant authentifié énumère les emails
de toute sa promotion via `GET /api/forum/topics` (tous les auteurs de topics du
même tenant, sans restriction de visibilité).

**3 sites supplémentaires trouvés par l'audit `spec-security` (non cités par
l'issue, même classe de vulnérabilité, CRITICAL confirmé par relecture directe)** :
`ForumTopicService.php:149` (`update()`), `ForumPostService.php:121` (`update()`),
`ForumPostService.php:171` (`markAsSolution()`) faisaient `$model->fresh(['user'])`
**sans aucune restriction de colonnes** — email + `klassci_id`/`klassci_role`/
`klassci_enseignant_id`/`last_klassci_sync`/`email_verified_at` tous exposés.
Réellement exploitable cross-utilisateur : `MarkPostAsSolutionRequest::authorize()`
vérifie explicitement la propriété du TOPIC, pas du POST (doc-commenté dans le
fichier lui-même) — le propriétaire d'un topic peut donc marquer la réponse d'un
AUTRE étudiant comme solution et recevoir l'email de cet autre étudiant en
retour. Même schéma pour `updatePost`/`update` topic via admin/coordinateur
intra-tenant (`UpdateForumPostRequest`/`UpdateForumTopicRequest`, propriétaire
OU modérateur même tenant). Corrigé avec le même pattern (R1 étendu à ces 3
sites), tests ajoutés pour les 3 nouveaux endpoints.

## Vérifications effectuées avant de choisir le correctif

- **Aucun consommateur backend ne lit `->user->email`** sur ces relations
  chargées : `grep -rn "->user->email"` sur `app/Services/Forum/`,
  `app/Notifications/` → aucun résultat. Aucune classe `Notification` liée au
  forum n'existe dans ce dépôt.
- **Aucun consommateur frontend ne lit `.email`** sur un topic/post :
  `lms-frontend/src/components/forum/*.vue`, `views/Forum.vue`,
  `views/ForumTopic.vue`, `composables/useForumTopic.js` → aucune occurrence.
- **Le frontend lit la forme de pagination brute** du paginator Laravel :
  `views/Forum.vue:147-148` fait
  `Array.isArray(response.data.data) ? response.data.data : []` — c'est-à-dire
  qu'il attend `body.data` = le paginator sérialisé nativement
  (`{current_page, data: [...], ...}`), **pas** l'enveloppe
  `{data: [...], links, meta}` que produirait un `ResourceCollection` Laravel
  standard. Envelopper la liste dans une API Resource de collection
  changerait cette forme et casserait le rendu de la liste forum en
  production (`Array.isArray()` deviendrait `false` sur l'objet enveloppe).

## Décision : ne pas sélectionner `email`, pas de couche API Resource

Le correctif suggéré par l'issue ("API Resource masquant l'email d'autrui")
fonctionnerait pour les réponses **non paginées** (`show`, `create`, posts),
mais introduirait le risque de rupture de forme documenté ci-dessus pour
`index` (paginé) sauf à traiter ce cas différemment (`paginator->through()`
plutôt que `Resource::collection()`) — complexité et surface de risque
évitables. Retenu à la place : retirer `email` du `select` partiel Eloquent
(`user:id,name,email,role` → `user:id,name,role`) aux 5 endroits. Root cause
authentique (Q1 self-critique) : la donnée ne doit jamais quitter la requête
SQL si elle n'est jamais censée être exposée — plus robuste qu'un filtrage en
sortie qu'un futur point d'entrée pourrait oublier d'appliquer.

## Exigences (format EARS)

**R1 — `email` n'est plus chargé sur les relations `user` du domaine forum**
QUAND `ForumTopicService` ou `ForumPostService` charge la relation `user` d'un
topic, d'un post, ou d'une réponse, ALORS `email` NE DOIT PAS figurer dans la
liste de colonnes sélectionnées.

**R2 — Aucun changement de forme de réponse**
LA forme JSON de `GET /api/forum/topics`, `GET /api/forum/topics/{id}`,
`POST /api/forum/topics`, `POST /api/forum/topics/{id}/posts` DOIT rester
strictement identique à l'actuelle (mêmes clés, même structure de pagination),
à l'exception de la disparition de la seule clé `user.email` — vérifié par
non-régression sur les tests de caractérisation existants
(`tests/Feature/E2E/ForumDiscussionFlowTest.php` notamment).

**R3 — `id`, `name`, `role` restent exposés**
LES champs `id`, `name`, `role` de l'auteur DOIVENT continuer à être exposés
(nécessaires à l'affichage de l'attribution des messages), conformément au
correctif suggéré par l'issue.

## Hors périmètre (explicitement écarté, avec raison)

- **`QuizCrudService::list` (creator email)** et
  **`AttendanceHistoryQueryService::formatAttendance:149`** — cités par
  l'issue comme "même motif" mais hors du domaine de fichiers assigné
  (`app/Http/Controllers/**/Forum*`, `app/Http/Resources/**/Forum*`,
  `ForumTopicService`). Une issue de suivi séparée devrait traiter ces deux
  cas avec la même analyse (vérifier consommateurs avant de couper).
- **Masquage conditionnel** (email visible pour l'auteur lui-même ou un
  admin/coordinateur) — non demandé par l'issue ("ne renvoyer que
  id/name/role", sans condition de rôle) ; aucun besoin produit identifié
  côté frontend (aucune UI n'affiche l'email d'un auteur de topic/post).

## Vérification

Tests Feature : pour `index`, `show`, `create` (topic), `create` (post),
asserter que le corps JSON ne contient PAS l'email distinctif d'un auteur
autre que l'appelant, ET que `id`/`name`/`role` de l'auteur sont bien
toujours présents. Non-régression via la suite existante
(`ForumDiscussionFlowTest`, `SearchResponseTest` n'est pas concerné).
