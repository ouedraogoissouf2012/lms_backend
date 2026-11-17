<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Seul l'enseignant crée une leçon (#1167, décision du user du 17/11).
 *
 * D502 (« l'admin d'école a les droits du coordinateur ») reste vrai, mais ne
 * donne pas la création : le coordinateur et l'admin d'école reçoivent 403, avec
 * le motif `lesson_creation_teacher_only` (ADR-906-01), que le front lit dans son
 * catalogue. Le même statut garde son autre sens, la classe d'une autre école,
 * SANS ce motif.
 *
 * La requête est VALIDE : sans la règle, le gestionnaire obtiendrait 201. Vrai
 * jeton, les deux types d'école.
 */
final class SeulLEnseignantCreeUneLeconTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MOTIF = 'lesson_creation_teacher_only';

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('ecoles')]
    public function test_le_coordinateur_et_l_admin_d_ecole_sont_refuses_avec_le_motif(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDuVolet($mode);
        $classe = $this->classe($ecole, '6e A');

        foreach (['coordinateur', 'admin', 'superAdmin'] as $role) {
            $this->creer($this->membre($ecole, $role), $classe)
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('reason', self::MOTIF)
                ->assertJsonPath('message', "Seul l'enseignant crée une leçon.");
        }

        self::assertSame(0, Lesson::query()->count());
    }

    /** Verrou : l'enseignant crée, comme avant. */
    #[DataProvider('ecoles')]
    public function test_l_enseignant_cree_toujours(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDuVolet($mode);
        $classe = $this->classe($ecole, '6e A');

        $this->creer($this->membre($ecole, 'enseignant'), $classe)
            ->assertCreated()
            ->assertJsonPath('data.classe_id', $classe->id);
    }

    /** Verrou : l'élève reste arrêté par la garde `personnel`, avant la requête, sans ce motif. */
    #[DataProvider('ecoles')]
    public function test_l_eleve_reste_refuse(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDuVolet($mode);

        $this->creer($this->membre($ecole, 'etudiant'), $this->classe($ecole, '6e A'))
            ->assertForbidden()
            ->assertJsonMissingPath('reason');

        self::assertSame(0, Lesson::query()->count());
    }

    /** Témoin : le second sens du 403, la classe d'une autre école, ne porte pas ce motif. */
    #[DataProvider('ecoles')]
    public function test_temoin_la_classe_d_une_autre_ecole_garde_son_refus_sans_motif(InstitutionMode $mode): void
    {
        $ecole = $this->ecoleDuVolet($mode);
        $voisine = $this->classe($this->ecole($mode), '6e B');

        $this->creer($this->membre($ecole, 'enseignant'), $voisine)
            ->assertForbidden()
            ->assertJsonMissingPath('reason');

        self::assertSame(0, Lesson::query()->count());
    }

    private function ecoleDuVolet(InstitutionMode $mode): Institution
    {
        $this->disableKlassciMiddleware();

        return $this->ecole($mode);
    }

    /** @return TestResponse<Response> */
    private function creer(User $auteur, Classe $classe): TestResponse
    {
        return $this->asTenant($auteur)->postJson('/api/lessons', [
            'title' => 'La proportionnalité',
            'type' => 'cours',
            'status' => 'draft',
            'classe_id' => $classe->id,
        ]);
    }
}
