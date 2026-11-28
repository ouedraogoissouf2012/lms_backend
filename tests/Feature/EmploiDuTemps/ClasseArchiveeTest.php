<?php

declare(strict_types=1);

namespace Tests\Feature\EmploiDuTemps;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1669, décision de la superviseure (28/11) : une classe ARCHIVÉE (#1763) ne reçoit plus d'emploi du temps.
 * Créer, générer ou copier vers une classe archivée est refusé en 409 `class_archived`, le motif que #1763 emploie
 * déjà ; rien n'est écrit. La lecture, la grille et la suppression restent ouvertes.
 *
 * Jeton Bearer RÉEL, par `asTenant()` ; horloge figée un lundi.
 */
final class ClasseArchiveeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const RACINE = '/api/lms/emplois-du-temps';

    private Institution $ecole;

    private User $coordinateur;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-07 06:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->classe = $this->classe($this->ecole, 'Terminale A');
        $this->programme($this->classe, $this->matiere($this->ecole, 'Maths'), $this->membre($this->ecole, 'enseignant'));
    }

    public function test_creer_l_emploi_du_temps_d_une_classe_archivee_est_refuse(): void
    {
        $this->archiver($this->classe);

        $this->asTenant($this->coordinateur)->postJson(self::RACINE, $this->plage() + ['classe_id' => $this->classe->id, 'titre' => 'S1'])
            ->assertStatus(409)->assertJsonPath('reason', 'class_archived');
        $this->assertDatabaseCount('emplois_du_temps', 0);
    }

    public function test_generer_pour_une_classe_archivee_est_refuse(): void
    {
        $id = $this->emploiAvecUnCours();
        $this->archiver($this->classe);

        $this->asTenant($this->coordinateur)->postJson(self::RACINE."/{$id}/generer")
            ->assertStatus(409)->assertJsonPath('reason', 'class_archived');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->where('institution_id', $this->ecole->id)->count());
    }

    public function test_copier_vers_une_classe_archivee_est_refuse(): void
    {
        $id = $this->emploiAvecUnCours();
        $archivee = $this->classe($this->ecole, 'Terminale Z');
        $this->archiver($archivee);

        $this->asTenant($this->coordinateur)->postJson(self::RACINE."/{$id}/copier", $this->plage() + ['classe_id' => $archivee->id])
            ->assertStatus(409)->assertJsonPath('reason', 'class_archived');
        $this->assertDatabaseCount('emplois_du_temps', 1);
    }

    public function test_lire_et_supprimer_restent_ouverts(): void
    {
        $id = $this->emploiAvecUnCours();
        $this->archiver($this->classe);

        $this->asTenant($this->coordinateur)->getJson(self::RACINE."/{$id}")->assertOk();
        $this->asTenant($this->coordinateur)->deleteJson(self::RACINE."/{$id}")->assertOk();
    }

    private function emploiAvecUnCours(): int
    {
        $id = (int) $this->asTenant($this->coordinateur)
            ->postJson(self::RACINE, $this->plage() + ['classe_id' => $this->classe->id, 'titre' => 'S1'])
            ->assertCreated()->json('data.id');
        $matiere = (int) $this->classe->matieres()->value('matieres.id');
        $this->asTenant($this->coordinateur)->putJson(self::RACINE."/{$id}/lignes", ['lignes' => [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $matiere],
        ]])->assertOk();

        return $id;
    }

    private function archiver(Classe $classe): void
    {
        $classe->forceFill(['archivee_le' => now()])->save();
    }

    /** @return array{du: string, au: string} */
    private function plage(): array
    {
        return ['du' => '2026-12-07', 'au' => '2026-12-20'];
    }
}
