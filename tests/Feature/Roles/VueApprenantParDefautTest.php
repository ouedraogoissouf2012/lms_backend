<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\ESBTPAttendance;
use App\Models\File;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #964 : sur chaque site qui restreignait par `if (isStudent())`, la vue par défaut est
 * celle de l'apprenant (`isLearnerAudience()`, la règle de #941) : seul le personnel
 * reconnu en sort. `isStudent()` ne vaut vrai que sur un rôle RECONNU, en
 * correspondance exacte ; un rôle non reconnu (`Etudiant`, `Enseignant` à la casse
 * près) n'en est pas un.
 *
 * Les deux types d'école. Vrai jeton.
 */
final class VueApprenantParDefautTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rolesNonReconnus(): iterable
    {
        foreach (['école KLASSCI' => false, 'école du LMS' => true] as $volet => $lms) {
            yield "Etudiant, $volet" => [$lms, 'Etudiant'];
            yield "Enseignant, $volet" => [$lms, 'Enseignant'];
        }
    }

    #[DataProvider('rolesNonReconnus')]
    public function test_un_role_non_reconnu_a_la_vue_apprenant_sur_les_fichiers(bool $lms, string $role): void
    {
        $ecole = $this->ecole($lms);
        $auteur = $this->compte($ecole, 'enseignant');
        $public = File::factory()->create(['institution_id' => $ecole->id, 'user_id' => $auteur->id, 'is_public' => true]);
        File::factory()->create(['institution_id' => $ecole->id, 'user_id' => $auteur->id, 'is_public' => false]);
        $compte = $this->compte($ecole, $role);

        $ids = array_column((array) $this->asTenant($compte)->getJson('/api/files')->assertOk()->json('data.data'), 'id');

        self::assertSame([$public->id], $ids, 'les fichiers publics, et les siens');
        $this->asTenant($compte)->getJson('/api/files/stats')->assertOk()->assertJsonPath('data.total_files', 0);
    }

    #[DataProvider('rolesNonReconnus')]
    public function test_un_role_non_reconnu_a_la_vue_apprenant_sur_l_historique_des_presences(bool $lms, string $role): void
    {
        $ecole = $this->ecole($lms);
        $autre = $this->compte($ecole, 'etudiant');
        ESBTPAttendance::factory()->create(['institution_id' => $ecole->id, 'seance_id' => $this->seance($ecole)->id, 'user_id' => $autre->id]);
        $compte = $this->compte($ecole, $role);

        $this->asTenant($compte)->getJson('/api/lms/attendance/history')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    /** Témoins : l'élève reconnu garde sa vue ; la gestion garde la sienne. */
    public function test_les_roles_reconnus_gardent_leur_vue(): void
    {
        $ecole = $this->ecole(true);
        $auteur = $this->compte($ecole, 'enseignant');
        File::factory()->create(['institution_id' => $ecole->id, 'user_id' => $auteur->id, 'is_public' => false]);
        $eleve = $this->compte($ecole, 'etudiant');
        ESBTPAttendance::factory()->create(['institution_id' => $ecole->id, 'seance_id' => $this->seance($ecole)->id, 'user_id' => $eleve->id]);
        $gestion = $this->compte($ecole, 'coordinateur');

        $this->asTenant($eleve)->getJson('/api/files')->assertOk()->assertJsonCount(0, 'data.data');
        $this->asTenant($eleve)->getJson('/api/lms/attendance/history')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->asTenant($gestion)->getJson('/api/files')->assertOk()->assertJsonCount(1, 'data.data');
        $this->asTenant($gestion)->getJson('/api/lms/attendance/history')->assertOk()->assertJsonPath('pagination.total', 1);
    }

    private function ecole(bool $lms): Institution
    {
        return $lms ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => null]);
    }

    private function seance(Institution $ecole): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => null, 'klassci_enseignant_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null,
        ]);
    }
}
