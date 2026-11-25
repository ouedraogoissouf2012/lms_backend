<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Institution;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use App\Services\Import\ImportApplyService;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;
use Throwable;

/**
 * #1617 — le parcours complet d'une école AUTONOME, dans l'ordre de la démonstration.
 *
 * De la demande publique d'ouverture jusqu'aux tableaux de bord, chaque geste passe par la VRAIE
 * route, sous un VRAI jeton : le supradmin, puis le titulaire connecté par `/api/auth/login` avec
 * le mot de passe qu'il a posé à l'activation, puis l'enseignant et l'élève que l'admin a créés.
 * Aucun compte n'est fabriqué pour un maillon : chacun est créé par le maillon qui le crée en démo.
 *
 * ## La carte des rouges
 *
 * Chaque étape est un MAILLON. Un maillon rouge est NOTÉ (la route, le statut, un extrait du corps
 * sans donnée personnelle) au lieu d'arrêter le test, et un maillon qui dépend d'un rouge est
 * noté NON JOUÉ. L'assertion finale exige une carte VIDE : le test reste rouge tant qu'un seul
 * maillon l'est. Il n'existe aucun mode tolérant.
 *
 * ## Une école autonome n'appelle jamais KLASSCI
 *
 * `Http::preventStrayRequests()` pour tout le parcours, et l'empilement RÉEL des middlewares
 * (`klassci.sync` n'est pas désactivé) : tout appel sortant devient un rouge.
 */
final class ParcoursEcoleAutonomeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseDemo#2026';

    /** @var list<string> */
    private array $carte = [];

    /** @var array<string, mixed> les résultats des maillons verts, par nom */
    private array $etat = [];

    public function test_une_ecole_autonome_se_deroule_de_bout_en_bout(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-26 08:00:00', 'UTC'));

        $this->ouvertureDeLEcole();
        $this->comptesDeLEcole();
        $this->catalogueDeLEcole();
        $this->coursDeLEnseignant();
        $this->evaluationDeBoutEnBout();
        $this->seanceEtVisio();
        $this->periodesDeFormation();
        $this->tableauxDeBord();
        $this->corrigeApresLeDelai();

        self::assertSame([], $this->carte, "Parcours autonome — maillons rouges ou non joués :\n".implode("\n", $this->carte));
    }

    // ───────────────────────────── 1. Ouverture de l'école

    private function ouvertureDeLEcole(): void
    {
        $this->maillon('demande publique', [], function (): int {
            $this->anonyme()->postJson('/api/school-requests', [
                'nom_demandeur' => 'Awa Kone',
                'email_demandeur' => 'titulaire@ecole-demo.test',
                'telephone_demandeur' => '0700000000',
                'nom_ecole' => 'Ecole Demo Autonome',
                'slug_souhaite' => 'ecole-demo',
                'usage_prevu' => 'Former nos stagiaires en bureautique, en ligne et en presentiel.',
            ])->assertCreated();

            return (int) SchoolRegistrationRequest::query()->where('email_demandeur', 'titulaire@ecole-demo.test')->value('id');
        });

        $this->etat['supradmin'] = $this->tokenFor(User::factory()->create([
            'institution_id' => null, 'role' => 'supradmin', 'klassci_token' => null, 'email' => 'supradmin@plateforme.test',
        ]));

        $this->maillon('le supradmin voit la demande', ['demande publique'], function (): bool {
            $liste = $this->attendre($this->comme('supradmin')->getJson('/api/admin/school-requests'), 200, 'GET admin/school-requests');
            self::assertStringContainsString('Ecole Demo Autonome', (string) json_encode($liste));

            return true;
        });

        $this->maillon('le supradmin valide', ['demande publique'], function (): string {
            $id = $this->etat['demande publique'];
            $corps = $this->attendre($this->comme('supradmin')->postJson("/api/admin/school-requests/{$id}/validate"), 200, 'POST validate');
            self::assertSame('ecole-demo', $corps['data']['institution']['slug'] ?? null);
            self::assertSame(InstitutionMode::Standalone, Institution::query()->where('slug', 'ecole-demo')->firstOrFail()->mode);

            return (string) ($corps['data']['activation_url'] ?? '');
        });

        $this->maillon('le titulaire active son compte', ['le supradmin valide'], function (): bool {
            $url = (string) $this->etat['le supradmin valide'];
            $jeton = substr($url, (int) strrpos($url, '/') + 1);
            $this->attendre($this->anonyme()->postJson('/api/activation', [
                'token' => $jeton, 'password' => self::MOT_DE_PASSE, 'password_confirmation' => self::MOT_DE_PASSE,
            ]), 200, 'POST activation');

            return true;
        });

        $this->maillon('admin', ['le titulaire active son compte'], fn (): string => $this->connexion('titulaire@ecole-demo.test'));
    }

    // ───────────────────────────── 2. Enseignant et élèves

    private function comptesDeLEcole(): void
    {
        foreach (['enseignant' => 'prof@ecole-demo.test', 'eleve' => 'eleve@ecole-demo.test'] as $qui => $email) {
            $this->maillon("l'admin crée le compte {$qui}", ['admin'], function () use ($qui, $email): int {
                $corps = $this->attendre($this->comme('admin')->postJson('/api/users', [
                    'name' => $qui === 'eleve' ? 'Yao Kouassi' : 'Mme Diallo',
                    'email' => $email,
                    'password' => self::MOT_DE_PASSE,
                    'password_confirmation' => self::MOT_DE_PASSE,
                    'role' => $qui === 'eleve' ? 'etudiant' : 'enseignant',
                ]), 201, 'POST users');

                return (int) $corps['data']['id'];
            });

            $this->maillon($qui, ["l'admin crée le compte {$qui}"], fn (): string => $this->connexion($email));
        }
    }

    // ───────────────────────────── 3. Classes, matières, enseignant, inscriptions

    private function catalogueDeLEcole(): void
    {
        $this->maillon('classe', ['admin'], fn (): int => (int) $this->attendre(
            $this->comme('admin')->postJson('/api/classes', ['libelle' => 'Bureautique A', 'code' => 'BUR-A']), 201, 'POST classes'
        )['data']['id']);

        $this->maillon('matiere', ['admin'], fn (): int => (int) $this->attendre(
            $this->comme('admin')->postJson('/api/matieres', ['libelle' => 'Traitement de texte', 'code' => 'TXT']), 201, 'POST matieres'
        )['data']['id']);

        $this->maillon("rattachement de l'enseignant", ['classe', 'matiere', "l'admin crée le compte enseignant"], function (): bool {
            $this->attendre($this->comme('admin')->postJson("/api/classes/{$this->etat['classe']}/matieres", [
                'matiere_id' => $this->etat['matiere'], 'enseignant_id' => $this->etat["l'admin crée le compte enseignant"],
            ]), 201, 'POST classes/{c}/matieres');

            return true;
        });

        $this->maillon("code d'inscription", ['classe'], function (): string {
            $corps = $this->attendre($this->comme('admin')->postJson("/api/classes/{$this->etat['classe']}/code-inscription"), 201, 'POST code-inscription');
            self::assertIsString($corps['data']['code_inscription'] ?? null);

            return $corps['data']['code_inscription'];
        });

        $this->maillon("l'élève rejoint par code", ["code d'inscription", 'eleve'], function (): bool {
            $corps = $this->attendre($this->comme('eleve')->postJson('/api/me/inscriptions', ['code' => $this->etat["code d'inscription"]]), 201, 'POST me/inscriptions');
            self::assertSame($this->etat['classe'], $corps['data']['classe']['id'] ?? null);

            return true;
        });

        $this->maillon("import d'élèves", ['classe', 'admin'], function (): bool {
            $rapport = $this->attendre($this->comme('admin')->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('eleves.csv', "nom;prenom;email;code_classe\nTraore;Mariam;mariam@ecole-demo.test;BUR-A\n"),
            ], ['Accept' => 'application/json']), 200, 'POST imports/preview');
            self::assertSame(1, $rapport['data']['counts']['ok'] ?? null);
            $importId = (int) $rapport['data']['import_id'];
            $this->attendre($this->comme('admin')->postJson("/api/lms/imports/{$importId}/confirm"), 200, 'POST imports/{id}/confirm');

            // Le travailleur de file : le job que la confirmation a mis en file, exécuté ici.
            $ecole = (int) Institution::query()->where('slug', 'ecole-demo')->value('id');
            (new ProcessImportJob($importId, $ecole))->handle(app(TenantManager::class), app(ImportApplyService::class));
            $inscrits = $this->attendre($this->comme('admin')->getJson("/api/lms/classes/{$this->etat['classe']}/etudiants"), 200, 'GET classes/{c}/etudiants');
            self::assertStringContainsString('mariam@ecole-demo.test', (string) json_encode($inscrits));

            return true;
        });
    }

    // ───────────────────────────── 4. Cours : leçon, chapitres, quiz

    private function coursDeLEnseignant(): void
    {
        $this->maillon('lecon', ["rattachement de l'enseignant", 'enseignant'], function (): int {
            $corps = $this->attendre($this->comme('enseignant')->postJson('/api/lessons', [
                'title' => 'Mise en forme d un document', 'description' => 'Styles, titres et sommaire',
                'type' => 'cours', 'classe_id' => $this->etat['classe'], 'matiere_id' => $this->etat['matiere'],
                'niveau_difficulte' => 'debutant',
            ]), 201, 'POST lessons');
            self::assertSame('draft', $corps['data']['status'] ?? null);

            return (int) $corps['data']['id'];
        });

        $this->maillon('chapitre texte', ['lecon'], fn (): int => (int) $this->attendre($this->comme('enseignant')->postJson(
            "/api/lessons/{$this->etat['lecon']}/chapters",
            ['titre' => 'Les styles', 'description' => 'Appliquer un style de titre.', 'type_contenu' => 'text'],
        ), 201, 'POST lessons/{id}/chapters')['data']['id']);

        $this->maillon('chapitre document', ['lecon'], fn (): int => (int) $this->attendre($this->comme('enseignant')->post(
            "/api/lessons/{$this->etat['lecon']}/chapters",
            ['titre' => 'Support PDF', 'type_contenu' => 'pdf', 'fichier' => UploadedFile::fake()->create('support.pdf', 40, 'application/pdf')],
            ['Accept' => 'application/json'],
        ), 201, 'POST lessons/{id}/chapters (pdf)')['data']['id']);

        $this->maillon('quiz du chapitre', ['lecon'], function (): int {
            $chapitre = (int) $this->attendre($this->comme('enseignant')->postJson(
                "/api/lessons/{$this->etat['lecon']}/chapters", ['titre' => 'Quiz', 'type_contenu' => 'quiz'],
            ), 201, 'POST lessons/{id}/chapters (quiz)')['data']['id'];

            return (int) $this->attendre($this->comme('enseignant')->postJson('/api/knowledge-checks', [
                'chapter_id' => $chapitre, 'title' => 'Vérifier les styles', 'show_correct_answers' => true,
                'questions' => [['question' => 'Un style de titre structure-t-il le sommaire ?', 'type' => 'single', 'options' => ['Oui', 'Non'], 'correct_answer' => 0]],
            ]), 201, 'POST knowledge-checks')['data']['id'];
        });

        $this->maillon('publication de la leçon', ['lecon'], function (): bool {
            $corps = $this->attendre($this->comme('enseignant')->postJson("/api/lessons/{$this->etat['lecon']}/publish"), 200, 'POST lessons/{id}/publish');
            self::assertSame('published', $corps['data']['status'] ?? null);

            return true;
        });

        $this->maillon("l'élève voit la leçon", ['publication de la leçon', "l'élève rejoint par code"], function (): bool {
            $corps = $this->attendre($this->comme('eleve')->getJson('/api/lessons/my-courses?per_page=100'), 200, 'GET lessons/my-courses');
            self::assertContains($this->etat['lecon'], $this->ids($corps));

            return true;
        });

        $this->maillon("l'élève termine un chapitre", ["l'élève voit la leçon", 'chapitre texte'], function (): bool {
            $this->attendre($this->comme('eleve')->postJson("/api/chapters/{$this->etat['chapitre texte']}/complete"), 200, 'POST chapters/{id}/complete');

            return true;
        });

        $this->maillon("l'élève passe le quiz", ["l'élève voit la leçon", 'quiz du chapitre'], function (): bool {
            $quiz = $this->etat['quiz du chapitre'];
            $this->attendre($this->comme('eleve')->postJson("/api/knowledge-checks/{$quiz}/start"), 200, 'POST knowledge-checks/{id}/start');
            $this->reussir($this->comme('eleve')->postJson("/api/knowledge-checks/{$quiz}/submit", ['answers' => [0], 'time_spent_seconds' => 30]), 'POST knowledge-checks/{id}/submit');

            return true;
        });
    }

    // ───────────────────────────── 5. Évaluation : création, publication, passage, correction

    private function evaluationDeBoutEnBout(): void
    {
        $this->maillon('evaluation', ["rattachement de l'enseignant", 'enseignant'], function (): array {
            $corps = $this->attendre($this->comme('enseignant')->postJson('/api/evaluations', [
                'classe_id' => $this->etat['classe'], 'matiere_id' => $this->etat['matiere'],
                'titre' => 'Controle styles', 'type' => 'mixte', 'date_evaluation' => now()->subMinutes(5)->toIso8601String(),
                'duree_minutes' => 60, 'is_online' => true, 'show_results' => true,
                'questions' => [
                    ['question' => 'Quel menu applique un style ?', 'type' => 'qcm', 'points' => 2, 'options' => ['Accueil', 'Fichier'], 'correct_answers' => ['Accueil']],
                    ['question' => 'Le raccourci de la sauvegarde ?', 'type' => 'reponse_courte', 'points' => 2, 'correct_answers' => ['Ctrl+S']],
                    ['question' => 'Expliquez l interet d un sommaire automatique.', 'type' => 'dissertation', 'points' => 4],
                ],
            ]), 201, 'POST evaluations');

            return ['id' => (int) $corps['data']['id'], 'questions' => array_column($corps['data']['questions'] ?? [], 'id')];
        });

        $this->maillon("publication de l'évaluation", ['evaluation'], function (): bool {
            $corps = $this->attendre($this->comme('enseignant')->postJson("/api/evaluations/{$this->evaluationId()}/publish"), 200, 'POST evaluations/{id}/publish');
            self::assertTrue($corps['data']['is_published'] ?? false);

            return true;
        });

        $this->maillon("l'élève remet sa copie", ["publication de l'évaluation", "l'élève rejoint par code"], function (): int {
            $id = $this->evaluationId();
            self::assertContains($id, $this->ids($this->attendre($this->comme('eleve')->getJson('/api/evaluations/student'), 200, 'GET evaluations/student')));
            $this->attendre($this->comme('eleve')->postJson("/api/evaluations/{$id}/start"), 200, 'POST evaluations/{id}/start');
            [$qcm, $courte, $dissertation] = $this->etat['evaluation']['questions'];
            $corps = $this->attendre($this->comme('eleve')->postJson("/api/evaluations/{$id}/submit", ['answers' => [
                (string) $qcm => 'Accueil', (string) $courte => 'ctrl+s', (string) $dissertation => 'Il se met a jour seul.',
            ]]), 201, 'POST evaluations/{id}/submit');

            return (int) $corps['data']['submission']['id'];
        });

        $this->maillon("l'enseignant corrige", ["l'élève remet sa copie"], function (): bool {
            $id = $this->evaluationId();
            $copie = $this->etat["l'élève remet sa copie"];
            self::assertContains($copie, $this->ids($this->attendre($this->comme('enseignant')->getJson("/api/evaluations/{$id}/submissions"), 200, 'GET evaluations/{id}/submissions')));
            $corps = $this->attendre($this->comme('enseignant')->postJson("/api/evaluations/{$id}/submissions/{$copie}/grade", [
                'points' => [(string) $this->etat['evaluation']['questions'][2] => 3], 'feedback' => 'Bien argumente.',
            ]), 200, 'POST submissions/{s}/grade');
            self::assertSame('corrige', $corps['data']['status'] ?? null);

            return true;
        });

        $this->maillon("l'élève lit sa note", ["l'enseignant corrige"], function (): bool {
            $corps = $this->attendre($this->comme('eleve')->getJson("/api/evaluations/{$this->evaluationId()}/my-submission"), 200, 'GET my-submission');
            self::assertSame('corrige', $corps['data']['status'] ?? null);
            self::assertNotNull($corps['data']['note_sur_20'] ?? null);

            return true;
        });

        $this->maillon('résultats de la classe', ["l'enseignant corrige"], function (): bool {
            $corps = $this->attendre($this->comme('enseignant')->getJson("/api/evaluations/{$this->evaluationId()}/results-by-class"), 200, 'GET results-by-class');
            self::assertSame(1, $corps['data']['statistiques']['etudiants_soumis'] ?? null);

            return true;
        });
    }

    // ───────────────────────────── 6. Séance locale, visio, enregistrement

    private function seanceEtVisio(): void
    {
        $this->maillon('seance', ["rattachement de l'enseignant", 'enseignant'], fn (): int => (int) $this->attendre($this->comme('enseignant')->postJson('/api/lms/seances', [
            'titre' => 'Atelier styles', 'date_seance' => now()->addMinutes(10)->toIso8601String(),
            'classe_id' => $this->etat['classe'], 'matiere_id' => $this->etat['matiere'],
        ]), 201, 'POST lms/seances')['data']['id']);

        $this->maillon('la séance est listée', ['seance', "l'élève rejoint par code"], function (): bool {
            self::assertContains($this->etat['seance'], $this->ids($this->attendre($this->comme('enseignant')->getJson('/api/lms/seances/my-teaching'), 200, 'GET my-teaching')));
            self::assertContains($this->etat['seance'], $this->ids($this->attendre($this->comme('eleve')->getJson('/api/lms/seances/upcoming'), 200, 'GET upcoming')));

            return true;
        });

        $this->maillon('visio ouverte et démarrée', ['seance'], function (): bool {
            $seance = $this->etat['seance'];
            $this->attendre($this->comme('enseignant')->postJson("/api/lms/seances/{$seance}/activate-visio"), 200, 'POST activate-visio');
            $corps = $this->attendre($this->comme('enseignant')->postJson("/api/lms/seances/{$seance}/start-visio"), 200, 'POST start-visio');
            self::assertSame('active', $corps['data']['visio_status'] ?? null);

            return true;
        });

        $this->maillon("l'élève rejoint la visio", ['visio ouverte et démarrée', "l'élève rejoint par code"], function (): bool {
            $seance = $this->etat['seance'];
            $this->attendre($this->comme('eleve')->postJson("/api/lms/seances/{$seance}/join"), 200, 'POST join');
            $this->attendre($this->comme('eleve')->postJson("/api/lms/seances/{$seance}/heartbeat"), 200, 'POST heartbeat');

            return true;
        });

        $this->maillon('enregistrement', ['visio ouverte et démarrée'], function (): bool {
            $seance = $this->etat['seance'];
            $this->attendre($this->comme('enseignant')->postJson('/api/lms/visio/consent', ['captation' => true, 'diffusion' => true, 'reutilisation' => false]), 200, 'POST visio/consent');
            $debut = $this->attendre($this->comme('enseignant')->postJson("/api/lms/seances/{$seance}/recording/start"), 200, 'POST recording/start');
            self::assertSame('recording', $debut['data']['recording']['status'] ?? null);
            $fin = $this->attendre($this->comme('enseignant')->postJson("/api/lms/seances/{$seance}/recording/stop"), 200, 'POST recording/stop');
            self::assertSame('processing', $fin['data']['recording']['status'] ?? null);

            return true;
        });

        $this->maillon('fin de la visio', ['visio ouverte et démarrée'], function (): bool {
            $seance = $this->etat['seance'];
            if (isset($this->etat["l'élève rejoint la visio"])) {
                $this->attendre($this->comme('eleve')->postJson("/api/lms/seances/{$seance}/leave"), 200, 'POST leave');
            }
            $corps = $this->attendre($this->comme('enseignant')->postJson("/api/lms/seances/{$seance}/end-visio"), 200, 'POST end-visio');
            self::assertSame('terminee', $corps['data']['visio_status'] ?? null);

            return true;
        });
    }

    // ───────────────────────────── 7. Périodes de formation et tableaux de bord

    private function periodesDeFormation(): void
    {
        $this->maillon('periode', ['admin'], function (): int {
            $programme = (int) $this->attendre($this->comme('admin')->postJson('/api/programs', ['titre' => 'Bureautique']), 201, 'POST programs')['data']['id'];
            $corps = $this->attendre($this->comme('admin')->postJson('/api/training-sessions', [
                'program_id' => $programme, 'libelle' => 'Session de decembre',
                'starts_on' => now()->addDays(7)->toDateString(), 'ends_on' => now()->addDays(37)->toDateString(),
            ]), 201, 'POST training-sessions');
            $periode = (int) $corps['data']['id'];
            $publiee = $this->attendre($this->comme('admin')->postJson("/api/training-sessions/{$periode}/publier"), 200, 'POST publier');
            self::assertSame('publiee', $publiee['data']['status'] ?? null);

            return $periode;
        });

        $this->maillon('la classe suit la période', ['periode', 'classe'], function (): bool {
            $corps = $this->attendre($this->comme('admin')->putJson("/api/classes/{$this->etat['classe']}/periode", ['training_session_id' => $this->etat['periode']]), 200, 'PUT classes/{c}/periode');
            self::assertSame($this->etat['periode'], $corps['data']['training_session_id'] ?? null);

            return true;
        });
    }

    private function tableauxDeBord(): void
    {
        $this->maillon("tableau de bord de l'admin", ['admin', "l'admin crée le compte eleve"], function (): bool {
            $corps = $this->attendre($this->comme('admin')->getJson('/api/dashboard/stats'), 200, 'GET dashboard/stats');
            self::assertGreaterThanOrEqual(1, $corps['data']['users']['students'] ?? 0, 'élèves');
            self::assertGreaterThanOrEqual(1, $corps['data']['users']['teachers'] ?? 0, 'enseignants');

            return true;
        });

        $this->maillon("tableau de bord de l'enseignant", ['publication de la leçon'], function (): bool {
            $corps = $this->attendre($this->comme('enseignant')->getJson('/api/dashboard/teacher'), 200, 'GET dashboard/teacher');
            self::assertGreaterThanOrEqual(1, $corps['data']['lessons']['published'] ?? 0, 'leçons publiées');

            return true;
        });

        $this->maillon("compteurs de l'enseignant", ['publication de la leçon', 'evaluation'], function (): bool {
            $corps = $this->attendre($this->comme('enseignant')->getJson('/api/teacher/stats'), 200, 'GET teacher/stats');
            foreach (['classes', 'matieres', 'lessons', 'evaluations'] as $compteur) {
                self::assertGreaterThanOrEqual(1, $corps['data'][$compteur] ?? 0, "teacher/stats.{$compteur} vaut ".json_encode($corps['data'][$compteur] ?? null));
            }

            return true;
        });

        $this->maillon("tableau de bord de l'élève", ["l'élève termine un chapitre"], function (): bool {
            $corps = $this->attendre($this->comme('eleve')->getJson('/api/dashboard/student'), 200, 'GET dashboard/student');
            self::assertGreaterThanOrEqual(1, $corps['data']['progression']['lessons']['total'] ?? 0, 'leçons suivies');

            return true;
        });
    }

    /** Le corrigé s'ouvre à l'élève 7 jours après la remise (EvaluationStudentSubmissionController:30). */
    private function corrigeApresLeDelai(): void
    {
        $this->maillon("l'élève lit le corrigé", ["l'enseignant corrige"], function (): bool {
            $this->travel(8)->days();
            $corps = $this->attendre($this->comme('eleve')->getJson("/api/evaluations/{$this->evaluationId()}/my-submission"), 200, 'GET my-submission (corrigé)');
            self::assertTrue($corps['data']['correction_available'] ?? false);
            self::assertSame(['Accueil'], $corps['data']['questions'][0]['correct_answers'] ?? null);

            return true;
        });
    }

    // ───────────────────────────── Le harnais du parcours

    /**
     * Joue un maillon, ou le note : ROUGE s'il échoue, NON JOUÉ si un maillon dont il dépend
     * l'est. Le résultat d'un maillon vert est rangé sous son nom.
     *
     * @param  list<string>  $requis
     */
    private function maillon(string $nom, array $requis, callable $jeu): void
    {
        foreach ($requis as $prealable) {
            if (! array_key_exists($prealable, $this->etat)) {
                $this->carte[] = "NON JOUÉ  {$nom} (dépend de « {$prealable} »)";

                return;
            }
        }

        try {
            $this->etat[$nom] = $jeu();
        } catch (Throwable $echec) {
            $this->carte[] = "ROUGE     {$nom} : ".mb_substr(trim($echec->getMessage()), 0, 400);
        }
    }

    /**
     * Le corps d'une réponse au statut attendu ; sinon, une erreur qui nomme la route, le statut et
     * un extrait du corps (message, motif, champs en erreur : jamais une valeur de compte).
     *
     * @param  TestResponse<Response>  $reponse
     * @return array<mixed>
     */
    private function attendre(TestResponse $reponse, int $statut, string $route): array
    {
        if ($reponse->getStatusCode() !== $statut) {
            throw new RuntimeException(sprintf('%s → HTTP %d (attendu %d) : %s', $route, $reponse->getStatusCode(), $statut, $this->extrait($reponse)));
        }

        return (array) $reponse->json();
    }

    /** @param  TestResponse<Response>  $reponse */
    private function reussir(TestResponse $reponse, string $route): void
    {
        if (! $reponse->isSuccessful()) {
            throw new RuntimeException(sprintf('%s → HTTP %d : %s', $route, $reponse->getStatusCode(), $this->extrait($reponse)));
        }
    }

    /** @param  TestResponse<Response>  $reponse */
    private function extrait(TestResponse $reponse): string
    {
        $corps = $reponse->json();
        if (! is_array($corps)) {
            return '(corps non JSON)';
        }

        return (string) json_encode([
            'message' => $corps['message'] ?? null,
            'reason' => $corps['reason'] ?? null,
            'champs' => is_array($corps['errors'] ?? null) ? array_keys($corps['errors']) : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Se connecte par la VRAIE route, avec le mot de passe posé ; rend le jeton. */
    private function connexion(string $email): string
    {
        $corps = $this->attendre($this->anonyme()->postJson('/api/auth/login', ['username' => $email, 'password' => self::MOT_DE_PASSE]), 200, 'POST auth/login');
        self::assertIsString($corps['data']['token'] ?? null);

        return $corps['data']['token'];
    }

    /** Sous le jeton d'un acteur du parcours (le harnais purge le garde quand le compte change). */
    private function comme(string $acteur): static
    {
        $this->flushHeaders();

        return $this->withToken((string) $this->etat[$acteur]);
    }

    private function anonyme(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function evaluationId(): int
    {
        return (int) $this->etat['evaluation']['id'];
    }

    /**
     * Les identifiants d'une liste, qu'elle soit sous `data`, `data.data` ou `data.items`.
     *
     * @param  array<mixed>  $corps
     * @return list<int>
     */
    private function ids(array $corps): array
    {
        $liste = $corps['data']['data'] ?? $corps['data']['items'] ?? $corps['data'] ?? [];

        return is_array($liste) ? array_map('intval', array_column($liste, 'id')) : [];
    }
}
