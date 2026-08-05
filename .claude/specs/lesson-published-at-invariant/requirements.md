# Requirements — Invariant `status=published ⇒ published_at != null` (#481)

## Contexte & preuves

Le scope `Lesson::published()` (`app/Models/Lesson.php:109-114`) exige
`status='published'` **ET** `published_at IS NOT NULL` **ET** `<= now()`. Or des
leçons sont créées `status='published'` **sans** `published_at` → invisibles :

- `database/factories/LessonFactory.php:28` (definition random) et `:39-44`
  (state `published()`) : posent `status='published'` **sans** `published_at`.
- `database/seeders/DemoDataSeeder.php:80,89,98,107` : 4 leçons `published`
  **sans** `published_at`.

Résultat observé : dashboard « Cours Suivis : 3 » mais page Mes Cours vide
(`Lesson::published()->count() = 0`).

La logique **applicative** est correcte (le service pose `published_at` sur les
4 chemins : create `:90-91`, update `:107-110`, publish `:139`, unpublish
`:157`). Le bug vient de données créées **en contournant** le service.

## Décision

Corriger les données (factory + seeder) **ET** poser un **invariant applicatif**
(Observer) : aucun point d'écriture — présent ou futur — ne pourra reproduire
l'incohérence. Défense en profondeur (§1.1).

## Portée

- **IN** : Observer `LessonObserver` (invariant bidirectionnel), correction
  factory + seeder, tests.
- **OUT** : le scope `published()` lui-même (correct, inchangé) ; le filtrage
  classe (#482, fait) ; la pagination (#483).

## Exigences (EARS)

**REQ-1 — Invariant publication (sens published)**
WHEN une `Lesson` est sauvegardée AVEC `status='published'` ET `published_at`
NULL, THE SYSTEM SHALL poser `published_at = now()` avant persistance.

**REQ-2 — Invariant dépublication (sens draft/archived)**
WHEN une `Lesson` est sauvegardée AVEC `status ∈ {draft, archived}`, THE SYSTEM
SHALL forcer `published_at = null` (cohérent avec
`LessonCrudOperationsService::update():109-110` et `unpublish()`).

**REQ-3 — Non-écrasement d'une date valide**
IF `status='published'` ET `published_at` est **déjà** renseigné, THEN THE
SYSTEM SHALL **conserver** cette valeur (ne pas la réécrire à `now()`) — préserve
les dates de publication passées/planifiées légitimes.

**REQ-4 — Couverture de TOUS les points d'écriture**
THE SYSTEM SHALL appliquer l'invariant quel que soit le point d'écriture :
service, **factory**, **seeder**, `Model::create`, `save()`, import, tinker.

**REQ-5 — Factory cohérente**
THE SYSTEM SHALL faire en sorte que `LessonFactory` génère des leçons
cohérentes : `published` ⇒ `published_at` non nul ; `draft`/`archived` ⇒
`published_at` nul. Les tests existants s'appuyant sur `->published()` doivent
voir la leçon via `published()`.

**REQ-6 — Seeder cohérent**
THE SYSTEM SHALL faire en sorte que `DemoDataSeeder` produise des leçons
`published` visibles (`Lesson::published()->count() > 0` après seed).

**REQ-7 — Non-régression du service**
THE SYSTEM SHALL ne PAS altérer le comportement des 4 chemins du service
(create/update/publish/unpublish) : ils posent déjà la bonne valeur, l'Observer
ne fait que confirmer l'invariant sans effet de bord observable.

## Critères d'acceptation

1. `Lesson::factory()->published()->create()` → visible via
   `Lesson::published()` (published_at non nul, ≤ now).
2. `new Lesson(['status'=>'published'])->save()` (contournement direct) →
   `published_at` posé automatiquement.
3. Une leçon repassée en `draft` → `published_at` remis à null.
4. Une leçon `published` avec `published_at` explicite passé → date **conservée**.
5. Après `DemoDataSeeder` : `Lesson::published()->count() > 0`.
6. `php artisan test` = 100 % ; PHPStan level 9 vert ; garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ L'Observer écrase une `published_at` déjà valide par `now()` (perte de la
  date réelle de publication).
- ❌ L'invariant ne s'applique qu'au service (factory/seeder/tinker encore
  cassables).
- ❌ `status=draft` conserve une `published_at` non nulle (incohérence inverse).
- ❌ Boucle/récursion d'événements (Observer qui re-déclenche `saving`).
- ❌ Un test existant qui créait `->published()` et l'attendait invisible casse
  silencieusement (comportement inversé non maîtrisé).
