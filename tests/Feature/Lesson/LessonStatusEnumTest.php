<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #522 P1 — verrou du refactor `LessonStatus` enum.
 *
 * Garantit que l'introduction du cast enum ne change RIEN au comportement
 * observable : le cast rend un enum côté PHP, mais la valeur sérialisée
 * (JSON client, colonne DB) reste la string historique. Couvre aussi les
 * prédicats/scope/observer qui reposent désormais sur des comparaisons d'enum.
 *
 * @see app/Enums/LessonStatus.php
 * @see app/Models/Lesson.php
 */
final class LessonStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_attribute_is_cast_to_enum(): void
    {
        $lesson = Lesson::factory()->create([
            'status' => LessonStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);

        $this->assertInstanceOf(LessonStatus::class, $lesson->refresh()->status);
        $this->assertSame(LessonStatus::Published, $lesson->status);
    }

    public function test_json_serialization_preserves_string_contract(): void
    {
        $lesson = Lesson::factory()->create(['status' => LessonStatus::Draft->value]);

        // Contrat client : `status` doit rester la string, pas un objet enum.
        $this->assertSame('draft', $lesson->toArray()['status']);
        $this->assertSame('draft', $lesson->refresh()->toArray()['status']);

        $decoded = json_decode($lesson->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('draft', $decoded['status']);
    }

    public function test_default_status_is_draft(): void
    {
        $lesson = new Lesson();

        $this->assertSame(LessonStatus::Draft, $lesson->status);
    }

    public function test_scope_published_filters_on_status_and_past_date(): void
    {
        $live = Lesson::factory()->create([
            'status' => LessonStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
        $draft = Lesson::factory()->create([
            'status' => LessonStatus::Draft->value,
            'published_at' => null,
        ]);
        $future = Lesson::factory()->create([
            'status' => LessonStatus::Published->value,
            'published_at' => now()->addDay(),
        ]);

        $ids = Lesson::published()->pluck('id');

        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($draft->id), 'un draft ne doit pas être publié');
        $this->assertFalse($ids->contains($future->id), 'published_at futur = pas encore publié');
    }

    public function test_is_published_predicate(): void
    {
        $live = Lesson::factory()->create([
            'status' => LessonStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
        $draft = Lesson::factory()->create([
            'status' => LessonStatus::Draft->value,
            'published_at' => null,
        ]);

        $this->assertTrue($live->isPublished());
        $this->assertFalse($draft->isPublished());
    }

    public function test_observer_sets_published_at_when_saved_as_published(): void
    {
        // Créée « published » sans date : l'observer (comparaison enum) doit poser published_at.
        $lesson = Lesson::factory()->create([
            'status' => LessonStatus::Published->value,
            'published_at' => null,
        ]);

        $this->assertNotNull($lesson->refresh()->published_at);
    }

    public function test_observer_clears_published_at_for_non_published(): void
    {
        $lesson = Lesson::factory()->create([
            'status' => LessonStatus::Draft->value,
            'published_at' => now(),
        ]);

        $this->assertNull($lesson->refresh()->published_at);
    }
}
