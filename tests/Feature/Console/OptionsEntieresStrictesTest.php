<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserClass;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1429 : une option entière saisie par un opérateur est validée strictement, ou refusée.
 *
 * `(int)` lisait `abc` comme 0 et `5abc` comme 5, en silence. `--hours=abc` rendait une fenêtre
 * vide et un SUCCESS ; `--chunk=5abc` lançait un backfill de 5 lignes par lot.
 *
 * Chaque refus est prouvé sur un état où la commande AURAIT écrit : une évaluation dans la
 * fenêtre et son élève, un compte KLASSCI à compléter. « Rien n'est écrit » ne vaut que si
 * quelque chose pouvait l'être ; les verrous le montrent avec une saisie valide.
 */
final class OptionsEntieresStrictesTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        // Le rappel n'appelle jamais KLASSCI (#1247) : tout appel serait un défaut.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function heuresRefusees(): iterable
    {
        yield 'texte' => ['abc'];
        yield 'zéro' => ['0'];
        yield 'négatif' => ['-3'];
        yield 'entier suivi de texte' => ['5abc'];
        yield 'au-delà d une semaine' => ['169'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function heuresAcceptees(): iterable
    {
        yield 'borne basse' => ['1'];
        yield 'la valeur planifiée' => ['24'];
        yield 'borne haute, une semaine' => ['168'];
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function lotsRefuses(): iterable
    {
        foreach (['klassci:backfill-role', 'klassci:backfill-enseignant-id'] as $commande) {
            yield "$commande, texte" => [$commande, 'abc'];
            yield "$commande, zéro" => [$commande, '0'];
            yield "$commande, entier suivi de texte" => [$commande, '5abc'];
            yield "$commande, au-delà de 10000" => [$commande, '10001'];
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function lotsAcceptes(): iterable
    {
        foreach (['klassci:backfill-role', 'klassci:backfill-enseignant-id'] as $commande) {
            yield "$commande, borne basse" => [$commande, '1'];
            yield "$commande, borne haute" => [$commande, '10000'];
        }
    }

    /**
     * @return iterable<string, array{0: InstitutionMode, 1: string}>
     */
    public static function heuresRefuseesParVolet(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            foreach (self::heuresRefusees() as $cas => [$heures]) {
                yield "$volet, $cas" => [$mode, $heures];
            }
        }
    }

    #[DataProvider('heuresRefuseesParVolet')]
    public function test_le_rappel_refuse_une_fenetre_invalide_et_n_ecrit_rien(InstitutionMode $mode, string $heures): void
    {
        $this->evaluationDansLaFenetre($mode);

        $this->rappeler($heures)
            ->expectsOutputToContain('--hours attend un entier de 1 à 168')
            ->assertExitCode(1);

        self::assertSame(0, Notification::withoutGlobalScope('institution')->count());
    }

    #[DataProvider('heuresAcceptees')]
    public function test_le_rappel_accepte_une_fenetre_valide_et_avertit(string $heures): void
    {
        $this->evaluationDansLaFenetre(InstitutionMode::Standalone);

        $this->rappeler($heures)->assertExitCode(0);

        self::assertSame(1, Notification::withoutGlobalScope('institution')->count());
    }

    #[DataProvider('lotsRefuses')]
    public function test_un_backfill_refuse_un_lot_invalide_et_n_ecrit_rien(string $commande, string $lot): void
    {
        $compte = $this->compteACompleter();

        $this->artisan($commande, ['--chunk' => $lot])
            ->expectsOutputToContain('between 1 and 10000')
            ->assertExitCode(1);

        self::assertSame([null, null], $this->colonnesCompletees($compte));
    }

    #[DataProvider('lotsAcceptes')]
    public function test_un_backfill_accepte_un_lot_valide_et_complete(string $commande, string $lot): void
    {
        $compte = $this->compteACompleter();

        $this->artisan($commande, ['--chunk' => $lot])->assertExitCode(0);

        $attendu = $commande === 'klassci:backfill-role' ? ['enseignant', null] : [null, 42];
        self::assertSame($attendu, $this->colonnesCompletees($compte));
    }

    /** Une évaluation dans 30 minutes et un élève qui la voit : un rappel valide l'avertit. */
    private function evaluationDansLaFenetre(InstitutionMode $mode): void
    {
        $klassci = $mode === InstitutionMode::Klassci;
        $ecole = Institution::factory()->create($klassci
            ? ['mode' => $mode, 'klassci_api_url' => 'https://klassci-ecole.test', 'klassci_api_token' => 'jeton-ecole']
            : ['mode' => $mode, 'klassci_api_url' => null, 'klassci_api_token' => null]);
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->voit($eleve, $ecole, $classe, $klassci);

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $klassci ? null : $classe->id,
            'klassci_classe_id' => $klassci ? self::KLASSCI_CLASSE : null,
            'titre' => 'Contrôle de fractions',
            'is_published' => true,
            'status' => 'planifiee',
            'date_evaluation' => now()->addMinutes(30),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'institution_id' => $ecole->id]);
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
    private function rappeler(string $heures): PendingCommand
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->reset();

        return $this->artisan('evaluations:notify-upcoming', ['--hours' => $heures]);
    }

    /** Un enseignant synchronisé, sans `klassci_role` ni `klassci_enseignant_id` : les deux backfills l'écriraient. */
    private function compteACompleter(): User
    {
        $compte = User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'enseignant',
            'klassci_id' => 100,
        ]);
        DB::table('users')->where('id', $compte->id)->update([
            'klassci_role' => null,
            'klassci_enseignant_id' => null,
            'klassci_data' => json_encode(['enseignant_id' => 42]),
        ]);

        return $compte;
    }

    /** @return array{0: mixed, 1: mixed} `klassci_role` et `klassci_enseignant_id`, lus en base */
    private function colonnesCompletees(User $compte): array
    {
        $ligne = DB::table('users')->where('id', $compte->id)->first(['klassci_role', 'klassci_enseignant_id']);
        self::assertNotNull($ligne);

        $enseignantId = $ligne->klassci_enseignant_id;

        return [$ligne->klassci_role, $enseignantId === null ? null : (int) $enseignantId];
    }
}
