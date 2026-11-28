<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1649 — l'enseignant (ou la gestion) d'une école autonome saisit l'appel d'une séance en ligne : présent, absent,
 * en retard, excusé. ARRÊT 1 validé (27/11) :
 * - seuls les élèves ACTIFS de la classe de la séance ; l'appel s'ouvre au début de la séance ;
 * - la marque est écrite avec son origine `delegated` et qui l'a posée, quand ; une ligne sans visio porte un statut
 *   visio NUL (jamais « connecté ») ;
 * - l'historique reste corrigeable, et chaque correction est auditée (qui, quand, avant, après) ;
 * - une école KLASSCI saisit l'appel dans KLASSCI : 409.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class AppelEnLigneTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private Classe $classe;

    private User $enseignant;

    private Seance $seance;

    /** @var list<User> */
    private array $eleves;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 09:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, 'Terminale A');
        $this->enseignant = $this->membre($this->ecole, 'enseignant');
        $this->eleves = [];
        foreach (['Awa', 'Brice', 'Chantal'] as $prenom) {
            $eleve = $this->membre($this->ecole, 'etudiant');
            $eleve->forceFill(['name' => $prenom])->save();
            $this->inscrire($eleve, $this->classe, 'actif');
            $this->eleves[] = $eleve;
        }
        $this->seance = $this->seanceDe($this->ecole, $this->classe, $this->enseignant, '2026-12-02 08:00:00');
    }

    public function test_l_enseignant_saisit_l_appel_et_une_ligne_sans_visio_n_est_pas_connectee(): void
    {
        [$awa, $brice] = $this->eleves;
        $this->ligneVisio($awa, 'connected');

        $this->appel($this->enseignant, [[$awa, 'present'], [$brice, 'absent']])->assertOk();

        $ligneAwa = $this->ligne($awa);
        self::assertSame(AttendanceMark::Present, $ligneAwa->mark);
        self::assertSame(AttendanceOrigin::Delegated, $ligneAwa->origin);
        self::assertSame($this->enseignant->id, $ligneAwa->delegated_by_id);
        self::assertSame('connected', $ligneAwa->status, 'La ligne visio garde son statut.');
        $ligneBrice = $this->ligne($brice);
        self::assertSame(AttendanceMark::Absent, $ligneBrice->mark);
        self::assertNull($ligneBrice->status, 'Une ligne d appel sans visio n est jamais « connectée ».');
    }

    public function test_la_lecture_rend_toute_la_classe_avec_ses_marques(): void
    {
        [$awa] = $this->eleves;
        $this->appel($this->enseignant, [[$awa, 'late']])->assertOk();

        $eleves = $this->asTenant($this->enseignant)->getJson($this->url())->assertOk()->json('data.eleves');

        self::assertSame(
            [[$this->eleves[0]->id, 'late'], [$this->eleves[1]->id, null], [$this->eleves[2]->id, null]],
            array_map(static fn (array $e): array => [$e['user_id'], $e['mark']], $eleves),
        );
    }

    public function test_un_eleve_hors_de_la_classe_422_et_rien_n_est_ecrit(): void
    {
        $etranger = $this->membre($this->ecole, 'etudiant');

        $this->appel($this->enseignant, [[$this->eleves[0], 'present'], [$etranger, 'present']])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'student_not_in_seance');
        self::assertSame(0, ESBTPAttendance::query()->count());
    }

    public function test_un_enseignant_qui_n_est_pas_l_auteur_403(): void
    {
        $this->appel($this->membre($this->ecole, 'enseignant'), [[$this->eleves[0], 'present']])->assertForbidden();
    }

    public function test_la_gestion_saisit_aussi_et_peut_excuser(): void
    {
        $this->appel($this->membre($this->ecole, 'coordinateur'), [[$this->eleves[0], 'excused']])->assertOk();

        self::assertSame(AttendanceMark::Excused, $this->ligne($this->eleves[0])->mark);
    }

    public function test_avant_le_debut_de_la_seance_409(): void
    {
        $future = $this->seanceDe($this->ecole, $this->classe, $this->enseignant, '2026-12-02 10:00:00');

        $this->asTenant($this->enseignant)
            ->putJson('/api/lms/seances/'.$future->id.'/appel', ['marques' => [['user_id' => $this->eleves[0]->id, 'mark' => 'present']]])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'seance_not_started');
    }

    public function test_une_correction_est_auditee_avant_apres(): void
    {
        [$awa] = $this->eleves;
        $this->appel($this->enseignant, [[$awa, 'absent']])->assertOk();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $this->appel($coordinateur, [[$awa, 'excused']])->assertOk();

        $ligne = $this->ligne($awa);
        self::assertSame(AttendanceMark::Excused, $ligne->mark);
        self::assertSame($coordinateur->id, $ligne->delegated_by_id);
        $correction = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', $ligne->getMorphClass())->where('auditable_id', $ligne->id)
            ->latest('id')->firstOrFail();
        self::assertSame($coordinateur->id, $correction->user_id);
        self::assertSame('absent', $correction->before['mark'] ?? null);
        self::assertSame('excused', $correction->after['mark'] ?? null);
    }

    public function test_une_marque_hors_de_la_liste_422(): void
    {
        $this->appel($this->enseignant, [[$this->eleves[0], 'present_peut_etre']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('marques.0.mark');
    }

    public function test_la_seance_d_une_autre_ecole_404(): void
    {
        $autre = $this->ecole();
        $seance = $this->seanceDe($autre, $this->classe($autre, 'Ailleurs'), $this->membre($autre, 'enseignant'), '2026-12-02 08:00:00');

        $this->asTenant($this->enseignant)
            ->putJson('/api/lms/seances/'.$seance->id.'/appel', ['marques' => [['user_id' => $this->eleves[0]->id, 'mark' => 'present']]])
            ->assertNotFound();
    }

    public function test_une_ecole_klassci_saisit_l_appel_dans_klassci_409(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $auteur = $this->membre($klassci, 'enseignant');
        $seance = $this->seanceDe($klassci, $this->classe($klassci, 'Miroir'), $auteur, '2026-12-02 08:00:00');

        $this->asTenant($auteur)
            ->putJson('/api/lms/seances/'.$seance->id.'/appel', ['marques' => [['user_id' => $this->eleves[0]->id, 'mark' => 'present']]])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'attendance_managed_by_klassci');
    }

    public function test_les_presences_de_la_seance_exposent_la_marque_et_son_origine(): void
    {
        [$awa] = $this->eleves;
        $this->ligneVisio($awa, 'disconnected');
        $this->appel($this->enseignant, [[$awa, 'absent']])->assertOk();

        $presences = $this->asTenant($this->enseignant)->getJson('/api/lms/seances/'.$this->seance->id.'/attendances')->assertOk()->json('attendances');

        self::assertSame(['absent', 'delegated'], [$presences[0]['mark'] ?? null, $presences[0]['origin'] ?? null]);
    }

    /** @param  list<array{0: User, 1: string}>  $marques */
    private function appel(User $acteur, array $marques): TestResponse
    {
        return $this->asTenant($acteur)->putJson($this->url(), [
            'marques' => array_map(static fn (array $m): array => ['user_id' => $m[0]->id, 'mark' => $m[1]], $marques),
        ]);
    }

    private function url(): string
    {
        return '/api/lms/seances/'.$this->seance->id.'/appel';
    }

    private function ligne(User $eleve): ESBTPAttendance
    {
        return ESBTPAttendance::query()->withoutGlobalScopes()
            ->where('seance_id', $this->seance->id)->where('user_id', $eleve->id)->firstOrFail();
    }

    private function ligneVisio(User $eleve, string $statut): void
    {
        ESBTPAttendance::factory()->create([
            'seance_id' => $this->seance->id,
            'user_id' => $eleve->id,
            'institution_id' => $this->ecole->id,
            'status' => $statut,
        ]);
    }

    private function seanceDe(Institution $ecole, Classe $classe, User $auteur, string $debut): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'date_seance' => $debut,
            'fin_seance' => null,
            'classe_id' => $classe->id,
            'created_by' => $auteur->id,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'is_active' => true,
        ]);
    }
}
