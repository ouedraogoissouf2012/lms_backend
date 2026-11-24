<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1127 — la mise à jour d'un chapitre et d'une évaluation n'a qu'un verbe : PUT.
 *
 * `Route::match(['put', 'patch'])` servait le même `update` sous deux verbes ; la spec
 * ne déclarait que `put`. `PATCH` n'avait aucun appelant (front `dev` et chunks
 * déployés : son seul `.patch(` vise `admin.institutions.toggle`) et aucune sémantique
 * propre (les règles sont déjà partielles). Il est retiré.
 *
 * Le refus se joue au routage, avant toute authentification : 405 pour tout le monde,
 * dans une école KLASSCI comme dans une école autonome. Une requête anonyme suffit
 * donc à le prouver, et PUT reste servi (verrou : 401, la route existe).
 */
final class PatchRetireDesMisesAJourTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function misesAJour(): array
    {
        return ['chapitre' => ['/api/chapters/1'], 'évaluation' => ['/api/evaluations/1']];
    }

    #[DataProvider('misesAJour')]
    public function test_patch_n_est_plus_servi(string $chemin): void
    {
        $this->patchJson($chemin, ['title' => 'Nouveau titre'])->assertStatus(405);
    }

    /** Verrou : PUT reste la porte de mise à jour (la route existe, l'authentification refuse). */
    #[DataProvider('misesAJour')]
    public function test_put_reste_servi(string $chemin): void
    {
        $this->putJson($chemin, ['title' => 'Nouveau titre'])->assertUnauthorized();
    }
}
