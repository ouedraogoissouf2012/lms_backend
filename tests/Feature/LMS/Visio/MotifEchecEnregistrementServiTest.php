<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\RecordingFailureReason;
use App\Enums\SeanceRecordingStatus;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le motif d'échec d'un enregistrement servi par `GET …/recording` ne sort que
 * du registre `RecordingFailureReason`.
 *
 * La liste blanche est à la sortie : une valeur hors registre est servie comme
 * `processing_exception`, que la ligne soit en base ou seulement dans le cache
 * d'état (servi quand la ligne n'existe plus).
 *
 * Jeton porteur réel (`asTenant`), sur la route réelle.
 */
final class MotifEchecEnregistrementServiTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** Une valeur hors registre. */
    private const HORS_REGISTRE = 'valeur-hors-registre-1002';

    private User $enseignant;

    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Cache::flush();

        $ecole = Institution::factory()->create();
        $this->enseignant = User::factory()->teacher()->for($ecole)->create([
            'klassci_id' => 9001,
            'klassci_enseignant_id' => 9001,
        ]);
        $this->seance = Seance::factory()->forInstitution($ecole)->visioActive()->create([
            'klassci_enseignant_id' => 9001,
            'klassci_classe_id' => 44,
        ]);
    }

    public function test_une_valeur_hors_registre_en_base_est_servie_comme_processing_exception(): void
    {
        $this->enregistrementEnEchec(self::HORS_REGISTRE);

        $reponse = $this->asTenant($this->enseignant)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.recording.status', 'failed')
            ->assertJsonPath('data.recording.error_message', RecordingFailureReason::ProcessingException->value);

        self::assertStringNotContainsString(self::HORS_REGISTRE, (string) $reponse->getContent());
    }

    /**
     * Le cache d'état est servi quand la ligne n'existe plus, et garde un état
     * jusqu'à 14 jours : il passe par la même liste blanche. La clé est celle de
     * `RecordingStateCache::key()`.
     */
    public function test_une_valeur_hors_registre_dans_le_cache_d_etat_est_servie_comme_processing_exception(): void
    {
        Cache::put('visio:recording:seance:'.$this->seance->id, [
            'id' => 'rec-ancien',
            'status' => 'failed',
            'error_message' => self::HORS_REGISTRE,
        ], 600);

        $reponse = $this->asTenant($this->enseignant)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.recording.error_message', RecordingFailureReason::ProcessingException->value);

        self::assertStringNotContainsString(self::HORS_REGISTRE, (string) $reponse->getContent());
    }

    /** @return iterable<string, array{0: RecordingFailureReason}> */
    public static function motifsDuRegistre(): iterable
    {
        foreach (RecordingFailureReason::cases() as $motif) {
            yield $motif->name => [$motif];
        }
    }

    #[DataProvider('motifsDuRegistre')]
    public function test_un_motif_du_registre_est_servi_tel_quel(RecordingFailureReason $motif): void
    {
        $this->enregistrementEnEchec($motif->value);

        $this->asTenant($this->enseignant)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.recording.error_message', $motif->value);
    }

    /** @return iterable<string, array{0: ?string}> */
    public static function absencesDeMotif(): iterable
    {
        yield 'null' => [null];
        yield 'chaîne vide' => [''];
    }

    #[DataProvider('absencesDeMotif')]
    public function test_sans_motif_rien_n_est_servi(?string $absence): void
    {
        $this->enregistrementEnEchec($absence);

        $this->asTenant($this->enseignant)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.recording.error_message', null);
    }

    private function enregistrementEnEchec(?string $motif): void
    {
        SeanceRecording::factory()->forSeance($this->seance)->create([
            'status' => SeanceRecordingStatus::Failed,
            'error_message' => $motif,
        ]);
    }

    private function url(): string
    {
        return "/api/lms/seances/{$this->seance->klassci_seance_id}/recording";
    }
}
