<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Enums\LessonStatus;
use App\Enums\SeanceRecordingStatus;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le parcours complet d'un enregistrement, du webhook à l'étudiant (#469).
 *
 * ## Le trou que ce fichier comble
 *
 * Chaque maillon était testé **isolément** :
 *
 * - `VisioRecordingWebhookTest` pose `Queue::fake()` et vérifie qu'un job est
 *   **mis en file** ;
 * - `ProcessSeanceRecordingReadyTest` vérifie que le job attache **quand on
 *   l'exécute à la main**.
 *
 * Personne ne vérifiait que les deux se rejoignent. Un webhook qui met en file
 * un job qui ne s'exécute jamais, ou qui s'exécute sur un enregistrement que la
 * résolution rejette, donnait deux suites vertes et **aucun chapitre**.
 *
 * Ce test fait tourner la chaîne pour de vrai : requête HTTP signée, job
 * exécuté, chapitre créé, étudiant qui le lit. C'est le « parcours bout en bout »
 * que le critère de fermeture de #469 réclame côté LMS.
 *
 * ## Ce qu'il ne prouve pas — et qu'il ne peut pas prouver
 *
 * Il ne démontre **pas** qu'un Jibri réel produit le fichier, ni que le script
 * de finalisation appelle réellement ce webhook. Ces deux maillons vivent sur le
 * serveur visio. Ce test prouve que **tout ce qui est du ressort du LMS est
 * juste** : si le fournisseur appelle correctement, le cours apparaît.
 */
final class VisioRecordingToChapterFlowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'webhook-secret-e2e-469';

    private const KLASSCI_MATIERE = 701;

    private const KLASSCI_CLASSE = 31;

    private const VIDEO_URL = 'https://recordings.example.test/seance-live.mp4';

    private Institution $institution;

    private User $teacher;

    private Classe $classe;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        config(['services.visio.webhook_secret' => self::WEBHOOK_SECRET]);

        // Le job est poussé sur la file `low`. En test la connexion est
        // `database` : il serait mis en file et jamais exécuté, et ce fichier ne
        // testerait qu'un demi-parcours. On l'exécute donc en ligne — c'est
        // précisément ce qu'un test de bout en bout doit faire.
        config(['queue.default' => 'sync']);

        $this->institution = Institution::factory()->create();

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);

        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_MATIERE,
        ]);

        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_CLASSE,
        ]);

        $this->lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $this->classe->id,
            'enseignant_id' => $this->teacher->id,
            'status' => LessonStatus::Published,
        ]);
    }

    /**
     * LE parcours : le fournisseur annonce la vidéo, l'étudiant la voit.
     */
    public function test_a_signed_webhook_publishes_the_recording_and_the_student_can_read_it(): void
    {
        $recording = $this->givenARecordingBeingProcessed();
        $student = $this->givenAStudentEnrolledIn($this->classe);

        $this->providerAnnouncesTheRecording($recording)->assertStatus(202);

        // 1. L'enregistrement est marqué prêt et porte son média.
        $recording->refresh();
        self::assertSame(SeanceRecordingStatus::Ready, $recording->status);
        self::assertNotNull($recording->chapter_id, 'Le chapitre doit être rattaché à l enregistrement.');

        // 2. Le chapitre existe dans LE cours de CET enseignant.
        $chapter = Chapter::query()->findOrFail($recording->chapter_id);
        self::assertSame($this->lesson->id, $chapter->lesson_id);
        self::assertSame('video', $chapter->content_type);
        self::assertSame(self::VIDEO_URL, $chapter->video_url);
        self::assertSame('visio_recording', $chapter->notes_enseignant['source'] ?? null);

        // 3. L'étudiant de la classe le lit réellement, via l'API.
        $this->asUser($student)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.video_url', self::VIDEO_URL);
    }

    /**
     * Le pendant indispensable : la vidéo d'une classe ne fuit pas vers une autre.
     *
     * Sans cette assertion, le parcours « fonctionne » tout en publiant le cours
     * filmé d'une classe à toute l'école.
     */
    public function test_a_student_of_another_classe_cannot_read_the_recording(): void
    {
        $recording = $this->givenARecordingBeingProcessed();
        $autreClasse = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 32,
        ]);
        $intrus = $this->givenAStudentEnrolledIn($autreClasse);

        $this->providerAnnouncesTheRecording($recording)->assertStatus(202);

        $chapter = Chapter::query()->where('content_type', 'video')->firstOrFail();

        $this->asUser($intrus)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertStatus(404);
    }

    /**
     * Le fournisseur peut réémettre son webhook : la chaîne complète doit rester
     * idempotente, sans créer un second chapitre vidéo dans le cours.
     */
    public function test_replaying_the_whole_chain_never_duplicates_the_chapter(): void
    {
        $recording = $this->givenARecordingBeingProcessed();

        $this->providerAnnouncesTheRecording($recording, nonce: 'premier-envoi')->assertStatus(202);
        $this->providerAnnouncesTheRecording($recording, nonce: 'second-envoi')->assertStatus(202);

        self::assertSame(
            1,
            Chapter::query()->where('lesson_id', $this->lesson->id)->where('content_type', 'video')->count(),
            'Un second envoi du fournisseur a créé un chapitre vidéo en double.',
        );
    }

    // ───────────────────── Fixtures ─────────────────────

    private function givenARecordingBeingProcessed(): SeanceRecording
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => self::KLASSCI_MATIERE,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'klassci_enseignant_id' => $this->teacher->klassci_enseignant_id,
            'titre' => 'Cours filmé',
        ]);

        return SeanceRecording::factory()->forSeance($seance)->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);
    }

    private function givenAStudentEnrolledIn(Classe $classe): User
    {
        $student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);

        UserClass::query()->create([
            'user_id' => $student->id,
            'institution_id' => $this->institution->id,
            'klassci_classe_id' => $classe->klassci_id,
            'classe_nom' => $classe->libelle,
        ]);

        return $student;
    }

    /**
     * Le fournisseur appelle le webhook, signé exactement comme en production :
     * HMAC sur `timestamp\nnonce\ncorps`, horodatage et nonce anti-rejeu.
     */
    private function providerAnnouncesTheRecording(SeanceRecording $recording, string $nonce = 'nonce-e2e'): TestResponse
    {
        $body = [
            'recording_id' => $recording->id,
            'url' => self::VIDEO_URL,
            'provider' => 'external',
        ];

        $timestamp = (string) time();
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->postJson('/api/webhooks/visio/recording-ready', $body, [
            'X-Visio-Signature' => 'sha256='.hash_hmac('sha256', $timestamp."\n".$nonce."\n".$raw, self::WEBHOOK_SECRET),
            'X-Visio-Timestamp' => $timestamp,
            'X-Visio-Nonce' => $nonce,
        ]);
    }

    /**
     * Jeton porteur RÉEL : sans bearer, aucun tenant n'est résolu et le scope
     * multi-tenant s'efface. `forgetGuards()` est indispensable — le garde
     * mémoïse l'utilisateur du premier appel (#691).
     */
    private function asUser(User $user): self
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('e2e-visio')->plainTextToken,
        ]);
    }
}
