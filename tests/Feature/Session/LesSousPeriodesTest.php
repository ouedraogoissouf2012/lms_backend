<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1806 — une période de formation se découpe en sous-périodes (semestres, trimestres).
 *
 * Une sous-période : libellé, début, fin, ordre. Elle tient dans les dates de sa période et ne chevauche aucune
 * de ses sœurs (bornes incluses), vérifié sous le verrou de la période ; début ≤ fin est garanti par la BASE. Une
 * période qui n'est plus modifiable (close, annulée, archivée) ne se découpe plus. Réduire les dates d'une période
 * sous une de ses sous-périodes est refusé. Routes imbriquées sous la période.
 *
 * Deux volets : école KLASSCI → 403 `catalogue_managed_by_klassci` ; période d'une autre école → 404. Vrai jeton.
 */
final class LesSousPeriodesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_une_periode_se_decoupe_et_la_liste_suit_le_calendrier(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->ajouter($gestion, $periode, 'Semestre 2', '2027-02-01', '2027-06-30', 2)->assertCreated()
            ->assertJsonPath('data.libelle', 'Semestre 2')->assertJsonPath('data.debut', '2027-02-01')->assertJsonPath('data.ordre', 2);
        $this->ajouter($gestion, $periode, 'Semestre 1', '2026-09-01', '2027-01-31', 1)->assertCreated();

        $this->asTenant($gestion)->getJson($this->url($periode))->assertOk()
            ->assertJsonPath('data.0.libelle', 'Semestre 1')->assertJsonPath('data.1.libelle', 'Semestre 2');
    }

    public function test_une_sous_periode_tient_dans_les_dates_de_sa_periode(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->ajouter($gestion, $periode, 'Trop tôt', '2026-08-15', '2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['debut']);
        $this->ajouter($gestion, $periode, 'Trop tard', '2027-05-01', '2027-07-15')->assertStatus(422)->assertJsonValidationErrors(['fin']);
        self::assertSame(0, DB::table('sous_periodes')->count());
    }

    public function test_deux_soeurs_ne_se_chevauchent_pas_bornes_incluses(): void
    {
        [$gestion, $periode] = $this->periode();
        $this->ajouter($gestion, $periode, 'Semestre 1', '2026-09-01', '2027-01-31')->assertCreated();

        $this->ajouter($gestion, $periode, 'Chevauche', '2027-01-31', '2027-03-01')->assertStatus(422)->assertJsonValidationErrors(['debut']);
        $this->ajouter($gestion, $periode, 'Contiguë', '2027-02-01', '2027-06-30')->assertCreated();
    }

    public function test_une_sous_periode_se_modifie_sans_se_heurter_a_elle_meme_et_se_supprime(): void
    {
        [$gestion, $periode] = $this->periode();
        $id = $this->ajouter($gestion, $periode, 'S1', '2026-09-01', '2027-01-31')->json('data.id');
        $autre = $this->ajouter($gestion, $periode, 'S2', '2027-02-01', '2027-06-30')->json('data.id');

        $this->asTenant($gestion)->patchJson($this->url($periode, $id), ['libelle' => 'Semestre 1', 'fin' => '2027-01-15'])->assertOk()
            ->assertJsonPath('data.libelle', 'Semestre 1')->assertJsonPath('data.fin', '2027-01-15')->assertJsonPath('data.debut', '2026-09-01');
        $this->asTenant($gestion)->patchJson($this->url($periode, $id), ['fin' => '2027-02-10'])->assertStatus(422)->assertJsonValidationErrors(['fin']);

        $this->asTenant($gestion)->deleteJson($this->url($periode, $autre))->assertOk();
        self::assertSame(1, DB::table('sous_periodes')->where('training_session_id', $periode->id)->count());
    }

    public function test_un_debut_apres_la_fin_est_refuse_et_la_base_le_garantit(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->ajouter($gestion, $periode, 'À l’envers', '2027-01-31', '2026-09-01')->assertStatus(422)->assertJsonValidationErrors(['fin']);

        $this->expectException(QueryException::class);
        DB::table('sous_periodes')->insert([
            'institution_id' => $periode->institution_id, 'training_session_id' => $periode->id, 'libelle' => 'Brute',
            'debut' => '2027-01-31', 'fin' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_une_periode_close_ne_se_decoupe_plus(): void
    {
        [$gestion, $periode] = $this->periode(TrainingSessionStatus::Cloturee);

        $this->ajouter($gestion, $periode, 'S1', '2026-09-01', '2027-01-31')
            ->assertStatus(409)->assertJsonPath('reason', 'training_session_not_editable');
    }

    public function test_reduire_une_periode_sous_une_sous_periode_est_refuse(): void
    {
        [$gestion, $periode] = $this->periode();
        $this->ajouter($gestion, $periode, 'S2', '2027-02-01', '2027-06-30')->assertCreated();

        $this->asTenant($gestion)->putJson("/api/training-sessions/{$periode->id}", [
            'libelle' => $periode->libelle, 'enrollment_opens_at' => null, 'enrollment_closes_at' => null,
            'starts_on' => '2026-09-01', 'ends_on' => '2027-05-31', 'certificate_available_at' => null,
        ])->assertStatus(409)->assertJsonPath('reason', 'sub_periods_outside_period');
        self::assertSame('2027-06-30', substr((string) DB::table('training_sessions')->where('id', $periode->id)->value('ends_on'), 0, 10));
    }

    public function test_la_periode_ou_la_sous_periode_d_ailleurs_est_introuvable(): void
    {
        [$gestion] = $this->periode();
        [$voisin, $ailleurs] = $this->periode();
        $sienne = $this->ajouter($voisin, $ailleurs, 'S1', '2026-09-01', '2027-01-31')->json('data.id');
        [, $autrePeriode] = $this->periodeDe($gestion);

        $this->ajouter($gestion, $ailleurs, 'Intrusion', '2026-09-01', '2027-01-31')->assertNotFound();
        $this->asTenant($gestion)->getJson($this->url($ailleurs))->assertNotFound();
        $this->asTenant($gestion)->patchJson($this->url($ailleurs, $sienne), ['libelle' => 'Intrusion'])->assertNotFound();
        $this->asTenant($voisin)->patchJson($this->url($autrePeriode, $sienne), ['libelle' => 'Mauvaise période'])->assertNotFound();
        self::assertSame('S1', DB::table('sous_periodes')->where('id', $sienne)->value('libelle'));
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        [$gestion, $periode] = $this->periode(mode: InstitutionMode::Klassci);

        $this->ajouter($gestion, $periode, 'S1', '2026-09-01', '2027-01-31')
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame(0, DB::table('sous_periodes')->count());
    }

    private function ajouter(User $gestion, TrainingSession $periode, string $libelle, string $debut, string $fin, ?int $ordre = null): TestResponse
    {
        return $this->asTenant($gestion)->postJson($this->url($periode), ['libelle' => $libelle, 'debut' => $debut, 'fin' => $fin, 'ordre' => $ordre]);
    }

    private function url(TrainingSession $periode, mixed $sousPeriode = null): string
    {
        return "/api/training-sessions/{$periode->id}/sous-periodes".($sousPeriode === null ? '' : "/{$sousPeriode}");
    }

    /** @return array{0: User, 1: TrainingSession} */
    private function periode(TrainingSessionStatus $statut = TrainingSessionStatus::Brouillon, InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $gestion = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur', 'klassci_token' => null]);

        return $this->periodeDe($gestion, $statut);
    }

    /** @return array{0: User, 1: TrainingSession} */
    private function periodeDe(User $gestion, TrainingSessionStatus $statut = TrainingSessionStatus::Brouillon): array
    {
        $programme = Program::factory()->create(['institution_id' => $gestion->institution_id]);

        return [$gestion, TrainingSession::factory()->create([
            'institution_id' => $gestion->institution_id, 'program_id' => $programme->id, 'status' => $statut,
            'enrollment_opens_at' => null, 'enrollment_closes_at' => null,
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'certificate_available_at' => null,
        ])];
    }
}
