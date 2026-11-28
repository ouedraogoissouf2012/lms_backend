<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1782 : sur les routes du personnel gardées par `ChecksEvaluationOwnership`, une évaluation
 * ABSENTE, SUPPRIMÉE ou d'une AUTRE école est introuvable : 404, du même corps dans les trois
 * cas. Un 403 dirait qu'elle existe ailleurs. Le 403 reste pour un appelant de la même école
 * sans le droit.
 *
 * L'appelant est l'admin de son école : toutes ces routes lui sont ouvertes sur une évaluation
 * de son école. Seule la PORTÉE peut donc le refuser. Vrai jeton (`asTenant`) ; chaque 404 est
 * validé par le contrat (il y est documenté).
 */
final class EvaluationHorsPorteeIntrouvableTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    /** @return iterable<string, array{string, string, array<string, mixed>, InstitutionMode}> */
    public static function routes(): iterable
    {
        $routes = [
            'publier' => ['post', '/api/evaluations/{id}/publish', []],
            'modifier' => ['put', '/api/evaluations/{id}', ['titre' => 'Titre modifié']],
            'supprimer' => ['delete', '/api/evaluations/{id}', []],
            'prévisualiser' => ['get', '/api/evaluations/{id}/preview', []],
            'noter une copie' => ['post', '/api/evaluations/{id}/submissions/1/grade', ['points' => []]],
            'synchroniser' => ['post', '/api/evaluations/{id}/sync-klassci', []],
            'lire les copies' => ['get', '/api/evaluations/{id}/submissions', []],
            'lire les résultats' => ['get', '/api/evaluations/{id}/results-by-class', []],
        ];
        foreach ([InstitutionMode::Standalone, InstitutionMode::Klassci] as $mode) {
            foreach ($routes as $nom => [$verbe, $chemin, $corps]) {
                yield "{$nom}, école {$mode->value}" => [$verbe, $chemin, $corps, $mode];
            }
        }
    }

    /** @param  array<string, mixed>  $corps */
    #[DataProvider('routes')]
    public function test_absente_supprimee_ou_d_une_autre_ecole_elle_est_introuvable(string $verbe, string $chemin, array $corps, InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $admin = $this->membre($ecole, 'superAdmin');
        $ailleurs = Evaluation::factory()->create(['institution_id' => $this->ecole($mode)->id]);
        $supprimee = Evaluation::factory()->create(['institution_id' => $ecole->id]);
        $supprimee->delete();
        $absente = (int) Evaluation::withTrashed()->max('id') + 1000;
        $this->disableKlassciMiddleware();

        $reponses = [
            'autre école' => $this->appeler($admin, $verbe, $chemin, $ailleurs->id, $corps),
            'supprimée' => $this->appeler($admin, $verbe, $chemin, $supprimee->id, $corps),
            'absente' => $this->appeler($admin, $verbe, $chemin, $absente, $corps),
        ];

        self::assertSame(
            ['autre école' => 404, 'supprimée' => 404, 'absente' => 404],
            array_map(static fn (TestResponse $r): int => $r->status(), $reponses),
        );
        self::assertSame($reponses['absente']->json(), $reponses['autre école']->json(), 'Le corps ne doit pas trahir une évaluation d\'une autre école.');
        self::assertSame($reponses['absente']->json(), $reponses['supprimée']->json());
        foreach ($reponses as $reponse) {
            $this->assertMatchesOpenApiResponse($reponse, str_replace('/api', '', str_replace('/1/grade', '/{submission}/grade', $chemin)), $verbe);
        }
    }

    /** Dans SON école, le refus d'un droit reste un 403 : un pour chaque contrôle de la garde. */
    public function test_dans_son_ecole_un_droit_manquant_reste_un_403(): void
    {
        $ecole = $this->ecole();
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->id, 'enseignant_id' => $this->membre($ecole, 'enseignant')->id]);
        $this->disableKlassciMiddleware();

        self::assertSame([
            'coordinateur qui publie (propriété)' => 403,
            'enseignant non auteur qui prévisualise (lecture)' => 403,
        ], [
            'coordinateur qui publie (propriété)' => $this->appeler($this->membre($ecole, 'coordinateur'), 'post', '/api/evaluations/{id}/publish', $evaluation->id, [])->status(),
            'enseignant non auteur qui prévisualise (lecture)' => $this->appeler($this->membre($ecole, 'enseignant'), 'get', '/api/evaluations/{id}/preview', $evaluation->id, [])->status(),
        ]);
    }

    /** @param  array<string, mixed>  $corps */
    private function appeler(User $acteur, string $verbe, string $chemin, int $id, array $corps): TestResponse
    {
        return $this->asTenant($acteur)->json(strtoupper($verbe), str_replace('{id}', (string) $id, $chemin), $corps);
    }
}
