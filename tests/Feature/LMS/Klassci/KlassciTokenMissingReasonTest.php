<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Klassci;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #806 — un compte sans jeton KLASSCI lisait « Session KLASSCI expirée.
 * Veuillez vous reconnecter. », et se reconnectait en boucle.
 *
 * ## Le défaut
 *
 * Le texte du jeton EXPIRÉ était posé sur le jeton ABSENT, sans `reason` : le
 * client ne pouvait distinguer ni l'un de l'autre, ni l'un ou l'autre d'une
 * session LMS morte. Mesuré sur le banc : un formateur d'école autonome reçoit
 * ce 401 sur `GET /api/proxy/evaluations` et `/api/proxy/me/dashboard`.
 *
 * ## Ce que ces tests figent
 *
 * - Chaque POINT DE RENDU (12 sites, cinq familles) rend 401 et
 *   `klassci_token_missing`, avec un message qui nomme KLASSCI et ne demande pas
 *   de se reconnecter.
 * - Le jeton EXPIRÉ (KLASSCI répond 401) garde `klassci_session_expired`, sur le
 *   proxy comme sur les routes LMS, où il n'en portait aucun.
 *
 * ## Les deux volets
 *
 * Aucun site ne lit `institutions.mode` : chaque cas joue une école klassci et
 * une école standalone. L'origine du compte suit la route : un compte local
 * (sans identité KLASSCI) partout où la route ne l'exige pas ; un compte qui
 * porte une identité mais pas de jeton là où l'identité est vérifiée avant
 * (`authorize()` d'un propriétaire, `klassci_id` d'un élève).
 *
 * Un VRAI jeton Bearer à chaque requête, et une seule requête par méthode : le
 * garde mémoïse l'utilisateur du premier appel.
 */
final class KlassciTokenMissingReasonTest extends TestCase
{
    use ActsAsTenantUser, RefreshDatabase;

    /**
     * Le message, tel que le client le reçoit. Écrit en dur : le comparer à la
     * constante du code ne prouverait rien si la constante redevenait fausse.
     */
    private const MESSAGE = 'Aucun accès KLASSCI n\'est enregistré pour ce compte : '
        .'ces données, qui proviennent de KLASSCI, ne peuvent pas être affichées.';

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => [InstitutionMode::Klassci];
        yield 'ecole standalone' => [InstitutionMode::Standalone];
    }

    // ───────────────────────── proxy : ResolvesPersonalKlassciToken

    #[DataProvider('volets')]
    public function test_proxy_evaluations_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $compte = $this->compteSansJeton($mode, 'enseignant');

        $this->assertJetonAbsent($this->asTenant($compte)->getJson('/api/proxy/evaluations'));
    }

    #[DataProvider('volets')]
    public function test_proxy_tableau_de_bord_eleve_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $compte = $this->compteSansJeton($mode, 'etudiant');

        $this->assertJetonAbsent($this->asTenant($compte)->getJson('/api/proxy/me/dashboard'));
    }

    // ───────────────────────── routes LMS : RendersKlassciBackedErrors

    #[DataProvider('volets')]
    public function test_mes_matieres_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $compte = $this->compteSansJeton($mode, 'enseignant');

        $this->assertJetonAbsent($this->asTenant($compte)->getJson('/api/lms/teacher/my-matieres'));
    }

    // ───────────────────────── services { status, payload }

    #[DataProvider('volets')]
    public function test_details_de_classe_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $compte = $this->compteSansJeton($mode, 'enseignant');

        $this->assertJetonAbsent($this->asTenant($compte)->getJson('/api/lms/classes/42'));
    }

    #[DataProvider('volets')]
    public function test_eleves_de_classe_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $compte = $this->compteSansJeton($mode, 'enseignant');

        $this->assertJetonAbsent($this->asTenant($compte)->getJson('/api/lms/classes/42/etudiants'));
    }

    #[DataProvider('volets')]
    public function test_resultats_par_classe_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        // `authorize()` exige le propriétaire : l'enseignant porte son identité.
        $enseignant = $this->compteIdentifieSansJeton($mode, 'enseignant');
        $evaluation = $this->evaluationDe($enseignant);

        $this->assertJetonAbsent(
            $this->asTenant($enseignant)->getJson("/api/evaluations/{$evaluation->id}/results-by-class")
        );
    }

    // ───────────────────────── contrôleurs d'évaluation

    #[DataProvider('volets')]
    public function test_synchroniser_vers_klassci_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $enseignant = $this->compteIdentifieSansJeton($mode, 'enseignant');
        $evaluation = $this->evaluationDe($enseignant);

        $this->assertJetonAbsent(
            $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")
        );
    }

    #[DataProvider('volets')]
    public function test_demarrer_une_evaluation_dit_le_jeton_absent(InstitutionMode $mode): void
    {
        $eleve = $this->compteIdentifieSansJeton($mode, 'etudiant');
        // Publiée, avec une question : les refus 404 et 422 passent AVANT le jeton.
        $evaluation = $this->evaluationDe($eleve, [
            'status' => 'planifiee',
            'is_published' => true,
            'date_evaluation' => now()->addDays(3),
            'is_online' => true,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $eleve->institution_id,
            'type' => 'qcm',
        ]);

        $this->assertJetonAbsent($this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start"));
    }

    // ───────────────────────── élève sans identité KLASSCI

    #[DataProvider('volets')]
    public function test_mes_evaluations_sans_identite_klassci(InstitutionMode $mode): void
    {
        $eleve = $this->compteLocal($mode, 'etudiant');

        $this->assertJetonAbsent($this->asTenant($eleve)->getJson('/api/evaluations/student'));
    }

    #[DataProvider('volets')]
    public function test_mes_evaluations_identite_sans_jeton(InstitutionMode $mode): void
    {
        // L'identité passe la garde du contrôleur ; c'est le SERVICE qui lève.
        $eleve = $this->compteIdentifieSansJeton($mode, 'etudiant');

        $this->assertJetonAbsent($this->asTenant($eleve)->getJson('/api/evaluations/student'));
    }

    #[DataProvider('volets')]
    public function test_mes_notes_sans_identite_klassci(InstitutionMode $mode): void
    {
        // Le refus est rendu DANS le `try` : il doit sortir tel quel, pas en 500.
        $eleve = $this->compteLocal($mode, 'etudiant');

        $this->assertJetonAbsent($this->asTenant($eleve)->getJson('/api/my-grades'));
    }

    #[DataProvider('volets')]
    public function test_ma_copie_sans_identite_klassci(InstitutionMode $mode): void
    {
        // Même garde, elle aussi DANS un `try`.
        $eleve = $this->compteLocal($mode, 'etudiant');

        $this->assertJetonAbsent($this->asTenant($eleve)->getJson('/api/evaluations/1/my-submission'));
    }

    // ───────────────────────── le jeton EXPIRÉ reste distinct

    #[DataProvider('volets')]
    public function test_jeton_expire_sur_le_proxy_dit_session_expiree(InstitutionMode $mode): void
    {
        $compte = $this->compteAvecJeton($mode, 'enseignant');
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEvaluations')->andThrow(new RuntimeException('Erreur API KLASSCI: 401', 401));
        });

        $this->asTenant($compte)->getJson('/api/proxy/evaluations')
            ->assertStatus(401)
            ->assertJsonPath('reason', 'klassci_session_expired');
    }

    #[DataProvider('volets')]
    public function test_jeton_expire_sur_une_route_lms_dit_session_expiree(InstitutionMode $mode): void
    {
        $compte = $this->compteAvecJeton($mode, 'enseignant');
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new RuntimeException('Erreur API KLASSCI: 401', 401));
        });

        $this->asTenant($compte)->getJson('/api/lms/teacher/my-matieres')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Accès refusé par KLASSCI pour ce compte.',
                'reason' => 'klassci_session_expired',
            ]);
    }

    // ───────────────────────── la session LMS morte : AUCUN motif

    /** @return iterable<string, array{0: string}> */
    public static function routesKlassci(): iterable
    {
        yield 'proxy' => ['/api/proxy/evaluations'];
        yield 'lms' => ['/api/lms/teacher/my-matieres'];
    }

    /**
     * La troisième cause d'un 401 (spec `KlassciUnauthorized`) : sans `reason`, le
     * client sait que c'est la session LMS qui est morte, et non KLASSCI. Sans
     * compte, il n'y a pas de volet à jouer.
     */
    #[DataProvider('routesKlassci')]
    public function test_sans_session_lms_le_401_ne_porte_aucun_motif(string $url): void
    {
        $this->getJson($url)
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    // ───────────────────────── harnais

    private function assertJetonAbsent(TestResponse $reponse): void
    {
        $reponse->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::MESSAGE,
            'reason' => 'klassci_token_missing',
        ]);

        // Invariantes du message, indépendantes de sa formulation exacte :
        // - « KLASSCI » : hors `/proxy/`, le front ne protège un 401 de la
        //   déconnexion qu'en lisant `/klassci/i` dans le message ;
        // - jamais « reconnect » : se reconnecter ne pose aucun jeton à un compte
        //   local (`LoginOrchestrator::refreshLinkedKlassciLogin`).
        $message = (string) $reponse->json('message');
        self::assertMatchesRegularExpression('/klassci/i', $message);
        self::assertStringNotContainsStringIgnoringCase('reconnect', $message);
    }

    /** Volet klassci : compte relié au jeton vide ; volet standalone : compte local. */
    private function compteSansJeton(InstitutionMode $mode, string $role): User
    {
        return $mode === InstitutionMode::Klassci
            ? $this->compteIdentifieSansJeton($mode, $role)
            : $this->compteLocal($mode, $role);
    }

    /** Compte sans AUCUNE identité KLASSCI : créé dans le LMS. */
    private function compteLocal(InstitutionMode $mode, string $role): User
    {
        return $this->compte($mode, $role, [
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_token' => null,
            'klassci_data' => null,
        ]);
    }

    /** Compte qui porte une identité KLASSCI, mais aucun jeton. */
    private function compteIdentifieSansJeton(InstitutionMode $mode, string $role): User
    {
        return $this->compte($mode, $role, ['klassci_token' => null]);
    }

    private function compteAvecJeton(InstitutionMode $mode, string $role): User
    {
        return $this->compte($mode, $role, ['klassci_token' => 'jeton-klassci-806']);
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(InstitutionMode $mode, string $role, array $attributs): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(),
            'role' => $role,
        ], $attributs));
    }

    /**
     * Évaluation KLASSCI de l'école du compte, possédée par lui s'il enseigne.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function evaluationDe(User $compte, array $attributs = []): Evaluation
    {
        return Evaluation::factory()->create(array_merge([
            'institution_id' => $compte->institution_id,
            'klassci_enseignant_id' => $compte->klassci_enseignant_id,
            'klassci_evaluation_id' => 8060,
            'klassci_classe_id' => 806,
        ], $attributs));
    }
}
