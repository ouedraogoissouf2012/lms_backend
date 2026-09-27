<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Un élève ne reçoit jamais de données sur un autre élève (GHSA-gg7j).
 *
 * ## Le défaut
 *
 * Le LMS confiait à KLASSCI le soin de refuser un jeton élève (point 4 de #617,
 * jamais réalisé) : la fiche de classe et les listes de participants pouvaient
 * livrer à un élève ses camarades avec leurs données personnelles — l'une des
 * listes étant même construite depuis la base LOCALE, sans passer par KLASSCI.
 *
 * ## La règle, en un lieu par ressource
 *
 * - Les quatre routes qui n'existent que pour lister des élèves sont réservées
 *   au personnel (garde de rôle, comme `/seances/{id}/attendances`).
 * - La fiche de classe reste ouverte à l'élève (matières, planning), mais son
 *   roster est vide pour qui n'est pas du personnel — décidé dans
 *   `ClasseDetailsQueryService`, donc pour les DEUX portes (ADR-760-01).
 * - La liste des évaluations ne recopie plus l'enveloppe KLASSCI de la classe :
 *   elle ne porte que l'identité de la classe, déjà connue.
 *
 * Fail-closed : un rôle qui n'est pas du personnel est traité comme un élève.
 *
 * ## Jeton porteur RÉEL
 *
 * `Sanctum::actingAs` n'émet pas de Bearer : aucun tenant résolu, le scope
 * d'établissement saute (#709). Voir EvaluationResultsOwnershipTest.
 */
final class RosterFermeAuxElevesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CLASSE_KLASSCI = 42;

    private const SEANCE_KLASSCI = 900;

    /** Toute clé de ce nom, à n'importe quelle profondeur, est une donnée personnelle. */
    private const CLES_PERSONNELLES = ['email', 'telephone', 'matricule', 'photo_url', 'nom_complet'];

    private Institution $institution;

    private User $eleve;

    /** @var array<int, string> Endpoints KLASSCI réellement demandés pendant un appel. */
    private array $endpointsAppeles = [];

    private int $prochainKlassciId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->eleve = $this->utilisateur('etudiant', 5555);
        $this->fakeKlassci();
    }

    /** `users.klassci_id` est unique par établissement : chaque compte reçoit le sien. */
    private function utilisateur(string $role, ?int $klassciId = null): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_id' => $klassciId ?? ++$this->prochainKlassciId,
            'klassci_enseignant_id' => $role === 'enseignant' ? 71 : null,
            'klassci_token' => "jeton-{$role}",
        ]);
    }

    /** @return array<int, array<string, mixed>> Deux camarades, avec toutes les données que KLASSCI livre. */
    private function roster(): array
    {
        return [
            ['id' => 1, 'matricule' => 'M-001', 'nom_complet' => 'Kone Awa', 'nom' => 'Kone', 'prenom' => 'Awa', 'email' => 'awa@ecole.test', 'telephone' => '0700000001', 'photo_url' => 'https://k/1.jpg', 'statut' => 'actif'],
            ['id' => 2, 'matricule' => 'M-002', 'nom_complet' => 'Diallo Ba', 'nom' => 'Diallo', 'prenom' => 'Ba', 'email' => 'ba@ecole.test', 'telephone' => '0700000002', 'photo_url' => 'https://k/2.jpg', 'statut' => 'actif'],
        ];
    }

    /** @return array<string, mixed> */
    private function reponseKlassci(string $endpoint): array
    {
        return match (true) {
            $endpoint === 'me/dashboard' => ['data' => ['classe' => ['id' => self::CLASSE_KLASSCI, 'name' => 'L1 INFO']]],
            $endpoint === 'me/teacher-dashboard' => ['data' => ['matieres' => [['id' => 7]]]],
            str_starts_with($endpoint, 'classes/42/etudiants') => ['data' => $this->roster()],
            str_starts_with($endpoint, 'classes/42') => ['data' => [
                'classe' => ['id' => self::CLASSE_KLASSCI, 'nom' => 'L1 INFO', 'nombre_places' => 30],
                'etudiants' => $this->roster(),
                'matieres' => [['id' => 7, 'nom' => 'Algorithmique']],
                'emploi_temps_semaine' => [],
            ]],
            $endpoint === 'matieres' => ['data' => [['id' => 7, 'nom' => 'Algorithmique']]],
            $endpoint === 'matieres/7' => ['data' => [
                'matiere' => ['id' => 7, 'nom' => 'Algorithmique'],
                'seances_programmees' => [['id' => self::SEANCE_KLASSCI, 'classe' => ['id' => self::CLASSE_KLASSCI]]],
            ]],
            default => ['data' => []],
        };
    }

    private function fakeKlassci(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint): array {
                    $this->endpointsAppeles[] = $endpoint;

                    return $this->reponseKlassci($endpoint);
                });
            $mock->shouldReceive('getClasseEtudiants')->andReturn(['success' => true, 'data' => $this->roster()]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => []]);
            $mock->shouldReceive('getMatieres')->andReturn(['data' => []]);
            $mock->shouldReceive('getEvaluations')->andReturn(['data' => []]);
        });
    }

    /** Une séance de la classe, et ses deux élèves connus de la base LOCALE. */
    private function seanceAvecRosterLocal(): void
    {
        Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => self::SEANCE_KLASSCI,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
        ]);

        foreach ([1, 2] as $i) {
            $camarade = $this->utilisateur('etudiant', 6000 + $i);
            UserClass::create([
                'user_id' => $camarade->id,
                'klassci_classe_id' => self::CLASSE_KLASSCI,
                'institution_id' => $this->institution->id,
            ]);
        }
    }

    /** @return array<string, string> Les quatre routes qui n'existent que pour lister des élèves. */
    private function listesDEleves(): array
    {
        $c = self::CLASSE_KLASSCI;
        $s = self::SEANCE_KLASSCI;

        return [
            'lms/classes/etudiants' => "/api/lms/classes/{$c}/etudiants",
            'proxy/classes/etudiants' => "/api/proxy/classes/{$c}/etudiants",
            'seances/participants' => "/api/lms/seances/{$s}/participants",
            'seances/visio-participants' => "/api/lms/seances/{$s}/visio-participants",
        ];
    }

    /**
     * Le détecteur ne connaît pas la forme des réponses : il cherche les CLÉS.
     * Une nouvelle route qui recopierait un roster rougirait sans qu'on l'ait prévue.
     */
    private function assertAucuneDonneePersonnelle(TestResponse $reponse, string $route): void
    {
        $trouvees = [];
        $this->collecter($reponse->json(), '$', $trouvees);

        self::assertSame([], $trouvees, "La route {$route} livre des données personnelles à un élève.");
    }

    /** @param  array<int, string>  $trouvees */
    private function collecter(mixed $noeud, string $chemin, array &$trouvees): void
    {
        if (! is_array($noeud)) {
            return;
        }
        foreach ($noeud as $cle => $valeur) {
            $ici = is_int($cle) ? "{$chemin}[]" : "{$chemin}.{$cle}";
            if (is_string($cle) && in_array($cle, self::CLES_PERSONNELLES, true)) {
                $trouvees[] = $ici;
            }
            $this->collecter($valeur, $ici, $trouvees);
        }
    }

    public function test_la_fiche_de_classe_ne_livre_aucun_eleve_a_un_eleve(): void
    {
        $locale = Classe::factory()->create(['institution_id' => $this->institution->id, 'klassci_id' => self::CLASSE_KLASSCI]);

        $portes = [
            'klassci' => '/api/lms/classes/'.self::CLASSE_KLASSCI,
            'locale' => "/api/lms/classes/local/{$locale->id}",
        ];

        foreach ($portes as $porte => $url) {
            $reponse = $this->asTenant($this->eleve)->getJson($url);

            $reponse->assertOk();
            self::assertSame([], $reponse->json('data.etudiants'), "Porte {$porte} : le roster est livré à un élève.");
            // Le nombre d'élèves n'est pas une donnée sur un élève : il reste.
            self::assertSame(2, $reponse->json('data.statistiques.nombre_etudiants'), "Porte {$porte}");
            $this->assertAucuneDonneePersonnelle($reponse, $url);
        }
    }

    public function test_un_role_qui_n_est_pas_du_personnel_est_traite_comme_un_eleve(): void
    {
        $inconnu = $this->utilisateur('invite', 7777);

        $reponse = $this->asTenant($inconnu)->getJson('/api/lms/classes/'.self::CLASSE_KLASSCI);

        $reponse->assertOk();
        self::assertSame([], $reponse->json('data.etudiants'));
    }

    public function test_les_quatre_listes_d_eleves_sont_refusees_a_un_eleve(): void
    {
        $this->seanceAvecRosterLocal();

        $statuts = array_map(
            fn (string $url): int => $this->asTenant($this->eleve)->getJson($url)->status(),
            $this->listesDEleves(),
        );

        self::assertSame(array_fill_keys(array_keys($this->listesDEleves()), 403), $statuts);
    }

    public function test_les_evaluations_d_un_eleve_ne_portent_que_l_identite_de_sa_classe(): void
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'klassci_evaluation_id' => null,
            'is_published' => true,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'institution_id' => $this->institution->id]);

        $reponse = $this->asTenant($this->eleve)->getJson('/api/evaluations/student');

        $reponse->assertOk();
        self::assertCount(1, $reponse->json('data'));
        self::assertSame(['id' => self::CLASSE_KLASSCI, 'nom' => 'L1 INFO'], $reponse->json('data.0.classe'));
        $this->assertAucuneDonneePersonnelle($reponse, '/api/evaluations/student');
        // L'identité de la classe est déjà dans `me/dashboard` : aucun appel de plus.
        self::assertNotContains('classes/'.self::CLASSE_KLASSCI, $this->endpointsAppeles);
    }

    public function test_le_personnel_garde_la_fiche_complete(): void
    {
        foreach (['enseignant', 'coordinateur', 'superAdmin'] as $role) {
            $reponse = $this->asTenant($this->utilisateur($role))->getJson('/api/lms/classes/'.self::CLASSE_KLASSCI);

            $reponse->assertOk();
            self::assertCount(2, $reponse->json('data.etudiants'), "Le rôle {$role} a perdu le roster.");
        }
    }

    public function test_le_personnel_garde_les_quatre_listes(): void
    {
        $this->seanceAvecRosterLocal();

        foreach (['enseignant', 'coordinateur'] as $role) {
            $statuts = array_map(
                fn (string $url): int => $this->asTenant($this->utilisateur($role))->getJson($url)->status(),
                $this->listesDEleves(),
            );

            self::assertSame(array_fill_keys(array_keys($this->listesDEleves()), 200), $statuts, "Rôle {$role}");
        }
    }
}
