<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Attendances\PresenceRetenue;
use App\Services\Attendances\Suivi\FiltresDePresences;
use App\Services\Attendances\Suivi\LocalSuiviDesPresences;
use App\Services\Attendances\Suivi\TauxDePresence;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1683 (PR A, ARRÊT 1 validé le 27/11) — le suivi des présences par classe et par élève :
 * - une présence est une paire (séance PASSÉE × élève ACTIF de sa classe) ; sans ligne, l'élève est absent ;
 * - sa marque retenue est celle de {@see PresenceRetenue} (la marque l'emporte, sinon la visio), en quatre valeurs :
 *   présent, en retard, absent, excusé ; la forme requête est liée à la forme ligne par un test d'équivalence ;
 * - le taux ({@see TauxDePresence}) : (présent + retard) ÷ séances attendues, les
 *   absences justifiées hors du dénominateur ;
 * - la gestion lit la liste filtrée et la synthèse (par élève, par classe, sous un seuil, 75 par défaut) ;
 * - une école KLASSCI tient ses présences dans KLASSCI (409).
 *
 * Jeton Bearer RÉEL, par `asTenant()` ; l'isolation, aussi SANS tenant résolu.
 */
final class SuiviDesPresencesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private User $coordinateur;

    /** @var array<string, User> */
    private array $eleves = [];

    /** @var array<string, Classe> */
    private array $classes = [];

    /** @var array<string, Seance> */
    private array $seances = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-15 12:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->classes = ['A' => $this->classe($this->ecole, 'Terminale A'), 'B' => $this->classe($this->ecole, 'Terminale B')];
        foreach (['awa' => 'A', 'brice' => 'A', 'chantal' => 'B'] as $nom => $classe) {
            $this->eleves[$nom] = $this->membre($this->ecole, 'etudiant');
            $this->eleves[$nom]->forceFill(['name' => ucfirst($nom)])->save();
            $this->inscrire($this->eleves[$nom], $this->classes[$classe], 'actif');
        }
        $this->seances = [
            's1' => $this->seance('A', '2026-12-01 08:00:00'),
            's4' => $this->seance('B', '2026-12-01 10:00:00'),
            's2' => $this->seance('A', '2026-12-02 08:00:00'),
            's3' => $this->seance('A', '2026-12-03 08:00:00'),
            'future' => $this->seance('A', '2026-12-20 08:00:00'),
        ];
        $this->ligne('s1', 'awa', AttendanceMark::Present);
        $this->ligne('s1', 'brice', AttendanceMark::Absent);
        $this->ligne('s2', 'awa', AttendanceMark::Late);
        $this->ligne('s2', 'brice', AttendanceMark::Excused);
        $this->ligne('s3', 'awa', null, 'connected');
        $this->ligne('s4', 'chantal', null, 'kicked');
    }

    public function test_la_liste_des_presences_de_l_ecole(): void
    {
        $data = $this->lire('/api/lms/presences?du=2026-12-01&au=2026-12-31');

        self::assertSame([
            ['s1', 'awa', 'present'], ['s1', 'brice', 'absent'], ['s4', 'chantal', 'absent'],
            ['s2', 'awa', 'late'], ['s2', 'brice', 'excused'], ['s3', 'awa', 'present'], ['s3', 'brice', 'absent'],
        ], $this->paires($data['presences']));
        self::assertSame(7, $data['total']);
    }

    public function test_les_filtres_de_la_liste(): void
    {
        $absents = $this->lire('/api/lms/presences?du=2026-12-01&au=2026-12-31&marque=absent')['presences'];
        self::assertSame([['s1', 'brice', 'absent'], ['s4', 'chantal', 'absent'], ['s3', 'brice', 'absent']], $this->paires($absents));

        $classeB = $this->lire('/api/lms/presences?du=2026-12-01&au=2026-12-31&classe_id='.$this->classes['B']->id)['presences'];
        self::assertSame([['s4', 'chantal', 'absent']], $this->paires($classeB));

        $awa = $this->lire('/api/lms/presences?du=2026-12-02&au=2026-12-31&eleve_id='.$this->eleves['awa']->id)['presences'];
        self::assertSame([['s2', 'awa', 'late'], ['s3', 'awa', 'present']], $this->paires($awa));
    }

    public function test_la_synthese_par_eleve_par_classe_et_sous_le_seuil(): void
    {
        $data = $this->lire('/api/lms/presences/synthese?du=2026-12-01&au=2026-12-31');

        self::assertSame([
            [$this->eleves['awa']->id, 3, 1, 0, 0, 100],
            [$this->eleves['brice']->id, 0, 0, 1, 2, 0],
            [$this->eleves['chantal']->id, 0, 0, 0, 1, 0],
        ], array_map(static fn (array $e): array => [$e['eleve_id'], $e['presences'], $e['retards'], $e['absences_justifiees'], $e['absences'], $e['taux']], $data['eleves']));
        self::assertSame([[$this->classes['A']->id, 60], [$this->classes['B']->id, 0]], array_map(static fn (array $c): array => [$c['classe_id'], $c['taux']], $data['classes']));
        self::assertSame([$this->eleves['brice']->id, $this->eleves['chantal']->id], $data['sous_le_seuil']);
        self::assertSame(75, $data['seuil']);

        $basse = $this->lire('/api/lms/presences/synthese?du=2026-12-01&au=2026-12-31&seuil=0');
        self::assertSame([], $basse['sous_le_seuil'], 'Sous 0 %, personne.');
    }

    public function test_un_eleve_d_une_autre_ecole_n_apparait_pas_meme_sans_tenant(): void
    {
        $ailleurs = $this->ecole();
        $classe = $this->classe($ailleurs, 'Voisine');
        $eleve = $this->membre($ailleurs, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        Seance::factory()->for($ailleurs)->create(['date_seance' => '2026-12-01 08:00:00', 'classe_id' => $classe->id, 'is_active' => true,
            'klassci_seance_id' => null, 'klassci_classe_id' => null, 'klassci_enseignant_id' => null]);
        app(TenantManager::class)->reset();

        $data = app(LocalSuiviDesPresences::class)->lister($this->coordinateur, new FiltresDePresences(du: '2026-12-01', au: '2026-12-31'));

        self::assertNotContains($eleve->id, array_column($data['presences'], 'eleve_id'));
        self::assertSame(7, $data['total']);
    }

    public function test_la_forme_requete_dit_la_meme_chose_que_la_forme_ligne(): void
    {
        $seance = $this->seance('A', '2026-12-05 08:00:00');
        $cas = [];
        foreach ([null, ...AttendanceMark::cases()] as $marque) {
            foreach ([null, 'connected', 'disconnected', 'kicked'] as $statut) {
                $eleve = $this->membre($this->ecole, 'etudiant');
                $ligne = ESBTPAttendance::query()->create([
                    'seance_id' => $seance->id, 'user_id' => $eleve->id, 'institution_id' => $this->ecole->id,
                    'nom' => 'x', 'email' => $eleve->email, 'status' => $statut, 'mark' => $marque,
                ]);
                $cas[$ligne->id] = PresenceRetenue::de($ligne)?->value ?? 'absent';
            }
        }

        $lues = DB::table('esbtp_attendance')->whereIn('id', array_keys($cas))
            ->selectRaw('id, '.PresenceRetenue::expression('mark', 'status').' as retenue')->pluck('retenue', 'id')->all();

        self::assertSame($cas, array_map('strval', $lues));
    }

    public function test_les_reponses_suivent_la_spec(): void
    {
        $liste = $this->asTenant($this->coordinateur)->getJson('/api/lms/presences?du=2026-12-01&au=2026-12-31')->assertOk();
        $this->assertMatchesOpenApiResponse($liste, '/lms/presences', 'get');
        $this->assertOpenApiSchemaRefuseSans($liste, '/lms/presences', 'get', 'data');
        $synthese = $this->asTenant($this->coordinateur)->getJson('/api/lms/presences/synthese?du=2026-12-01&au=2026-12-31')->assertOk();
        $this->assertMatchesOpenApiResponse($synthese, '/lms/presences/synthese', 'get');
        $refus = $this->asTenant($this->membre($this->ecole, 'enseignant'))->getJson('/api/lms/presences?du=2026-12-01&au=2026-12-31')->assertForbidden();
        $this->assertMatchesOpenApiResponse($refus, '/lms/presences', 'get');
        $klassci = $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))->getJson('/api/lms/presences?du=2026-12-01&au=2026-12-31');
        $this->assertMatchesOpenApiResponse($klassci->assertStatus(409), '/lms/presences', 'get');
    }

    /** @return array<string, array{string, string}> */
    public static function horsGestion(): array
    {
        return [
            'enseignant, liste' => ['enseignant', '/api/lms/presences'],
            'enseignant, synthese' => ['enseignant', '/api/lms/presences/synthese'],
            'eleve, liste' => ['etudiant', '/api/lms/presences'],
            'eleve, synthese' => ['etudiant', '/api/lms/presences/synthese'],
        ];
    }

    #[DataProvider('horsGestion')]
    public function test_hors_gestion_le_suivi_est_refuse(string $role, string $chemin): void
    {
        $this->asTenant($this->membre($this->ecole, $role))->getJson($chemin.'?du=2026-12-01&au=2026-12-31')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return ['liste' => ['/api/lms/presences'], 'synthese' => ['/api/lms/presences/synthese']];
    }

    /** L'administrateur de l'école est de la gestion, comme le coordinateur : il lit le suivi. */
    #[DataProvider('routes')]
    public function test_l_administrateur_de_l_ecole_lit_le_suivi(string $chemin): void
    {
        $this->asTenant($this->membre($this->ecole, 'superAdmin'))->getJson($chemin.'?du=2026-12-01&au=2026-12-31')->assertOk();
    }

    /** La période vaut une année au plus après `du` : la borne est admise, le jour suivant refusé. */
    #[DataProvider('routes')]
    public function test_la_periode_est_plafonnee_a_un_an(string $chemin): void
    {
        $this->asTenant($this->coordinateur)->getJson($chemin.'?du=2026-01-01&au=2027-01-01')->assertOk();
        $this->asTenant($this->coordinateur)->getJson($chemin.'?du=2026-01-01&au=2027-01-02')
            ->assertUnprocessable()->assertJsonValidationErrors('au');
    }

    public function test_une_ecole_klassci_tient_ses_presences_dans_klassci(): void
    {
        $coordinateur = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');

        $this->asTenant($coordinateur)->getJson('/api/lms/presences/synthese?du=2026-12-01&au=2026-12-31')
            ->assertStatus(409)->assertJsonPath('reason', 'attendance_managed_by_klassci');
    }

    /** @return array<string, mixed> */
    private function lire(string $url): array
    {
        $data = $this->asTenant($this->coordinateur)->getJson($url)->assertOk()->json('data');
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function paires(array $lignes): array
    {
        $seances = array_flip(array_map(static fn (Seance $s): int => (int) $s->id, $this->seances));
        $eleves = array_flip(array_map(static fn (User $u): int => (int) $u->id, $this->eleves));

        return array_map(static fn (array $l): array => [
            (string) $seances[$l['seance_id']], (string) $eleves[$l['eleve_id']], (string) $l['marque'],
        ], $lignes);
    }

    private function seance(string $classe, string $debut): Seance
    {
        return Seance::factory()->for($this->ecole)->create([
            'date_seance' => $debut,
            'classe_id' => $this->classes[$classe]->id,
            'created_by' => $this->coordinateur->id,
            'is_active' => true,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);
    }

    private function ligne(string $seance, string $eleve, ?AttendanceMark $marque, ?string $statut = null): void
    {
        ESBTPAttendance::query()->create([
            'seance_id' => $this->seances[$seance]->id,
            'user_id' => $this->eleves[$eleve]->id,
            'institution_id' => $this->ecole->id,
            'nom' => $this->eleves[$eleve]->name,
            'email' => $this->eleves[$eleve]->email,
            'status' => $statut,
            'mark' => $marque,
            'origin' => $marque === null ? null : AttendanceOrigin::Delegated,
        ]);
    }
}
