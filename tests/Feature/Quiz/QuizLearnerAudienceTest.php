<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #941 — la liste et le détail des quiz suivent la portée apprenant par défaut :
 * seul le personnel reconnu (`isStaff()`) voit un quiz indisponible (brouillon,
 * pas encore ouvert). Un rôle non reconnu, y compris une variante de casse d'un
 * rôle à privilège, reçoit la vue apprenant.
 *
 * Vrai jeton porteur, deux volets, sans lire le mode.
 *
 * @see app/Services/Quiz/QuizCrudService.php
 */
final class QuizLearnerAudienceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Quiz $disponible;

    private Quiz $brouillon;

    private Quiz $pasEncoreOuvert;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function horsPersonnel(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            foreach (['etudiant', 'Etudiant', 'eleve', 'SuperAdmin', 'Supradmin', 'ADMIN', 'Enseignant', ''] as $role) {
                yield "« $role », école $volet" => [$volet, $role];
            }
        }
    }

    #[DataProvider('horsPersonnel')]
    public function test_hors_personnel_seuls_les_quiz_disponibles(string $volet, string $role): void
    {
        $compte = $this->ecole($volet, $role);

        $ids = array_column($this->asTenant($compte)->getJson('/api/quizzes?per_page=50')->assertOk()->json('data.data'), 'id');
        self::assertSame([$this->disponible->id], $ids);

        $this->asTenant($compte)->getJson("/api/quizzes/{$this->disponible->id}")->assertOk();
        $this->asTenant($compte)->getJson("/api/quizzes/{$this->brouillon->id}")->assertForbidden();
        $this->asTenant($compte)->getJson("/api/quizzes/{$this->pasEncoreOuvert->id}")->assertForbidden();
    }

    /** @return iterable<string, array{0: string}> */
    public static function personnel(): iterable
    {
        foreach (['enseignant', 'coordinateur', 'admin', 'superAdmin'] as $role) {
            yield "« $role »" => [$role];
        }
    }

    #[DataProvider('personnel')]
    public function test_le_personnel_voit_aussi_les_quiz_indisponibles(string $role): void
    {
        $compte = $this->ecole('klassci', $role);

        $ids = array_column($this->asTenant($compte)->getJson('/api/quizzes?per_page=50')->assertOk()->json('data.data'), 'id');
        sort($ids);
        $attendus = [$this->disponible->id, $this->brouillon->id, $this->pasEncoreOuvert->id];
        sort($attendus);
        self::assertSame($attendus, $ids);

        $this->asTenant($compte)->getJson("/api/quizzes/{$this->brouillon->id}")->assertOk();
    }

    private function ecole(string $volet, string $role): User
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';
        $ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        $base = ['institution_id' => $ecole->id, 'created_by' => $auteur->id];
        $this->disponible = Quiz::factory()->create($base + ['status' => 'published', 'published_at' => now()->subDay()]);
        $this->brouillon = Quiz::factory()->create($base + ['status' => 'draft', 'published_at' => null]);
        $this->pasEncoreOuvert = Quiz::factory()->create($base + [
            'status' => 'published',
            'published_at' => now()->subDay(),
            'available_from' => now()->addWeek(),
        ]);

        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_id' => $standalone ? null : 910001,
        ]);
    }
}
