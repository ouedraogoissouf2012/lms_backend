<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2E — Flow 2 (#211) : cycle de publication d'un cours, côté enseignant
 * ET côté étudiant.
 *
 * Parcours vérifié de bout en bout :
 *
 *   enseignant crée (draft) → ajoute un chapitre → étudiant ne voit RIEN
 *   → enseignant publie → étudiant voit le cours + ses chapitres
 *   → enseignant dépublie → le cours redisparaît pour l'étudiant
 *
 * L'enjeu : la frontière draft/published est LE contrôle d'accès du
 * catalogue de cours. Un brouillon qui fuite = contenu non validé exposé
 * aux étudiants.
 *
 * @see docs/IMPROVEMENT_PRIORITIES.md CRITICAL #1 (issue #211)
 * @see app/Services/Lesson/LessonCrudOperationsService.php (publish/unpublish)
 * @see app/Services/Lesson/LessonListService.php (visibilité étudiant)
 */
final class TeacherLessonPublicationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private Classe $classe;
    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
        ]);

        // #482 — l'étudiant doit être inscrit à la classe du cours pour le voir
        // (isolation inter-classes). Rattachement via UserClass (source KLASSCI),
        // le pont klassci_classe_id → classes.klassci_id → classes.id.
        UserClass::create([
            'user_id' => $this->student->id,
            'klassci_classe_id' => $this->classe->klassci_id,
            'classe_nom' => $this->classe->libelle,
            'institution_id' => $this->institution->id,
            'synced_at' => now(),
        ]);
    }

    /**
     * Cycle complet création → chapitre → publication → dépublication,
     * avec vérification de la visibilité étudiante à CHAQUE transition.
     */
    public function test_complete_lesson_publication_lifecycle(): void
    {
        // ── Étape 1 : l'enseignant crée le cours (brouillon par défaut) ────
        Sanctum::actingAs($this->teacher);

        $create = $this->postJson('/api/lessons', [
            'title' => 'Introduction à Laravel',
            'description' => 'Les fondamentaux du framework',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'matiere_id' => $this->matiere->id,
            'niveau_difficulte' => 'debutant',
        ]);
        $create->assertStatus(201);

        $lessonId = $create->json('data.id');
        $this->assertNotNull($lessonId);
        $this->assertSame('draft', $create->json('data.status'));

        // ── Étape 2 : l'enseignant ajoute un chapitre au brouillon ─────────
        $chapter = $this->postJson("/api/lessons/{$lessonId}/chapters", [
            'titre' => 'Chapitre 1 — Installation',
            'description' => 'Mise en place de l\'environnement',
            'type_contenu' => 'text',
        ]);
        $chapter->assertStatus(201);
        $chapterId = $chapter->json('data.id');
        $this->assertNotNull($chapterId);

        // ── Étape 3 : le brouillon est INVISIBLE pour l'étudiant ───────────
        Sanctum::actingAs($this->student);

        $listBefore = $this->getJson('/api/lessons');
        $listBefore->assertStatus(200);
        $this->assertNotContains(
            $lessonId,
            array_column($listBefore->json('data.data') ?? $listBefore->json('data'), 'id'),
            'Un brouillon ne doit JAMAIS apparaître dans le catalogue étudiant'
        );

        $this->getJson("/api/lessons/{$lessonId}")
            ->assertStatus(403);

        // ── Étape 4 : l'enseignant publie ──────────────────────────────────
        Sanctum::actingAs($this->teacher);

        $publish = $this->postJson("/api/lessons/{$lessonId}/publish");
        $publish->assertStatus(200);
        $this->assertSame('published', $publish->json('data.status'));

        // ── Étape 5 : le cours est maintenant visible pour l'étudiant ──────
        Sanctum::actingAs($this->student);

        $listAfter = $this->getJson('/api/lessons');
        $listAfter->assertStatus(200);
        $this->assertContains(
            $lessonId,
            array_column($listAfter->json('data.data') ?? $listAfter->json('data'), 'id'),
            'Le cours publié doit apparaître dans le catalogue étudiant'
        );

        $detail = $this->getJson("/api/lessons/{$lessonId}");
        $detail->assertStatus(200);
        $this->assertSame('Introduction à Laravel', $detail->json('data.lesson.title') ?? $detail->json('data.title'));

        // ── Étape 6 : l'étudiant voit les chapitres du cours publié ────────
        $chapters = $this->getJson("/api/lessons/{$lessonId}/chapters");
        $chapters->assertStatus(200);
        $chapterIds = array_column($chapters->json('data') ?? [], 'id');
        $this->assertContains($chapterId, $chapterIds);

        // ── Étape 7 : l'enseignant dépublie → le cours redisparaît ─────────
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/lessons/{$lessonId}/unpublish")
            ->assertStatus(200);

        Sanctum::actingAs($this->student);
        $listFinal = $this->getJson('/api/lessons');
        $this->assertNotContains(
            $lessonId,
            array_column($listFinal->json('data.data') ?? $listFinal->json('data'), 'id'),
            'Un cours dépublié doit redevenir invisible pour les étudiants'
        );
        $this->getJson("/api/lessons/{$lessonId}")
            ->assertStatus(403);
    }

    /**
     * Un étudiant ne peut PAS créer ni publier de cours — la frontière de
     * rôle tient sur tout le parcours d'écriture.
     */
    public function test_student_cannot_create_or_publish_lessons(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/lessons', [
            'title' => 'Cours pirate',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ])->assertStatus(403);

        // Même un cours existant ne peut pas être publié par un étudiant.
        Sanctum::actingAs($this->teacher);
        $lessonId = $this->postJson('/api/lessons', [
            'title' => 'Cours légitime',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ])->json('data.id');

        Sanctum::actingAs($this->student);
        $this->postJson("/api/lessons/{$lessonId}/publish")
            ->assertStatus(403);
    }
}
