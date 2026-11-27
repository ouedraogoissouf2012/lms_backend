<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Quiz;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1653 (PR B) — le programme d'une classe se modifie, et une matière s'en retire.
 *
 * `PATCH /classes/{c}/matieres/{m}` : le formateur (de l'école, enseignant ; nul le retire) et le
 * coefficient propre à la classe ; seul un champ envoyé change. `DELETE` : refusé (409
 * `subject_in_use_in_class`) tant qu'une leçon, séance, évaluation, quiz ou discussion de CETTE
 * école renvoie au couple, même supprimée en douceur.
 *
 * Vrai jeton (`asTenant`), les deux volets : école KLASSCI 403, objet d'une autre école 404.
 */
final class LeProgrammeDUneClasseSeModifieTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_le_formateur_et_le_coefficient_de_classe_se_modifient(): void
    {
        [$gestion, $ecole, $classe, $matiere] = $this->programme();
        $formateur = $this->membre($ecole, 'enseignant');

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['enseignant_id' => $formateur->id, 'coefficient' => 4])
            ->assertOk()
            ->assertJsonPath('data.enseignant_id', $formateur->id)
            ->assertJsonPath('data.coefficient', 4);
        self::assertSame([$formateur->id, 4], $this->pivot($classe, $matiere));
    }

    public function test_un_champ_absent_garde_sa_valeur_et_nul_retire(): void
    {
        [$gestion, $ecole, $classe, $matiere] = $this->programme();
        $formateur = $this->membre($ecole, 'enseignant');
        $classe->matieres()->updateExistingPivot($matiere->id, ['enseignant_id' => $formateur->id, 'coefficient' => 3]);

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['coefficient' => 5])->assertOk();
        self::assertSame([$formateur->id, 5], $this->pivot($classe, $matiere));

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['enseignant_id' => null, 'coefficient' => null])->assertOk();
        self::assertSame([null, null], $this->pivot($classe, $matiere));
    }

    public function test_un_formateur_hors_de_l_ecole_ou_qui_n_enseigne_pas_est_refuse(): void
    {
        [$gestion, $ecole, $classe, $matiere] = $this->programme();
        $ailleurs = $this->membre(Institution::factory()->create(['mode' => InstitutionMode::Standalone]), 'enseignant');
        $eleve = $this->membre($ecole, 'etudiant');

        foreach ([$ailleurs, $eleve] as $intrus) {
            $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['enseignant_id' => $intrus->id])->assertNotFound();
        }
        self::assertSame([null, null], $this->pivot($classe, $matiere));
    }

    public function test_un_coefficient_hors_bornes_est_refuse(): void
    {
        [$gestion, , $classe, $matiere] = $this->programme();

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['coefficient' => 21])
            ->assertStatus(422)->assertJsonValidationErrors(['coefficient']);
    }

    public function test_une_matiere_libre_se_retire_de_la_classe(): void
    {
        [$gestion, , $classe, $matiere] = $this->programme();

        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))->assertOk();
        self::assertFalse($classe->matieres()->whereKey($matiere->id)->exists());
        self::assertNotNull($matiere->fresh(), 'Retirer du programme ne supprime pas la matière.');
    }

    /** @param class-string<Model> $modele */
    #[DataProvider('traces')]
    public function test_une_matiere_employee_dans_la_classe_ne_se_retire_pas(string $modele, string $nom): void
    {
        [$gestion, $ecole, $classe, $matiere] = $this->programme();
        $this->trace($modele, $ecole, $classe, $matiere);

        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'subject_in_use_in_class')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, "1 {$nom}"));
        self::assertTrue($classe->matieres()->whereKey($matiere->id)->exists());
    }

    /** @return array<string, array{0: class-string<Model>, 1: string}> */
    public static function traces(): array
    {
        return [
            'une leçon' => [Lesson::class, 'leçon(s)'],
            'une séance' => [Seance::class, 'séance(s)'],
            'une évaluation' => [Evaluation::class, 'évaluation(s)'],
            'un quiz' => [Quiz::class, 'quiz'],
            'une discussion' => [ForumTopic::class, 'discussion(s)'],
        ];
    }

    public function test_une_lecon_supprimee_en_douceur_garde_la_matiere_au_programme(): void
    {
        [$gestion, $ecole, $classe, $matiere] = $this->programme();
        $this->trace(Lesson::class, $ecole, $classe, $matiere)->delete();

        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))->assertStatus(409);
    }

    public function test_la_trace_d_une_autre_ecole_aux_memes_numeros_ne_bloque_pas(): void
    {
        [$gestion, , $classe, $matiere] = $this->programme();
        $this->trace(Lesson::class, Institution::factory()->create(['mode' => InstitutionMode::Klassci]), $classe, $matiere);

        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))->assertOk();
    }

    public function test_un_couple_absent_du_programme_est_introuvable(): void
    {
        [$gestion, $ecole, $classe] = $this->programme();
        $horsProgramme = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        $this->asTenant($gestion)->patchJson($this->url($classe, $horsProgramme), ['coefficient' => 2])->assertNotFound();
        $this->asTenant($gestion)->deleteJson($this->url($classe, $horsProgramme))->assertNotFound();
    }

    public function test_la_classe_d_une_autre_ecole_est_introuvable(): void
    {
        [$gestion] = $this->programme();
        [, , $classe, $matiere] = $this->programme();

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['coefficient' => 2])->assertNotFound();
        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))->assertNotFound();
        self::assertTrue($classe->matieres()->whereKey($matiere->id)->exists());
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        [$gestion, , $classe, $matiere] = $this->programme(InstitutionMode::Klassci);

        $this->asTenant($gestion)->patchJson($this->url($classe, $matiere), ['coefficient' => 2])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->asTenant($gestion)->deleteJson($this->url($classe, $matiere))
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertTrue($classe->matieres()->whereKey($matiere->id)->exists());
    }

    /** @return array{0: User, 1: Institution, 2: Classe, 3: Matiere} */
    private function programme(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $matiere = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $classe->matieres()->attach($matiere->id, ['institution_id' => $ecole->id]);

        return [$this->membre($ecole, 'superAdmin'), $ecole, $classe, $matiere];
    }

    private function membre(Institution $ecole, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id, 'role' => $role,
            'klassci_token' => null, 'klassci_id' => null, 'klassci_enseignant_id' => null,
        ]);
    }

    /** @param class-string<Model> $modele */
    private function trace(string $modele, Institution $ecole, Classe $classe, Matiere $matiere): Model
    {
        $fabrique = $modele::factory();
        self::assertInstanceOf(Factory::class, $fabrique);
        $trace = $fabrique->create(['institution_id' => $ecole->id, 'classe_id' => $classe->id, 'matiere_id' => $matiere->id]);
        self::assertInstanceOf(Model::class, $trace);

        return $trace;
    }

    private function url(Classe $classe, Matiere $matiere): string
    {
        return "/api/classes/{$classe->id}/matieres/{$matiere->id}";
    }

    /** @return array{0: int|null, 1: int|null} */
    private function pivot(Classe $classe, Matiere $matiere): array
    {
        $ligne = DB::table('classe_matiere')->where('classe_id', $classe->id)->where('matiere_id', $matiere->id)->first();

        return [
            is_numeric($ligne?->enseignant_id) ? (int) $ligne->enseignant_id : null,
            is_numeric($ligne?->coefficient) ? (int) $ligne->coefficient : null,
        ];
    }
}
