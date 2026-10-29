<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `GET /evaluations`, la liste générale des évaluations de l'établissement
 * (brouillons et questions compris), est réservée au personnel reconnu.
 *
 * Ses quatre consommateurs sont des écrans du personnel (calendrier côté
 * enseignant, coordinateur et admin ; évaluations de l'enseignant ; résultats
 * admin ; évaluations du coordinateur). L'élève a sa propre route,
 * `/evaluations/student`.
 *
 * Le prédicat est `isStaff()`, en correspondance exacte : une variante de casse
 * d'un rôle à privilège (`SuperAdmin`, `ADMIN`, `Enseignant`) n'ouvre pas la
 * liste. Vrai jeton porteur, deux volets.
 *
 * @see app/Http/Requests/ListEvaluationsRequest.php
 */
final class EvaluationListStaffOnlyTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function horsPersonnel(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            // '' : le rôle absent que la base admet (`users.role` est NOT NULL).
            foreach (['etudiant', 'Etudiant', 'eleve', 'SuperAdmin', 'Supradmin', 'ADMIN', 'Enseignant', ''] as $role) {
                yield "« $role », école $volet" => [$volet, $role];
            }
        }
    }

    #[DataProvider('horsPersonnel')]
    public function test_la_liste_est_refusee_hors_personnel(string $volet, string $role): void
    {
        [$compte] = $this->ecoleAvecBrouillon($volet, $role);

        $reponse = $this->asTenant($compte)->getJson('/api/evaluations')->assertForbidden();

        self::assertSame(['success', 'message'], array_keys((array) $reponse->json()));
        self::assertFalse($reponse->json('success'));
        self::assertStringNotContainsString('Algebre', (string) $reponse->getContent());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function personnel(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            foreach (['enseignant', 'coordinateur', 'admin', 'superAdmin'] as $role) {
                yield "« $role », école $volet" => [$volet, $role];
            }
        }
    }

    #[DataProvider('personnel')]
    public function test_le_personnel_garde_la_liste_brouillons_compris(string $volet, string $role): void
    {
        [$compte, $brouillon] = $this->ecoleAvecBrouillon($volet, $role);

        $ids = array_column($this->asTenant($compte)->getJson('/api/evaluations')->assertOk()->json('data'), 'id');

        self::assertContains($brouillon->id, $ids);
    }

    /** @return array{0: User, 1: Evaluation} */
    private function ecoleAvecBrouillon(string $volet, string $role): array
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';
        $ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();

        $brouillon = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_classe_id' => $standalone ? null : 4242,
            'titre' => 'Algebre, controle',
            'status' => 'brouillon',
            'is_published' => false,
        ]);
        $compte = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_id' => $standalone ? null : 910001,
        ]);

        return [$compte, $brouillon];
    }
}
