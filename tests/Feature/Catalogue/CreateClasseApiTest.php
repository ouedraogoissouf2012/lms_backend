<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #848 — le parcours COMPLET, depuis la requête HTTP.
 *
 * ## Pourquoi un VRAI jeton Bearer
 *
 * `Sanctum::actingAs` n'émet aucun jeton : `ResolveInstitution` ne pose alors
 * aucun tenant, le scope multi-tenant s'efface, et un test d'isolation écrit
 * ainsi est un FAUX NÉGATIF — il passerait même si la fuite existait.
 *
 * ## Ce que ce fichier garde
 *
 * Que le refus opposé à un établissement KLASSCI tient jusqu'à la RÉPONSE, et
 * pas seulement au niveau du service : c'est là que l'utilisateur le rencontre.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class CreateClasseApiTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSES = '/api/classes';

    /**
     * @return array<string, string>
     */
    private function entete(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api-classe-848')->plainTextToken];
    }

    private function responsable(InstitutionMode $mode): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create(['mode' => $mode])->getKey(),
            'role' => 'superAdmin',
        ]);
    }

    public function test_une_ecole_autonome_cree_sa_classe_par_l_api(): void
    {
        $reponse = $this->withHeaders($this->entete($this->responsable(InstitutionMode::Standalone)))
            ->postJson(self::CLASSES, ['libelle' => 'Bureautique — groupe A', 'code' => 'BUR-A'])
            ->assertStatus(201);

        $reponse->assertJsonPath('data.libelle', 'Bureautique — groupe A');
        $reponse->assertJsonPath('data.code', 'BUR-A');

        self::assertSame(1, Classe::query()->withoutGlobalScopes()->count());
    }

    public function test_un_etablissement_klassci_recoit_un_refus_explicite(): void
    {
        $this->withHeaders($this->entete($this->responsable(InstitutionMode::Klassci)))
            ->postJson(self::CLASSES, ['libelle' => 'Classe que KLASSCI devrait fournir'])
            ->assertStatus(403);

        self::assertSame(
            0,
            Classe::query()->withoutGlobalScopes()->count(),
            'Un refus qui écrirait quand même serait pire qu\'aucune garde.'
        );
    }

    public function test_un_etudiant_ne_compose_pas_le_catalogue(): void
    {
        // La garde de rôle passe AVANT la capacité : composer son catalogue est
        // un acte de gestion, dans un établissement pourtant autonome.
        $etudiant = User::factory()->create([
            'institution_id' => Institution::factory()->create([
                'mode' => InstitutionMode::Standalone,
            ])->getKey(),
            'role' => 'etudiant',
        ]);

        $this->withHeaders($this->entete($etudiant))
            ->postJson(self::CLASSES, ['libelle' => 'Ma propre classe'])
            ->assertStatus(403);
    }

    public function test_un_libelle_manquant_est_refuse_avant_toute_ecriture(): void
    {
        $this->withHeaders($this->entete($this->responsable(InstitutionMode::Standalone)))
            ->postJson(self::CLASSES, ['code' => 'SANS-LIBELLE'])
            ->assertStatus(422);

        self::assertSame(0, Classe::query()->withoutGlobalScopes()->count());
    }

    public function test_la_classe_creee_appartient_a_l_etablissement_du_jeton(): void
    {
        // L'isolation ne se déduit pas : elle se mesure sur la ligne écrite.
        $responsable = $this->responsable(InstitutionMode::Standalone);

        $this->withHeaders($this->entete($responsable))
            ->postJson(self::CLASSES, ['libelle' => 'Groupe du matin'])
            ->assertStatus(201);

        $classe = Classe::query()->withoutGlobalScopes()->firstOrFail();

        self::assertSame($responsable->institution_id, $classe->institution_id);
        self::assertNull($classe->klassci_id);
    }
}
