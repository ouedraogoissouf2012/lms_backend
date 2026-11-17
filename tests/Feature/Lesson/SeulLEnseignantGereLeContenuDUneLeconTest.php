<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Le contenu d'une leçon est l'affaire de son enseignant (#1167, décisions du
 * user du 17/11).
 *
 * Sur une leçon, ses chapitres, leurs fichiers et les quiz de chapitre, le
 * coordinateur et l'admin d'école ne font que CONSULTER. Chaque écriture leur
 * rend 403, motif `lesson_content_teacher_only` (ADR-906-01, un concept, un
 * motif), et ne change rien en base. D502 (« l'admin d'école a les droits du
 * coordinateur ») reste vrai, mais ne donne pas ces écritures.
 *
 * L'enseignant écrit sur le contenu dont il est l'AUTEUR, et seulement sur
 * celui-là : la création d'un chapitre suit la même règle d'auteur que les
 * autres écritures. Le collègue reçoit le 403 générique, sans motif : c'est
 * l'autre sens du statut.
 *
 * Chaque requête est VALIDE : l'auteur la voit aboutir. Vrai jeton, les deux
 * types d'école. Les quiz hérités (`/quizzes`) relèvent des évaluations : hors
 * de ce test.
 */
final class SeulLEnseignantGereLeContenuDUneLeconTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MOTIF = 'lesson_content_teacher_only';

    private const GESTION = ['coordinateur', 'admin', 'superAdmin'];

    /** Les écritures que la garde `personnel` réserve déjà au personnel. */
    private const ROUTES_DU_PERSONNEL = [
        'créer une leçon', 'modifier la leçon', 'supprimer la leçon', 'publier la leçon', 'dépublier la leçon',
        'créer un chapitre', 'modifier le chapitre', 'supprimer le chapitre', 'restaurer le chapitre',
        'déposer un fichier', 'réordonner les chapitres',
    ];

    private const ROUTES_DES_QUIZ = ['créer un quiz', 'modifier le quiz', 'supprimer le quiz'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Queue::fake();
        Storage::fake('local');
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('ecoles')]
    public function test_la_gestion_est_refusee_partout_avec_le_motif_et_rien_ne_change(InstitutionMode $mode): void
    {
        [$ecole, , $contenu] = $this->ecoleEtContenu($mode);
        $avant = $this->etat();

        foreach (self::GESTION as $role) {
            $compte = $this->membre($ecole, $role);

            foreach ($this->ecritures($contenu) as $nom => [$methode, $uri, $donnees]) {
                $this->assertRefus($this->appeler($compte, $methode, $uri, $donnees), self::MOTIF, "$role, $nom");
            }
        }

        self::assertSame($avant, $this->etat());
        Queue::assertNothingPushed();
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    /** Verrou : l'auteur aboutit sur chaque écriture, chacune sur un contenu neuf. */
    #[DataProvider('ecoles')]
    public function test_l_enseignant_auteur_ecrit_partout(InstitutionMode $mode): void
    {
        foreach ([...self::ROUTES_DU_PERSONNEL, ...self::ROUTES_DES_QUIZ] as $nom) {
            [, $auteur, $contenu] = $this->ecoleEtContenu($mode);
            [$methode, $uri, $donnees] = $this->ecritures($contenu)[$nom];

            $reponse = $this->appeler($auteur, $methode, $uri, $donnees);

            self::assertTrue($reponse->isSuccessful(), "auteur, $nom : {$reponse->status()} {$reponse->getContent()}");
        }
    }

    /**
     * Le collègue de la même école : 403 générique, SANS motif, et rien ne change.
     * La création d'un chapitre suit la même règle d'auteur que les autres écritures.
     */
    #[DataProvider('ecoles')]
    public function test_un_enseignant_non_auteur_est_refuse_sans_motif(InstitutionMode $mode): void
    {
        [$ecole, , $contenu] = $this->ecoleEtContenu($mode);
        $collegue = $this->membre($ecole, 'enseignant');
        $avant = $this->etat();

        foreach ($this->ecritures($contenu) as $nom => [$methode, $uri, $donnees]) {
            if ($nom === 'créer une leçon') {
                continue; // Créer n'a pas d'auteur préalable.
            }

            $this->assertRefus($this->appeler($collegue, $methode, $uri, $donnees), null, "collègue, $nom");
        }

        self::assertSame($avant, $this->etat());
        Queue::assertNothingPushed();
    }

    /** Verrou : la gestion consulte toujours la leçon et ses chapitres. */
    #[DataProvider('ecoles')]
    public function test_la_gestion_consulte_toujours(InstitutionMode $mode): void
    {
        [$ecole, , $contenu] = $this->ecoleEtContenu($mode);

        foreach (self::GESTION as $role) {
            $compte = $this->membre($ecole, $role);

            $this->appeler($compte, 'GET', "/api/lessons/{$contenu['lecon']->id}")->assertOk();
            $this->appeler($compte, 'GET', "/api/lessons/{$contenu['lecon']->id}/chapters")->assertOk();
            $this->appeler($compte, 'GET', "/api/chapters/{$contenu['chapitre']->id}")->assertOk();
        }
    }

    /**
     * L'élève : toujours 403. Sur les routes du personnel, la garde `personnel`
     * l'arrête avant la requête, sans motif ; les quiz de chapitre n'ont pas de
     * garde de route, et il y reçoit le motif : il n'est pas l'enseignant.
     */
    #[DataProvider('ecoles')]
    public function test_l_eleve_reste_refuse(InstitutionMode $mode): void
    {
        [$ecole, , $contenu] = $this->ecoleEtContenu($mode);
        $eleve = $this->membre($ecole, 'etudiant');
        $avant = $this->etat();

        foreach ($this->ecritures($contenu) as $nom => [$methode, $uri, $donnees]) {
            $motif = in_array($nom, self::ROUTES_DES_QUIZ, true) ? self::MOTIF : null;
            $this->assertRefus($this->appeler($eleve, $methode, $uri, $donnees), $motif, "élève, $nom");
        }

        self::assertSame($avant, $this->etat());
    }

    /** Témoin : la classe d'une autre école garde son refus, sans motif. */
    #[DataProvider('ecoles')]
    public function test_temoin_la_classe_d_une_autre_ecole_garde_son_refus_sans_motif(InstitutionMode $mode): void
    {
        [, $auteur] = $this->ecoleEtContenu($mode);
        $voisine = $this->classe($this->ecole($mode), '6e B');

        $this->assertRefus($this->appeler($auteur, 'POST', '/api/lessons', $this->nouvelleLecon($voisine)), null, 'classe voisine');

        self::assertSame(0, Lesson::withoutGlobalScopes()->where('classe_id', $voisine->id)->count());
    }

    /**
     * Une école, l'enseignant auteur, et son contenu : une leçon en brouillon et
     * son chapitre, un chapitre à la corbeille, un quiz, une leçon publiée.
     *
     * @return array{0: Institution, 1: User, 2: array{classe: Classe, lecon: Lesson, publiee: Lesson, chapitre: Chapter, corbeille: Chapter, quiz: KnowledgeCheck}}
     */
    private function ecoleEtContenu(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $lecon = $this->leconDe($auteur, $classe, 'draft');
        $chapitre = $this->chapitreDe($lecon);
        $corbeille = $this->chapitreDe($lecon);
        $corbeille->delete();

        return [$ecole, $auteur, [
            'classe' => $classe,
            'lecon' => $lecon,
            'publiee' => $this->leconDe($auteur, $classe, 'published'),
            'chapitre' => $chapitre,
            'corbeille' => $corbeille,
            'quiz' => KnowledgeCheck::factory()->create(['chapter_id' => $chapitre->id, 'institution_id' => $ecole->id]),
        ]];
    }

    /**
     * Les 14 écritures sur le contenu d'une leçon, chacune avec une requête valide.
     *
     * @param  array{classe: Classe, lecon: Lesson, publiee: Lesson, chapitre: Chapter, corbeille: Chapter, quiz: KnowledgeCheck}  $c
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function ecritures(array $c): array
    {
        [$lecon, $chapitre] = [$c['lecon']->id, $c['chapitre']->id];

        return [
            'créer une leçon' => ['POST', '/api/lessons', $this->nouvelleLecon($c['classe'])],
            'modifier la leçon' => ['PATCH', "/api/lessons/$lecon", ['title' => 'La proportionnalité, revue']],
            'supprimer la leçon' => ['DELETE', "/api/lessons/$lecon", []],
            'publier la leçon' => ['POST', "/api/lessons/$lecon/publish", []],
            'dépublier la leçon' => ['POST', "/api/lessons/{$c['publiee']->id}/unpublish", []],
            'créer un chapitre' => ['POST', "/api/lessons/$lecon/chapters", ['titre' => 'Les tableaux']],
            'modifier le chapitre' => ['PATCH', "/api/chapters/$chapitre", ['title' => 'Les tableaux, revus']],
            'supprimer le chapitre' => ['DELETE', "/api/chapters/$chapitre", []],
            'restaurer le chapitre' => ['POST', "/api/chapters/{$c['corbeille']->id}/restore", []],
            'déposer un fichier' => ['POST', "/api/chapters/$chapitre/upload?async=1", [
                'file' => UploadedFile::fake()->create('cours.pdf', 128, 'application/pdf'),
            ]],
            'réordonner les chapitres' => ['POST', "/api/lessons/$lecon/chapters/reorder", [
                'chapters' => [['id' => $chapitre, 'order' => 3]],
            ]],
            'créer un quiz' => ['POST', '/api/knowledge-checks', $this->nouveauQuiz($chapitre)],
            'modifier le quiz' => ['PUT', "/api/knowledge-checks/{$c['quiz']->id}", ['title' => 'Testez-vous, revu']],
            'supprimer le quiz' => ['DELETE', "/api/knowledge-checks/{$c['quiz']->id}", []],
        ];
    }

    /**
     * Les tables du contenu, ligne à ligne, corbeille comprise : une écriture
     * refusée n'y change rien.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function etat(): array
    {
        $etat = [];
        foreach (['lessons', 'chapters', 'knowledge_checks'] as $table) {
            $etat[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $ligne): array => (array) $ligne)->all();
        }

        return $etat;
    }

    /** @return array<string, mixed> */
    private function nouvelleLecon(Classe $classe): array
    {
        return ['title' => 'La proportionnalité', 'type' => 'cours', 'status' => 'draft', 'classe_id' => $classe->id];
    }

    /** @return array<string, mixed> */
    private function nouveauQuiz(int $chapitre): array
    {
        return [
            'chapter_id' => $chapitre,
            'title' => 'Testez-vous',
            'questions' => [[
                'question' => 'Deux et deux ?',
                'type' => 'single',
                'options' => ['3', '4'],
                'correct_answer' => 1,
            ]],
        ];
    }

    private function leconDe(User $auteur, Classe $classe, string $etat): Lesson
    {
        return Lesson::factory()->{$etat}()->create([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->id,
            'enseignant_id' => $auteur->id,
        ]);
    }

    private function chapitreDe(Lesson $lecon): Chapter
    {
        return Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $lecon->institution_id,
            'enseignant_id' => $lecon->enseignant_id,
        ]);
    }

    /**
     * Un 403, avec ce motif, ou sans aucun (`null`).
     *
     * @param  TestResponse<Response>  $reponse
     */
    private function assertRefus(TestResponse $reponse, ?string $motif, string $cas): void
    {
        self::assertSame(403, $reponse->status(), "$cas : {$reponse->getContent()}");
        self::assertFalse($reponse->json('success'), $cas);
        self::assertSame($motif, $reponse->json('reason'), "$cas : motif");
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return TestResponse<Response>
     */
    private function appeler(User $compte, string $methode, string $uri, array $donnees = []): TestResponse
    {
        return $this->asTenant($compte)->json($methode, $uri, $donnees);
    }
}
