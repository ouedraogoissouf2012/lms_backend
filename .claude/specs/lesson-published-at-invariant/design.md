# Design — Invariant `status=published ⇒ published_at != null` (#481)

## 1. Mécanisme : Observer sur l'événement `saving`

`saving` se déclenche **avant** tout INSERT et UPDATE, quel que soit le point
d'écriture (`create`, `save`, factory, seeder, `update`) → couvre REQ-4. On
modifie l'attribut **en mémoire** sur l'instance avant persistance : **aucun
second `save()`**, donc **pas de récursion** (Q15).

```php
namespace App\Observers;

use App\Models\Lesson;

/**
 * Garantit l'invariant #481 : `status=published ⇒ published_at != null`, et sa
 * réciproque `status ∈ {draft, archived} ⇒ published_at = null`.
 *
 * Filet de sécurité : les 4 chemins de LessonCrudOperationsService posent déjà
 * published_at correctement ; cet observer capture les écritures qui les
 * contournent (factory, seeder, import, tinker) pour que l'incohérence ne
 * puisse plus rendre une leçon publiée invisible via Lesson::published().
 */
final class LessonObserver
{
    public function saving(Lesson $lesson): void
    {
        if ($lesson->status === 'published') {
            // Ne JAMAIS écraser une date déjà posée (REQ-3).
            if ($lesson->published_at === null) {
                $lesson->published_at = now();          // REQ-1
            }

            return;
        }

        // draft / archived (ou tout autre statut non publié) : pas de date.
        $lesson->published_at = null;                   // REQ-2
    }
}
```

- `saving` modifie l'instance **avant** l'écriture SQL → l'invariant est
  persisté dans la même requête, sans re-déclencher d'événement (pas de
  `$lesson->save()` interne). REQ anti-récursion.
- Logique **identique** à `LessonCrudOperationsService::update():106-111`
  (published ⇒ date si absente ; sinon null), donc **aucune** divergence de
  comportement sur les chemins service (REQ-7).

## 2. Enregistrement — attribut `#[ObservedBy]` sur le modèle

Laravel 11/12 : l'attribut est le plus lisible pour un observer unique (le
projet utilise déjà `static::observe()` pour `AuditableObserver` via un trait ;
ici, un seul observer dédié → attribut direct sur `Lesson`).

```php
use App\Observers\LessonObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([LessonObserver::class])]
class Lesson extends Model { … }
```

## 3. Factory cohérente (REQ-5)

Problème actuel : `definition()` tire `status` au hasard sans `published_at`, et
`published()` ne pose pas la date. Avec l'Observer, `save()` corrigerait déjà —
mais la factory DOIT rester **explicite et déterministe** (ne pas dépendre de
l'observer pour être correcte, et couvrir le cas `published_at <= now()` pour la
visibilité immédiate).

```php
public function definition(): array
{
    return [
        // … inchangé …
        'status' => 'draft',        // défaut déterministe (pas de random)
    ];
}

public function published(): static
{
    return $this->state(fn () => [
        'status' => 'published',
        'published_at' => now()->subDay(),   // visible via published()
    ]);
}

public function draft(): static
{
    return $this->state(fn () => ['status' => 'draft', 'published_at' => null]);
}

public function archived(): static
{
    return $this->state(fn () => ['status' => 'archived', 'published_at' => null]);
}
```

> ⚠️ **Changement de défaut** : `definition()` passe de `status` **random** à
> `draft`. Justification : un défaut aléatoire rend les tests non déterministes
> (une leçon « visible » un run sur trois). Les tests qui veulent une leçon
> publiée utilisent `->published()`. À vérifier : aucun test existant ne
> s'appuie sur le random pour créer une leçon publiée « par chance ».

## 4. Seeder cohérent (REQ-6)

`DemoDataSeeder` : ajouter `'published_at' => now()->subDay()` à chaque entrée
`status='published'` (`:80,89,98,107`). Même si l'Observer le corrigerait, on
reste **explicite** dans la donnée de démo (lisibilité + intention claire).

## 5. Impact & non-régression

- **Observer** : agit sur toutes les écritures `Lesson`. Sur les 4 chemins
  service (déjà corrects) → no-op observable (la valeur est déjà bonne).
- **Risque identifié** : REQ-2 force `published_at=null` sur `draft`/`archived`
  à CHAQUE save. Si un test créait une leçon `draft` AVEC une `published_at`
  résiduelle et l'attendait, il verrait désormais `null`. À détecter en
  non-régression (probable no-op car incohérent de toute façon).
- **Factory random → draft** : les tests créant `Lesson::factory()->create()`
  sans état obtiendront désormais un `draft` déterministe. À balayer.

## 6. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Observers/LessonObserver.php` (NEW) | Invariant `saving`. |
| `app/Models/Lesson.php` | Attribut `#[ObservedBy([LessonObserver::class])]`. |
| `database/factories/LessonFactory.php` | Défaut `draft` déterministe + états cohérents. |
| `database/seeders/DemoDataSeeder.php` | `published_at` sur les 4 leçons publiées. |
| `tests/Feature/Lesson/LessonPublishedAtInvariantTest.php` (NEW) | Invariant + contournement direct + réciproque + non-écrasement + seed. |
