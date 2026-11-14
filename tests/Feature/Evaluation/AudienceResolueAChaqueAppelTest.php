<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Services\Evaluation\Student\EvaluationWindowGate;
use App\Services\Evaluation\Student\LearnerEvaluationScope;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * ADR-EI-01, règle 3, appliquée à l'audience de l'apprenant (D72, E2a).
 *
 * `LearnerEvaluationScope` est reçu par le CONSTRUCTEUR de quatre services,
 * eux-mêmes gardés par des contrôleurs que Laravel mémoïse sur l'objet Route.
 * La source (KLASSCI ou locale) doit donc être résolue À CHAQUE APPEL, comme
 * `LessonListService:213`. Ce test sert deux écoles avec la MÊME instance : il
 * rougit si la source est figée à la construction.
 */
final class AudienceResolueAChaqueAppelTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    public function test_la_meme_instance_sert_une_ecole_klassci_puis_une_ecole_du_lms(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $eleveKlassci = $this->membre($klassci, 'etudiant');
        $eleveKlassci->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $deKlassci = $this->publiee($klassci, ['klassci_classe_id' => 77]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 77, 'nom' => 'Terminale D']]]);
        });

        $lms = $this->ecole();
        $eleveLms = $this->membre($lms, 'etudiant');
        $classe = $this->classe($lms, '6e A');
        $this->inscrire($eleveLms, $classe, 'actif');
        $deLms = $this->publiee($lms, ['classe_id' => $classe->getKey(), 'klassci_classe_id' => null]);

        $audience = app(LearnerEvaluationScope::class);
        $tenants = app(TenantManager::class);

        $tenants->set($klassci);
        self::assertSame([$deKlassci->getKey()], $audience->visibleFor($eleveKlassci)?->pluck('id')->all());

        $tenants->set($lms);
        self::assertSame([$deLms->getKey()], $audience->visibleFor($eleveLms)?->pluck('id')->all());

        $tenants->set($klassci);
        self::assertSame([$deKlassci->getKey()], $audience->visibleFor($eleveKlassci)?->pluck('id')->all());
        $tenants->reset();
    }

    public function test_la_meme_fenetre_sert_une_ecole_klassci_puis_une_ecole_du_lms(): void
    {
        // Même règle pour la fenêtre (E2b) : la source KLASSCI exige le jeton
        // (`no_token`), la source locale non. Une source figée à la
        // construction rendrait le même verdict aux deux écoles.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $sansJeton = $this->membre($klassci, 'etudiant');
        $lms = $this->ecole();
        $eleveLms = $this->membre($lms, 'etudiant');

        $fenetre = app(EvaluationWindowGate::class);
        $tenants = app(TenantManager::class);

        $tenants->set($klassci);
        self::assertSame(['status' => 'no_token'], $fenetre->refusAvantLecture($sansJeton));
        $tenants->set($lms);
        self::assertNull($fenetre->refusAvantLecture($eleveLms));
        $tenants->set($klassci);
        self::assertSame(['status' => 'no_token'], $fenetre->refusAvantLecture($sansJeton));
        $tenants->reset();
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function publiee(Institution $ecole, array $surcharges): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(),
            'is_published' => true,
            'status' => 'planifiee',
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return $evaluation;
    }
}
