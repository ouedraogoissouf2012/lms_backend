<?php

declare(strict_types=1);

namespace Tests\Feature\Conservation;

use App\Enums\InstitutionMode;
use App\Models\ESBTPAttendance;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\LessonProgress;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1705, PR 2 : l'export des données d'un élève (droit d'accès, loi 2013-450). Sous vrai jeton.
 *
 * - `GET /api/eleves/{eleve}/export?format=json|pdf`, par l'ADMIN de son école (admin, superAdmin), dans les deux
 *   volets. Hors de l'école, ou un compte qui n'est pas un élève : 404, du même corps qu'un id absent.
 * - TOUT ce que le LMS détient sur l'élève, une section par source, rien d'un autre élève. Aucun secret (mot de passe,
 *   jetons). Une note que l'élève ne voit pas encore (`ResultatsVisibles`) n'en sort pas.
 * - Téléchargement (`attachment`, `no-store`), journalisé (`eleve.donnees_exportees`).
 */
final class ExportDesDonneesDeLEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const SECTIONS = [
        'compte', 'classes', 'classes_klassci', 'copies', 'presences', 'progression_lecons', 'progression_chapitres', 'quiz',
        'controles_de_connaissances', 'consentements', 'forum_sujets', 'forum_messages', 'notifications', 'fichiers',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_l_admin_exporte_en_json_tout_ce_que_le_lms_detient(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->eleveAvecSesDonnees($ecole);

        $reponse = $this->exporter($this->membre($ecole, 'admin'), $eleve, 'json');

        $reponse->assertOk()->assertJsonPath('data.compte.email', $eleve->email)
            ->assertJsonCount(1, 'data.copies')->assertJsonPath('data.copies.0.note_sur_20', 15)
            ->assertJsonCount(1, 'data.presences')->assertJsonCount(1, 'data.progression_lecons')
            ->assertJsonCount(1, 'data.consentements')->assertJsonCount(1, 'data.notifications');
        self::assertSame(self::SECTIONS, array_keys((array) $reponse->json('data')));
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        $this->assertMatchesOpenApiResponse($reponse, '/eleves/{eleve}/export', 'get');
        foreach (['password', 'remember_token', 'klassci_token', 'klassci_token_encrypted'] as $secret) {
            self::assertStringNotContainsString('"'.$secret.'"', (string) $reponse->getContent(), $secret);
        }
    }

    public function test_aucune_donnee_d_un_autre_eleve(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->eleveAvecSesDonnees($ecole);
        $this->eleveAvecSesDonnees($ecole);

        $reponse = $this->exporter($this->membre($ecole, 'admin'), $eleve, 'json')->assertOk();

        foreach (['copies', 'presences', 'progression_lecons', 'consentements', 'notifications'] as $section) {
            $reponse->assertJsonCount(1, "data.{$section}");
        }
    }

    public function test_une_note_que_l_eleve_ne_voit_pas_encore_n_en_sort_pas(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->id, 'show_results' => false]);
        EvaluationSubmission::factory()->create(['evaluation_id' => $evaluation->id, 'student_id' => $eleve->id, 'note_sur_20' => 12, 'score' => 60]);

        $copie = $this->exporter($this->membre($ecole, 'admin'), $eleve, 'json')->assertOk()->json('data.copies.0');

        self::assertIsArray($copie);
        self::assertArrayNotHasKey('note_sur_20', $copie);
        self::assertArrayNotHasKey('score', $copie);
        self::assertSame($evaluation->titre, $copie['evaluation']);
    }

    public function test_le_pdf_est_rendu_et_l_export_journalise(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->eleveAvecSesDonnees($ecole);
        $admin = $this->membre($ecole, 'admin');

        $reponse = $this->exporter($admin, $eleve, 'pdf');

        $reponse->assertOk();
        self::assertStringStartsWith('application/pdf', (string) $reponse->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $reponse->getContent());
        $trace = DB::table('audit_logs')->where('action', 'eleve.donnees_exportees')->first();
        self::assertNotNull($trace);
        self::assertSame([$admin->id, $eleve->id], [(int) $trace->user_id, (int) $trace->auditable_id]);
        self::assertStringContainsString('pdf', (string) $trace->after);
    }

    public function test_l_admin_d_une_ecole_klassci_exporte_aussi(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->eleveAvecSesDonnees($ecole);

        $this->exporter($this->membre($ecole, 'admin'), $eleve, 'json')->assertOk()->assertJsonCount(1, 'data.copies');
    }

    public function test_403_hors_de_l_administration_de_l_ecole(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');

        foreach (['coordinateur', 'enseignant', 'etudiant'] as $role) {
            $this->exporter($this->membre($ecole, $role), $eleve, 'json')->assertForbidden();
        }
        $this->exporter($eleve, $eleve, 'json')->assertForbidden();
        self::assertSame(0, DB::table('audit_logs')->where('action', 'eleve.donnees_exportees')->count());
    }

    /** L'isolation : un élève d'une autre école, ou un compte qui n'est pas un élève, est introuvable. */
    public function test_404_hors_de_l_ecole_ou_hors_eleve_du_meme_corps_qu_un_id_absent(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');

        $absent = $this->asTenant($admin)->getJson('/api/eleves/999999/export?format=json')->assertNotFound()->json();
        self::assertSame($absent, $this->exporter($admin, $this->membre($this->ecole(), 'etudiant'), 'json')->assertNotFound()->json());
        self::assertSame($absent, $this->exporter($admin, $this->membre($ecole, 'enseignant'), 'json')->assertNotFound()->json());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'eleve.donnees_exportees')->count());
    }

    public function test_422_un_format_inconnu(): void
    {
        $ecole = $this->ecole();

        $this->exporter($this->membre($ecole, 'admin'), $this->membre($ecole, 'etudiant'), 'xml')
            ->assertUnprocessable()->assertJsonValidationErrors('format');
    }

    private function eleveAvecSesDonnees(Institution $ecole): User
    {
        $eleve = $this->membre($ecole, 'etudiant');
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->id, 'show_results' => true]);
        EvaluationSubmission::factory()->create(['evaluation_id' => $evaluation->id, 'student_id' => $eleve->id, 'note_sur_20' => 15]);
        $seance = Seance::factory()->create(['institution_id' => $ecole->id]);
        ESBTPAttendance::factory()->create(['seance_id' => $seance->id, 'user_id' => $eleve->id]);
        LessonProgress::factory()->create(['user_id' => $eleve->id, 'institution_id' => $ecole->id]);
        Notification::factory()->create(['user_id' => $eleve->id, 'institution_id' => $ecole->id]);
        DB::table('consents')->insert(['institution_id' => $ecole->id, 'user_id' => $eleve->id, 'actor_user_id' => $eleve->id,
            'purpose' => 'recording', 'granted' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $eleve;
    }

    private function exporter(User $acteur, User $eleve, string $format): TestResponse
    {
        return $this->asTenant($acteur)->getJson("/api/eleves/{$eleve->id}/export?format={$format}");
    }
}
