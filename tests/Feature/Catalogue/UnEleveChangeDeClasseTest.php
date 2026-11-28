<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1652 (PR C) — un élève change de classe dans son école, en une transaction.
 *
 * L'ancienne adhésion se ferme : `inactif`, datée (`ends_on`, ADR-711-02), motif `mutation`.
 * La nouvelle s'ouvre `actif` ; une ancienne ligne de l'élève dans la classe cible est
 * réactivée, jamais doublée. La cible doit recevoir : ni archivée (`class_archived`), ni
 * complète (`class_full`), ni rattachée à une Période close (`class_period_closed`). Un
 * refus ne change RIEN, ni à la source ni à la cible.
 *
 * Deux volets : école KLASSCI ou classe miroir → 403 ; classe ou élève d'une autre école → 404.
 * Vrai jeton (`asTenant`).
 */
final class UnEleveChangeDeClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_l_eleve_change_de_classe_et_l_ancienne_adhesion_se_ferme(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');

        $this->muter($gestion, $source, $eleve, $cible)->assertOk()
            ->assertJsonPath('data.eleve_id', $eleve->id)
            ->assertJsonPath('data.depuis', $source->id)
            ->assertJsonPath('data.vers', $cible->id);

        $ancienne = $this->ligne($source, $eleve);
        self::assertSame(['inactif', '2026-11-27', 'mutation'], [$ancienne?->statut, substr((string) $ancienne?->ends_on, 0, 10), $ancienne?->motif_fin]);
        $nouvelle = $this->ligne($cible, $eleve);
        self::assertSame(['actif', '2026-11-27'], [$nouvelle?->statut, substr((string) $nouvelle?->date_inscription, 0, 10)]);
        self::assertSame($ecole->id, (int) $nouvelle?->institution_id);
    }

    public function test_une_ancienne_ligne_dans_la_cible_est_reactivee_jamais_doublee(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');
        $this->adhesion($cible, $eleve, 'inactif', ['ends_on' => '2026-10-01', 'motif_fin' => 'mutation']);

        $this->muter($gestion, $source, $eleve, $cible)->assertOk();

        self::assertSame(1, DB::table('classe_etudiant')->where('classe_id', $cible->id)->where('user_id', $eleve->id)->count());
        $ligne = $this->ligne($cible, $eleve);
        self::assertSame(['actif', null, null], [$ligne?->statut, $ligne?->ends_on, $ligne?->motif_fin]);
    }

    public function test_une_cible_complete_refuse_et_rien_ne_change(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole, capacite: 1)];
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');
        $this->adhesion($cible, $this->eleve($ecole), 'actif');

        $this->muter($gestion, $source, $eleve, $cible)->assertStatus(409)->assertJsonPath('reason', 'class_full');
        $this->rienNAChange($source, $cible, $eleve);
    }

    public function test_une_cible_archivee_refuse(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        DB::table('classes')->where('id', $cible->id)->update(['archivee_le' => now()]);
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');

        $this->muter($gestion, $source, $eleve, $cible)->assertStatus(409)->assertJsonPath('reason', 'class_archived');
        $this->rienNAChange($source, $cible, $eleve);
    }

    public function test_une_cible_d_une_periode_close_refuse(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $close = TrainingSession::factory()->create(['institution_id' => $ecole->id, 'status' => TrainingSessionStatus::Cloturee]);
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole, periode: $close)];
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');

        $this->muter($gestion, $source, $eleve, $cible)->assertStatus(409)->assertJsonPath('reason', 'class_period_closed');
        $this->rienNAChange($source, $cible, $eleve);
    }

    public function test_une_adhesion_source_non_active_ou_absente_est_refusee(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        $inactif = $this->eleve($ecole);
        $this->adhesion($source, $inactif, 'abandonne');

        $this->muter($gestion, $source, $inactif, $cible)->assertStatus(409)->assertJsonPath('reason', 'enrolment_not_active');
        $this->muter($gestion, $source, $this->eleve($ecole), $cible)->assertNotFound();
        self::assertSame(0, DB::table('classe_etudiant')->where('classe_id', $cible->id)->count());
    }

    public function test_un_eleve_deja_actif_dans_la_cible_est_refuse(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');
        $this->adhesion($cible, $eleve, 'actif');

        $this->muter($gestion, $source, $eleve, $cible)->assertStatus(409)->assertJsonPath('reason', 'already_in_class');
        self::assertSame('actif', $this->ligne($source, $eleve)?->statut);
    }

    public function test_la_meme_classe_ou_une_cible_absente_est_refusee(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $source = $this->classe($ecole);
        $eleve = $this->eleve($ecole);
        $this->adhesion($source, $eleve, 'actif');

        $this->muter($gestion, $source, $eleve, $source)->assertStatus(422)->assertJsonValidationErrors(['classe_id']);
        $this->asTenant($gestion)->postJson("/api/classes/{$source->id}/eleves/{$eleve->id}/muter", [])
            ->assertStatus(422)->assertJsonValidationErrors(['classe_id']);
        self::assertSame('actif', $this->ligne($source, $eleve)?->statut);
    }

    /** L'autre école : sa classe source, sa classe cible, son élève. Rien n'est écrit, nulle part. */
    public function test_une_classe_ou_un_eleve_d_une_autre_ecole_est_introuvable(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$ailleurs] = $this->ecole();
        [$source, $cible] = [$this->classe($ecole), $this->classe($ecole)];
        [$sourceAilleurs, $cibleAilleurs] = [$this->classe($ailleurs), $this->classe($ailleurs)];
        $eleve = $this->eleve($ecole);
        $eleveAilleurs = $this->eleve($ailleurs);
        $this->adhesion($source, $eleve, 'actif');
        $this->adhesion($sourceAilleurs, $eleveAilleurs, 'actif');

        $this->muter($gestion, $source, $eleve, $cibleAilleurs)->assertNotFound();
        $this->muter($gestion, $sourceAilleurs, $eleveAilleurs, $cible)->assertNotFound();
        $this->muter($gestion, $sourceAilleurs, $eleveAilleurs, $cibleAilleurs)->assertNotFound();

        self::assertSame(['actif', 'actif'], [$this->ligne($source, $eleve)?->statut, $this->ligne($sourceAilleurs, $eleveAilleurs)?->statut]);
        self::assertSame(0, DB::table('classe_etudiant')->whereIn('classe_id', [$cible->id, $cibleAilleurs->id])->count());
    }

    public function test_une_ecole_klassci_et_une_classe_miroir_sont_refusees(): void
    {
        [$klassci, $gestionKlassci] = $this->ecole(InstitutionMode::Klassci);
        [$source, $cible] = [$this->classe($klassci), $this->classe($klassci)];
        $eleve = $this->eleve($klassci);
        $this->adhesion($source, $eleve, 'actif');
        $this->muter($gestionKlassci, $source, $eleve, $cible)
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        [$ecole, $gestion] = $this->ecole();
        $miroir = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242, 'effectif' => 0]);
        $autre = $this->eleve($ecole);
        $this->adhesion($miroir, $autre, 'actif');
        $this->muter($gestion, $miroir, $autre, $this->classe($ecole))
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        self::assertSame(['actif', 'actif'], [$this->ligne($source, $eleve)?->statut, $this->ligne($miroir, $autre)?->statut]);
    }

    private function muter(User $gestion, Classe $source, User $eleve, Classe $cible): TestResponse
    {
        return $this->asTenant($gestion)->postJson("/api/classes/{$source->id}/eleves/{$eleve->id}/muter", ['classe_id' => $cible->id]);
    }

    private function rienNAChange(Classe $source, Classe $cible, User $eleve): void
    {
        $ancienne = $this->ligne($source, $eleve);
        self::assertSame(['actif', null, null], [$ancienne?->statut, $ancienne?->ends_on, $ancienne?->motif_fin]);
        self::assertNull($this->ligne($cible, $eleve), 'Une adhésion est née dans la cible malgré le refus.');
    }

    private function ligne(Classe $classe, User $eleve): ?object
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->first();
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null])];
    }

    private function classe(Institution $ecole, int $capacite = 0, ?TrainingSession $periode = null): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $ecole->id, 'klassci_id' => null, 'effectif' => $capacite, 'training_session_id' => $periode?->id,
        ]);
    }

    private function eleve(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);
    }

    /** @param array<string, mixed> $plus */
    private function adhesion(Classe $classe, User $eleve, string $statut, array $plus = []): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $classe->institution_id,
            'statut' => $statut, 'date_inscription' => '2026-01-10', 'created_at' => now(), 'updated_at' => now(),
        ] + $plus);
    }
}
