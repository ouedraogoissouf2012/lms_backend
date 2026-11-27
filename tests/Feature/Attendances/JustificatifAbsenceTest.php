<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\AttendanceMark;
use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1681 — l'élève justifie une absence, l'école tranche (ARRÊT 1 validé le 27/11) :
 * - justifiable : une séance PASSÉE de sa classe active où sa présence retenue n'est pas « présent » (le retard est
 *   une présence) ;
 * - dépôt : motif, séances nommées ou plage de dates, pièce FACULTATIVE ; la pièce a son propre stockage ;
 * - la gestion (coordinateur, admin d'école) valide (`excused`, ancienne marque auditée, « présent » jamais écrasé)
 *   ou rejette avec un motif ; décision définitive ; l'élève est notifié ;
 * - une école KLASSCI justifie dans KLASSCI (409).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class JustificatifAbsenceTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private User $enseignant;

    private User $coordinateur;

    private User $awa;

    private User $brice;

    /** @var array{presente: Seance, absente: Seance, sansLigne: Seance, future: Seance} */
    private array $seances;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-12-01 08:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $classe = $this->classe($this->ecole, 'Terminale A');
        $this->enseignant = $this->membre($this->ecole, 'enseignant');
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        [$this->awa, $this->brice] = [$this->membre($this->ecole, 'etudiant'), $this->membre($this->ecole, 'etudiant')];
        $this->inscrire($this->awa, $classe, 'actif');
        $this->inscrire($this->brice, $classe, 'actif');
        $this->seances = [
            'presente' => $this->seanceDe($classe, '2026-12-01 08:00:00'),
            'absente' => $this->seanceDe($classe, '2026-12-01 10:00:00'),
            'sansLigne' => $this->seanceDe($classe, '2026-12-01 14:00:00'),
            'future' => $this->seanceDe($classe, '2026-12-05 08:00:00'),
        ];
        Carbon::setTestNow('2026-12-02 12:00:00');
        $this->marquer($this->seances['presente'], 'present');
        $this->marquer($this->seances['absente'], 'absent');
    }

    public function test_l_eleve_voit_ses_absences_passees(): void
    {
        $absences = $this->asTenant($this->awa)->getJson('/api/lms/mes-absences')->assertOk()->json('data');

        self::assertSame(
            [[$this->seances['sansLigne']->id, null], [$this->seances['absente']->id, 'absent']],
            array_map(static fn (array $a): array => [$a['seance_id'], $a['marque']], $absences),
        );
    }

    public function test_l_eleve_depose_un_justificatif_avec_sa_piece(): void
    {
        $reponse = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]], true)->assertCreated();

        $reponse->assertJsonPath('data.etat', 'en_attente')->assertJsonPath('data.piece', true);
        $chemin = (string) DB::table('justificatifs_absence')->value('piece_chemin');
        self::assertStringStartsWith('justificatifs/'.$this->ecole->id.'/', $chemin);
        Storage::disk('local')->assertExists($chemin);
    }

    public function test_la_piece_est_facultative(): void
    {
        $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]])->assertCreated()->assertJsonPath('data.piece', false);
    }

    public function test_un_eleve_ne_justifie_pas_la_seance_d_une_autre_classe(): void
    {
        $chantal = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($chantal, $this->classe($this->ecole, 'Terminale B'), 'actif');

        $this->deposer($chantal, ['seance_ids' => [$this->seances['absente']->id]])->assertNotFound();
        self::assertSame(0, DB::table('justificatifs_absence')->count());
    }

    public function test_une_seance_a_venir_n_est_pas_justifiable(): void
    {
        $this->deposer($this->awa, ['seance_ids' => [$this->seances['future']->id]])
            ->assertStatus(409)->assertJsonPath('reason', 'seance_not_started');
    }

    public function test_valider_excuse_les_absences_sans_ecraser_une_presence(): void
    {
        $id = $this->deposer($this->awa, ['du' => '2026-12-01', 'au' => '2026-12-01'])->assertCreated()->json('data.id');

        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/valider")->assertOk()->assertJsonPath('data.etat', 'validee');

        self::assertSame(AttendanceMark::Present, $this->ligne($this->seances['presente'])->mark);
        self::assertSame(AttendanceMark::Excused, $this->ligne($this->seances['absente'])->mark);
        $creee = $this->ligne($this->seances['sansLigne']);
        self::assertSame([AttendanceMark::Excused, null], [$creee->mark, $creee->status]);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'update')
            ->where('auditable_type', (new ESBTPAttendance)->getMorphClass())
            ->where('auditable_id', $this->ligne($this->seances['absente'])->id)->count(), 'L’ancienne marque est journalisée.');
        self::assertSame(1, DB::table('notifications')->where('user_id', $this->awa->id)->count());
    }

    public function test_rejeter_exige_un_motif_et_ne_touche_aucune_marque(): void
    {
        $id = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]])->json('data.id');

        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/rejeter")->assertUnprocessable();
        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/rejeter", ['motif' => 'Pièce illisible'])
            ->assertOk()->assertJsonPath('data.etat', 'rejetee');

        self::assertSame(AttendanceMark::Absent, $this->ligne($this->seances['absente'])->mark);
        $mien = $this->asTenant($this->awa)->getJson('/api/lms/mes-justificatifs')->assertOk()->json('data.0');
        self::assertSame(['rejetee', 'Pièce illisible'], [$mien['etat'], $mien['motif_rejet']]);
    }

    public function test_une_decision_est_definitive(): void
    {
        $id = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]])->json('data.id');
        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/valider")->assertOk();

        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/rejeter", ['motif' => 'x'])
            ->assertStatus(409)->assertJsonPath('reason', 'justification_already_decided');
    }

    public function test_l_enseignant_ne_tranche_pas(): void
    {
        $id = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]])->json('data.id');

        $this->asTenant($this->enseignant)->postJson("/api/lms/justificatifs/{$id}/valider")->assertForbidden();
    }

    public function test_la_liste_de_la_gestion_porte_les_compteurs(): void
    {
        $premier = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]])->json('data.id');
        $this->deposer($this->brice, ['seance_ids' => [$this->seances['sansLigne']->id]]);
        $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$premier}/valider")->assertOk();

        $data = $this->asTenant($this->membre($this->ecole, 'admin'))->getJson('/api/lms/justificatifs?etat=en_attente')->assertOk()->json('data');

        self::assertSame(['en_attente' => 1, 'validee' => 1, 'rejetee' => 0], $data['compteurs']);
        self::assertSame([$this->brice->id], array_column($data['justificatifs'], 'eleve_id'));
    }

    public function test_la_piece_n_est_lue_que_par_l_eleve_et_la_gestion_de_l_ecole(): void
    {
        $id = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]], true)->json('data.id');
        $piece = "/api/lms/justificatifs/{$id}/piece";

        $this->asTenant($this->awa)->get($piece)->assertOk();
        $this->asTenant($this->coordinateur)->get($piece)->assertOk();
        $this->asTenant($this->brice)->getJson($piece)->assertNotFound();
        $this->asTenant($this->membre($this->ecole(), 'admin'))->getJson($piece)->assertNotFound();
        $supradmin = User::factory()->create(['role' => 'supradmin', 'institution_id' => null, 'klassci_token' => null, 'klassci_id' => null]);
        self::assertContains($this->asTenant($supradmin)->getJson($piece)->status(), [400, 403, 404]);
    }

    public function test_les_reponses_suivent_la_spec(): void
    {
        $this->assertMatchesOpenApiResponse($this->asTenant($this->awa)->getJson('/api/lms/mes-absences')->assertOk(), '/lms/mes-absences', 'get');
        $depot = $this->deposer($this->awa, ['seance_ids' => [$this->seances['absente']->id]], true)->assertCreated();
        $this->assertMatchesOpenApiResponse($depot, '/lms/justificatifs', 'post');
        $this->assertOpenApiSchemaRefuseSans($depot, '/lms/justificatifs', 'post', 'data');
        $id = $depot->json('data.id');
        $gestion = $this->asTenant($this->coordinateur)->getJson('/api/lms/justificatifs')->assertOk();
        $this->assertMatchesOpenApiResponse($gestion, '/lms/justificatifs', 'get');
        $valide = $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/valider")->assertOk();
        $this->assertMatchesOpenApiResponse($valide, '/lms/justificatifs/{justificatifId}/valider', 'post');
        $refus = $this->asTenant($this->coordinateur)->postJson("/api/lms/justificatifs/{$id}/rejeter", ['motif' => 'x'])->assertStatus(409);
        $this->assertMatchesOpenApiResponse($refus, '/lms/justificatifs/{justificatifId}/rejeter', 'post');
        $this->assertMatchesOpenApiResponse($this->asTenant($this->awa)->getJson('/api/lms/mes-justificatifs')->assertOk(), '/lms/mes-justificatifs', 'get');
        $aVenir = $this->deposer($this->awa, ['seance_ids' => [$this->seances['future']->id]])->assertStatus(409);
        $this->assertMatchesOpenApiResponse($aVenir, '/lms/justificatifs', 'post');
    }

    public function test_une_ecole_klassci_justifie_dans_klassci(): void
    {
        $eleve = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');

        $this->asTenant($eleve)->getJson('/api/lms/mes-absences')->assertStatus(409)->assertJsonPath('reason', 'attendance_managed_by_klassci');
    }

    /** @param  array<string, mixed>  $champs */
    private function deposer(User $eleve, array $champs, bool $avecPiece = false): TestResponse
    {
        $champs += ['motif' => 'Malade, certificat du médecin.'];
        if ($avecPiece) {
            $champs['piece'] = UploadedFile::fake()->create('certificat.pdf', 120, 'application/pdf');
        }

        return $this->asTenant($eleve)->post('/api/lms/justificatifs', $champs, ['Accept' => 'application/json']);
    }

    private function marquer(Seance $seance, string $marque): void
    {
        $this->asTenant($this->enseignant)->putJson('/api/lms/seances/'.$seance->id.'/appel', [
            'marques' => [['user_id' => $this->awa->id, 'mark' => $marque]],
        ])->assertOk();
    }

    private function ligne(Seance $seance): ESBTPAttendance
    {
        return ESBTPAttendance::query()->withoutGlobalScopes()
            ->where('seance_id', $seance->id)->where('user_id', $this->awa->id)->firstOrFail();
    }

    private function seanceDe(Classe $classe, string $debut): Seance
    {
        return Seance::factory()->for($this->ecole)->create([
            'date_seance' => $debut,
            'fin_seance' => null,
            'classe_id' => $classe->id,
            'created_by' => $this->enseignant->id,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'is_active' => true,
        ]);
    }
}
