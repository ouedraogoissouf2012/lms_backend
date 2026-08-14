# Requirements — #515 [PERF][HIGH] SyncKlassciSeances : N+1 HTTP → batch

## Contexte vérifié (code réel, HEAD 4a74320b)

Les lignes citées par l'issue (`KlassciSeancesSyncService.php:56-62`, `:60`, `:95`,
`:98`, `:129`) ont bougé depuis le dépôt de l'issue — extraites dans un service
dédié par le refactor #475 (`app/Services/Seances/Sync/KlassciSeancesSyncService.php`),
mais **le N+1 HTTP lui-même est intact** :

- `syncTeacher()` (ligne ~100) : 1 appel `requestWithUserToken($token, 'matieres', 'GET')`
  par enseignant (légitime, pas de N+1 ici).
- `syncMatiereSeances()` (ligne ~141), appelée dans un `foreach` sur les matières
  de l'enseignant : **1 appel HTTP séquentiel** `requestWithUserToken($token, "matieres/{$matiereId}", 'GET')`
  **par matière**. C'est le N+1 réel : T enseignants × M matières = T×M appels
  HTTP séquentiels par run.

## Ce qui a changé depuis le dépôt de l'issue (pertinent pour le correctif)

1. **#539 (commit `b9cfe2a7`, déjà mergé)** a remplacé le job mono-bloc à
   timeout 600s par un job unique à **budget-temps souple** (45s, `SYNC_BUDGET_SECONDS`)
   + timeout dur 55s, avec reprise idempotente au drain suivant (cron toutes les
   5 min). Décision motivée par une contrainte d'infra documentée
   (`docs/DEPLOYMENT_OPS.md` §3) : cPanel mutualisé, **aucun worker persistant**,
   un seul slot de drain par tick cron (`queue:work --stop-when-empty --max-time=55`).
   Les 3 autres jobs de maintenance séances (`ArchiveOldSeances`,
   `FinalizeSeanceAttendances`, `CleanObsoleteSeances`) suivent la même
   convention (`InteractsWithDrainBudget`).
2. **`KlassciBatchFetcher`/`KlassciProxyService::fetchManyMatieresDetails()`**
   (PERF-02, #135, déjà en prod) — exactement la méthode que l'issue cite comme
   "déjà disponible" — existe et est directement appelable :
   `KlassciSeancesSyncService` injecte déjà `KlassciProxyService`, qui expose
   `fetchManyMatieresDetails(array $matiereIds, string $userToken, ?int $ttl = 600): array`
   (pool HTTP parallèle, cache+memo intégrés, tolérance aux échecs partiels).

## Décision architecturale : batch HTTP dans le job unique existant, PAS de fan-out par job de queue

L'issue suggère "1 job dispatché en queue par enseignant". **Écarté** (Q12
self-critique — alternative explorée et rejetée avec raison) :

1. **Conflit avec #539** : dispatcher un job par enseignant réintroduirait
   exactement le problème que #539 a résolu (churn massif de la table `jobs`
   sur un worker mutualisé unique, sans lien avec le nombre de teachers) et
   diverge de la convention désormais établie sur les 3 jobs de maintenance
   soeurs (incohérence Q6).
2. **Casse l'invariant d'archivage** : `archiveStaleSeances()` exige un
   `$activeIdsByInstitution` accumulé sur une passe **complète et séquentielle**
   de tous les enseignants d'une institution (`$completePass` guard, ligne 66-89)
   avant d'archiver — invariant en mémoire partagée, impossible à préserver si
   les enseignants sont traités par des exécutions de jobs indépendantes et
   potentiellement concurrentes. Le reconstruire nécessiterait un mécanisme de
   coordination inter-jobs entièrement nouveau — risque et complexité largement
   disproportionnés par rapport au problème réel (le HTTP N+1, pas l'absence de
   parallélisme process).

**Retenu** : garder le job unique à budget existant, remplacer la boucle
séquentielle de `syncMatiereSeances()` par un appel batch
`fetchManyMatieresDetails()` par enseignant. Répond directement au critère de
fermeture de l'issue ("appels batchés") sans les risques ci-dessus.

## Exigences (format EARS)

**R1 — Les appels `matieres/{id}` d'un même enseignant sont batchés**
QUAND `KlassciSeancesSyncService` traite les matières d'un enseignant, ALORS il
DOIT récupérer les détails de toutes ces matières via UN SEUL appel
`fetchManyMatieresDetails()` (pool HTTP parallèle), et non via des appels
`requestWithUserToken()` séquentiels un par un.

**R2 — Comportement de synchronisation préservé à l'identique**
LE résultat de la synchronisation (séances créées/mises à jour, notifications
envoyées, `SeanceSyncStats`) DOIT être strictement identique à celui produit
par la boucle séquentielle actuelle, pour un même jeu de données KLASSCI —
seul le mécanisme de récupération HTTP change.

**R3 — Tolérance aux échecs partiels préservée**
SI `fetchManyMatieresDetails()` omet une matière de son résultat (échec HTTP
individuel, cf. doc-bloc `KlassciBatchFetcher::persistBatchResponses`), ALORS
cette matière DOIT être ignorée sans faire échouer le traitement des autres
matières de l'enseignant ni celui des autres enseignants — même sémantique de
tolérance qu'aujourd'hui (`try/catch` par enseignant/séance déjà en place).

**R4 — Budget-temps et archivage inchangés**
LE mécanisme de budget-temps souple (#539), la structure `$activeIdsByInstitution`
et la condition `$completePass` avant archivage NE DOIVENT PAS être modifiés —
hors périmètre de cette issue (le batch HTTP réduit le temps consommé par
enseignant, ce qui *améliore* mécaniquement le nombre d'enseignants traités par
passe dans le même budget, sans changer la logique de budget elle-même).

## Hors périmètre (explicitement écarté, avec raison)

- **Fan-out par job de queue** — cf. décision architecturale ci-dessus.
- **Modifier `KlassciBatchFetcher`/`KlassciProxyService`** — hors du domaine de
  fichiers assigné (`app/Services/Klassci/*` n'est pas sous
  `app/Services/Seances/*`) ; la méthode existante convient telle quelle, aucun
  changement n'y est nécessaire.
- **#516 et #542** — traités dans des specs et PR séparées, dans cet ordre.

## Vérification

Test anti-N+1 HTTP, pattern déjà établi dans ce dépôt
(`tests/Unit/Services/Matiere/MatiereSeancesFetcherTest.php`, issue #517) : mock
`KlassciProxyService`, assertion `fetchManyMatieresDetails()` appelée **une
seule fois** avec la liste complète des IDs de matières d'un enseignant, plutôt
que de compter des appels HTTP réels. Complété par un test baseline-vs-afterGrowth
(3 matières vs 30) prouvant que le nombre d'appels `fetchManyMatieresDetails()`
reste constant (1 par enseignant) indépendamment du nombre de matières.
