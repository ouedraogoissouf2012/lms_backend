<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

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
 * `GET /api/lessons/{id}` doit porter l'identifiant KLASSCI de la matière.
 *
 * ## Le défaut, observé EN VRAI le 2026-09-07
 *
 * Après avoir créé une leçon, l'enseignant atterrit sur l'écran chapitres.
 * Les deux retours vers la matière — bouton « Retour » et redirection après
 * publication — naviguent ainsi
 * (`lms-frontend/src/composables/useLessonChapters.js:73` et `:97`) :
 *
 * ```js
 * params: { id: lesson.value.matiere_id }
 * ```
 *
 * Or `lessons.matiere_id` est un identifiant **LOCAL**, et la route
 * `/lms/matieres/{id}` est **proxifiée vers KLASSCI**, qui attend le sien. Le
 * frontend réinjectait donc du local là où KLASSCI est attendu — la
 * confusion des deux espaces, prise par l'autre bout.
 *
 * Conséquence mesurée : depuis une leçon d'« Anglais » (local 1, KLASSCI 3),
 * le retour ouvrait `/lms/matieres/1`. KLASSCI répondait pour SA matière 1,
 * « Marketing digital », tandis que la liste de leçons — résolue localement —
 * restait celle d'Anglais. **L'en-tête annonçait une matière, la liste en
 * montrait une autre.** Une leçon a été supprimée par erreur depuis cet écran :
 * il était impossible de savoir ce qu'on regardait.
 *
 * ## Le remède
 *
 * La réponse porte `matiere_klassci_id`, pendant exact de `matiere_id_local`
 * côté matière. Chaque espace est nommé, aucun n'est deviné.
 *
 * `null` quand la leçon n'a pas de matière, ou quand la matière n'a pas de
 * `klassci_id` — le frontend retombe alors sur `router.back()`, comme il le
 * fait déjà quand `matiere_id` est absent.
 */
final class LessonCarriesMatiereKlassciIdTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
    }

    /**
     * LE cas réel : les deux espaces sont exposés, distinctement.
     */
    public function test_the_response_carries_both_the_local_and_the_klassci_identifier(): void
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7003,
            'libelle' => 'Anglais',
        ]);

        $reponse = $this->show($this->lesson($matiere));

        $reponse->assertOk();
        $reponse->assertJsonPath('data.matiere_id', $matiere->id);
        $reponse->assertJsonPath('data.matiere_klassci_id', 7003);
        self::assertNotSame(
            $matiere->id,
            7003,
            'fixture inopérante : les deux espaces doivent différer pour que le test discrimine',
        );
    }

    /**
     * Une leçon sans matière ne fabrique pas d'identifiant : le champ est
     * présent et nul, pour que le frontend puisse s'y fier.
     */
    public function test_a_lesson_without_matiere_exposes_a_null_klassci_identifier(): void
    {
        $reponse = $this->show($this->lesson(null));

        $reponse->assertOk();
        // `assertJsonPath(..., null)` passe AUSSI quand la clé est absente :
        // la présence est donc vérifiée séparément. Le contrat est que le champ
        // existe toujours, pour qu'un frontend puisse s'y fier sans tester.
        $reponse->assertJsonStructure(['data' => ['matiere_klassci_id']]);
        $reponse->assertJsonPath('data.matiere_klassci_id', null);
    }

    /**
     * L'isolation : la traduction est bornée à l'institution de la leçon, comme
     * partout ailleurs — le `klassci_id` n'est unique que par établissement.
     */
    public function test_the_translation_never_crosses_the_institution_boundary(): void
    {
        $ailleurs = Institution::factory()->create();
        $matiereAilleurs = Matiere::factory()->create([
            'institution_id' => $ailleurs->id,
            'klassci_id' => 9999,
        ]);

        // Une leçon de NOTRE institution pointant (anormalement) une matière
        // d'ailleurs ne doit rien révéler de cette matière.
        $lesson = $this->lesson(null);
        $lesson->forceFill(['matiere_id' => $matiereAilleurs->id])->save();

        $this->show($lesson)
            ->assertJsonStructure(['data' => ['matiere_klassci_id']])
            ->assertJsonPath('data.matiere_klassci_id', null);
    }

    private function lesson(?Matiere $matiere): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'classe_id' => Classe::factory()->create(['institution_id' => $this->institution->id])->id,
            'matiere_id' => $matiere?->id,
            'enseignant_id' => $this->teacher->id,
            'status' => LessonStatus::Draft,
        ]);
    }

    private function show(Lesson $lesson): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->teacher->createToken('nav')->plainTextToken,
        ])->getJson("/api/lessons/{$lesson->id}");
    }
}
