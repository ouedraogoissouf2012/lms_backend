<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #797 / #848 — le parcours COMPLET, depuis la requête HTTP.
 *
 * `Sanctum::actingAs` n'émet aucun jeton : `ResolveInstitution` ne pose alors
 * aucun tenant, le scope multi-tenant s'efface, et un test d'isolation écrit
 * ainsi est un FAUX NÉGATIF. D'où un vrai Bearer, comme en #860.
 *
 * Ce fichier garde que le refus opposé à un établissement KLASSCI tient jusqu'à
 * la RÉPONSE — c'est là que l'utilisateur le rencontre, pas dans le service.
 */
final class CreateMatiereApiTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERES = '/api/matieres';

    /**
     * @return array<string, string>
     */
    private function entete(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api-matiere-848')->plainTextToken];
    }

    private function responsable(InstitutionMode $mode, string $role = 'superAdmin'): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create(['mode' => $mode])->getKey(),
            'role' => $role,
        ]);
    }

    public function test_une_ecole_autonome_cree_sa_matiere_par_l_api(): void
    {
        $reponse = $this->withHeaders($this->entete($this->responsable(InstitutionMode::Standalone)))
            ->postJson(self::MATIERES, ['libelle' => 'Traitement de texte', 'code' => 'BUR-TXT'])
            ->assertStatus(201);

        $reponse->assertJsonPath('data.libelle', 'Traitement de texte');
        $reponse->assertJsonPath('data.coefficient', 1);

        self::assertSame(1, Matiere::query()->withoutGlobalScopes()->count());
    }

    public function test_un_etablissement_klassci_recoit_un_refus_explicite(): void
    {
        $this->withHeaders($this->entete($this->responsable(InstitutionMode::Klassci)))
            ->postJson(self::MATIERES, ['libelle' => 'Matière que KLASSCI devrait fournir'])
            ->assertStatus(403);

        self::assertSame(
            0,
            Matiere::query()->withoutGlobalScopes()->count(),
            'Un refus qui écrirait quand même serait pire qu\'aucune garde.'
        );
    }

    public function test_un_etudiant_ne_compose_pas_le_catalogue(): void
    {
        // La garde de rôle passe AVANT la capacité, dans un établissement
        // pourtant autonome : composer son catalogue est un acte de gestion.
        $this->withHeaders($this->entete($this->responsable(InstitutionMode::Standalone, 'etudiant')))
            ->postJson(self::MATIERES, ['libelle' => 'Ma propre matière'])
            ->assertStatus(403);
    }

    public function test_un_libelle_manquant_est_refuse_avant_toute_ecriture(): void
    {
        $this->withHeaders($this->entete($this->responsable(InstitutionMode::Standalone)))
            ->postJson(self::MATIERES, ['code' => 'SANS-LIBELLE'])
            ->assertStatus(422);

        self::assertSame(0, Matiere::query()->withoutGlobalScopes()->count());
    }

    public function test_la_matiere_creee_appartient_a_l_etablissement_du_jeton(): void
    {
        $responsable = $this->responsable(InstitutionMode::Standalone);

        $this->withHeaders($this->entete($responsable))
            ->postJson(self::MATIERES, ['libelle' => 'Tableur'])
            ->assertStatus(201);

        $matiere = Matiere::query()->withoutGlobalScopes()->firstOrFail();

        self::assertSame($responsable->institution_id, $matiere->institution_id);
        self::assertNull($matiere->klassci_id);
    }
}
