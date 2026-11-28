<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1198 — un modérateur dont le rôle est stocké sous un ALIAS (`teacher`, `coordinator`)
 * modère comme son rôle le prévoit.
 *
 * La table des alias est `Role::aliases()`. Les gardes de route la lisent : `permission:forum.moderer`
 * (par le rôle socle, #1665) admet `teacher` et `coordinator`, et les routes
 * sans garde de rôle admettent tout compte de l'école. Mais
 * `ChecksForumAuthorization::isForumActionAuthorized()` compare la valeur BRUTE
 * (`in_array($user->role, $moderatorRoles, true)`, ligne 93) à des listes recopiées
 * en valeurs canoniques. La route laisse donc entrer le compte, puis la FormRequest le
 * refuse : 403.
 *
 * - Verrous (rouges sur le code actuel pour les seuls alias) : fermer et épingler un
 *   sujet, marquer une solution, lire les copies d'un quiz. Chaque verrou porte aussi
 *   le compte canonique témoin, admis aujourd'hui comme demain.
 * - Témoins (verts) : `administrateur`, alias déjà recopié dans la liste par défaut et
 *   dans celle de `ShowAttemptRequest`, est admis ; un coordinateur, alias compris, ne
 *   lit pas la copie d'un élève (exclusion voulue de `ShowAttemptRequest`).
 * - Isolation : un compte d'une AUTRE école reste refusé, alias ou non.
 *
 * Les fabriques n'écrivent que des valeurs canoniques : les alias sont posés à la main.
 * Les deux types d'école. Vrai jeton, `asTenant()` avant chaque requête.
 */
final class AliasDeLaModerationTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function moderateursDuSujet(): iterable
    {
        return self::parVolet(['enseignant', 'teacher', 'coordinateur', 'coordinator']);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function moderateursAAliasDuSujet(): iterable
    {
        return self::parVolet(['teacher', 'coordinator']);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function coordinateurs(): iterable
    {
        return self::parVolet(['coordinateur', 'coordinator']);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function administrateurs(): iterable
    {
        return self::parVolet(['admin', 'administrateur']);
    }

    /**
     * Verrou, `CloseTopicRequest:34` : rouge pour `teacher` et `coordinator`, que la garde
     * `personnel` admet et que la liste brute refuse ; vert pour les témoins canoniques.
     */
    #[DataProvider('moderateursDuSujet')]
    public function test_un_moderateur_de_l_ecole_ferme_le_sujet_d_un_autre(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $sujet = $this->sujet($ecole);
        $moderateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($moderateur)->postJson("/api/forum/topics/{$sujet->id}/close");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('closed', $reponse->json('data.status'));
        $this->assertDatabaseHas(ForumTopic::class, ['id' => $sujet->id, 'status' => 'closed']);
    }

    /** Verrou, `PinTopicRequest:34` : même partage que la fermeture. */
    #[DataProvider('moderateursDuSujet')]
    public function test_un_moderateur_de_l_ecole_epingle_le_sujet_d_un_autre(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $sujet = $this->sujet($ecole);
        $moderateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($moderateur)->postJson("/api/forum/topics/{$sujet->id}/pin");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('pinned', $reponse->json('data.status'));
        $this->assertDatabaseHas(ForumTopic::class, ['id' => $sujet->id, 'status' => 'pinned']);
    }

    /**
     * Verrou, `MarkPostAsSolutionRequest:33` : rouge pour `coordinator` (la route n'a pas
     * de garde de rôle) ; vert pour `coordinateur`.
     */
    #[DataProvider('coordinateurs')]
    public function test_un_coordinateur_de_l_ecole_marque_la_solution_du_sujet_d_un_autre(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $sujet = $this->sujet($ecole);
        $reponseDEleve = $this->reponseDEleve($ecole, $sujet);
        $coordinateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($coordinateur)->postJson("/api/forum/posts/{$reponseDEleve->id}/solution");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertTrue($reponse->json('data.is_solution'));
        $this->assertDatabaseHas(ForumPost::class, ['id' => $reponseDEleve->id, 'is_solution' => true]);
        $this->assertDatabaseHas(ForumTopic::class, ['id' => $sujet->id, 'is_resolved' => true]);
    }

    /**
     * Verrou, `GetQuizAttemptsRequest:40` : rouge pour `coordinator`, que la garde
     * `personnel` admet ; vert pour `coordinateur`.
     */
    #[DataProvider('coordinateurs')]
    public function test_un_coordinateur_de_l_ecole_lit_les_copies_du_quiz_d_un_autre(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $copie = $this->copieRendue($ecole);
        $coordinateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($coordinateur)->getJson("/api/quizzes/{$copie->quiz_id}/attempts");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame([$copie->id], array_column((array) $reponse->json('data.data'), 'id'));
    }

    /** Isolation, vert aujourd'hui comme demain : l'alias n'ouvre pas le sujet d'une autre école. */
    #[DataProvider('moderateursAAliasDuSujet')]
    public function test_un_moderateur_a_alias_d_une_autre_ecole_ne_ferme_ni_n_epingle_le_sujet(InstitutionMode $mode, string $role): void
    {
        $sujet = $this->sujet($this->ecole($mode));
        $etranger = $this->compte($this->ecole($mode), $role);

        foreach (['close', 'pin'] as $action) {
            $reponse = $this->asTenant($etranger)->postJson("/api/forum/topics/{$sujet->id}/{$action}");

            self::assertContains($reponse->getStatusCode(), [403, 404], $action.' : '.$reponse->getContent());
            self::assertNull($reponse->json('data.status'), $action);
        }

        $this->assertDatabaseHas(ForumTopic::class, ['id' => $sujet->id, 'status' => 'open']);
    }

    /** Isolation, vert aujourd'hui comme demain : ni solution marquée, ni copies lues. */
    #[DataProvider('volets')]
    public function test_un_coordinateur_a_alias_d_une_autre_ecole_ne_modere_rien(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $reponseDEleve = $this->reponseDEleve($ecole, $this->sujet($ecole));
        $copie = $this->copieRendue($ecole);
        $etranger = $this->compte($this->ecole($mode), 'coordinator');

        $solution = $this->asTenant($etranger)->postJson("/api/forum/posts/{$reponseDEleve->id}/solution");
        $copies = $this->asTenant($etranger)->getJson("/api/quizzes/{$copie->quiz_id}/attempts");

        self::assertContains($solution->getStatusCode(), [403, 404], (string) $solution->getContent());
        self::assertContains($copies->getStatusCode(), [403, 404], (string) $copies->getContent());
        self::assertNull($copies->json('data.data'));
        $this->assertDatabaseHas(ForumPost::class, ['id' => $reponseDEleve->id, 'is_solution' => false]);
        $this->assertDatabaseHas(ForumTopic::class, ['id' => $reponseDEleve->topic_id, 'is_resolved' => false]);
    }

    /**
     * Témoin VERT, liste par défaut (`ChecksForumAuthorization:78`, ici par
     * `DeleteForumTopicRequest`) : `administrateur` y est déjà recopié.
     */
    #[DataProvider('administrateurs')]
    public function test_un_administrateur_de_l_ecole_supprime_le_sujet_d_un_autre(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $sujet = $this->sujet($ecole);
        $administrateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($administrateur)->deleteJson("/api/forum/topics/{$sujet->id}");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertTrue($reponse->json('success'));
        $this->assertSoftDeleted($sujet);
    }

    /** Témoin VERT, `ShowAttemptRequest:52` : `administrateur` y est déjà recopié. */
    #[DataProvider('administrateurs')]
    public function test_un_administrateur_de_l_ecole_lit_la_copie_d_un_eleve(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $copie = $this->copieRendue($ecole);
        $administrateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($administrateur)->getJson("/api/quiz-attempts/{$copie->id}");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame($copie->id, $reponse->json('data.id'));
    }

    /**
     * Garde VERTE, `ShowAttemptRequest:52` : le coordinateur supervise sans lire le détail
     * des réponses d'un élève (docblock de la FormRequest). L'alias n'y change rien.
     */
    #[DataProvider('coordinateurs')]
    public function test_un_coordinateur_de_l_ecole_ne_lit_pas_la_copie_d_un_eleve(InstitutionMode $mode, string $role): void
    {
        $ecole = $this->ecole($mode);
        $copie = $this->copieRendue($ecole);
        $coordinateur = $this->compte($ecole, $role);

        $reponse = $this->asTenant($coordinateur)->getJson("/api/quiz-attempts/{$copie->id}");

        self::assertSame(403, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertNull($reponse->json('data.id'));
    }

    /** Isolation, vert : l'alias d'administrateur ne vaut que dans sa propre école. */
    #[DataProvider('volets')]
    public function test_un_administrateur_d_une_autre_ecole_ne_supprime_ni_ne_lit_rien(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $sujet = $this->sujet($ecole);
        $copie = $this->copieRendue($ecole);
        $etranger = $this->compte($this->ecole($mode), 'administrateur');

        $suppression = $this->asTenant($etranger)->deleteJson("/api/forum/topics/{$sujet->id}");
        $lecture = $this->asTenant($etranger)->getJson("/api/quiz-attempts/{$copie->id}");

        self::assertContains($suppression->getStatusCode(), [403, 404], (string) $suppression->getContent());
        self::assertContains($lecture->getStatusCode(), [403, 404], (string) $lecture->getContent());
        self::assertNull($lecture->json('data.id'));
        $this->assertNotSoftDeleted($sujet);
    }

    /**
     * Chaque rôle, dans chaque type d'école ; le libellé dit s'il est canonique ou alias.
     *
     * @param  list<string>  $roles
     * @return iterable<string, array{0: InstitutionMode, 1: string}>
     */
    private static function parVolet(array $roles): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            foreach ($roles as $role) {
                $nature = Role::tryFrom($role) === null ? 'alias' : 'canonique';

                yield "{$role} ({$nature}), {$volet}" => [$mode, $role];
            }
        }
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    /** Le rôle est écrit tel quel : un alias reste un alias en base. */
    private function compte(Institution $ecole, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'last_klassci_sync' => now(),
        ]);
    }

    /** Un sujet ouvert, écrit par un enseignant de l'école : le modérateur n'en est jamais l'auteur. */
    private function sujet(Institution $ecole): ForumTopic
    {
        return ForumTopic::factory()->create([
            'user_id' => $this->compte($ecole, 'enseignant')->id,
            'institution_id' => $ecole->id,
            'status' => 'open',
            'is_resolved' => false,
        ]);
    }

    /** La réponse d'un élève de l'école au sujet : ni l'auteur du sujet, ni le modérateur. */
    private function reponseDEleve(Institution $ecole, ForumTopic $sujet): ForumPost
    {
        return ForumPost::factory()->create([
            'topic_id' => $sujet->id,
            'user_id' => $this->compte($ecole, 'etudiant')->id,
            'institution_id' => $ecole->id,
            'is_solution' => false,
        ]);
    }

    /** Une copie rendue d'un élève, sur un quiz créé par un enseignant de l'école. */
    private function copieRendue(Institution $ecole): QuizAttempt
    {
        $quiz = Quiz::factory()->create([
            'created_by' => $this->compte($ecole, 'enseignant')->id,
            'institution_id' => $ecole->id,
        ]);

        return QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->compte($ecole, 'etudiant')->id,
            'institution_id' => $ecole->id,
            'status' => 'submitted',
        ]);
    }
}
