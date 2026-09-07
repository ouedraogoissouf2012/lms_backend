<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
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
        $matiere = Matiere::factory()->create(['institution_id' => $institution->id]);
        $this->lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
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
        // Le statut D'ABORD. Sans lui, ce test était un FAUX VERT : une
        // création échouée laisse `value('order')` à `null`, que `(int)`
        // transforme en 0 — l'assertion passait sur une leçon sans le moindre
        // chapitre. Signalé par la revue adversariale, prouvé par la jambe
        // MySQL de la CI, qui rendait 500 là où SQLite rendait 201.
        $this->postChapter(['order' => 0])->assertStatus(201);

        self::assertSame(0, $this->lesson->chapters()->firstOrFail()->order);
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
     * LE défaut révélé par la jambe MySQL de la CI.
     *
     * `lessons.matiere_id` est nullable — il existe des leçons sans matière, et
     * le frontend en crée désormais quand la matière n'est pas encore miroitée.
     * Or `ChapterCrudService` recopie cette valeur dans `chapters.matiere_id`,
     * une colonne créée NOT NULL... que la reconstruction de table SQLite de
     * `2026_01_03_220000` avait rendue nullable.
     *
     * Les deux moteurs de la CI n'avaient donc plus le même schéma : sous
     * SQLite 201, sous MySQL **500**. Un chapitre était impossible à créer sur
     * une leçon sans matière, en production, et rien en local ne le disait.
     */
    public function test_a_chapter_can_be_created_on_a_lesson_without_matiere(): void
    {
        $sansMatiere = Lesson::factory()->create([
            'institution_id' => $this->lesson->institution_id,
            'classe_id' => $this->lesson->classe_id,
            'matiere_id' => null,
            'enseignant_id' => $this->teacher->id,
            'status' => LessonStatus::Draft,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->teacher->createToken('chapitres')->plainTextToken,
        ])->postJson("/api/lessons/{$sansMatiere->id}/chapters", [
            'titre' => 'Chapitre sans matière',
            'type_contenu' => 'text',
            'content' => 'Contenu du chapitre.',
        ])->assertStatus(201);

        self::assertNull($sansMatiere->chapters()->firstOrFail()->matiere_id);
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
            'content' => 'Contenu du chapitre.',
        ] + $extra);
    }
}
