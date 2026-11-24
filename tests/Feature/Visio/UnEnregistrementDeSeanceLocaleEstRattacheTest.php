<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\Seances\LocalSeanceCreator;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * #1483 — l'enregistrement d'une séance LOCALE est rattaché à la leçon de son enseignant.
 *
 * ## Le défaut
 *
 * Le rattachement ne lisait que l'espace KLASSCI : `klassci_matiere_id` (refus d'emblée en
 * `missing_klassci_matiere_id`), `klassci_classe_id` et `klassci_enseignant_id`. Une séance
 * d'école autonome ({@see LocalSeanceCreator}) a les trois à null et
 * porte `matiere_id`, `classe_id` et `created_by`. Son enregistrement n'était JAMAIS rattaché.
 *
 * ## Ce que ces tests gardent surtout
 *
 * Corriger la seule matière enverrait la vidéo dans la leçon de n'importe quel enseignant de
 * la même matière et de la même classe : le défaut #707. L'enseignant d'une séance locale est
 * `created_by`, la règle de `VisioActorAuthorization` et de `SeanceRecordingAccessService`.
 *
 * Et le choix se fait par le MODE de l'école, jamais par la nullité d'une colonne : les deux
 * derniers tests croisent les deux.
 *
 * Par le vrai job, sans école résolue (un job n'en a pas).
 */
final class UnEnregistrementDeSeanceLocaleEstRattacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_seance_locale_donne_un_chapitre_dans_la_lecon_de_son_enseignant(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        [$enseignant, $matiere, $classe] = $this->cadre($ecole);
        $collegue = User::factory()->teacher()->create(['institution_id' => $ecole->id]);
        $sienne = $this->lecon($ecole, $matiere, $classe, $enseignant);
        $this->lecon($ecole, $matiere, $classe, $collegue);

        $enregistrement = $this->traiter($this->seanceLocale($ecole, $matiere, $classe, $enseignant));

        self::assertSame(SeanceRecordingStatus::Ready, $enregistrement->status, (string) $enregistrement->error_message);
        self::assertSame($sienne->id, $enregistrement->lesson_id, 'La leçon de SON enseignant, pas celle d\'un collègue.');
        self::assertNotNull($enregistrement->chapter_id);
    }

    public function test_la_lecon_d_un_autre_enseignant_n_est_jamais_choisie(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        [$enseignant, $matiere, $classe] = $this->cadre($ecole);
        $collegue = User::factory()->teacher()->create(['institution_id' => $ecole->id]);
        $this->lecon($ecole, $matiere, $classe, $collegue);

        $enregistrement = $this->traiter($this->seanceLocale($ecole, $matiere, $classe, $enseignant));

        self::assertSame(SeanceRecordingStatus::Failed, $enregistrement->status);
        self::assertSame('lesson_not_found', $enregistrement->error_message);
        self::assertNull($enregistrement->chapter_id);
    }

    public function test_sans_matiere_locale_le_motif_est_nomme(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        [$enseignant, , $classe] = $this->cadre($ecole);

        $enregistrement = $this->traiter($this->seanceLocale($ecole, null, $classe, $enseignant));

        self::assertSame(SeanceRecordingStatus::Failed, $enregistrement->status);
        self::assertSame('missing_local_matiere_id', $enregistrement->error_message);
    }

    public function test_la_matiere_d_une_autre_ecole_n_est_pas_lue(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        [$enseignant, , $classe] = $this->cadre($ecole);
        $ailleurs = Matiere::factory()->create(['institution_id' => Institution::factory()->standalone()->create()->id]);

        $enregistrement = $this->traiter($this->seanceLocale($ecole, $ailleurs, $classe, $enseignant));

        self::assertSame('matiere_not_found', $enregistrement->error_message);
    }

    /** Le MODE choisit : en école KLASSCI, une matière locale ne remplace pas la matière KLASSCI. */
    public function test_en_ecole_klassci_une_matiere_locale_ne_remplace_pas_la_matiere_klassci(): void
    {
        $ecole = Institution::factory()->create();
        [$enseignant, $matiere, $classe] = $this->cadre($ecole);
        $this->lecon($ecole, $matiere, $classe, $enseignant);

        $enregistrement = $this->traiter($this->seanceLocale($ecole, $matiere, $classe, $enseignant));

        self::assertSame(SeanceRecordingStatus::Failed, $enregistrement->status);
        self::assertSame('missing_klassci_matiere_id', $enregistrement->error_message);
    }

    /** Le MODE choisit : en école autonome, un `klassci_matiere_id` ne détourne pas vers KLASSCI. */
    public function test_en_ecole_autonome_un_identifiant_klassci_ne_detourne_pas_le_rattachement(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        [$enseignant, $matiere, $classe] = $this->cadre($ecole);
        $sienne = $this->lecon($ecole, $matiere, $classe, $enseignant);
        $autreMatiere = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 701]);
        $this->lecon($ecole, $autreMatiere, $classe, $enseignant);
        $seance = $this->seanceLocale($ecole, $matiere, $classe, $enseignant);
        $seance->forceFill(['klassci_matiere_id' => 701])->save();

        $enregistrement = $this->traiter($seance);

        self::assertSame($sienne->id, $enregistrement->lesson_id, 'La matière locale de la séance, pas la 701.');
    }

    /** @return array{User, Matiere, Classe} */
    private function cadre(Institution $ecole): array
    {
        return [
            User::factory()->teacher()->create(['institution_id' => $ecole->id]),
            Matiere::factory()->create(['institution_id' => $ecole->id]),
            Classe::factory()->create(['institution_id' => $ecole->id]),
        ];
    }

    private function lecon(Institution $ecole, Matiere $matiere, Classe $classe, User $enseignant): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $ecole->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $enseignant->id,
        ]);
    }

    /** La forme qu'écrit `LocalSeanceCreator` : rien dans `klassci_*`. */
    private function seanceLocale(Institution $ecole, ?Matiere $matiere, Classe $classe, User $auteur): Seance
    {
        return Seance::factory()->forInstitution($ecole)->create([
            'klassci_seance_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'matiere_id' => $matiere?->id,
            'classe_id' => $classe->id,
            'created_by' => $auteur->id,
            'titre' => 'Comptabilité',
            'date_seance' => '2026-11-20 08:00:00',
        ]);
    }

    private function traiter(Seance $seance): SeanceRecording
    {
        $enregistrement = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        (new ProcessSeanceRecordingReady($enregistrement->id, 'https://cdn.exemple.test/local.mp4', null, 's3'))
            ->handle($this->app->make(SeanceRecordingAttachmentResolver::class), new NullLogger);

        return SeanceRecording::query()->withoutGlobalScope('institution')->findOrFail($enregistrement->id);
    }
}
