<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #934 — qui lit et qui écrit la progression d'une leçon.
 *
 * - Écrire : l'apprenant doit pouvoir lire la leçon (même règle que les
 *   chapitres, ChapterReadGate). Sinon 404, et AUCUNE ligne n'est créée :
 *   c'est vérifié dans la BASE, et pas seulement sur le code HTTP. Le personnel
 *   n'écrit pas de progression (403), sauf l'aperçu d'un quiz depuis l'écran
 *   de gestion des chapitres.
 * - Lire la liste des progressions : l'auteur de la leçon et les gestionnaires.
 *
 * L'état de production est reproduit : l'inscription passe par le pivot
 * `classe_etudiant`, sans aucune ligne `user_classes`, dans les deux volets.
 * Vrai jeton porteur.
 *
 * @see app/Services/Lesson/LessonProgressGate.php
 */
final class LessonProgressGateTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private User $eleve;

    private User $auteur;

    private Lesson $sienne;

    private Lesson $autreClasse;

    private Lesson $brouillon;

    /** @var array<string, Chapter> */
    private array $chapitres = [];

    /** @var array<string, KnowledgeCheck> */
    private array $quiz = [];

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'école klassci (pivot, aucun cache user_classes)' => ['klassci'];
        yield 'école autonome (pivot)' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_l_eleve_inscrit_ecrit_sa_progression_par_les_sept_chemins(string $volet): void
    {
        $this->ecole($volet);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            $this->asTenant($this->eleve)->postJson($url, $corps)->assertOk();
        }

        self::assertSame(1, LessonProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(1, ChapterProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(1, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function ciblesInterdites(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            yield "leçon d'une autre classe, école $volet" => [$volet, 'autreClasse'];
            yield "brouillon de sa classe, école $volet" => [$volet, 'brouillon'];
        }
    }

    #[DataProvider('ciblesInterdites')]
    public function test_hors_de_ce_qu_il_lit_rien_n_est_ecrit(string $volet, string $cible): void
    {
        $this->ecole($volet);

        $this->asTenant($this->eleve)->getJson("/api/lessons/{$this->{$cible}->id}/progress")->assertNotFound();
        foreach ($this->ecritures($cible) as $chemin => [$url, $corps]) {
            self::assertSame(404, $this->asTenant($this->eleve)->postJson($url, $corps)->status(), $chemin);
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $this->eleve->id)->count(), 'lesson_progress');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->eleve->id)->count(), 'chapter_progress');
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count(), 'tentatives');
    }

    /**
     * Le personnel n'écrit aucune progression, par AUCUN chemin : 403 sur les
     * écritures de progression ; l'aperçu du quiz répond 200, mais ne crée ni
     * ligne de leçon ni ligne de chapitre.
     */
    #[DataProvider('volets')]
    public function test_le_personnel_n_ecrit_pas_de_progression(string $volet): void
    {
        $this->ecole($volet);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            $attendu = str_starts_with($chemin, 'quiz') ? 200 : 403;
            self::assertSame($attendu, $this->asTenant($this->auteur)->postJson($url, $corps)->status(), $chemin);
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $this->auteur->id)->count(), 'lesson_progress');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->auteur->id)->count(), 'chapter_progress');
    }

    /**
     * L'écran /teacher/lessons/:id/chapters joue le quiz en aperçu et relit le
     * meilleur score depuis les TENTATIVES : elles restent persistées. La
     * progression, jamais, même à la seconde soumission. L'élève inscrit, lui,
     * crée bien la sienne.
     */
    #[DataProvider('volets')]
    public function test_l_apercu_du_quiz_persiste_la_tentative_jamais_la_progression(string $volet): void
    {
        $this->ecole($volet);
        $quiz = $this->quiz['sienne'];
        $soumission = ['answers' => ['4', 'True'], 'time_spent_seconds' => 20];

        $this->asTenant($this->auteur)->postJson("/api/knowledge-checks/{$quiz->id}/start")->assertOk();
        $this->asTenant($this->auteur)->postJson("/api/knowledge-checks/{$quiz->id}/submit", $soumission)->assertOk();
        $this->asTenant($this->auteur)->postJson("/api/knowledge-checks/{$quiz->id}/submit", $soumission)->assertOk();

        self::assertSame(2, KnowledgeCheckAttempt::query()->where('user_id', $this->auteur->id)->count(), 'tentatives de l\'aperçu');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->auteur->id)->count(), 'chapter_progress du personnel');
        self::assertSame(0, LessonProgress::query()->where('user_id', $this->auteur->id)->count(), 'lesson_progress du personnel');

        $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$quiz->id}/submit", $soumission)->assertOk();
        self::assertSame(1, ChapterProgress::query()->where('user_id', $this->eleve->id)->count(), 'le droit de l\'élève');
        self::assertSame(1, LessonProgress::query()->where('user_id', $this->eleve->id)->count());
    }

    public function test_la_liste_des_progressions_est_reservee_a_l_auteur_et_aux_gestionnaires(): void
    {
        $this->ecole('klassci');
        $ecole = $this->sienne->institution_id;
        $coEnseignant = User::factory()->create(['institution_id' => $ecole, 'role' => 'enseignant']);
        $coordinateur = User::factory()->create(['institution_id' => $ecole, 'role' => 'coordinateur']);

        $this->asTenant($this->auteur)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();
        $this->asTenant($coordinateur)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();
        foreach (['admin', 'superAdmin'] as $role) {
            $gestionnaire = User::factory()->create(['institution_id' => $ecole, 'role' => $role]);
            $this->asTenant($gestionnaire)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();
        }

        // Effet voulu : un co-enseignant ne lit pas les progressions de la leçon
        // d'un collègue, et le refus ne porte AUCUNE donnée.
        $refus = $this->asTenant($coEnseignant)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertForbidden();
        self::assertSame(['success', 'message'], array_keys((array) $refus->json()));
        self::assertStringNotContainsString($this->eleve->name, (string) $refus->getContent());
    }

    public function test_la_liste_dans_une_ecole_autonome_et_pour_le_gestionnaire_d_une_autre_ecole(): void
    {
        $this->ecole('standalone');
        $this->asTenant($this->auteur)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();

        // Ce cas serait vert MÊME SANS le correctif C2 : avec un vrai jeton, le
        // scope global masque déjà la leçon de l'autre école (introuvable avant
        // la porte). Il documente le comportement, il ne prouve pas C2. Le compte
        // SANS établissement, que le scope ne filtre pas, est désormais refusé
        // par la porte des comptes sans établissement, avant C2 : la preuve de C2
        // sans passer par la porte appartient au lot de défense en profondeur.
        $ailleurs = Institution::factory()->create();
        $coordinateurAilleurs = User::factory()->create(['institution_id' => $ailleurs->id, 'role' => 'coordinateur']);
        $this->asTenant($coordinateurAilleurs)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertNotFound();
    }

    /**
     * Un compte SANS établissement n'écrit rien, comme il ne lit rien (#942) :
     * ResolveInstitution ne pose aucun tenant pour lui, et la porte des comptes
     * sans établissement le refuse avant tout contrôleur. Le même refus pour
     * l'apprenant et pour le personnel, sur chaque chemin : il ne dit rien de la
     * leçon.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function sansEtablissement(): iterable
    {
        yield 'apprenant sans établissement' => ['etudiant'];
        yield 'enseignant sans établissement' => ['enseignant'];
    }

    #[DataProvider('sansEtablissement')]
    public function test_un_compte_sans_etablissement_n_ecrit_rien(string $role): void
    {
        $this->ecole('klassci');
        $compte = User::factory()->create(['institution_id' => null, 'role' => $role]);

        $refus = ['success' => false, 'message' => EnsureSchoolMemberOrPlatformRoute::MESSAGE, 'reason' => EnsureSchoolMemberOrPlatformRoute::REASON];
        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            $reponse = $this->asTenant($compte)->postJson($url, $corps);
            self::assertSame([403, $refus], [$reponse->status(), $reponse->json()], $chemin);
        }
        $this->asTenant($compte)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertForbidden()->assertExactJson($refus);

        self::assertSame(0, LessonProgress::query()->where('user_id', $compte->id)->count(), 'lesson_progress');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $compte->id)->count(), 'chapter_progress');
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $compte->id)->count(), 'tentatives');
    }

    /** Le chemin KLASSCI réel : inscription par le cache `user_classes`, SANS pivot. */
    public function test_l_eleve_inscrit_par_le_cache_klassci_ecrit_sa_progression(): void
    {
        $this->ecole('klassci');
        $this->eleve->classes()->detach();
        $this->cacheKlassci(7001);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            self::assertSame(200, $this->asTenant($this->eleve)->postJson($url, $corps)->status(), $chemin);
        }
    }

    /**
     * FIGE le comportement actuel, sans le juger (ADR-818-01, une décision à
     * part) : un élève INACTIF dans le pivot d'une école KLASSCI, mais présent
     * dans le cache `user_classes`, écrit sa progression. Le composite ne lit le
     * pivot que pour `statut = actif` ; vide, il se replie sur le cache KLASSCI.
     */
    public function test_l_eleve_inactif_au_pivot_mais_dans_le_cache_klassci_ecrit_encore(): void
    {
        $this->ecole('klassci');
        $this->eleve->classes()->updateExistingPivot($this->sienne->classe_id, ['statut' => 'inactif']);
        $this->cacheKlassci(7001);

        $this->asTenant($this->eleve)->postJson("/api/lessons/{$this->sienne->id}/progress", ['progress_percentage' => 30])->assertOk();
    }

    /**
     * La leçon, le chapitre ou le quiz d'un AUTRE établissement : 404, avec le
     * MÊME corps qu'une ressource qui n'existe pas (rien ne se devine).
     */
    #[DataProvider('volets')]
    public function test_une_autre_ecole_et_une_ressource_absente_rendent_le_meme_404(string $volet): void
    {
        $this->ecole($volet);
        [$lecon, $chapitre, $quiz] = $this->autreEcole();

        $paires = [
            'leçon' => ["/api/lessons/{$lecon->id}/progress", '/api/lessons/999999/progress', ['progress_percentage' => 30]],
            'chapitre' => ["/api/chapters/{$chapitre->id}/time", '/api/chapters/999999/time', ['time_spent_seconds' => 60]],
            'quiz' => ["/api/knowledge-checks/{$quiz->id}/start", '/api/knowledge-checks/999999/start', []],
        ];
        // Même école, autre classe : la ressource EXISTE et le tenant la voit, mais
        // elle est illisible. C'est ce cas qui prouve le même corps : l'autre école,
        // elle, est déjà masquée par le scope global (introuvable comme l'absente).
        $paires += [
            'leçon illisible' => ["/api/lessons/{$this->autreClasse->id}/progress", '/api/lessons/999999/progress', ['progress_percentage' => 30]],
            'chapitre illisible' => ["/api/chapters/{$this->chapitres['autreClasse']->id}/time", '/api/chapters/999999/time', ['time_spent_seconds' => 60]],
            'quiz illisible' => ["/api/knowledge-checks/{$this->quiz['autreClasse']->id}/start", '/api/knowledge-checks/999999/start', []],
        ];
        foreach ($paires as $cas => [$ailleurs, $absent, $corps]) {
            $refus = $this->asTenant($this->eleve)->postJson($ailleurs, $corps);
            self::assertSame(404, $refus->status(), $cas);
            self::assertSame($this->asTenant($this->eleve)->postJson($absent, $corps)->json(), $refus->json(), "$cas : corps identique");
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count());
    }

    /**
     * Le supradmin plateforme est du personnel : il n'écrit pas de
     * progression. Il peut jouer en aperçu le quiz d'une autre école (sans
     * progression), et lit la liste des progressions.
     */
    /**
     * Le supradmin plateforme ne lit ni n'écrit aucune progression, aucun quiz
     * (décision produit) : la porte des comptes sans établissement le refuse sur
     * chaque chemin, y compris le quiz d'une autre école et la liste des
     * progressions, et rien n'est écrit en base.
     */
    public function test_le_supradmin_plateforme(): void
    {
        $this->ecole('klassci');
        [, , $quizAilleurs] = $this->autreEcole();
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            self::assertSame(403, $this->asTenant($supradmin)->postJson($url, $corps)->status(), $chemin);
        }
        $this->asTenant($supradmin)->postJson("/api/knowledge-checks/{$quizAilleurs->id}/submit", ['answers' => ['4', 'True'], 'time_spent_seconds' => 5])
            ->assertForbidden()->assertJsonPath('message', EnsureSchoolMemberOrPlatformRoute::MESSAGE);
        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertForbidden()->assertJsonMissingPath('data');

        self::assertSame(0, LessonProgress::query()->where('user_id', $supradmin->id)->count());
        self::assertSame(0, ChapterProgress::query()->where('user_id', $supradmin->id)->count());
        self::assertSame(0, KnowledgeCheckAttempt::withoutGlobalScopes()->where('user_id', $supradmin->id)->count());
    }

    /** Un rôle hors de l'énumération, même inscrit, ne lit pas la leçon (ChapterReadGate) : il n'écrit rien. */
    public function test_un_role_hors_enumeration_n_ecrit_rien(): void
    {
        $this->ecole('klassci');
        $this->eleve->update(['role' => 'Etudiant']);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            self::assertSame(404, $this->asTenant($this->eleve)->postJson($url, $corps)->status(), $chemin);
        }
        self::assertSame(0, LessonProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->eleve->id)->count());
    }

    /**
     * Un chapitre supprimé, le quiz d'un chapitre supprimé, une leçon
     * dépubliée APRÈS le début de la progression : plus rien ne s'écrit.
     */
    public function test_chapitre_supprime_et_lecon_depubliee_n_acceptent_plus_d_ecriture(): void
    {
        $this->ecole('klassci');
        $this->asTenant($this->eleve)->postJson("/api/lessons/{$this->sienne->id}/progress", ['progress_percentage' => 10])->assertOk();

        $this->chapitres['sienne']->delete();
        $this->asTenant($this->eleve)->postJson("/api/chapters/{$this->chapitres['sienne']->id}/time", ['time_spent_seconds' => 60])->assertNotFound();
        $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz['sienne']->id}/start")->assertNotFound();

        $this->sienne->update(['status' => 'draft']);
        $this->asTenant($this->eleve)->postJson("/api/lessons/{$this->sienne->id}/progress", ['progress_percentage' => 50])->assertNotFound();

        self::assertSame(10, LessonProgress::query()->where('user_id', $this->eleve->id)->value('progress_percentage'), 'la progression d\'avant reste, rien ne s\'écrit');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->eleve->id)->count());
    }

    /**
     * Les deux lectures de SA progression (B2, B3) passent par la règle de
     * lecture : la leçon ou le chapitre d'une autre classe, ou d'une autre
     * école, est introuvable, sans rien de son contenu.
     */
    public function test_lire_sa_progression_suit_la_regle_de_lecture(): void
    {
        $this->ecole('klassci');
        [$leconAilleurs, $chapitreAilleurs] = $this->autreEcole();

        $this->asTenant($this->eleve)->getJson("/api/lessons/{$this->sienne->id}/chapter-progress")->assertOk();
        $this->asTenant($this->eleve)->getJson("/api/chapters/{$this->chapitres['sienne']->id}/progress")->assertOk();

        // Chaque refus porte le MÊME corps qu'une ressource absente de la même
        // école : ni titre, ni ordre, ni quiz bloquant, rien qui se devine.
        $absenteLecon = $this->asTenant($this->eleve)->getJson('/api/lessons/999999/chapter-progress')->assertNotFound()->json();
        $absentChapitre = $this->asTenant($this->eleve)->getJson('/api/chapters/999999/progress')->assertNotFound()->json();
        foreach ([
            "/api/lessons/{$this->autreClasse->id}/chapter-progress" => $absenteLecon,
            "/api/lessons/{$this->brouillon->id}/chapter-progress" => $absenteLecon,
            "/api/lessons/{$leconAilleurs->id}/chapter-progress" => $absenteLecon,
            "/api/chapters/{$this->chapitres['autreClasse']->id}/progress" => $absentChapitre,
            "/api/chapters/{$this->chapitres['brouillon']->id}/progress" => $absentChapitre,
            "/api/chapters/{$chapitreAilleurs->id}/progress" => $absentChapitre,
        ] as $url => $corpsAbsent) {
            $reponse = $this->asTenant($this->eleve)->getJson($url);
            self::assertSame(404, $reponse->status(), $url);
            self::assertSame($corpsAbsent, $reponse->json(), "$url : corps identique à une ressource absente");
        }
    }

    /** Inscription par le cache KLASSCI, comme l'écrit le login. */
    private function cacheKlassci(int $klassciClasseId): void
    {
        UserClass::create([
            'user_id' => $this->eleve->id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => 'L1',
            'institution_id' => $this->sienne->institution_id,
            'synced_at' => now(),
        ]);
    }

    /**
     * Une leçon publiée, son chapitre et son quiz, dans une AUTRE école.
     *
     * @return array{0: Lesson, 1: Chapter, 2: KnowledgeCheck}
     */
    private function autreEcole(): array
    {
        $ailleurs = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ailleurs->id, 'klassci_id' => 7001]);
        $auteur = User::factory()->create(['institution_id' => $ailleurs->id, 'role' => 'enseignant']);
        $lecon = Lesson::factory()->published()->create(['institution_id' => $ailleurs->id, 'classe_id' => $classe->id, 'enseignant_id' => $auteur->id]);
        $chapitre = Chapter::factory()->create(['lesson_id' => $lecon->id, 'institution_id' => $ailleurs->id, 'enseignant_id' => $auteur->id, 'content_type' => 'text', 'order' => 0]);
        $quiz = KnowledgeCheck::factory()->create(['chapter_id' => $chapitre->id, 'institution_id' => $ailleurs->id]);

        return [$lecon, $chapitre, $quiz];
    }

    /**
     * Les sept chemins d'écriture vers la leçon `$cible` et son chapitre.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function ecritures(string $cible): array
    {
        $lecon = $this->{$cible};
        $chapitre = $this->chapitres[$cible];
        $quiz = $this->quiz[$cible];

        return [
            'progression' => ["/api/lessons/{$lecon->id}/progress", ['progress_percentage' => 30]],
            'complétion' => ["/api/lessons/{$lecon->id}/complete", []],
            'note' => ["/api/lessons/{$lecon->id}/rating", ['rating' => 4]],
            'temps du chapitre' => ["/api/chapters/{$chapitre->id}/time", ['time_spent_seconds' => 60]],
            'complétion du chapitre' => ["/api/chapters/{$chapitre->id}/complete", ['time_spent_seconds' => 30]],
            'quiz : démarrage' => ["/api/knowledge-checks/{$quiz->id}/start", []],
            'quiz : soumission' => ["/api/knowledge-checks/{$quiz->id}/submit", ['answers' => ['4', 'True'], 'time_spent_seconds' => 20]],
        ];
    }

    private function ecole(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';
        $ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $classeA = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $standalone ? null : 7001]);
        $classeB = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $standalone ? null : 7002]);

        $this->auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);
        $this->eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_id' => $standalone ? null : 910001]);
        $classeA->etudiants()->attach($this->eleve->id, ['statut' => 'actif']);

        $base = ['institution_id' => $ecole->id, 'enseignant_id' => $this->auteur->id];
        $this->sienne = Lesson::factory()->published()->create($base + ['classe_id' => $classeA->id]);
        $this->autreClasse = Lesson::factory()->published()->create($base + ['classe_id' => $classeB->id]);
        $this->brouillon = Lesson::factory()->create($base + ['classe_id' => $classeA->id, 'status' => 'draft']);

        foreach (['sienne', 'autreClasse', 'brouillon'] as $nom) {
            $this->chapitres[$nom] = Chapter::factory()->create([
                'lesson_id' => $this->{$nom}->id,
                'institution_id' => $ecole->id,
                'enseignant_id' => $this->auteur->id,
                'content_type' => 'text',
                'order' => 0,
            ]);
            $this->quiz[$nom] = KnowledgeCheck::factory()->create([
                'chapter_id' => $this->chapitres[$nom]->id,
                'institution_id' => $ecole->id,
            ]);
        }
    }
}
