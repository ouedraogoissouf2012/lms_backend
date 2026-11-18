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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #1247 : le rappel du matin (`evaluations:notify-upcoming`) avertit les élèves qui VOIENT
 * l'évaluation, par la règle d'inscription de l'école de sa classe, sans appeler KLASSCI.
 *
 * Avant : la commande envoyait le jeton de l'école à l'adresse KLASSCI par défaut du serveur,
 * prenait l'identifiant KLASSCI rendu pour un identifiant LOCAL, ignorait le mode de l'école,
 * et créait la notification sans établissement (invisible pour son destinataire).
 *
 * Le serveur a une adresse KLASSCI globale : c'est la situation où le jeton partait. Sans
 * elle, l'ancien code levait avant d'envoyer et l'absence d'appel ne prouverait rien. La fausse
 * réponse KLASSCI rend un compte LEURRE d'une autre école.
 */
final class RappelDEvaluationTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 7001;

    private Institution $ecole;

    private Classe $classe;

    private User $leurre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureWrittenLog();
        config(['services.klassci.url' => 'https://klassci-global.test']);
        $this->leurre = $this->membre(Institution::factory()->create(), 'etudiant');
        // Une seule closure : deux `Http::fake` s'empilent et le premier gagne.
        Http::fake(fn (Request $requete) => Http::response(['data' => [['id' => $this->leurre->id]]], 200));
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

    public function test_ecole_klassci_l_eleve_du_miroir_est_averti_jamais_le_compte_rendu_par_klassci(): void
    {
        $this->monter(InstitutionMode::Klassci);
        $parLeMiroir = $this->membre($this->ecole, 'etudiant');
        $this->miroir($parLeMiroir);
        $evaluation = $this->evaluation();

        $this->rappeler();

        self::assertSame([$parLeMiroir->id], $this->avertis($evaluation));
        Http::assertNothingSent();
    }

    public function test_ecole_autonome_les_eleves_actifs_de_la_classe_sont_avertis(): void
    {
        $this->monter(InstitutionMode::Standalone);
        $actif = $this->eleveInscrit('actif');
        $this->eleveInscrit('inactif');
        $evaluation = $this->evaluation();

        $this->rappeler();

        self::assertSame([$actif->id], $this->avertis($evaluation));
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string|null}> */
    public static function detachements(): iterable
    {
        yield 'URL vidée, jeton gardé' => [InstitutionMode::Klassci, null];
        yield 'basculée en autonome, URL et jeton gardés' => [InstitutionMode::Standalone, 'https://klassci-ecole.test'];
    }

    /** Le jeton d'une école détachée ne quitte jamais le serveur. */
    #[DataProvider('detachements')]
    public function test_une_ecole_detachee_n_envoie_pas_son_jeton(InstitutionMode $mode, ?string $url): void
    {
        $this->monter($mode, ['klassci_api_url' => $url, 'klassci_api_token' => 'jeton-ecole-detachee']);
        $eleve = $this->eleveInscrit('actif');
        $evaluation = $this->evaluation();

        $this->rappeler();

        Http::assertNothingSent();
        self::assertSame([$eleve->id], $this->avertis($evaluation));
    }

    /** La notification porte l'école de l'évaluation : l'élève la lit, avec un vrai jeton. */
    #[DataProvider('volets')]
    public function test_l_eleve_lit_son_rappel(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $eleve = $this->eleveInscrit('actif');
        $evaluation = $this->evaluation();

        $this->rappeler();

        $rappel = Notification::withoutGlobalScope('institution')->where('user_id', $eleve->id)->sole();
        self::assertSame($this->ecole->id, (int) $rappel->institution_id);
        $this->disableKlassciMiddleware();
        $lus = $this->asTenant($eleve)->getJson('/api/notifications')->assertOk()->json('data');
        self::assertIsArray($lus);
        self::assertSame([Notification::TYPE_EVALUATION_APPROACHING], array_column($lus, 'type'));
        self::assertSame($evaluation->id, $lus[0]['data']['evaluation_id'] ?? null);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: array<string, mixed>, 2: bool}> */
    public static function invisibles(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, publiée sans question" => [$mode, [], false];
            yield "{$volet}, brouillon publié" => [$mode, ['status' => 'brouillon'], true];
            yield "{$volet}, non publiée" => [$mode, ['is_published' => false], true];
        }
    }

    /**
     * Le rappel suit ce que l'élève voit (`EvaluationsOuvertesAuxEleves`) : une évaluation
     * qu'il ne peut pas ouvrir n'est pas rappelée.
     *
     * @param  array<string, mixed>  $attributs
     */
    #[DataProvider('invisibles')]
    public function test_une_evaluation_que_l_eleve_ne_voit_pas_n_est_pas_rappelee(InstitutionMode $mode, array $attributs, bool $avecQuestion): void
    {
        $this->monter($mode);
        $eleve = $this->eleveInscrit('actif');
        $this->miroir($eleve);
        $evaluation = $this->evaluation($attributs, $avecQuestion);

        $this->rappeler();

        self::assertSame([], $this->avertis($evaluation));
    }

    #[DataProvider('volets')]
    public function test_deux_lancements_le_meme_jour_un_seul_rappel_par_eleve(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $deja = $this->eleveInscrit('actif');
        $nouveau = $this->eleveInscrit('actif');
        $evaluation = $this->evaluation();

        $this->rappeler();
        $this->rappeler();

        self::assertSame([$deja->id, $nouveau->id], $this->avertis($evaluation));
    }

    #[DataProvider('volets')]
    public function test_une_evaluation_hors_de_la_fenetre_n_est_pas_rappelee(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $this->eleveInscrit('actif');
        $evaluation = $this->evaluation(['date_evaluation' => now()->addHours(30)]);

        $this->rappeler();

        self::assertSame([], $this->avertis($evaluation));
    }

    /** Une évaluation dont la classe n'a pas de miroir local : personne, et le motif est écrit. */
    #[DataProvider('volets')]
    public function test_une_evaluation_sans_classe_locale_n_avertit_personne_et_le_dit(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $this->eleveInscrit('actif');
        $evaluation = $this->evaluation(['classe_id' => null, 'klassci_classe_id' => self::KLASSCI_CLASSE + 1]);

        $this->rappeler();

        self::assertSame([], $this->avertis($evaluation));
        $motifs = array_values(array_filter(
            $this->recordsAtLeast(Level::Info),
            static fn (LogRecord $r): bool => $r->message === 'Rappel d\'évaluation : aucune classe locale, personne à avertir',
        ));
        self::assertCount(1, $motifs);
        self::assertSame($evaluation->id, $motifs[0]->context['evaluation_id'] ?? null);
    }

    /** @param  array<string, mixed>  $ecole */
    private function monter(InstitutionMode $mode, array $ecole = []): void
    {
        $this->ecole = Institution::factory()->create(array_merge(
            $mode === InstitutionMode::Klassci
                ? ['mode' => $mode, 'klassci_api_url' => 'https://klassci-ecole.test', 'klassci_api_token' => 'jeton-ecole']
                : ['mode' => $mode, 'klassci_api_url' => null, 'klassci_api_token' => null],
            $ecole,
        ));
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
    }

    private function eleveInscrit(string $statut): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe, $statut);

        return $eleve;
    }

    /** L'élève KLASSCI tel que le login l'écrit : le miroir `user_classes`, sans pivot. */
    private function miroir(User $eleve): void
    {
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id,
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'classe_nom' => '6e A',
        ]);
    }

    /**
     * Une évaluation visible dans les deux heures. Sa classe est désignée comme son école la
     * désigne : `classe_id` en école autonome, `klassci_classe_id` en école KLASSCI.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function evaluation(array $attributs = [], bool $avecQuestion = true): Evaluation
    {
        $klassci = $this->ecole->mode === InstitutionMode::Klassci;
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $this->ecole->id,
            'classe_id' => $klassci ? null : $this->classe->id,
            'klassci_classe_id' => $klassci ? self::KLASSCI_CLASSE : null,
            'titre' => 'Contrôle de fractions',
            'is_published' => true,
            'status' => 'planifiee',
            'date_evaluation' => now()->addHours(2),
        ], $attributs));
        if ($avecQuestion) {
            EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'institution_id' => $this->ecole->id]);
        }

        return $evaluation;
    }

    /** La commande planifiée, lancée comme le planificateur : sans tenant hérité du montage. */
    private function rappeler(): void
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->reset();
        $this->artisan('evaluations:notify-upcoming', ['--hours' => 24])->assertSuccessful();
    }

    /** @return list<int> les comptes avertis de cette évaluation, triés, un par rappel créé */
    private function avertis(Evaluation $evaluation): array
    {
        $ids = Notification::withoutGlobalScope('institution')
            ->where('type', Notification::TYPE_EVALUATION_APPROACHING)
            ->where('data->evaluation_id', $evaluation->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($ids);

        return $ids;
    }
}
