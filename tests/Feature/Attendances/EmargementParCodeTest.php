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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1680 — un code d'émargement ouvre la signature d'une séance EN LIGNE (ARRÊT 1 validé le 27/11) :
 * - l'auteur de la séance ou la gestion génère un code par séance, rendu en clair UNE SEULE fois, gardé haché ;
 * - l'élève actif de la classe le saisit : marque `present` (ou `late` après le seuil), origine `code`, idempotente ;
 * - le code n'écrase jamais la marque de l'enseignant ; expiré ou révoqué, il ne signe plus ;
 * - 10 échecs par élève sur 10 minutes, puis 429 avec `Retry-After` ;
 * - une école KLASSCI émarge dans KLASSCI (409).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class EmargementParCodeTest extends TestCase
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
        Carbon::setTestNow('2026-12-02 08:05:00');
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
        $this->seance = $this->seanceDe($this->ecole, $this->classe, $this->enseignant);
    }

    public function test_le_code_est_rendu_une_fois_et_garde_hache(): void
    {
        $reponse = $this->generer($this->enseignant)->assertCreated();
        $code = $reponse->json('data.code');

        self::assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', (string) $code);
        $ligne = (array) DB::table('codes_emargement')->first();
        self::assertNotContains($code, $ligne, 'Le code en clair n est gardé dans aucune colonne.');
        $liste = $this->asTenant($this->enseignant)->getJson('/api/lms/codes-emargement?seance_id='.$this->seance->id)->assertOk();
        self::assertStringNotContainsString((string) $code, (string) $liste->getContent());
    }

    public function test_l_eleve_signe_present_par_le_code(): void
    {
        $code = $this->code();

        $this->signer($this->eleves[0], strtolower($code))->assertOk()->assertJsonPath('data.mark', 'present');

        $ligne = $this->ligne($this->eleves[0]);
        self::assertSame(AttendanceMark::Present, $ligne->mark);
        self::assertSame(AttendanceOrigin::Code, $ligne->origin);
        self::assertNull($ligne->status, 'Une signature sans visio n est pas une connexion.');
    }

    public function test_rejouer_la_signature_ne_double_rien(): void
    {
        $code = $this->code();
        $this->signer($this->eleves[0], $code)->assertOk();

        $this->signer($this->eleves[0], $code)->assertOk()->assertJsonPath('data.deja_signe', true);

        self::assertSame(1, ESBTPAttendance::query()->withoutGlobalScopes()->where('user_id', $this->eleves[0]->id)->count());
    }

    public function test_au_dela_du_seuil_la_signature_est_un_retard(): void
    {
        $code = $this->code(['retard_apres_minutes' => 10]);
        Carbon::setTestNow('2026-12-02 08:15:00');

        $this->signer($this->eleves[0], $code)->assertOk()->assertJsonPath('data.mark', 'late');
    }

    public function test_le_code_n_ecrase_jamais_la_marque_de_l_enseignant(): void
    {
        $code = $this->code();
        $this->asTenant($this->enseignant)->putJson('/api/lms/seances/'.$this->seance->id.'/appel', [
            'marques' => [['user_id' => $this->eleves[0]->id, 'mark' => 'absent']],
        ])->assertOk();

        $this->signer($this->eleves[0], $code)->assertStatus(409)->assertJsonPath('reason', 'already_marked_by_teacher');

        self::assertSame(AttendanceMark::Absent, $this->ligne($this->eleves[0])->mark);
    }

    public function test_un_code_expire_ou_revoque_ne_signe_plus(): void
    {
        $expire = $this->code(['duree_minutes' => 60]);
        Carbon::setTestNow('2026-12-02 09:06:00');
        $this->signer($this->eleves[0], $expire)->assertStatus(410)->assertJsonPath('reason', 'attendance_code_expired');

        $reponse = $this->generer($this->enseignant)->assertCreated();
        $this->asTenant($this->enseignant)->deleteJson('/api/lms/codes-emargement/'.$reponse->json('data.id'))->assertOk();
        $this->signer($this->eleves[1], (string) $reponse->json('data.code'))->assertStatus(410)->assertJsonPath('reason', 'attendance_code_expired');
    }

    public function test_un_eleve_d_une_autre_classe_est_refuse(): void
    {
        $code = $this->code();
        $autre = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($autre, $this->classe($this->ecole, 'Terminale B'), 'actif');

        $this->signer($autre, $code)->assertStatus(422)->assertJsonPath('reason', 'student_not_in_seance');
    }

    public function test_le_code_d_une_autre_ecole_est_inconnu(): void
    {
        $code = $this->code();
        $ailleurs = $this->ecole();
        $eleve = $this->membre($ailleurs, 'etudiant');

        $this->signer($eleve, $code)->assertNotFound()->assertJsonPath('reason', 'attendance_code_unknown');
    }

    public function test_dix_echecs_puis_429_avec_retry_after(): void
    {
        $this->code();
        for ($i = 0; $i < 10; $i++) {
            $this->signer($this->eleves[0], 'ZZZZZZ')->assertNotFound();
        }

        $this->signer($this->eleves[0], 'ZZZZZZ')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'attendance_code_attempts_exceeded')
            ->assertHeader('Retry-After');
    }

    public function test_les_statistiques_d_un_code(): void
    {
        $code = $this->code();
        $this->signer($this->eleves[0], $code)->assertOk();

        $codes = $this->asTenant($this->enseignant)->getJson('/api/lms/codes-emargement?seance_id='.$this->seance->id)->assertOk()->json('data');

        self::assertSame([1, 3, 33], [$codes[0]['signatures'], $codes[0]['attendus'], $codes[0]['taux']]);
    }

    public function test_un_enseignant_qui_n_est_pas_l_auteur_ne_genere_pas(): void
    {
        $this->generer($this->membre($this->ecole, 'enseignant'))->assertForbidden();
    }

    public function test_une_ecole_klassci_emarge_dans_klassci(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $auteur = $this->membre($klassci, 'enseignant');
        $seance = $this->seanceDe($klassci, $this->classe($klassci, 'Miroir'), $auteur);

        $this->asTenant($auteur)->postJson('/api/lms/codes-emargement', ['seance_id' => $seance->id, 'duree_minutes' => 60])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'attendance_managed_by_klassci');
    }

    /** @param  array<string, int>  $options */
    private function generer(User $auteur, array $options = []): TestResponse
    {
        return $this->asTenant($auteur)->postJson('/api/lms/codes-emargement', $options + [
            'seance_id' => $this->seance->id,
            'duree_minutes' => 120,
        ]);
    }

    /** @param  array<string, int>  $options */
    private function code(array $options = []): string
    {
        return (string) $this->generer($this->enseignant, $options)->assertCreated()->json('data.code');
    }

    private function signer(User $eleve, string $code): TestResponse
    {
        return $this->asTenant($eleve)->postJson('/api/lms/emargement', ['code' => $code]);
    }

    private function ligne(User $eleve): ESBTPAttendance
    {
        return ESBTPAttendance::query()->withoutGlobalScopes()
            ->where('seance_id', $this->seance->id)->where('user_id', $eleve->id)->firstOrFail();
    }

    private function seanceDe(Institution $ecole, Classe $classe, User $auteur): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'date_seance' => '2026-12-02 08:00:00',
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
