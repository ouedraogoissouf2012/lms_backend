<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1097, point 2 : `POST /evaluations/{id}/sync-klassci` envoie les copies remises hors
 * entraînement (`EvaluationSubmission::aSynchroniser()`), mais marquait `synced_to_klassci`
 * et `synced_at` sur TOUTES les copies de l'évaluation. Une copie en cours, verrouillée,
 * ou un entraînement était dit synchronisé sans être parti, et l'écran de l'enseignant
 * l'affichait ainsi.
 *
 * Les copies sont choisies une fois ; l'envoi et le marquage lisent les mêmes.
 *
 * Seule la frontière réseau est feinte (une closure par requête) ; vrai jeton Sanctum.
 */
final class SyncKlassciMarqueLesCopiesEnvoyeesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENSEIGNANT = 4242;

    private const EVALUATION_KLASSCI = 9001;

    private User $enseignant;

    private Evaluation $evaluation;

    /** @var list<int> les `etudiant_id` du corps envoyé à KLASSCI */
    private array $envoyes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $ecole = Institution::factory()->create(['klassci_api_url' => 'https://klassci.ecole.test']);
        $this->enseignant = User::factory()->for($ecole)->create([
            'role' => 'enseignant',
            'klassci_enseignant_id' => self::ENSEIGNANT,
            'klassci_token' => 'jeton-enseignant',
        ]);
        $this->evaluation = Evaluation::factory()->terminee()->create([
            'institution_id' => $ecole->id,
            'klassci_enseignant_id' => self::ENSEIGNANT,
            'klassci_evaluation_id' => self::EVALUATION_KLASSCI,
            'is_published' => true,
            'notes_published' => false,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $this->evaluation->id,
            'institution_id' => $ecole->id,
            'type' => 'qcm',
        ]);
    }

    public function test_seules_les_copies_envoyees_sont_marquees_et_datees(): void
    {
        $soumise = $this->copie(5001, 'soumis');
        $corrigee = $this->copie(5002, 'corrige');
        $enCours = $this->copie(5003, 'en_cours');
        $entrainement = $this->copie(5004, 'soumis', '[PRACTICE] entraînement');
        $this->klassciEnregistre();

        $this->asTenant($this->enseignant)
            ->postJson("/api/evaluations/{$this->evaluation->id}/sync-klassci")
            ->assertOk();

        foreach ([$soumise, $corrigee] as $envoyee) {
            $envoyee->refresh();
            self::assertTrue($envoyee->synced_to_klassci, "La copie {$envoyee->klassci_etudiant_id} est partie : marquée.");
            self::assertNotNull($envoyee->synced_at);
        }
        foreach ([$enCours, $entrainement] as $restee) {
            $restee->refresh();
            self::assertFalse($restee->synced_to_klassci, "La copie {$restee->klassci_etudiant_id} n'est pas partie : non marquée.");
            self::assertNull($restee->synced_at);
        }
    }

    public function test_les_copies_marquees_sont_celles_du_corps_envoye(): void
    {
        $this->copie(5001, 'soumis');
        $this->copie(5002, 'corrige');
        $this->copie(5003, 'en_cours');
        $this->copie(5004, 'soumis', '[PRACTICE] entraînement');
        $this->klassciEnregistre();

        $this->asTenant($this->enseignant)
            ->postJson("/api/evaluations/{$this->evaluation->id}/sync-klassci")
            ->assertOk();

        $marquees = EvaluationSubmission::query()
            ->where('evaluation_id', $this->evaluation->id)
            ->where('synced_to_klassci', true)
            ->pluck('klassci_etudiant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $envoyes = $this->envoyes;
        sort($envoyes);

        self::assertSame([5001, 5002], $envoyes);
        self::assertSame($envoyes, $marquees, 'Envoyées = marquées.');
    }

    /** La règle voisine (#1091) ne régresse pas : un échec déclaré ne marque aucune copie. */
    public function test_un_echec_declare_par_klassci_ne_marque_aucune_copie(): void
    {
        $this->copie(5001, 'soumis');
        $this->copie(5002, 'corrige');
        $this->copie(5003, 'en_cours');
        $this->klassciEnregistre(false);

        $this->asTenant($this->enseignant)
            ->postJson("/api/evaluations/{$this->evaluation->id}/sync-klassci")
            ->assertStatus(503);

        self::assertSame(0, EvaluationSubmission::query()
            ->where('evaluation_id', $this->evaluation->id)
            ->where(fn ($q) => $q->where('synced_to_klassci', true)->orWhereNotNull('synced_at'))
            ->count());
        self::assertFalse($this->evaluation->refresh()->notes_published);
    }

    private function copie(int $etudiant, string $statut, ?string $feedback = null): EvaluationSubmission
    {
        return EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => $etudiant,
            'attempt' => 1,
            'status' => $statut,
            'feedback' => $feedback,
            'started_at' => now(),
            'submitted_at' => $statut === 'en_cours' ? null : now(),
            'note_sur_20' => $statut === 'en_cours' ? null : 12,
            'institution_id' => $this->evaluation->institution_id,
        ]);
    }

    /**
     * KLASSCI enregistre (ou déclare un échec en 2xx) ; le corps du POST est relevé
     * (une closure par requête).
     */
    private function klassciEnregistre(bool $succes = true): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) use ($succes): PromiseInterface {
            if (str_contains($requete->url(), 'evaluations/'.self::EVALUATION_KLASSCI.'/notes')) {
                $notes = $requete->data()['notes'] ?? [];
                foreach (is_array($notes) ? $notes : [] as $note) {
                    $this->envoyes[] = (int) ($note['etudiant_id'] ?? 0);
                }

                return $succes
                    ? Http::response(['success' => true, 'data' => ['saved_count' => count($this->envoyes)]])
                    : Http::response(['success' => false, 'message' => 'Notes non enregistrées']);
            }

            return Http::response(['success' => true, 'data' => []]);
        });
        $this->app->instance(HttpFactory::class, $factory);
    }
}
