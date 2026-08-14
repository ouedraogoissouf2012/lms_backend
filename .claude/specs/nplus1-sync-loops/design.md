# Design — Éliminer les N+1 des boucles de sync (#503)

## P1 — VideoSessionAttendancesSyncer

### 1.1 Précharger les users (REQ-1)

Avant la boucle, une requête bornée + map par `klassci_id` :

```php
$klassciIds = array_values(array_filter(array_map(
    fn (array $p): mixed => $p['etudiant_id'] ?? null,
    $participants
), fn ($id): bool => $id !== null));

/** @var \Illuminate\Support\Collection<int|string, User> $usersByKlassciId */
$usersByKlassciId = User::query()
    ->where('institution_id', $seance->institution_id)
    ->whereIn('klassci_id', $klassciIds)
    ->get()
    ->keyBy('klassci_id');
```

`klassci_id` est unique par institution (contrainte) → `keyBy` sans collision (Q15).

### 1.2 Précalculer l'ensemble des inscrits (REQ-2)

`isEnrolled` faisait 1-2 `exists()` par étudiant. On calcule **une fois**
l'ensemble des `user_id` inscrits (union des 2 sources), puis test en mémoire :

```php
private function enrolledUserIds(Seance $seance): array
{
    $klassciClasseId = (int) $seance->klassci_classe_id;

    // Source 1 : classes KLASSCI synchronisées (UserClass).
    $fromUserClass = UserClass::query()
        ->where('institution_id', $seance->institution_id)
        ->where('klassci_classe_id', $klassciClasseId)
        ->pluck('user_id');

    // Source 2 : pivot classe_etudiant actif de la classe locale.
    $fromPivot = Classe::query()
        ->where('institution_id', $seance->institution_id)
        ->where('klassci_id', $klassciClasseId)
        ->with(['etudiants' => fn ($q) => $q->wherePivot('statut', 'actif')])
        ->get()
        ->flatMap(fn (Classe $c) => $c->etudiants->pluck('id'));

    // Union, dédupliquée, en set (valeurs = user_id).
    return $fromUserClass->concat($fromPivot)->unique()->values()->all();
}
```

### 1.3 `resolveStudents` en mémoire (REQ-1/3)

```php
$usersByKlassciId = /* §1.1 */;
$enrolledIds = $this->enrolledUserIds($seance);   // set, 2 requêtes fixes

$students = [];
foreach ($participants as $participant) {
    $klassciId = $participant['etudiant_id'] ?? null;
    $student = $usersByKlassciId->get($klassciId);

    if (! $student instanceof User || ! $student->isStudent()
        || ! in_array($student->id, $enrolledIds, true)) {
        return null;   // tout-ou-rien préservé (REQ-3)
    }

    $students[] = $student;   // alignement d'index préservé (REQ-1)
}

return $students;
```

- `isEnrolled()` (méthode) est **supprimée** (remplacée par le set en mémoire).
- **Bilan requêtes** : 1 (users) + 2 (enrollment) = **3 constantes** vs ~3N.

### 1.4 Plafond participants (REQ-4)

`SyncAttendancesRequest:54` :
```php
'participants' => 'required|array|min:1|max:100',
```

## P2 — TeacherEvaluationResultsService::buildResultats

### 2.1 Précharger users par email (REQ-5) + submissions (REQ-6)

Avant la boucle :

```php
$emails = array_values(array_filter(array_map(
    fn (array $e): string => $e['email'] ?? '',
    $etudiants
), fn (string $email): bool => $email !== ''));

$usersByEmail = User::query()->whereIn('email', $emails)->get()->keyBy('email');

// Toutes les submissions "live" (non-practice) de l'éval, la plus récente par
// klassci_etudiant_id. `latest()->get()->groupBy(...)` → 1re de chaque groupe
// = la plus récente (REQ-6).
$submissionsByKlassci = $evaluation->submissions()
    ->where(fn ($q) => $q->whereNull('feedback')->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%'))
    ->latest()
    ->get()
    ->groupBy('klassci_etudiant_id');
```

### 2.2 Résolution en mémoire (REQ-6/8)

```php
foreach ($etudiants as $etudiant) {
    $email = $etudiant['email'] ?? '';
    $userLocal = $email !== '' ? $usersByEmail->get($email) : null;

    // Stratégie 1 : klassci_id du user local ; Stratégie 2 : id KLASSCI direct.
    $submission = null;
    if ($userLocal !== null && $userLocal->klassci_id !== null) {
        $submission = $submissionsByKlassci->get($userLocal->klassci_id)?->first();
    }
    if ($submission === null) {
        $submission = $submissionsByKlassci->get($etudiant['id'])?->first();
    }

    $resultats[] = [ /* … inchangé … */ ];
}
```

- Le `latest()->groupBy` garantit que `->first()` d'un groupe = la submission la
  plus récente — équivalent au `->latest()->first()` par étudiant (REQ-8).
- Filtre feedback `[PRACTICE]` et scope tenant : **inchangés** (dans la requête).

## 3. Décisions & justifications

| Décision | Pourquoi |
|---|---|
| `keyBy('klassci_id')` / `keyBy('email')` | Unicité par tenant → pas d'écrasement (Q15). |
| Set `enrolledUserIds` en mémoire | Remplace 1-2 `exists()`/étudiant par 2 requêtes fixes (REQ-2). |
| `latest()->get()->groupBy` + `first()` | Reproduit `latest()->first()` par étudiant en 1 requête (REQ-6). |
| Alignement via `foreach` séquentiel | `$students[]` garde l'ordre de `$participants` (REQ-1). |
| `max:100` sur participants | Anti-DOS, borne raisonnable (classe réelle << 100). |
| Suppression de `isEnrolled()` | Logique déplacée dans le set préchargé ; pas de méthode morte. |

## 4. Tests (pattern anti-N+1 du repo)

`enableQueryLog()` : mesurer les requêtes avec N=2 puis N=5 participants/étudiants
et asserter **même compte** (indépendant de N). Cf.
`tests/Feature/Performance/UpcomingSeancesNoNPlusOneTest.php`.

## 5. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Services/Attendances/VideoSessionAttendancesSyncer.php` | preload users + `enrolledUserIds`, suppression `isEnrolled` |
| `app/Http/Requests/SyncAttendancesRequest.php` | `max:100` |
| `app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php` | preload users email + submissions groupées |
| `tests/Feature/Performance/AttendancesSyncNoNPlusOneTest.php` (NEW) | anti-N+1 P1 |
| `tests/Feature/Performance/EvaluationResultsNoNPlusOneTest.php` (NEW) | anti-N+1 P2 (mock KLASSCI roster) |
| `tests/Feature/Requests/SyncAttendancesRequestTest.php` | +test `max` |

Vérifier tailles : `sync`/`resolveStudents`/`enrolledUserIds`/`buildResultats` ≤40.
