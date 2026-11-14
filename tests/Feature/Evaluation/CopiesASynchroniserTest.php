<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Les copies à synchroniser vers KLASSCI (D539, D540) : remises (`soumis`, `corrige`),
 * hors entraînement (`[PRACTICE]`). Une seule règle, `EvaluationSubmission::aSynchroniser()`,
 * lue par l'envoi des notes ET par le compteur de la liste (`submissions_to_sync_count`) :
 * le bouton de l'écran compte ce que l'envoi enverra.
 *
 * Sans aucune copie à synchroniser, l'envoi est refusé (422 `no_submission_to_sync`) avant
 * tout appel à KLASSCI, et rien n'est marqué. Avant, il envoyait `notes: []`, rendait 200 et
 * marquait l'évaluation publiée et ses copies synchronisées.
 *
 * Vrai jeton (`asTenant`), les deux types d'école.
 */
final class CopiesASynchroniserTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ENSEIGNANT_KLASSCI = 42;

    /** Les envois de notes reçus par le faux KLASSCI. @var list<array<string, mixed>> */
    private array $envois = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake(function (Request $requete) {
            if (str_contains($requete->url(), '/notes')) {
                $corps = $requete->data();
                $this->envois[] = is_array($corps) ? $corps : [];
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    /** Le compteur de la liste ne compte que les copies remises, hors entraînement. */
    #[DataProvider('ecoles')]
    public function test_le_compteur_de_la_liste_ne_compte_que_les_copies_a_synchroniser(InstitutionMode $mode): void
    {
        [$enseignant, $evaluation] = $this->evaluationAvecUnJeuMele($mode);

        $reponse = $this->asTenant($enseignant)->getJson('/api/evaluations')->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'get');
        $ligne = collect($reponse->json('data'))->firstWhere('id', $evaluation->id);
        self::assertIsArray($ligne);
        self::assertSame(5, $ligne['submissions_count'], 'toutes les copies, comme avant');
        self::assertSame(2, $ligne['submissions_to_sync_count'], 'soumis et corrige, hors entraînement');
    }

    /** L'équivalence : le compteur égale le nombre de notes envoyées à KLASSCI. */
    public function test_le_compteur_egale_le_nombre_de_notes_envoyees(): void
    {
        [$enseignant, $evaluation] = $this->evaluationAvecUnJeuMele(InstitutionMode::Klassci);

        $compteur = collect($this->asTenant($enseignant)->getJson('/api/evaluations')->json('data'))
            ->firstWhere('id', $evaluation->id)['submissions_to_sync_count'] ?? null;
        $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")->assertOk();

        self::assertCount(1, $this->envois, 'un seul envoi de notes');
        self::assertSame($compteur, count($this->envois[0]['notes'] ?? []));
    }

    /** Sans copie à synchroniser : 422 motivé, KLASSCI n'est jamais appelé, rien n'est marqué. */
    public function test_sans_copie_a_synchroniser_rien_n_est_envoye_ni_marque(): void
    {
        [$enseignant, $evaluation] = $this->evaluation(InstitutionMode::Klassci);
        $this->copie($evaluation, 'en_cours', null);
        $this->copie($evaluation, 'soumis', '[PRACTICE] Entraînement - note non officielle');

        $reponse = $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'no_submission_to_sync');

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/sync-klassci', 'post');
        self::assertSame([], $this->envois, 'KLASSCI n est jamais appelé');
        self::assertFalse((bool) $evaluation->fresh()?->notes_published);
        self::assertSame(0, EvaluationSubmission::query()->where('evaluation_id', $evaluation->id)->where('synced_to_klassci', true)->count());
    }

    /** Une seule copie remise : l'envoi part, comme avant (témoin). */
    public function test_une_copie_remise_est_envoyee_comme_avant(): void
    {
        [$enseignant, $evaluation] = $this->evaluation(InstitutionMode::Klassci);
        $this->copie($evaluation, 'soumis', null);

        $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")
            ->assertOk()
            ->assertJsonPath('message', 'Notes synchronisées vers KLASSCI');

        self::assertCount(1, $this->envois);
        self::assertCount(1, $this->envois[0]['notes'] ?? []);
    }

    /**
     * en_cours, soumis, corrige, et deux entraînements (soumis et corrige) : 2 copies à
     * synchroniser sur 5.
     *
     * @return array{0: User, 1: Evaluation}
     */
    private function evaluationAvecUnJeuMele(InstitutionMode $mode): array
    {
        [$enseignant, $evaluation] = $this->evaluation($mode);
        $this->copie($evaluation, 'en_cours', null);
        $this->copie($evaluation, 'soumis', null);
        $this->copie($evaluation, 'corrige', 'Bien.');
        $this->copie($evaluation, 'soumis', '[PRACTICE] Entraînement - note non officielle');
        $this->copie($evaluation, 'corrige', '[PRACTICE] Entraînement - note non officielle');

        return [$enseignant, $evaluation];
    }

    /** @return array{0: User, 1: Evaluation} */
    private function evaluation(InstitutionMode $mode): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode, 'klassci_api_url' => 'https://klassci.test']);
        $enseignant = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI,
            'klassci_token' => 'jeton-enseignant',
        ]);
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI,
            'enseignant_id' => $enseignant->id,
            'klassci_evaluation_id' => $mode === InstitutionMode::Klassci ? 7777 : null,
            'notes_published' => false,
        ]);

        return [$enseignant, $evaluation];
    }

    private function copie(Evaluation $evaluation, string $statut, ?string $feedback): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $evaluation->institution_id,
            'status' => $statut,
            'feedback' => $feedback,
            'synced_to_klassci' => false,
        ]);
    }
}
