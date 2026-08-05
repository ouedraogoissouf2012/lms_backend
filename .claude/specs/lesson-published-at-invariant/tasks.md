# Tasks — Invariant `status=published ⇒ published_at != null` (#481)

Ordre TDD : RED → GREEN → non-régression → vérif.

## 1. Tests de l'invariant (RED)

- [ ] **1.1** Créer `tests/Feature/Lesson/LessonPublishedAtInvariantTest.php`.
  - **1.1a** `test_published_lesson_without_date_gets_published_at` : créer via
    contournement direct `Lesson::create(['status'=>'published', ...])` SANS
    `published_at` → après save, `published_at` non nul ET la leçon apparaît
    dans `Lesson::published()`. _(REQ-1, REQ-4, AC2.)_
  - **1.1b** `test_factory_published_state_is_visible` :
    `Lesson::factory()->published()->create()` → présent dans
    `Lesson::published()`. _(REQ-5, AC1.)_
  - **1.1c** `test_draft_lesson_has_null_published_at` : leçon `draft` (même si
    on tente de poser une date) → `published_at` null après save. _(REQ-2, AC3.)_
  - **1.1d** `test_existing_published_at_is_not_overwritten` : leçon `published`
    avec `published_at` explicite (ex. `2020-01-01`) → date **conservée**, pas
    réécrite à now(). _(REQ-3, AC4.)_
  - **1.1e** `test_archived_lesson_has_null_published_at`. _(REQ-2.)_
  - **Lancer → RED** (aujourd'hui l'invariant n'existe pas).

## 2. Implémentation (GREEN)

- [ ] **2.1** Créer `app/Observers/LessonObserver.php` : `saving()` — published
  sans date ⇒ now() (sans écraser une date existante) ; sinon ⇒ null.
  _(REQ-1/2/3, pas de save() interne → pas de récursion.)_
- [ ] **2.2** `app/Models/Lesson.php` : ajouter l'attribut
  `#[ObservedBy([LessonObserver::class])]` + import. _(REQ-4.)_
- [ ] **2.3** `LessonFactory` : `definition()` défaut `status='draft'`
  déterministe ; états `published()` (pose `published_at = now()->subDay()`),
  `draft()`/`archived()` (published_at null). _(REQ-5.)_
- [ ] **2.4** `DemoDataSeeder` : ajouter `'published_at' => now()->subDay()` aux
  4 leçons `status='published'` (`:80,89,98,107`). _(REQ-6.)_
- [ ] **2.5** Lancer 1.1 → **GREEN**.

## 3. Non-régression (balayage factory)

- [ ] **3.1** Balayer tous les `Lesson::factory()` des tests (≈40 usages) :
  identifier ceux qui dépendaient du `status` **random** pour obtenir une leçon
  publiée « par chance » et les rendre explicites (`->published()` /
  `'status'=>'published'`). Les `->published()`/`->draft()` existants sont OK.
- [ ] **3.2** `php artisan test tests/Feature/Lesson/ tests/Unit/Services/Lesson/
  tests/Unit/Services/Dashboard/ tests/Feature/Dashboard/` → 100 %.
- [ ] **3.3** `php artisan test` global (fin) → 100 % (via CI si trop long en
  local ; documenter).

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur Observer + Lesson + factory → 0 erreur.
- [ ] **4.2** Garde tailles : Observer ≤300, méthode ≤40 ; Lesson ≤150 (modèle).
- [ ] **4.3** Seed réel : `php artisan migrate:fresh --seed` (ou
    `db:seed --class=DemoDataSeeder` sur base test) → `Lesson::published()->count()
    > 0`. _(AC5.)_

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #481 explicitement + récap.

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (published⇒date) | 1.1a, 2.1, 2.5 |
| REQ-2 (draft/archived⇒null) | 1.1c, 1.1e, 2.1 |
| REQ-3 (non-écrasement) | 1.1d, 2.1 |
| REQ-4 (tous points d'écriture) | 1.1a, 2.1, 2.2 |
| REQ-5 (factory) | 1.1b, 2.3 |
| REQ-6 (seeder) | 2.4, 4.3 |
| REQ-7 (non-régression service) | 3.2, 3.3 |
