# Tasks — #542 [P2] Séance soft-deleted bloque la recréation/sync

## Révisé après `/code-review effort max` (6 agents finders + 1-vote verify)

L'implémentation initiale (tâches 1-7) a fait l'objet d'une revue de code qui
a mis au jour 1 gap CRITIQUE + 1 régression + 1 simplification + 1
dépassement de plafond de taille. Tous corrigés avant PR :

1. **CRITIQUE — `is_active`/`archived_at`/`archive_reason` jamais réinitialisés** :
   `restore()` ne remet à zéro QUE `deleted_at` ; une séance archivée
   (`StaleSeanceArchiver`) AVANT sa suppression revenait « restaurée » côté
   `deleted_at` mais restait invisible aux étudiants (`is_active=false`) —
   sync/toggle rapportant un succès (0 erreur, 200) alors que le symptôme
   originel de #542 (séance invisible) persistait sous une forme différente.
   Détecté indépendamment par 3 agents (cross-file tracer, line-by-line —
   ce dernier avec une preuve empirique : test sonde exécuté puis supprimé).
   Corrigé : nouveau `SeanceRestoreGuard::restoreIfTrashed()` (extrait en
   classe statique partagée, cf. point 3) réinitialise explicitement les 3
   champs AVANT `restore()` (même `UPDATE`). Tests renforcés : les fixtures
   trashed portent désormais `is_active=false` + `archived_at`/`archive_reason`
   réalistes, et les 2 tests RED principaux assertent leur remise à zéro.
2. **Régression — perte du retry-sur-conflit natif de `updateOrCreate()`
   dans `VisioToggleService`** : `Seance::updateOrCreate()` (remplacé)
   s'appuie sur `Builder::createOrFirst()` (vendor Laravel), qui catche
   `UniqueConstraintViolationException` sur une course concurrente et
   retente le lookup au lieu d'échouer. Le remplacement manuel (`first()` /
   `create()`) n'avait pas cette protection — deux requêtes HTTP concurrentes
   sur le même `klassci_seance_id` (double-clic, retry client) pouvaient
   faire échouer la perdante en 500 là où l'ancien code renvoyait 200.
   Détecté par l'agent « removed-behavior », vérifié directement dans
   `vendor/laravel/framework`. Corrigé : `catch (UniqueConstraintViolationException)`
   autour du `create()`, retente via un re-lookup `withTrashed()` — reproduit
   fidèlement le comportement natif perdu.
   **Mise à jour post-audit** : la limite honnête initiale (« non testable
   sans seam artificiel ») s'est avérée fausse — `DB::listen()` intercepte
   la requête SELECT du lookup (déjà exécutée, résultat `null`) pour insérer
   en synchrone la ligne « concurrente » juste avant le `Seance::create()`
   du test, reproduisant fidèlement la fenêtre de course TOCTOU sans
   concurrence réelle ni seam de test dans le code de production. Test
   `VisioToggleServiceTest::test_toggle_retries_after_concurrent_create_race`
   ajouté, vérifié RED (500) en retirant temporairement le `catch` puis
   GREEN (200) une fois restauré.
3. **Simplification — extraction `SeanceRestoreGuard`** : le design initial
   (voir section précédente) avait délibérément REJETÉ un helper partagé,
   jugeant la logique (`if trashed → restore`) trop triviale pour être
   extraite. Deux agents ont indépendamment vérifié dans le vendor Laravel
   que `restore()` est déjà un no-op sûr sur un modèle non trashed (aucun
   `UPDATE` émis) — le garde n'était donc pas juste trivial mais REDONDANT.
   Une fois le point 1 ajouté (réinitialisation is_active/archived_at/
   archive_reason, qui DOIT rester conditionnée à `trashed()`), le garde est
   redevenu porteur de sens (il ne protège plus `restore()` mais la décision
   de désarchiver) — et la duplication entre les 2 fichiers est devenue une
   vraie dette (même logique dans 2 endroits, une correction future risquant
   de diverger). Extrait en classe utilitaire statique
   `app/Services/Seances/SeanceRestoreGuard.php`, sur le modèle déjà établi
   de `KlassciPayload` (même répertoire) — décision initiale du design.md
   révisée en conséquence.
4. **Dépassement §1.1 (≤300 lignes)** : l'ajout du point 1 a fait passer
   `KlassciSeancesSyncService.php` à 317 lignes. Résolu PAR l'extraction du
   point 3 (retire ~15 lignes du fichier).

Findings évalués mais NON corrigés (documentés, pas des bugs) :
`VisioToggleService::toggle()` reste à ~84 lignes (plafond 40, §5) — dette
PRÉEXISTANTE (le fichier faisait déjà ~93 lignes avant #542) non résolue par
cette PR ; un refactor complet de cette méthode (notifications, workaround
KLASSCI, logging) dépasse le périmètre de #542 et risquerait des régressions
dans du code non couvert par cette issue. `VisioActivationService::activate:58`
laissé intentionnellement non corrigé (hors périmètre de fichiers, cf. point
10 ci-dessous). Une primitive modèle centralisée (`Seance::upsertByKlassciId()`)
protégeant automatiquement TOUS les call sites présents et futurs — suggérée
par l'audit architecture comme fix plus profond que le patch actuel par
call site — non implémentée ici (modifier `app/Models/Seance.php`, fichier
partagé hors du périmètre le plus strict de cette fenêtre) mais recommandée
en suivi.

- [x] 1. Test RED : `upsertSeance()` échoue sur séance soft-deletée
  - Créer une séance, `->delete()`, re-synchroniser même
    `klassci_seance_id`+`institution_id` → doit échouer AVANT le fix
    (QueryException unique violation, comptée dans `stats->errors`, séance
    jamais restaurée).
  - _Requirements: R1_

- [x] 2. GREEN : `withTrashed()` + `restore()` dans `upsertSeance()`
  - `app/Services/Seances/Sync/KlassciSeancesSyncService.php`.
  - _Requirements: R1, R2, R4_

- [x] 3. Test RED : `VisioToggleService::toggle()` échoue sur séance soft-deletée
  - Même scénario, via l'appel direct du service.
  - _Requirements: R1_

- [x] 4. GREEN : lookup manuel `withTrashed()` + restore/update-ou-create
  - `app/Services/Seances/Mutations/VisioToggleService.php`, remplace
    `Seance::updateOrCreate(...)`.
  - _Requirements: R1, R2, R3, R4_

- [x] 5. Test non-régression : cas nominal inchangé (les 2 call sites)
  - Pas de ligne trashed → comportement identique à avant (create vs update,
    `wasRecentlyCreated`, notifications/sync classe pour une vraie création).
  - _Requirements: R3_

- [x] 6. Test isolation tenant : ligne trashed d'une AUTRE institution
  - `upsertSeance()` — ligne trashed institution B, resync institution A même
    `klassci_seance_id` → B reste intacte/trashed, nouvelle ligne créée pour A.
  - _Requirements: R4_

- [x] 7. Non-régression : suite Seances/Jobs/Visio complète + PHPStan (scope
      `app/`) + garde-fou taille.

- [x] 8. `/code-review effort max` (fallback confirmé,
      `/thermo-nuclear-code-quality-review` indisponible) — corriger tout ce
      qui remonte.

- [x] 9. Audits `spec-security` + `spec-architect` en parallèle
      (CONTRIBUTING.md §A).
      - `spec-security` : **PASS**, 0 finding. Isolation cross-tenant des 2
        nouveaux lookups `withTrashed()` (dont le nouveau chemin retry de
        `restoreOrCreateVisio()`) vérifiée saine au niveau mécanique du
        framework (`withTrashed()` ne retire QUE `SoftDeletingScope`, jamais
        le scope `institution`) ET au niveau du test.
      - `spec-architect` : **PASS**, 2 MEDIUM + 3 LOW, aucun bloquant.
        `SeanceRestoreGuard` en classe utilitaire statique confirmé être le
        bon niveau d'abstraction (aucune dépendance/I/O, même catégorie que
        `KlassciPayload` du même répertoire). `VisioToggleService::toggle()`
        et `dispatchNotifications()` dépassent le plafond ≤40 lignes (§5) —
        confirmé PRÉEXISTANT (dette non aggravée par ce diff), non corrigé
        ici (refactor complet hors périmètre de #542, risque de régression
        dans du code non couvert). 2 recommandations LOW appliquées avant
        PR : test unitaire dédié `SeanceRestoreGuardTest` (contrat du garde
        verrouillé indépendamment des tests feature des 2 call sites) +
        micro-extraction `restoreThenUpdate()` dans `VisioToggleService`
        (élimine la duplication sous-seuil entre les 2 branches de
        `restoreOrCreateVisio()`).

- [ ] 10. PR vers `lms`, reporter le numéro à l'orchestrateur — mentionner
      explicitement `VisioActivationService::activate:58` (hors périmètre,
      même bug confirmé par lecture directe) comme recommandation de suivi
      pour la fenêtre propriétaire de `app/Services/Visio/*`.
