<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1651 (PR A) — une période se modifie, un brouillon se supprime, et UNE période est « en cours ».
 *
 * L'invariant « seule une période PUBLIÉE peut être en cours » tient à tout instant : la base le
 * garantit (contrainte), et toute sortie de l'état publiée libère la marque dans la même écriture.
 * La marque est portée par la période elle-même : elle ne peut désigner que sa propre école, et
 * l'unicité `(institution_id, en_cours)` n'en laisse qu'une par école.
 *
 * Vrai jeton (`asTenant`), les deux volets : une école KLASSCI est refusée et ne marque rien.
 */
final class UnePeriodeSeModifieEtDevientCouranteTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    // ───────────────────────── modifier

    public function test_un_brouillon_se_modifie(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->modifier($gestion, $periode, $this->corps('Session de janvier', '2027-01-04', '2027-03-26'))
            ->assertOk()
            ->assertJsonPath('data.libelle', 'Session de janvier')
            ->assertJsonPath('data.starts_on', '2027-01-04')
            ->assertJsonPath('data.ends_on', '2027-03-26')
            ->assertJsonPath('data.en_cours', false);
    }

    public function test_une_periode_publiee_se_modifie_mais_garde_sa_date_de_debut(): void
    {
        [$gestion, $periode] = $this->periode(TrainingSessionStatus::Publiee);

        $this->modifier($gestion, $periode, $this->corps('Session publiée', '2027-02-01', '2027-04-30'))->assertOk();
        $this->modifier($gestion, $periode, $this->corps('Sans début', null, '2027-04-30'))
            ->assertStatus(422)->assertJsonPath('reason', 'training_session_published_needs_start');
    }

    public function test_une_periode_close_ne_se_modifie_pas(): void
    {
        foreach ([TrainingSessionStatus::Cloturee, TrainingSessionStatus::Annulee, TrainingSessionStatus::Archivee] as $statut) {
            [$gestion, $periode] = $this->periode($statut);

            $this->modifier($gestion, $periode, $this->corps('Retouche', '2027-01-04', '2027-03-26'))
                ->assertStatus(409)->assertJsonPath('reason', 'training_session_not_editable');
        }
    }

    public function test_des_dates_dans_le_desordre_sont_refusees_lisiblement(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->modifier($gestion, $periode, $this->corps('Désordre', '2027-03-26', '2027-01-04'))
            ->assertStatus(422)->assertJsonValidationErrors(['ends_on']);
    }

    // ───────────────────────── supprimer

    public function test_un_brouillon_sans_classe_se_supprime_en_douceur(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->asTenant($gestion)->deleteJson("/api/training-sessions/{$periode->id}")->assertOk();

        self::assertSoftDeleted('training_sessions', ['id' => $periode->id]);
    }

    public function test_un_brouillon_avec_une_classe_ne_se_supprime_pas(): void
    {
        [$gestion, $periode] = $this->periode();
        Classe::factory()->create(['institution_id' => $periode->institution_id, 'training_session_id' => $periode->id]);

        $this->asTenant($gestion)->deleteJson("/api/training-sessions/{$periode->id}")
            ->assertStatus(409)->assertJsonPath('reason', 'training_session_not_deletable');
    }

    public function test_une_periode_publiee_ne_se_supprime_pas(): void
    {
        [$gestion, $periode] = $this->periode(TrainingSessionStatus::Publiee);

        $this->asTenant($gestion)->deleteJson("/api/training-sessions/{$periode->id}")
            ->assertStatus(409)->assertJsonPath('reason', 'training_session_not_deletable');
    }

    // ───────────────────────── la période en cours

    public function test_une_periode_publiee_devient_en_cours_et_passe_en_tete_de_liste(): void
    {
        [$gestion, $ancienne] = $this->periode(TrainingSessionStatus::Publiee, ['starts_on' => '2026-09-01']);
        $courante = $this->autrePeriode($ancienne, TrainingSessionStatus::Publiee, ['starts_on' => '2026-01-05']);

        $this->enCours($gestion, $courante)->assertOk()->assertJsonPath('data.en_cours', true);

        $liste = $this->asTenant($gestion)->getJson('/api/training-sessions')->assertOk();
        $liste->assertJsonPath('data.0.id', $courante->id)->assertJsonPath('data.0.en_cours', true)
            ->assertJsonPath('data.1.en_cours', false);
    }

    public function test_designer_une_autre_periode_deplace_la_marque(): void
    {
        [$gestion, $premiere] = $this->periode(TrainingSessionStatus::Publiee);
        $seconde = $this->autrePeriode($premiere, TrainingSessionStatus::Publiee);

        $this->enCours($gestion, $premiere)->assertOk();
        $this->enCours($gestion, $seconde)->assertOk();

        self::assertSame([$seconde->id], $this->periodesEnCours($premiere->institution_id));
    }

    public function test_un_brouillon_ne_peut_pas_etre_en_cours(): void
    {
        [$gestion, $periode] = $this->periode();

        $this->enCours($gestion, $periode)->assertStatus(409)->assertJsonPath('reason', 'training_session_not_publiee');
    }

    /** Toute sortie de l'état publiée libère la marque, dans la même écriture. */
    public function test_cloturer_ou_annuler_la_periode_en_cours_libere_la_marque(): void
    {
        foreach (['cloturer', 'annuler'] as $transition) {
            [$gestion, $periode] = $this->periode(TrainingSessionStatus::Publiee);
            $this->enCours($gestion, $periode)->assertOk();

            $this->asTenant($gestion)->postJson("/api/training-sessions/{$periode->id}/{$transition}")->assertOk();

            self::assertSame([], $this->periodesEnCours($periode->institution_id), $transition);
        }
    }

    /**
     * Archiver part d'une période close ou annulée, qui ne peut déjà plus être en cours : la base
     * refuse la marque hors de l'état publiée. L'archivage la laisse donc vide, et le reste.
     */
    public function test_archiver_laisse_la_marque_vide(): void
    {
        [$gestion, $periode] = $this->periode(TrainingSessionStatus::Publiee);
        $this->enCours($gestion, $periode)->assertOk();
        $this->asTenant($gestion)->postJson("/api/training-sessions/{$periode->id}/cloturer")->assertOk();

        $this->asTenant($gestion)->postJson("/api/training-sessions/{$periode->id}/archiver")->assertOk();

        self::assertSame([], $this->periodesEnCours($periode->institution_id));
    }

    // ───────────────────────── la base garde l'invariant

    public function test_la_base_refuse_une_marque_hors_de_l_etat_publiee(): void
    {
        [, $periode] = $this->periode();

        $this->expectException(QueryException::class);

        DB::table('training_sessions')->where('id', $periode->id)->update(['en_cours' => true]);
    }

    public function test_la_base_refuse_deux_periodes_en_cours_dans_une_ecole(): void
    {
        [, $premiere] = $this->periode(TrainingSessionStatus::Publiee);
        $seconde = $this->autrePeriode($premiere, TrainingSessionStatus::Publiee);
        DB::table('training_sessions')->where('id', $premiere->id)->update(['en_cours' => true]);

        $this->expectException(QueryException::class);

        DB::table('training_sessions')->where('id', $seconde->id)->update(['en_cours' => true]);
    }

    public function test_deux_ecoles_ont_chacune_leur_periode_en_cours(): void
    {
        [$gestionA, $periodeA] = $this->periode(TrainingSessionStatus::Publiee);
        [$gestionB, $periodeB] = $this->periode(TrainingSessionStatus::Publiee);

        $this->enCours($gestionA, $periodeA)->assertOk();
        $this->enCours($gestionB, $periodeB)->assertOk();

        self::assertSame([$periodeA->id], $this->periodesEnCours($periodeA->institution_id));
        self::assertSame([$periodeB->id], $this->periodesEnCours($periodeB->institution_id));
    }

    // ───────────────────────── les deux volets, et l'isolement

    public function test_la_periode_d_une_autre_ecole_est_introuvable(): void
    {
        [$gestion] = $this->periode();
        [, $ailleurs] = $this->periode(TrainingSessionStatus::Publiee);

        $this->enCours($gestion, $ailleurs)->assertNotFound();
        $this->modifier($gestion, $ailleurs, $this->corps('Intrusion', '2027-01-04', '2027-03-26'))->assertNotFound();
        $this->asTenant($gestion)->deleteJson("/api/training-sessions/{$ailleurs->id}")->assertNotFound();

        self::assertSame([], $this->periodesEnCours($ailleurs->institution_id));
    }

    public function test_une_ecole_klassci_est_refusee_et_ne_marque_rien(): void
    {
        [$gestion, $periode] = $this->periode(TrainingSessionStatus::Publiee, [], InstitutionMode::Klassci);

        $this->enCours($gestion, $periode)->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->modifier($gestion, $periode, $this->corps('KLASSCI', '2027-01-04', '2027-03-26'))
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        self::assertSame([], $this->periodesEnCours($periode->institution_id));
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @return array{0: User, 1: TrainingSession}
     */
    private function periode(
        TrainingSessionStatus $statut = TrainingSessionStatus::Brouillon,
        array $attributs = [],
        InstitutionMode $mode = InstitutionMode::Standalone,
    ): array {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $gestion = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur', 'klassci_token' => null]);
        $programme = Program::factory()->create(['institution_id' => $ecole->id]);

        return [$gestion, TrainingSession::factory()->create(array_merge([
            'institution_id' => $ecole->id, 'program_id' => $programme->id, 'status' => $statut,
            'enrollment_opens_at' => null, 'enrollment_closes_at' => null,
            'starts_on' => '2026-12-01', 'ends_on' => null, 'certificate_available_at' => null,
        ], $attributs))];
    }

    /** @param  array<string, mixed>  $attributs */
    private function autrePeriode(TrainingSession $voisine, TrainingSessionStatus $statut, array $attributs = []): TrainingSession
    {
        return TrainingSession::factory()->create(array_merge([
            'institution_id' => $voisine->institution_id, 'program_id' => $voisine->program_id, 'status' => $statut,
            'enrollment_opens_at' => null, 'enrollment_closes_at' => null,
            'starts_on' => '2026-12-15', 'ends_on' => null, 'certificate_available_at' => null,
        ], $attributs));
    }

    /** @return array<string, string|null> */
    private function corps(string $libelle, ?string $debut, ?string $fin): array
    {
        return [
            'libelle' => $libelle, 'enrollment_opens_at' => null, 'enrollment_closes_at' => null,
            'starts_on' => $debut, 'ends_on' => $fin, 'certificate_available_at' => null,
        ];
    }

    /**
     * @param  array<string, string|null>  $corps
     * @return TestResponse<Response>
     */
    private function modifier(User $gestion, TrainingSession $periode, array $corps): TestResponse
    {
        return $this->asTenant($gestion)->putJson("/api/training-sessions/{$periode->id}", $corps);
    }

    /** @return TestResponse<Response> */
    private function enCours(User $gestion, TrainingSession $periode): TestResponse
    {
        return $this->asTenant($gestion)->postJson("/api/training-sessions/{$periode->id}/en-cours");
    }

    /** @return list<int> */
    private function periodesEnCours(int $ecole): array
    {
        return array_map('intval', DB::table('training_sessions')->where('institution_id', $ecole)->where('en_cours', true)->pluck('id')->all());
    }
}
