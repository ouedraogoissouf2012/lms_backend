# Requirements — Éliminer les N+1 des boucles de sync (#503)

## Contexte & preuves

Deux services exécutent une requête DB **par élément** dans une boucle, sur des
endpoints HTTP **synchrones** — même patron de fix (`whereIn` + `keyBy` en amont).

### P1 — sync présences visio
`app/Services/Attendances/VideoSessionAttendancesSyncer::resolveStudents()`
(`:185-190`) : `foreach ($participants) { User::query()->where(institution_id)->where(klassci_id)->first() }`
→ 1 requête User **par participant**. `isEnrolled()` (`:203-224`) ajoute 1-2 requêtes
(`UserClass::exists` + `Classe::whereHas::exists`) **par participant**. Route
`routes/api/lms.php` (`POST /api/lms/attendances/from-video-session`, synchrone).
Entrée **non plafonnée** : `SyncAttendancesRequest:54` = `participants` `required|array|min:1`
(**aucun `max`**). Classe de 60 → ~120-180 requêtes séquentielles.

### P2 — résultats d'évaluation par classe
`app/Services/Evaluation/Teacher/TeacherEvaluationResultsService::buildResultats()`
(`:141-168`) : `foreach ($etudiants) { User::where('email')->first() }` (`:143`)
+ 1-2 requêtes `submissions()->where('klassci_etudiant_id')->latest()->first()`
(`:148`, `:160`) **par étudiant**. Route `GET /api/evaluations/{id}/results-by-class`.
Classe de 40 → ~120 requêtes. **Aucun test existant** sur ce chemin.

## Portée

- **IN** : P1 (résolution users + enrollment en amont), P2 (users par email +
  submissions préchargées), plafond `max` sur `participants` (anti-DOS).
- **OUT** : le comportement fonctionnel (mêmes filtres, même validation tout-ou-rien
  de P1, même stratégie de matching de P2) ; les autres N+1 hors de ces 2 boucles.

## Exigences (EARS)

**REQ-1 — P1 users préchargés**
THE SYSTEM SHALL résoudre les users de `resolveStudents` en **une** requête bornée
(`User::whereIn('klassci_id', $ids)` tenant-scopée + `keyBy`), au lieu d'une requête
par participant. L'**alignement d'index** `$students[$index]` avec `$participants`
SHALL être préservé.

**REQ-2 — P1 enrollment préchargé**
THE SYSTEM SHALL précalculer **une** fois l'ensemble des `user_id` inscrits à la
classe de la séance (union `UserClass` + pivot `classe_etudiant` actif), puis
tester l'inscription **en mémoire** — au lieu d'1-2 requêtes par participant.

**REQ-3 — P1 validation tout-ou-rien préservée**
THE SYSTEM SHALL conserver le comportement actuel : si **un** participant n'est
pas trouvé, n'est pas étudiant, ou n'est pas inscrit → `resolveStudents` retourne
`null` (batch refusé, 403 `not_enrolled`).

**REQ-4 — Plafond participants (anti-DOS)**
THE SYSTEM SHALL borner `participants` dans `SyncAttendancesRequest` (`max`, ex.
100) — rejet 422 au-delà.

**REQ-5 — P2 users par email préchargés**
THE SYSTEM SHALL résoudre les users locaux de `buildResultats` en **une** requête
(`User::whereIn('email', $emails)->keyBy('email')`), au lieu d'une par étudiant.

**REQ-6 — P2 submissions préchargées**
THE SYSTEM SHALL charger les submissions "live" (non-practice) de l'évaluation en
**une** requête, groupées par `klassci_etudiant_id` (dernière par groupe), puis
résoudre chaque étudiant **en mémoire** (stratégie via `klassci_id` local puis
fallback `etudiant['id']` — inchangée).

**REQ-7 — Comptes de requêtes constants**
THE SYSTEM SHALL rendre le nombre de requêtes des 2 chemins **indépendant** du
nombre de participants/étudiants (borné constant, hors requêtes de setup).

**REQ-8 — Sortie fonctionnelle inchangée**
THE SYSTEM SHALL produire exactement les mêmes résultats qu'avant (mêmes created/
updated/errors pour P1, mêmes résultats par étudiant pour P2).

## Critères d'acceptation

1. Test anti-N+1 P1 : le nombre de requêtes de `sync()` est **constant** quand le
   nombre de participants croît (pattern baseline vs afterGrowth, `enableQueryLog`).
2. `participants` > `max` → **422**.
3. P1 : created/updated/errors identiques (non-régression `SyncAttendancesRequestTest`).
4. Test anti-N+1 P2 : requêtes de `buildResultats` constantes quand l'effectif croît.
5. P2 : résultats par étudiant identiques (submission live correctement rattachée).
6. `php artisan test` 100 %, PHPStan level 9 vert, garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ Perdre l'alignement d'index P1 (`$students[$index]` ↔ `$participants[$index]`).
- ❌ Changer la sémantique tout-ou-rien de P1 (un absent → tout le batch échoue).
- ❌ P2 : rattacher la **mauvaise** submission (mauvaise stratégie ou pas la plus
  récente) → résultats faussés.
- ❌ Introduire une régression de filtre (feedback `[PRACTICE]`, tenant, statut actif).
- ❌ Un `keyBy('klassci_id')`/`keyBy('email')` qui écrase des doublons silencieux
  (vérifier l'unicité effective par tenant).
