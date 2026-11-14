<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Le tableau de bord de l'élève admet EXACTEMENT ce que ses leçons admettent
 * (`GET /api/lessons/my-courses`) : une seule règle d'admission, le port
 * `EnrollmentSource`, sans prédicat propre au tableau (revue croisée, A1).
 *
 * Le port retombe sur le miroir `user_classes` dès que l'élève n'a aucune ligne de
 * pivot ACTIVE. Une école passée du mode KLASSCI au mode autonome garde ce miroir.
 * Déclaré : D57 n'est donc tenu ni par les leçons ni par le tableau pour un élève
 * aux lignes toutes inactives qui garde des lignes `user_classes`. Le correctif est
 * à la racine (le port), hors de ce lot.
 */
final class TableauDeBordSuitLaRegleDesLeconsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    public function test_le_tableau_admet_exactement_ce_que_les_lecons_admettent(): void
    {
        $this->disableKlassciMiddleware();
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => 77])->save();
        Lesson::factory()->published()->create(['institution_id' => $ecole->id, 'classe_id' => $classe->id]);

        $actif = $this->membre($ecole, 'etudiant');
        $this->inscrire($actif, $classe, 'actif');
        $inactifAvecMiroir = $this->membre($ecole, 'etudiant');
        $this->inscrire($inactifAvecMiroir, $classe, 'inactif');
        $this->miroir($inactifAvecMiroir, $ecole, 77);
        $sansLigneAvecMiroir = $this->membre($ecole, 'etudiant');
        $this->miroir($sansLigneAvecMiroir, $ecole, 77);

        foreach (['actif' => $actif, 'inactif avec miroir' => $inactifAvecMiroir, 'sans ligne avec miroir' => $sansLigneAvecMiroir] as $cas => $eleve) {
            $lecons = $this->asTenant($eleve)->getJson('/api/lessons/my-courses')->assertOk()->json('data');
            $admisParLesLecons = is_array($lecons) && $lecons !== [];

            $tableau = $this->asTenant($eleve)->getJson('/api/proxy/me/dashboard')->assertOk();

            self::assertSame(
                $admisParLesLecons ? $classe->id : null,
                $tableau->json('data.classe.id'),
                "$cas : le tableau n'admet pas ce que les leçons admettent",
            );
        }
    }

    /** Une ligne du miroir KLASSCI, telle que la synchronisation du login l'écrit. */
    private function miroir(User $eleve, Institution $ecole, int $klassciClasseId): void
    {
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id,
            'institution_id' => $ecole->id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => '6e A',
        ]);
    }
}
