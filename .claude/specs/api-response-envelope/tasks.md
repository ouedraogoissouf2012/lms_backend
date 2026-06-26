# Tasks — Unification des enveloppes de réponse JSON de l'API

> Spec-Driven Workflow — Phase 3 (Tasks). Réf. [requirements.md](requirements.md) · [design.md](design.md).
> Checklist hiérarchique (max 2 niveaux). Chaque tâche référence un requirement.
> Règle : **une tâche à la fois**, marquer `- [x]` immédiatement après complétion ([CONTRIBUTING §A](../../../CONTRIBUTING.md#L72)).

## Décision tracée
- `successResponse()` : `string $message = ''` (défaut vide) — un endpoint purement « data » n'est pas forcé d'inventer un message. _Réf. design §4.1._

---

## PR-0 — Trait + branchement + tests (périmètre de cette livraison)

- [x] **1. Créer le trait `RespondsWithJson`** _(R1, R2, R4)_
  - [x] 1.1 Fichier `app/Http/Controllers/Concerns/RespondsWithJson.php`, namespace `App\Http\Controllers\Concerns`, `declare(strict_types=1)`. _(R1.3)_
  - [x] 1.2 Méthode `protected successResponse(mixed $data = null, string $message = '', int $status = 200, array $meta = []): JsonResponse` — payload `{success, message, data}`, `meta` ajouté seulement si non vide. _(R1.1, R2.1, R2.3)_
  - [x] 1.3 Méthode `protected errorResponse(string $message, int $status = 400, array $errors = []): JsonResponse` — payload `{success:false, message}`, `errors` ajouté seulement si non vide. _(R1.2, R2.2, R4.1)_
  - [x] 1.4 PHPDoc expliquant le POURQUOI (issue/axe #1, contrat dérivé d'`AuthResponsePresenter`, omission `meta`/`errors`). _(NF4)_

- [x] **2. Brancher le trait sur le `Controller` de base** _(R1.1)_
  - [x] 2.1 `app/Http/Controllers/Controller.php` : `use App\Http\Controllers\Concerns\RespondsWithJson;` dans la classe `abstract class Controller`.

- [x] **2b. Résoudre la collision `ProxyDashboardController::errorResponse`** _(R6.2 amendé — découverte Phase 4)_
  - [x] 2b.1 Supprimer le `private function errorResponse()` redondant (4 appels passent un status explicite → zéro changement de comportement). _Réf. design §3.1._

- [x] **3. Tests unitaires du trait** _(R5)_
  - [x] 3.1 Fichier `tests/Unit/Http/Controllers/Concerns/RespondsWithJsonTest.php`, étend `Tests\TestCase`, classe anonyme utilisant le trait. _(design §6)_
  - [x] 3.2 Couvrir les 8 cas de la table design §6 (succès avec/sans data, sans/avec meta, status custom ; erreur simple/avec errors/status custom). _(R5.1)_
  - [x] 3.3 Asserter `status()` **et** `getData(true)` sur le golden contract (design §4.3).

- [x] **4. Validation PR-0** _(R6, §3 checklist pre-commit)_
  - [x] 4.1 Tests verts. ⚠️ La suite **complète** `php artisan test` segfault au shutdown (`0xC0000005`, bug PHP/Windows en fin de run, pas un échec) ; validé via runs **ciblés** : trait 8/8, + Proxy/Dashboard/Security **26 passed (183 assertions), exit 0**. _(R7.2 / §1.3)_
  - [x] 4.2 Diff limité : `Controller.php` (modif) + `Concerns/RespondsWithJson.php` (ajout) + `ProxyDashboardController.php` (collision R6.2) + test. Aucune réponse de controller migrée. _(R6.2)_
  - [x] 4.3 PHPStan vert sur les fichiers touchés, pas de nouvelle entrée baseline. _(DoD)_
  - [x] 4.4 Grep anti-patterns : seules occurrences de `getMessage` = PHPDoc documentant qu'on ne l'utilise PAS. Aucun `dd/var_dump/PHP_EOL` introduit. _(§3)_
  - [x] 4.5 Trait 97 l (≤300), base Controller 12 l. _(§1.1)_

---

## Hors PR-0 — Migrations (cadrées, NON exécutées ici)

> Détaillées en tasks dédiées au moment de chaque PR. Listées pour visibilité du séquencement (R7). **Aucune** ne fait partie de cette livraison.

- [ ] **PR-1 — Domaine couvert A** : migrer les controllers Quiz / Chapter / Lesson / Forum déjà testés vers `successResponse`/`errorResponse`. _(R3, R7.2)_
- [ ] **PR-2 — Domaine couvert B** : Evaluation / KnowledgeCheck / Notifications testés. _(R3, R7.2)_
- [ ] **PR-3 — Controllers sans test** : test de caractérisation **puis** migration (Proxy*, Dashboard*, TeacherStats, Report, Search…). _(R7.1)_

---

## Definition of Done — PR-0
Toutes les cases 1→4 cochées, `php artisan test` 100 %, PHPStan vert, diff limité à 3 fichiers (trait + Controller + test).
