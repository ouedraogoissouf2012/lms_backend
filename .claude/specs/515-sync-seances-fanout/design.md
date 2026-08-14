# Design — #515 [PERF][HIGH] SyncKlassciSeances : N+1 HTTP → batch

> **Révisé après revue de code multi-angles (8 agents, `/code-review effort max`)**.
> Le design initial prévoyait de garder tout le refactor dans
> `KlassciSeancesSyncService` (voir git blame de ce fichier pour la version
> d'origine). Deux findings de la revue ont fait évoluer l'architecture
> réellement livrée par rapport à cette première version :
> 1. Le fichier dépassait 300 lignes une fois le batch ajouté → extraction de
>    2 puis 4 classes collaboratrices (ci-dessous), pas prévue au design initial.
> 2. Un finding CRITIQUE (removed-behavior audit) a montré que le passage au
>    batch faisait disparaître silencieusement le comptage `stats->errors`
>    pour une matière en échec HTTP — corrigé (§3 ci-dessous), documenté ici
>    pour que la trace de décision reste fidèle à ce qui est réellement livré.

## 1. Architecture livrée (4 fichiers `app/`, contre 1 seul modifié au départ)

| Fichier | Rôle |
|---|---|
| `KlassciSeancesSyncService.php` | Orchestrateur (inchangé dans son rôle, méthodes internes ajustées) |
| `TeacherMatieresResolver.php` | **Nouveau** — batch-fetch des détails matières d'un enseignant (élimine le N+1 HTTP) |
| `TeacherMatieresResolution.php` | **Nouveau** — DTO résultat : matières résolues + IDs en échec |
| `ResolvedMatiere.php` | **Nouveau** — DTO (matière, détails) remplaçant un `array{matiere, details}` fragile |
| `StaleSeanceArchiver.php` | **Nouveau** — extraction verbatim de l'archivage, pour respecter §1.1 (≤300 lignes) |

Raison de l'extraction en 4 classes plutôt qu'un seul fichier grossi : le
garde-fou CI (`php scripts/check-file-sizes.php`, §1.1 PRODUCTION_STANDARDS.md)
est un hard cap à 300 lignes, sans exception. Inliner tout le batch + le DTO
de résultat + l'archivage dans `KlassciSeancesSyncService` seul poussait le
fichier à 333 lignes. Chaque classe extraite a une seule responsabilité et ne
dépend que de ce dont elle a réellement besoin (`TeacherMatieresResolver` ne
connaît que `KlassciProxyService` ; `StaleSeanceArchiver` ne connaît que
`LoggerInterface`) — aucun couplage artificiel introduit pour la découpe.

## 2. Avant / après (le N+1 lui-même)

### Avant

```php
private function syncMatiereSeances(..., array $matiere, ...): void
{
    $matiereId = KlassciPayload::toInt($matiere['id'] ?? null);
    if ($matiereId === null) return;

    // 1 appel HTTP SÉQUENTIEL par matière — le N+1.
    $details = $this->klassciService->requestWithUserToken($teacherToken, "matieres/{$matiereId}", 'GET');
    // ...
}
```

### Après

`TeacherMatieresResolver::resolve()` remplace la boucle séquentielle par UN
appel batch (`KlassciProxyService::fetchManyMatieresDetails()`, pool HTTP
parallèle déjà en production sur 7 autres callsites — #135/#517) :

```php
public function resolve(array $matieresList, string $teacherToken): TeacherMatieresResolution
{
    $matieresById = KlassciPayload::keyById(
        $matieresList,
        fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null),
    );
    if ($matieresById === []) {
        return new TeacherMatieresResolution([], []);
    }

    $detailsById = $this->klassciService->fetchManyMatieresDetails(array_keys($matieresById), $teacherToken);

    $resolved = [];
    foreach ($detailsById as $matiereId => $details) {
        $resolved[$matiereId] = new ResolvedMatiere($matieresById[$matiereId], $details);
    }

    $failedMatiereIds = array_values(array_diff(array_keys($matieresById), array_keys($detailsById)));

    return new TeacherMatieresResolution($resolved, $failedMatiereIds);
}
```

`KlassciPayload::keyById()` (nouvelle méthode statique, même fichier que
`uniqueIntIds()` déjà existant pour le même idiome — #517) remplace un
`indexMatieresById()` privé qui aurait dupliqué `KlassciSeanceLookupService::
keyByMatiereId()` (finding "reuse" de la revue) : la logique "indexer une
liste de payloads par id résolu" est désormais en un seul endroit.

## 3. Restauration du signal `stats->errors` (finding CRITIQUE corrigé)

Une première version de ce refactor (voir la section "Nuance honnête"
ci-dessous pour le raisonnement original) laissait `KlassciBatchFetcher`
absorber silencieusement les échecs HTTP individuels sans jamais les
répercuter dans `stats->errors`. Un agent de revue a tracé la conséquence
jusqu'au bout : **plus aucun signal de supervision** ne distinguait "tout
s'est bien passé" de "une matière n'a pas pu être récupérée" — régression
réelle par rapport à l'ancien code (qui, lui, comptait +1 erreur par
enseignant en échec).

Corrigé : `TeacherMatieresResolution::$failedMatiereIds` porte explicitement
les IDs de matières demandées au batch mais absentes du résultat (distinct
des matières sans ID exploitable, qui elles restent silencieusement écartées
— comportement inchangé, jamais compté comme erreur ni avant ni après #515).
`KlassciSeancesSyncService::syncTeacherMatieres()` incrémente `stats->errors`
et logue pour chacun :

```php
foreach ($resolution->failedMatiereIds as $matiereId) {
    $stats->errors++;
    $this->logger->error('Erreur de récupération batch pour une matière — séances potentiellement non synchronisées', [
        'teacher_id' => $teacher->id,
        'matiere_id' => $matiereId,
    ]);
}
```

## 4. Nuance honnête restante — isolement de panne ENTRE matières (Q15 self-critique)

Ce qui **reste** un changement de comportement assumé, distinct du point §3
(qui restaure la parité de *signal*, pas de *flux de contrôle*) :

- **Avant** : une matière en échec HTTP faisait remonter l'exception jusqu'au
  `catch` de `syncTeacher()` — **les matières suivantes de ce même enseignant
  n'étaient jamais traitées non plus**, même si elles auraient réussi
  individuellement.
- **Après** : les autres matières du même batch, elles, sont traitées
  normalement (tolérance partielle native de `KlassciBatchFetcher`).

C'est un **meilleur** isolement de panne, gardé délibérément : c'est la
sémantique native déjà auditée et en production sur 7 autres callsites
(#135), la reproduire fidèlement est plus cohérent (Q6) que d'ajouter une
couche de compensation pour recréer artificiellement un comportement qui
était de toute façon un défaut non intentionnel du code séquentiel, pas une
exigence produit. Aucun test existant ne dépendait de "une matière en échec
bloque les suivantes" (vérifié par grep avant ce choix).

## 5. Risque pré-existant identifié pendant la revue — PAS corrigé ici, dette tracée

Un agent de revue a identifié un risque réel mais **pré-existant** (pas
introduit par #515, seulement rendu plus silencieux avant le correctif §3,
et toujours présent après) : `StaleSeanceArchiver::archive()` traite toute
séance locale active absente de `activeIdsByInstitution` comme supprimée de
KLASSCI. Si une matière échoue à cause d'un problème réseau **transitoire**
(pas une vraie suppression côté KLASSCI), ses séances ne contribuent jamais à
`activeIdsByInstitution`, et — puisque `$completePass` ne dépend que du
budget-temps (#539), pas du succès individuel des fetchs — l'archivage
s'exécute quand même et archive à tort ces séances.

**Pourquoi ce n'est pas corrigé dans cette PR** : le résoudre proprement
demanderait de faire dépendre `$completePass` (ou une variante par
institution) du succès de CHAQUE fetch de matière, pas seulement du budget —
un changement de portée notable au comportement d'archivage lui-même, hors du
périmètre de #515 ("batcher les appels HTTP"). Avec le correctif §3, ce risque
est au moins **visible** (`stats->errors` > 0 signale qu'une investigation est
nécessaire avant de faire confiance à l'archivage de ce run) — avant #515, le
même risque existait avec la même visibilité partielle (un enseignant entier
non traité après le point d'échec, mais la même absence de garde-fou
structurel sur l'archivage lui-même).

**Recommandation explicite** : ouvrir une issue de suivi dédiée
("StaleSeanceArchiver : ne pas archiver si des fetchs de matière ont échoué
pendant la passe") plutôt que d'élargir #515.

## 6. Tests

Pattern anti-N+1 établi (#517, `MatiereSeancesFetcherTest`) : mock
`KlassciProxyService`, assertion `fetchManyMatieresDetails()` appelée
**exactement une fois par enseignant**, avec la liste complète des IDs de
matières.

1. RED d'abord (assertion sur `fetchManyMatieresDetails` jamais appelée
   contre le code séquentiel d'origine), puis GREEN.
2. Baseline-vs-afterGrowth : 3 matières vs 30 matières → 1 seul appel batch
   dans les deux cas.
3. Non-régression fonctionnelle : 2 tests de caractérisation existants
   (`KlassciSeancesSyncServiceTest`) mis à jour pour mocker
   `fetchManyMatieresDetails` au lieu de `requestWithUserToken('matieres/{id}')`
   — mêmes assertions de stats/DB qu'avant.
4. Tolérance partielle + comptage restauré : une matière omise du résultat
   batch → les autres matières du même enseignant sont quand même
   synchronisées (isolement, §4) ET `stats->errors` est incrémenté (signal
   restauré, §3) — les deux assertions dans le même test, pour qu'elles ne
   puissent pas dériver l'une de l'autre sans casser le test.
5. Unit tests dédiés des 2 nouvelles classes principales
   (`TeacherMatieresResolverTest`, `StaleSeanceArchiverTest`), en isolation
   du reste du service.
6. Non-régression suite complète : 3 tests d'autres fichiers
   (`SeanceTenantIsolationTest` ×2, `DrainBudgetTest` ×1) mockaient encore
   `requestWithUserToken('matieres/{id}', ...)` — mis à jour vers
   `fetchManyMatieresDetails` pour rester alignés avec le nouveau mécanisme.
