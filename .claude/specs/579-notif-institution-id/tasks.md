# Tasks — #579 · `institution_id` à la source des notifications

- [x] **1. Reproduire le bug avant tout correctif** _(Requirements: §0)_
  - [x] 1.1 Test de visibilité (écriture sans tenant → lecture HTTP) → **2 RED exécutés**
  - [x] 1.2 Vérifier `Notification::$fillable` contient `institution_id` (oui, `:37`)
  - [x] 1.3 Recenser les émetteurs et lesquels restaurent déjà le tenant

- [x] **2. Tests restants AVANT correctif** _(Requirements: R2, R3, R4)_
  - [x] 2.1 Cycle complet via un **vrai bearer token** — `Sanctum::actingAs()` ne reproduit PAS la production (voir §Piège ci-dessous)
  - [x] 2.2 `unread-count` et bloc `recent` voient la notification asynchrone
  - [x] 2.3 Destinataire supradmin → `institution_id` reste `NULL` (non-régression)

- [x] **3. Correctif à la source** _(Requirements: R1)_
  - [x] 3.1 `NotificationDispatcher::send()` : `'institution_id' => $user->institution_id`
  - [x] 3.2 `NotifyUpcomingEvaluations:68` : **volte-face après revue** — la ligne ajoutée a été RETIRÉE. Ces notifications portent un id KLASSCI dans `user_id` : leur donner une institution les rendrait visibles à un tiers (requirements §5)

- [x] **4. Migration de rattrapage** _(Requirements: R5, R6)_
  - [x] 4.1 **Sous-requête corrélée**, pas `UPDATE ... JOIN` : SQLite ne connaît pas cette forme (erreur `no such column: users.institution_id`, attrapée par le test)
  - [x] 4.2 Décompte journalisé ; `down()` no-op documenté
  - [x] 4.3 Tests : répare les orphelines, laisse celles des supradmins, **et laisse inertes les `evaluation_approaching` mal adressées**
  - [x] 4.4 Traitement **par lots de 1000** : `notifications` n'est jamais purgée pour les non-lues → une transaction unique bloquerait les workers au déploiement

- [x] **5. Validation**
  - [x] 5.1 Les 8 tests du lot VERTS sous SQLite (5 RED avant correctif, 3 pour la migration)
  - [x] 5.2 Non-régression : `tests/Feature/Notifications`, `tests/Feature/Jobs`, `tests/Feature/Console`, `tests/Feature/BelongsToInstitutionTest.php`, `tests/Unit/Services/Visio`
  - [x] 5.3 PHPStan → 0 erreur
  - [x] 5.4 Vérifié aussi **sous MySQL 8.4** (migrations complètes + les 8 tests du lot)

- [ ] **6. Revue et livraison**
  - [x] 6.1 `/code-review` (repli) → **4 findings, 3 retenus** : fuite via lignes mal adressées (majeur), verrou de migration non borné, absence de test sur le fichier hors périmètre (résolue en le laissant intact)
  - [ ] 6.2 `git add -f .claude/specs/579-notif-institution-id/`
  - [ ] 6.3 Commit conventionnel avec `(#579)` en fin de titre — **après accord du user**
  - [ ] 6.4 PR vers `lms`, reporter le n° à l'orchestrateur

---

## Piège du harnais de test — à retenir

Les deux tests de bout en bout passaient **au vert sur le code bogué** dans leur première
version. Cause : `Sanctum::actingAs()` feint le garde sans émettre de token. Or
`ResolveInstitution` (préfixé au groupe `api`) commence par `reset()` puis résout le tenant
**depuis le bearer token** ; sans token ni en-tête `X-Institution`, il laisse le tenant nul,
le scope global s'efface et la ligne `NULL` redevient visible.

En production le client envoie un token : le tenant est résolu, la ligne disparaît.

→ **Tout test qui vérifie une isolation par tenant au niveau HTTP doit s'authentifier par un
vrai token** (`createToken()->plainTextToken` + en-tête `Authorization`), sinon il teste un
monde où le multi-tenant n'existe pas. C'est ce faux négatif qui a laissé passer #579.
