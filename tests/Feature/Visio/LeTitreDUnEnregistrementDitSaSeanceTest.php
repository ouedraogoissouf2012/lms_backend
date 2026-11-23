<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;
use Tests\Unit\Services\Visio\Recording\TitreDEnregistrementTest;

/**
 * #854 — de l'enregistrement prêt au chapitre, par le vrai job : le titre distingue la séance,
 * et un chapitre renommé par l'enseignant n'est plus écrasé au prochain enregistrement.
 *
 * Volet KLASSCI seulement : c'est le seul qui rattache aujourd'hui. Une séance locale échoue en
 * amont (`missing_klassci_matiere_id`, #1483) ; sa règle de titre est prouvée dans
 * {@see TitreDEnregistrementTest}.
 */
final class LeTitreDUnEnregistrementDitSaSeanceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    private User $enseignant;

    private Lesson $lecon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ecole = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->ecole);

        Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 701]);
        $classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 31]);
        $this->enseignant = User::factory()->teacher()->create(['institution_id' => $this->ecole->id, 'klassci_enseignant_id' => 9001]);
        $this->lecon = Lesson::factory()->create([
            'institution_id' => $this->ecole->id,
            'matiere_id' => Matiere::query()->where('klassci_id', 701)->value('id'),
            'classe_id' => $classe->id,
            'enseignant_id' => $this->enseignant->id,
        ]);
    }

    public function test_deux_seances_de_la_meme_matiere_donnent_deux_titres(): void
    {
        $premiere = $this->enregistrer($this->seance(501, '2026-09-15 08:00:00'));
        $seconde = $this->enregistrer($this->seance(502, '2026-09-18 08:00:00'));

        self::assertSame('Enregistrement - Marketing digital - 15/09/2026 08:00', $premiere->title);
        self::assertSame('Enregistrement - Marketing digital - 18/09/2026 08:00', $seconde->title);
    }

    public function test_un_chapitre_renomme_n_est_pas_ecrase_au_prochain_enregistrement(): void
    {
        $seance = $this->seance(501, '2026-09-18 08:00:00');
        $chapitre = $this->enregistrer($seance);

        $this->asTenant($this->enseignant)->putJson("/api/chapters/{$chapitre->id}", ['title' => 'Cours du lundi'])->assertOk();

        // Deux enregistrements de plus : chacun réécrit les notes du chapitre. La protection
        // doit survivre à chacun, pas seulement au premier.
        $this->enregistrer($seance, 'https://cdn.exemple.test/deux.mp4');
        $relu = $this->enregistrer($seance, 'https://cdn.exemple.test/trois.mp4');

        self::assertSame($chapitre->id, $relu->id, 'Un chapitre par séance, pas un par enregistrement.');
        self::assertSame('Cours du lundi', $relu->title);
        self::assertSame('https://cdn.exemple.test/trois.mp4', $relu->video_url, 'La vidéo, elle, suit.');
        $this->assertToujoursReconnuParLaRetention($relu, $seance);
    }

    public function test_un_titre_fourni_par_le_fournisseur_n_ecrase_pas_un_renommage(): void
    {
        $seance = $this->seance(501, '2026-09-18 08:00:00');
        $chapitre = $this->enregistrer($seance, 'https://cdn.exemple.test/un.mp4', 'Replay live');
        self::assertSame('Replay live', $chapitre->title);

        $this->asTenant($this->enseignant)->putJson("/api/chapters/{$chapitre->id}", ['title' => 'Cours du lundi'])->assertOk();
        $relu = $this->enregistrer($seance, 'https://cdn.exemple.test/deux.mp4', 'Replay live');

        self::assertSame('Cours du lundi', $relu->title);
    }

    public function test_un_chapitre_encore_automatique_suit_sa_seance(): void
    {
        $seance = $this->seance(501, '2026-09-18 08:00:00');
        $this->enregistrer($seance);

        $seance->update(['date_seance' => '2026-09-18 09:00:00']);
        $relu = $this->enregistrer($seance, 'https://cdn.exemple.test/deux.mp4');

        self::assertSame('Enregistrement - Marketing digital - 18/09/2026 09:00', $relu->title);
    }

    public function test_un_chapitre_a_l_ancien_defaut_prend_le_nouveau_titre(): void
    {
        $seance = $this->seance(501, '2026-09-18 08:00:00');
        $chapitre = $this->enregistrer($seance);
        // L'état d'un chapitre écrit avant #854 : l'ancien défaut, sans titre automatique noté.
        $chapitre->forceFill([
            'title' => 'Recording - Marketing digital',
            'notes_enseignant' => ['source' => 'visio_recording', 'seance_id' => $seance->id],
        ])->save();

        $relu = $this->enregistrer($seance, 'https://cdn.exemple.test/deux.mp4');

        self::assertSame('Enregistrement - Marketing digital - 18/09/2026 08:00', $relu->title);
    }

    private function seance(int $klassciId, string $date): Seance
    {
        return Seance::factory()->forInstitution($this->ecole)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => 9001,
            'klassci_seance_id' => $klassciId,
            'titre' => 'Marketing digital',
            'date_seance' => $date,
        ]);
    }

    private function enregistrer(Seance $seance, string $url = 'https://cdn.exemple.test/un.mp4', ?string $titre = null): Chapter
    {
        $enregistrement = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        (new ProcessSeanceRecordingReady($enregistrement->id, $url, $titre, 's3'))
            ->handle($this->app->make(SeanceRecordingAttachmentResolver::class), new NullLogger);

        $chapitre = Chapter::query()->withoutGlobalScope('institution')->find($enregistrement->refresh()->chapter_id);
        self::assertInstanceOf(Chapter::class, $chapitre, 'L\'enregistrement devait être rattaché.');

        return $chapitre;
    }

    /** La rétention reconnaît « son » chapitre par `source` et `seance_id` : ils restent. */
    private function assertToujoursReconnuParLaRetention(Chapter $chapitre, Seance $seance): void
    {
        $notes = $chapitre->notes_enseignant;
        self::assertIsArray($notes);
        self::assertSame('visio_recording', $notes['source'] ?? null);
        self::assertSame($seance->id, (int) ($notes['seance_id'] ?? 0));
    }
}
