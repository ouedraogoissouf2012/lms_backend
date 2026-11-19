<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1206 — l'école lit ses Programmes, et chaque Période rend le titre du sien.
 *
 * Le front faisait saisir le NUMÉRO d'un programme et affichait « Programme n° 12 » :
 * aucune route ne listait les Programmes, et la Période ne rendait que `program_id`.
 *
 * Les deux volets : école autonome (la liste, bornée à l'établissement) et école
 * KLASSCI (la porte du catalogue refuse, #1184). Vrai jeton porteur : l'isolation se
 * prouve hors de `Sanctum::actingAs`, qui effacerait le scope.
 */
final class ProgrammesDeLEcoleTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMMES = '/api/programs';

    public function test_l_ecole_ne_lit_que_ses_programmes_par_titre_puis_id(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $dessinA = $this->programme($ecole, 'Dessin');
        $bureautique = $this->programme($ecole, 'Bureautique');
        $dessinB = $this->programme($ecole, 'Dessin');
        $this->programme(Institution::factory()->standalone()->create(), 'Anglais');

        $reponse = $this->withToken($this->jeton($this->responsable($ecole)))->getJson(self::PROGRAMMES)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        self::assertSame([$bureautique->id, $dessinA->id, $dessinB->id], array_column((array) $reponse->json('data'), 'id'));
    }

    public function test_un_programme_a_exactement_la_forme_de_sa_creation(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $programme = $this->programme($ecole, 'Bureautique');

        $this->withToken($this->jeton($this->responsable($ecole)))->getJson(self::PROGRAMMES)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [['id' => $programme->id, 'titre' => 'Bureautique', 'version' => 1]],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1],
            ]);
    }

    public function test_un_programme_supprime_n_est_plus_propose(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $this->programme($ecole, 'Bureautique')->delete();

        $this->withToken($this->jeton($this->responsable($ecole)))->getJson(self::PROGRAMMES)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_une_taille_hors_bornes_est_refusee(): void
    {
        $ecole = Institution::factory()->standalone()->create();

        $this->withToken($this->jeton($this->responsable($ecole)))->getJson(self::PROGRAMMES.'?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_la_periode_creee_porte_le_titre_de_son_programme(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $programme = $this->programme($ecole, 'Bureautique');

        $this->withToken($this->jeton($this->responsable($ecole)))
            ->postJson('/api/training-sessions', ['program_id' => $programme->id, 'libelle' => 'Promotion de janvier'])
            ->assertCreated()
            ->assertJsonPath('data.program_id', $programme->id)
            ->assertJsonPath('data.program', ['id' => $programme->id, 'titre' => 'Bureautique']);
    }

    /** Un Programme supprimé en douceur reste l'histoire de sa Période : son titre est rendu. */
    public function test_la_liste_rend_le_titre_meme_d_un_programme_supprime(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $programme = $this->programme($ecole, 'Comptabilité');
        TrainingSession::factory()->create(['institution_id' => $ecole->id, 'program_id' => $programme->id]);
        $programme->delete();

        $this->withToken($this->jeton($this->responsable($ecole)))->getJson('/api/training-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.program', ['id' => $programme->id, 'titre' => 'Comptabilité']);
    }

    /** École KLASSCI : la porte du catalogue refuse la liste, comme tout le reste des sessions. */
    public function test_ecole_klassci_la_liste_des_programmes_est_refusee(): void
    {
        $ecole = Institution::factory()->create();
        $this->programme($ecole, 'Bureautique');

        $this->withToken($this->jeton($this->responsable($ecole)))->getJson(self::PROGRAMMES)
            ->assertForbidden()
            ->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    private function programme(Institution $ecole, string $titre): Program
    {
        return Program::factory()->create(['institution_id' => $ecole->id, 'titre' => $titre, 'version' => 1]);
    }

    private function responsable(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }

    private function jeton(User $user): string
    {
        return $user->createToken('programmes-1206')->plainTextToken;
    }
}
