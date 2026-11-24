<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserClass;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1444 : un rappel d'évaluation en échec fait échouer la commande, et seulement lui.
 *
 * `evaluations:notify-upcoming` écrivait l'échec puis rendait SUCCESS : le planificateur ne
 * voyait jamais qu'un rappel n'était pas parti. Les autres rappels, eux, doivent partir :
 * `RappelDEvaluation::rappeler` continue après un échec, et la commande ne doit pas l'annuler.
 */
final class RappelEnEchecTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        // Le rappel n'appelle jamais KLASSCI (#1247).
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_un_echec_parmi_trois_fait_echouer_la_commande_et_les_autres_partent(InstitutionMode $mode): void
    {
        [$enEchec, $deuxieme, $troisieme] = $this->troisEvaluations($mode);
        // L'échec vient de la lecture des rappels du jour de la PREMIÈRE évaluation (ordre des id).
        $this->faireEchouerLaPremiereRequeteSur('notifications');

        $this->rappeler()
            ->expectsOutputToContain("1 rappel(s) en échec : évaluation(s) {$enEchec->id}.")
            ->assertExitCode(1);

        self::assertSame([$deuxieme->id, $troisieme->id], $this->evaluationsRappelees());
    }

    /** Verrou : sans échec, la commande réussit et chaque évaluation est rappelée. */
    #[DataProvider('volets')]
    public function test_sans_echec_la_commande_reussit(InstitutionMode $mode): void
    {
        $evaluations = $this->troisEvaluations($mode);

        $this->rappeler()->assertExitCode(0);

        self::assertSame(array_map(static fn (Evaluation $e): int => $e->id, $evaluations), $this->evaluationsRappelees());
    }

    /** @return array{0: Evaluation, 1: Evaluation, 2: Evaluation} triées par id */
    private function troisEvaluations(InstitutionMode $mode): array
    {
        $klassci = $mode === InstitutionMode::Klassci;
        $ecole = Institution::factory()->create($klassci
            ? ['mode' => $mode, 'klassci_api_url' => 'https://klassci-ecole.test', 'klassci_api_token' => 'jeton-ecole']
            : ['mode' => $mode, 'klassci_api_url' => null, 'klassci_api_token' => null]);
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
        $this->voit($this->membre($ecole, 'etudiant'), $ecole, $classe, $klassci);

        return [
            $this->evaluation($ecole, $classe, $klassci),
            $this->evaluation($ecole, $classe, $klassci),
            $this->evaluation($ecole, $classe, $klassci),
        ];
    }

    private function evaluation(Institution $ecole, Classe $classe, bool $klassci): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $klassci ? null : $classe->id,
            'klassci_classe_id' => $klassci ? self::KLASSCI_CLASSE : null,
            'is_published' => true,
            'status' => 'planifiee',
            'date_evaluation' => now()->addHours(2),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'institution_id' => $ecole->id]);

        return $evaluation;
    }

    /** L'élève voit la classe : par le miroir KLASSCI, ou par une inscription active. */
    private function voit(User $eleve, Institution $ecole, Classe $classe, bool $klassci): void
    {
        if (! $klassci) {
            $this->inscrire($eleve, $classe, 'actif');

            return;
        }

        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id,
            'institution_id' => $ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'classe_nom' => '6e A',
        ]);
    }

    /** Lancée comme le planificateur : sans tenant hérité du montage. */
    private function rappeler(): PendingCommand
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->reset();

        return $this->artisan('evaluations:notify-upcoming', ['--hours' => 24]);
    }

    /** @return list<int> les évaluations pour lesquelles un rappel a été écrit, triées */
    private function evaluationsRappelees(): array
    {
        $ids = Notification::withoutGlobalScope('institution')
            ->where('type', Notification::TYPE_EVALUATION_APPROACHING)
            ->pluck('data')
            ->map(static function (mixed $donnees): int {
                $lues = is_string($donnees) ? json_decode($donnees, true) : $donnees;

                return is_array($lues) && is_numeric($lues['evaluation_id'] ?? null) ? (int) $lues['evaluation_id'] : 0;
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_values($ids);
    }

    /** La première requête qui touche `$table` lève, comme une base verrouillée. */
    private function faireEchouerLaPremiereRequeteSur(string $table): void
    {
        $dejaLevee = false;
        DB::connection()->beforeExecuting(static function (string $sql, array $liaisons) use ($table, &$dejaLevee): void {
            if ($dejaLevee || ! str_contains($sql, $table)) {
                return;
            }
            $dejaLevee = true;

            throw new QueryException('sqlite', $sql, $liaisons, new PDOException('verrou'));
        });
    }
}
