<?php

declare(strict_types=1);

namespace Tests\Feature\Enseignants;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1666 (ARRÊT 1 validé le 28/11) — les disponibilités d'un enseignant :
 * - une semaine type (jour, heure de début et de fin, disponible ou indisponible), remplacée d'un bloc ; deux créneaux
 *   d'un même jour ne se chevauchent pas ;
 * - des exceptions datées (journée entière, ou un créneau), ajoutées et retirées une à une ;
 * - la gestion écrit, l'enseignant lit les siennes ;
 * - les DEUX volets : en école KLASSCI aussi, et rattachées à l'id LOCAL du compte, jamais à son id KLASSCI
 *   (exigence de la superviseure).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class DisponibilitesEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private User $coordinateur;

    private User $awa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->awa = $this->membre($this->ecole, 'enseignant');
    }

    public function test_la_semaine_type_part_d_un_bloc_et_se_relit(): void
    {
        $this->semaine($this->awa, [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '12:00', 'disponible' => true],
            ['jour' => 3, 'debut' => '14:00', 'fin' => '18:00', 'disponible' => false],
        ])->assertOk();

        $data = $this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->assertOk()->json('data');

        self::assertSame([[1, '08:00', '12:00', true], [3, '14:00', '18:00', false]],
            array_map(static fn (array $c): array => [$c['jour'], $c['debut'], $c['fin'], $c['disponible']], $data['semaine']));

        $this->semaine($this->awa, [['jour' => 2, 'debut' => '09:00', 'fin' => '11:00', 'disponible' => true]])->assertOk();
        self::assertSame([2], array_column($this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->json('data.semaine'), 'jour'));
    }

    public function test_deux_creneaux_du_meme_jour_ne_se_chevauchent_pas(): void
    {
        $this->semaine($this->awa, [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '12:00', 'disponible' => true],
            ['jour' => 1, 'debut' => '11:00', 'fin' => '13:00', 'disponible' => false],
        ])->assertUnprocessable()->assertJsonPath('reason', 'disponibilites_en_conflit');
    }

    public function test_la_fin_suit_le_debut(): void
    {
        $this->semaine($this->awa, [['jour' => 1, 'debut' => '12:00', 'fin' => '08:00', 'disponible' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['semaine.0.fin']);
    }

    public function test_une_exception_datee_s_ajoute_et_se_retire(): void
    {
        $id = (int) $this->asTenant($this->coordinateur)->postJson($this->chemin($this->awa).'/exceptions', [
            'date' => '2026-12-15', 'disponible' => false, 'motif' => 'Jury de thèse',
        ])->assertCreated()->assertJsonPath('data.debut', null)->json('data.id');
        $this->asTenant($this->coordinateur)->postJson($this->chemin($this->awa).'/exceptions', [
            'date' => '2026-12-16', 'debut' => '14:00', 'fin' => '16:00', 'disponible' => true,
        ])->assertCreated();

        self::assertSame(['2026-12-15', '2026-12-16'], array_column($this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->json('data.exceptions'), 'date'));

        $this->asTenant($this->coordinateur)->deleteJson($this->chemin($this->awa)."/exceptions/{$id}")->assertOk();
        self::assertSame(['2026-12-16'], array_column($this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->json('data.exceptions'), 'date'));
    }

    public function test_l_enseignant_lit_les_siennes_mais_ne_les_ecrit_pas(): void
    {
        $this->semaine($this->awa, [['jour' => 1, 'debut' => '08:00', 'fin' => '12:00', 'disponible' => true]])->assertOk();

        $this->asTenant($this->awa)->getJson($this->chemin($this->awa))->assertOk()->assertJsonCount(1, 'data.semaine');
        $this->asTenant($this->awa)->putJson($this->chemin($this->awa), ['semaine' => []])->assertForbidden();
        $this->asTenant($this->awa)->getJson($this->chemin($this->membre($this->ecole, 'enseignant')))->assertNotFound();
    }

    public function test_une_exception_d_un_autre_enseignant_ne_se_retire_pas_par_celui_ci(): void
    {
        $moussa = $this->membre($this->ecole, 'enseignant');
        $id = (int) $this->asTenant($this->coordinateur)->postJson($this->chemin($moussa).'/exceptions', ['date' => '2026-12-15', 'disponible' => false])
            ->assertCreated()->json('data.id');

        $this->asTenant($this->coordinateur)->deleteJson($this->chemin($this->awa)."/exceptions/{$id}")->assertNotFound();
    }

    public function test_un_enseignant_d_une_autre_ecole_est_introuvable(): void
    {
        $this->semaine($this->membre($this->ecole(), 'enseignant'), [])->assertNotFound();
    }

    public function test_en_ecole_klassci_elles_se_rattachent_a_l_id_local_du_compte(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $enseignant = $this->membre($ecole, 'enseignant');
        // L'id KLASSCI de l'enseignant diffère de son id local, et désigne en local un AUTRE compte.
        $autre = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_enseignant_id' => $autre->id, 'klassci_id' => $autre->id + 1000])->save();

        $this->asTenant($coordinateur)->putJson($this->chemin($enseignant), ['semaine' => [
            ['jour' => 2, 'debut' => '08:00', 'fin' => '10:00', 'disponible' => true],
        ]])->assertOk();

        self::assertSame([$enseignant->id], DB::table('disponibilites_enseignant')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all());
        $this->asTenant($coordinateur)->getJson($this->chemin($autre))->assertOk()->assertJsonCount(0, 'data.semaine');
        $this->asTenant($enseignant)->getJson($this->chemin($enseignant))->assertOk()->assertJsonCount(1, 'data.semaine');
    }

    /** @param  list<array<string, mixed>>  $semaine */
    private function semaine(User $enseignant, array $semaine): TestResponse
    {
        return $this->asTenant($this->coordinateur)->putJson($this->chemin($enseignant), ['semaine' => $semaine]);
    }

    private function chemin(User $enseignant): string
    {
        return "/api/lms/enseignants/{$enseignant->id}/disponibilites";
    }
}
