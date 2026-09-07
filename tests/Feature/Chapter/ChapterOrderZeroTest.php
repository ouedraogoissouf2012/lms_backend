<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le PREMIER chapitre d'une leçon porte l'ordre 0 — et doit être acceptable.
 *
 * ## La contradiction corrigée
 *
 * Trois sources s'opposaient sur la même colonne :
 *
 * | Source | Borne |
 * |---|---|
 * | `chapters.order` (migration `2025_10_25_202933`) | `->default(0)` |
 * | `ReorderChaptersRequest` | `min:0` — « L'ordre ne peut pas être négatif » |
 * | `StoreChapterRequest` | `PositiveInteger` — **strictement > 0** |
 *
 * On pouvait donc DÉPLACER un chapitre en position 0, mais pas en CRÉER un.
 * Le frontend numérote ses chapitres à partir de zéro : la création du premier
 * chapitre échouait systématiquement en 422 « The ordre must be a positive
 * integer (> 0) », sur un formulaire pourtant correctement rempli.
 *
 * Observé en local le 2026-09-07, écran « Gestion des chapitres ».
 */
final class ChapterOrderZeroTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $classe = Classe::factory()->create(['institution_id' => $institution->id]);
        $this->lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $this->teacher->id,
            'status' => LessonStatus::Draft,
        ]);
    }

    /**
     * LE défaut : le premier chapitre, numéroté 0 par le frontend, était refusé.
     */
    public function test_the_first_chapter_can_be_created_at_order_zero(): void
    {
        $this->postChapter(['order' => 0])
            ->assertStatus(201);
    }

    /**
     * L'ordre reste enregistré tel quel : accepter 0 ne doit pas le transformer
     * silencieusement en 1, sans quoi le classement du frontend se décalerait.
     */
    public function test_the_zero_order_is_stored_verbatim(): void
    {
        $this->postChapter(['order' => 0]);

        self::assertSame(0, (int) $this->lesson->chapters()->value('order'));
    }

    /**
     * La borne basse reste une borne : un ordre négatif n'a aucun sens et doit
     * toujours être refusé — c'est exactement ce que dit `ReorderChaptersRequest`.
     */
    public function test_a_negative_order_is_still_refused(): void
    {
        $this->postChapter(['order' => -1])
            ->assertStatus(422)
            ->assertJsonPath('errors.ordre.0', 'L\'ordre ne peut pas être négatif');
    }

    /**
     * Sans ordre fourni, le service continue de l'attribuer lui-même.
     */
    public function test_an_absent_order_is_still_assigned_automatically(): void
    {
        $this->postChapter([])->assertStatus(201);

        self::assertNotNull($this->lesson->chapters()->value('order'));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function postChapter(array $extra): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->teacher->createToken('chapitres')->plainTextToken,
        ])->postJson("/api/lessons/{$this->lesson->id}/chapters", [
            'titre' => 'Chapitre introductif',
            'type_contenu' => 'text',
            'contenu' => 'Contenu du chapitre.',
        ] + $extra);
    }
}
