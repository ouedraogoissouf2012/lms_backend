<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1707 — chaque 404 du rattachement d'une matière au programme d'une classe dit CE QUI manque.
 *
 * `POST /api/classes/{classe}/matieres` (LocalClasseMatiereLinker) répondait 404 dans trois cas, sans motif :
 * l'écran (front#654) ne pouvait dire lequel. Les motifs sont les identifiants de contrat GLOBAUX déjà émis pour la
 * même situation par la séance locale (AttributsDeSeanceLocale) : `class_out_of_scope`, `subject_out_of_scope`, et,
 * pour le formateur, `teacher_out_of_scope`.
 *
 * Un formateur d'une autre école et un compte de l'école qui n'est pas enseignant restent INDISCERNABLES : le même
 * corps, mot pour mot. Dire « ce compte existe mais n'enseigne pas » divulguerait l'existence d'un compte.
 * Sous vrai jeton (jamais `actingAs`), et chaque corps suit `docs/openapi.yaml`.
 */
final class LinkClasseMatiereMotifsApiTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const SPEC = '/classes/{classe}/matieres';

    public function test_une_classe_d_une_autre_ecole_dit_class_out_of_scope(): void
    {
        [$admin] = $this->ecole();
        [, $classeAilleurs] = $this->ecole();
        [, , $matiere] = $this->ecoleDe($admin);

        $this->refusAttendu($this->rattacher($admin, $classeAilleurs->getKey(), $matiere->getKey()), 'class_out_of_scope', 'Classe introuvable dans cet établissement.');
    }

    public function test_une_classe_inexistante_dit_le_meme_motif(): void
    {
        [$admin, , $matiere] = $this->ecole();

        $this->refusAttendu($this->rattacher($admin, 999999, $matiere->getKey()), 'class_out_of_scope', 'Classe introuvable dans cet établissement.');
    }

    public function test_une_matiere_d_une_autre_ecole_dit_subject_out_of_scope(): void
    {
        [$admin, $classe] = $this->ecole();
        [, , $matiereAilleurs] = $this->ecole();

        $this->refusAttendu($this->rattacher($admin, $classe->getKey(), $matiereAilleurs->getKey()), 'subject_out_of_scope', 'Matière introuvable dans cet établissement.');
    }

    public function test_un_formateur_d_une_autre_ecole_dit_teacher_out_of_scope(): void
    {
        [$admin, $classe, $matiere] = $this->ecole();
        $etranger = $this->compte($this->ecole()[0]->institution_id, 'enseignant');

        $this->refusAttendu($this->rattacher($admin, $classe->getKey(), $matiere->getKey(), $etranger->getKey()), 'teacher_out_of_scope', 'Formateur introuvable dans cet établissement.');
    }

    public function test_un_compte_de_l_ecole_qui_n_enseigne_pas_est_indiscernable_d_un_etranger(): void
    {
        [$admin, $classe, $matiere] = $this->ecole();
        $etudiant = $this->compte($admin->institution_id, 'etudiant');
        $etranger = $this->compte($this->ecole()[0]->institution_id, 'enseignant');

        $duCompteDeLEcole = $this->rattacher($admin, $classe->getKey(), $matiere->getKey(), $etudiant->getKey());
        $dEtranger = $this->rattacher($admin, $classe->getKey(), $matiere->getKey(), $etranger->getKey());

        $this->refusAttendu($duCompteDeLEcole, 'teacher_out_of_scope', 'Formateur introuvable dans cet établissement.');
        self::assertSame($dEtranger->json(), $duCompteDeLEcole->json(), 'Le corps distinguerait un compte existant de l\'école.');
    }

    // ───────────────────────────────── harnais

    private function refusAttendu(TestResponse $reponse, string $motif, string $message): void
    {
        $reponse->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => $message, 'reason' => $motif]);
        $this->assertMatchesOpenApiResponse($reponse, self::SPEC, 'post');
        self::assertSame(0, DB::table('classe_matiere')->count(), 'Un refus ne doit rien écrire.');
    }

    /**
     * Une école autonome, son admin (vrai compte), une classe et une matière locales.
     *
     * @return array{0: User, 1: Classe, 2: Matiere}
     */
    private function ecole(): array
    {
        $institution = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        return [
            $this->compte($institution->getKey(), 'admin'),
            Classe::factory()->create(['institution_id' => $institution->getKey(), 'klassci_id' => null]),
            Matiere::factory()->create(['institution_id' => $institution->getKey(), 'klassci_id' => null]),
        ];
    }

    /**
     * Une classe et une matière de l'école d'un compte déjà créé.
     *
     * @return array{0: User, 1: Classe, 2: Matiere}
     */
    private function ecoleDe(User $admin): array
    {
        return [
            $admin,
            Classe::factory()->create(['institution_id' => $admin->institution_id, 'klassci_id' => null]),
            Matiere::factory()->create(['institution_id' => $admin->institution_id, 'klassci_id' => null]),
        ];
    }

    private function compte(mixed $institutionId, string $role): User
    {
        return User::factory()->create(['institution_id' => $institutionId, 'role' => $role]);
    }

    private function rattacher(User $admin, int $classeId, int $matiereId, ?int $enseignantId = null): TestResponse
    {
        $jeton = $admin->createToken('programme-1707')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$jeton])
            ->postJson("/api/classes/{$classeId}/matieres", array_filter(
                ['matiere_id' => $matiereId, 'enseignant_id' => $enseignantId],
                static fn (?int $v): bool => $v !== null,
            ));
    }
}
