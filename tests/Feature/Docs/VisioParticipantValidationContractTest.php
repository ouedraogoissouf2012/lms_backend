<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeance;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `POST /lms/seances/{seanceId}/validate-participant` : chaque
 * réponse RÉELLE suit ce que `docs/openapi.yaml` en dit, statut et corps.
 *
 * La décision ne lit que des données locales, aucun jeton KLASSCI n'est
 * exigé : les deux volets tournent. Le 403
 * a plusieurs formes sous un même schéma fermé (pas de `oneOf` dans l'outillage
 * de contrat) : chaque motif est donc prouvé par `assertJsonPath`. Vrai jeton
 * porteur (`asTenant`).
 *
 * @see app/Services/Seances/Mutations/ParticipantValidationService.php
 */
final class VisioParticipantValidationContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeance;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances/{seanceId}/validate-participant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function ciblesAdmises(): iterable
    {
        yield 'élève inscrit' => ['eleve', 'student'];
        yield 'enseignant de la séance' => ['enseignant', 'teacher'];
        yield 'coordinateur' => ['coordinateur', 'moderator'];
        yield 'admin' => ['admin', 'moderator'];
    }

    #[DataProvider('ciblesAdmises')]
    public function test_200_l_acces_est_accorde(string $cible, string $role): void
    {
        $ecole = $this->ecole(false);
        $seance = $this->visio($ecole);
        $vise = match ($cible) {
            'eleve' => $this->eleveInscrit($ecole),
            'enseignant' => $this->enseignantDeLaSeance($ecole),
            default => $this->compte($ecole, $cible),
        };

        $reponse = $this->valider($this->compte($ecole, 'coordinateur'), $seance->id, $vise->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'authorized');
        $reponse->assertJsonPath('authorized', true)->assertJsonPath('role', $role)->assertJsonStructure(['message']);
    }

    /** L'enseignant de la séance valide un de ses élèves, et la séance se désigne par son identifiant KLASSCI. */
    public function test_200_l_enseignant_valide_par_l_identifiant_klassci(): void
    {
        $ecole = $this->ecole(false);
        $seance = $this->visio($ecole);

        $reponse = $this->valider($this->enseignantDeLaSeance($ecole), (int) $seance->klassci_seance_id, $this->eleveInscrit($ecole)->id)
            ->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('authorized', true)->assertJsonPath('role', 'student');
    }

    /** Volet autonome : l'enseignant auteur de la séance passe. */
    public function test_200_l_enseignant_auteur_d_une_seance_autonome(): void
    {
        $ecole = $this->ecole(true);
        $auteur = $this->compte($ecole, 'enseignant');
        $seance = $this->visio($ecole, $auteur);

        $reponse = $this->valider($this->compte($ecole, 'coordinateur'), $seance->id, $auteur->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('authorized', true)->assertJsonPath('role', 'teacher');
    }

    /** Un rôle ni du personnel ni élève : un REFUS rendu en 200. */
    public function test_200_un_refus_invalid_role(): void
    {
        $ecole = $this->ecole(false);
        $seance = $this->visio($ecole);
        $parent = $this->compte($ecole, 'parent');

        $reponse = $this->valider($this->compte($ecole, 'coordinateur'), $seance->id, $parent->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(['success' => true, 'authorized' => false, 'reason' => 'invalid_role', 'user_role' => 'parent']);
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->postJson('/api/lms/seances/1/validate-participant', ['user_id' => 1])->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    public function test_401_un_jeton_porteur_invalide(): void
    {
        $reponse = $this->withToken('jeton-invalide')
            ->postJson('/api/lms/seances/1/validate-participant', ['user_id' => 1])
            ->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /**
     * Un compte supprimé en douceur passe la règle `exists` de la requête, mais
     * la relecture l'exclut : 404, sans `message`.
     */
    public function test_404_un_compte_supprime(): void
    {
        $ecole = $this->ecole(false);
        $supprime = $this->compte($ecole, 'etudiant');
        $supprime->delete();

        $reponse = $this->valider($this->compte($ecole, 'coordinateur'), $this->visio($ecole)->id, $supprime->id)->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'reason');
        $reponse->assertExactJson(['success' => false, 'authorized' => false, 'reason' => 'user_not_found']);
    }

    /** Refusé par la garde de rôle, dans les deux volets. */
    #[DataProvider('volets')]
    public function test_403_un_eleve_ne_valide_pas(bool $autonome): void
    {
        $ecole = $this->ecole($autonome);
        $eleve = $this->compte($ecole, 'etudiant');

        $reponse = $this->valider($eleve, $this->visio($ecole)->id, $eleve->id)->assertForbidden();

        $this->assertRefus($reponse, null);
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'coordinateur']);

        $this->assertRefus($this->valider($compte, 1, $compte->id)->assertForbidden(), 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);
        $coordinateur = $this->compte($ecole, 'coordinateur');

        $this->assertRefus($this->valider($coordinateur, 1, $coordinateur->id)->assertForbidden(), null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function motifsDeRefus(): iterable
    {
        foreach (['visio_not_enabled', 'visio_ended', 'actor_not_authorized', 'teacher_not_owner', 'not_enrolled'] as $motif) {
            yield $motif => [$motif];
        }
    }

    /** Chaque motif de la décision d'accès, atteint par un vrai chemin (volet KLASSCI). */
    #[DataProvider('motifsDeRefus')]
    public function test_403_la_decision_d_acces(string $motif): void
    {
        $ecole = $this->ecole(false);
        $seance = $this->visio($ecole, null, match ($motif) {
            'visio_not_enabled' => ['visio_enabled' => false],
            'visio_ended' => ['visio_status' => 'terminee'],
            default => [],
        });
        $acteur = $motif === 'actor_not_authorized'
            ? $this->compte($ecole, 'enseignant', ['klassci_enseignant_id' => 1])
            : $this->compte($ecole, 'coordinateur');
        $vise = $motif === 'teacher_not_owner'
            ? $this->compte($ecole, 'enseignant', ['klassci_enseignant_id' => 1])
            : $this->compte($ecole, 'etudiant');

        $this->assertRefus($this->valider($acteur, $seance->id, $vise->id)->assertForbidden(), $motif);
    }

    /**
     * Le vrai chemin de `no_classe_id` en école KLASSCI : la bascule efface la
     * classe de la séance, qui refuse alors ses élèves (défaut H, décrit tel quel).
     */
    public function test_403_une_seance_basculee_refuse_ses_eleves(): void
    {
        Http::fake();
        $ecole = $this->ecole(false);
        $coordinateur = $this->compte($ecole, 'coordinateur', ['klassci_token' => 'jeton-klassci']);
        $this->asTenant($coordinateur)->postJson('/api/lms/seances/4242/toggle-visio', ['enabled' => true])->assertOk();
        $seance = Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', 4242)->firstOrFail();

        $reponse = $this->valider($coordinateur, $seance->id, $this->compte($ecole, 'etudiant')->id)->assertForbidden();

        $this->assertRefus($reponse, 'no_classe_id');
    }

    /** Volet autonome : la séance n'a pas de classe KLASSCI, l'élève est refusé (défaut G, décrit tel quel). */
    public function test_403_un_eleve_d_une_seance_autonome(): void
    {
        $ecole = $this->ecole(true);
        $seance = $this->visio($ecole, $this->compte($ecole, 'enseignant'));
        $eleve = $this->compte($ecole, 'etudiant');

        $this->assertRefus($this->valider($this->compte($ecole, 'coordinateur'), $seance->id, $eleve->id)->assertForbidden(), 'no_classe_id');
    }

    /** @return iterable<string, array{0: string}> */
    public static function chargesInvalides(): iterable
    {
        yield 'user_id absent' => ['absent'];
        yield 'user_id non entier' => ['texte'];
        yield 'compte d une autre école' => ['ailleurs'];
    }

    #[DataProvider('chargesInvalides')]
    public function test_422_une_charge_invalide(string $cas): void
    {
        $ecole = $this->ecole(false);
        $charge = match ($cas) {
            'absent' => [],
            'texte' => ['user_id' => 'abc'],
            default => ['user_id' => $this->compte($this->ecole(false), 'etudiant')->id],
        };

        $reponse = $this->asTenant($this->compte($ecole, 'coordinateur'))
            ->postJson("/api/lms/seances/{$this->visio($ecole)->id}/validate-participant", $charge)
            ->assertUnprocessable();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'errors');
    }

    public function test_500_rattrape_par_le_service(): void
    {
        $this->inscriptionIllisible();
        $ecole = $this->ecole(false);

        $reponse = $this->valider($this->compte($ecole, 'coordinateur'), $this->visio($ecole)->id, $this->compte($ecole, 'etudiant')->id)
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('error', 'Une erreur est survenue.');
    }

    public function test_500_du_gestionnaire_global_sans_error(): void
    {
        $coordinateur = $this->compte($this->ecole(false), 'coordinateur');

        $reponse = $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/abc/validate-participant', ['user_id' => $coordinateur->id])
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('error');
    }

    private function valider(User $acteur, int $seanceId, int $userId): TestResponse
    {
        return $this->asTenant($acteur)->postJson("/api/lms/seances/{$seanceId}/validate-participant", ['user_id' => $userId]);
    }

    /** Le 403 suit le schéma ; son motif (ou son absence) est prouvé à part. */
    private function assertRefus(TestResponse $reponse, ?string $motif): void
    {
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');

        $motif === null ? $reponse->assertJsonMissingPath('reason') : $reponse->assertJsonPath('reason', $motif);
    }
}
