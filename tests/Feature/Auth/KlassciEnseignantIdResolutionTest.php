<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Résolution de `klassci_enseignant_id` au sync (incident « enseignant KLASSCI
 * non synchronisé » → 403 à la création d'évaluation).
 *
 * Cause : certains tenants n'exposent PAS `enseignant_id` dans `auth/me` (ils
 * renvoient `enseignant_data` sans id) → le champ restait null → 403. Le
 * synchronizer retombe désormais sur `klassci_id` pour les enseignants, et
 * auto-répare les comptes existants au login (write-once-IF-NULL).
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php (resolveKlassciEnseignantId, healEnseignantIdIfNull)
 */
final class KlassciEnseignantIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralise les appels HTTP sortants (sync classes étudiant / matières prof).
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    private function synchronizer(): KlassciUserSynchronizer
    {
        return app(KlassciUserSynchronizer::class);
    }

    public function test_nouvel_enseignant_sans_enseignant_id_retombe_sur_klassci_id(): void
    {
        // LE cas de l'incident : KLASSCI ne fournit pas `enseignant_id`.
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $synced = $this->synchronizer()->sync(
            ['id' => 9, 'nom' => 'PROF BEDE', 'email' => 'prof.bede@school.edu', 'role' => 'enseignant'],
            'token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame(9, $synced->fresh()->klassci_enseignant_id);
    }

    public function test_enseignant_id_dedie_est_prioritaire_sur_le_fallback(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $synced = $this->synchronizer()->sync(
            ['id' => 9, 'enseignant_id' => 500, 'nom' => 'PROF', 'email' => 'p@school.edu', 'role' => 'enseignant'],
            'token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame(500, $synced->fresh()->klassci_enseignant_id);
    }

    public function test_enseignant_existant_a_null_est_repare_au_login(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);
        User::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 22,
            'email' => 'prof.existant@school.edu',
            'role' => 'enseignant',
            'klassci_enseignant_id' => null,
        ]);

        $synced = $this->synchronizer()->sync(
            ['id' => 22, 'nom' => 'PROF', 'email' => 'prof.existant@school.edu', 'role' => 'enseignant'],
            'token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame(22, $synced->fresh()->klassci_enseignant_id);
    }

    public function test_valeur_etablie_jamais_reecrite_invariant_119(): void
    {
        // Sécurité : un id déjà posé ne doit JAMAIS être écrasé, même si KLASSCI
        // pousse un enseignant_id différent (KLASSCI compromis).
        $institution = Institution::factory()->create(['slug' => 'school-a']);
        User::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 22,
            'email' => 'prof@school.edu',
            'role' => 'enseignant',
            'klassci_enseignant_id' => 777,
        ]);

        $synced = $this->synchronizer()->sync(
            ['id' => 22, 'enseignant_id' => 999, 'nom' => 'PROF', 'email' => 'prof@school.edu', 'role' => 'enseignant'],
            'token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame(777, $synced->fresh()->klassci_enseignant_id);
    }

    public function test_etudiant_sans_enseignant_id_reste_null(): void
    {
        // Un non-enseignant ne reçoit pas de klassci_enseignant_id (pas de création
        // d'éval — comportement #119 préservé).
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $synced = $this->synchronizer()->sync(
            ['id' => 50, 'nom' => 'ELEVE', 'email' => 'eleve@school.edu', 'role' => 'etudiant'],
            'token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertNull($synced->fresh()->klassci_enseignant_id);
    }
}
