<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Dashboard\AdminDashboardService;
use App\Services\Dashboard\AdminStatisticsService;
use App\Services\KlassciProxyService;
use App\Services\Notification\QuizNotificationDispatcher;
use App\Services\Notification\VisioNotificationTargets;
use App\Services\Seances\SeanceVisioEnricher;
use App\Services\TenantManager;
use App\Services\Visio\VisioParticipantsListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #1198 — un compte se lit sous CHAQUE valeur que la table des rôles reconnaît
 * (`Role::aliases()`), pas sous la seule valeur canonique.
 *
 * `users.role` peut porter un alias : `student` ou `étudiant` pour un élève, `teacher` pour un
 * enseignant. Plusieurs requêtes comparent la seule valeur canonique (`->where('role',
 * 'etudiant')`) et manquent ces comptes : compteurs du tableau d'admin, avertissements de quiz
 * et de visio, listes de participants. Défaut latent : 0 alias stocké en production au 17/11.
 *
 * ## Les deux groupes
 *
 * - Groupe A, ROUGE avant le correctif : le site compare la seule valeur canonique.
 * - Groupe B, VERT aujourd'hui, témoin : `AdminStatisticsService` lit déjà toutes les valeurs.
 *
 * Chaque cas crée UN compte par valeur stockée de la table, la canonique comprise : le compte
 * canonique est le témoin (vu aujourd'hui comme demain), les alias sont le verrou. Les ids
 * attendus sont comparés en ENTIER : un rouge montre l'alias manquant, et le canonique présent.
 * Un compte d'un autre rôle, qui ne doit pas être compté, borne le correctif.
 *
 * La table est lue, pas recopiée : un alias ajouté à `Role::aliases()` est verrouillé d'office.
 * `aliasesDe()` refuse une table sans alias : le test ne vérifierait plus que le canonique.
 *
 * ## Les deux volets
 *
 * Les compteurs et les quiz ne lisent pas le mode : ils couvrent l'école autonome et l'école
 * KLASSCI (`volets()`). Les autres sites n'existent que dans l'école KLASSCI, chaque test dit
 * pourquoi.
 *
 * ## Le harnais
 *
 * Les services sont appelés directement, résolus par le conteneur, sans tenant : le scope
 * `institution` est inerte, et chaque ligne porte son `institution_id` explicite. Seuls les deux
 * services de statistiques exigent un tenant (`getResolvedSlug()` de leur clé de cache) : il est
 * posé juste avant l'appel, et le cache est vidé avant chaque test.
 *
 * Les fabriques n'écrivent que la valeur canonique : chaque alias est écrit explicitement, et
 * `compte()` relit la colonne brute pour prouver qu'il est bien stocké tel quel. Sans cela, une
 * normalisation à l'écriture rendrait ces tests verts sans rien prouver.
 */
final class AliasDesNotificationsEtDesListesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** Groupe A — `AdminDashboardService::userCounts` (`:154`, `:155`). */
    #[DataProvider('volets')]
    public function test_le_tableau_d_admin_compte_chaque_valeur_stockee_d_eleve_et_d_enseignant(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $gestion = $this->compte($ecole, Role::Coordinateur->value);
        $eleves = $this->unParValeurStockee($ecole, Role::Etudiant);
        $enseignants = $this->unParValeurStockee($ecole, Role::Enseignant);
        $this->compte($ecole, 'coordinator');

        $this->app->make(TenantManager::class)->set($ecole);
        $stats = $this->app->make(AdminDashboardService::class)->buildStats($gestion);

        self::assertSame(2 + count($eleves) + count($enseignants), $stats['users']['total'], 'Témoin : le total compte tous les comptes.');
        self::assertSame(count($eleves), $stats['users']['students'], 'Élèves : '.implode(', ', Role::Etudiant->aliases()));
        self::assertSame(count($enseignants), $stats['users']['teachers'], 'Enseignants : '.implode(', ', Role::Enseignant->aliases()));
    }

    /** Groupe B, témoin — `AdminStatisticsService::build` (`:56`, `:57`) lit déjà toutes les valeurs. */
    #[DataProvider('volets')]
    public function test_les_statistiques_d_admin_comptent_deja_chaque_valeur_stockee(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $eleves = $this->unParValeurStockee($ecole, Role::Etudiant);
        $enseignants = $this->unParValeurStockee($ecole, Role::Enseignant);
        $this->compte($ecole, 'coordinator');

        $this->app->make(TenantManager::class)->set($ecole);
        $stats = $this->app->make(AdminStatisticsService::class)->build();

        self::assertSame(count($eleves), $stats['nb_etudiants']);
        self::assertSame(count($enseignants), $stats['nb_enseignants']);
    }

    /** Groupe A — `QuizNotificationDispatcher::notifyQuizAvailable` (`:39`). */
    #[DataProvider('volets')]
    public function test_un_quiz_disponible_avertit_chaque_valeur_stockee_d_eleve(InstitutionMode $mode): void
    {
        [$quiz, $attendus] = $this->quizDUneClasse($this->ecole($mode));

        $envoyes = $this->app->make(QuizNotificationDispatcher::class)->notifyQuizAvailable($quiz);

        self::assertSame($attendus, $this->destinataires(Notification::TYPE_QUIZ_AVAILABLE));
        self::assertSame(count($attendus), $envoyes);
    }

    /** Groupe A — `QuizNotificationDispatcher::notifyQuizDeadline` (`:88`). Aucun élève n'a encore participé. */
    #[DataProvider('volets')]
    public function test_l_echeance_d_un_quiz_avertit_chaque_valeur_stockee_d_eleve(InstitutionMode $mode): void
    {
        [$quiz, $attendus] = $this->quizDUneClasse($this->ecole($mode));

        $envoyes = $this->app->make(QuizNotificationDispatcher::class)->notifyQuizDeadline($quiz, 2);

        self::assertSame($attendus, $this->destinataires(Notification::TYPE_QUIZ_DEADLINE));
        self::assertSame(count($attendus), $envoyes);
    }

    /**
     * Groupe A — `VisioNotificationTargets::elevesProgrammation` (`:59`).
     *
     * École KLASSCI seule : la ligne n'est lue que si la charge porte `klassci_classe_id`. Une
     * séance d'école autonome n'en a pas (`LocalSeanceCreator`, `VisioToggleService`), et passe
     * par l'audience locale, couverte par le témoin d'école autonome ci-dessous.
     */
    public function test_la_programmation_d_une_visio_klassci_avertit_chaque_valeur_stockee_d_eleve(): void
    {
        [$charge, $attendus] = $this->visioKlassci();

        $eleves = $this->app->make(VisioNotificationTargets::class)->elevesProgrammation((int) $charge['seance_locale_id'], $charge);

        self::assertSame($attendus, self::idsTries($eleves));
    }

    /**
     * Groupe A — `VisioNotificationTargets::elevesDemarrage` (`:93`).
     *
     * École KLASSCI seule : `:93` est la branche `klassci_classe_id`. Le chemin de la séance
     * locale (`elevesDeLaSeanceLocale`) lit l'audience, qui passe par `isStudent()`.
     */
    public function test_le_demarrage_d_une_visio_klassci_avertit_chaque_valeur_stockee_d_eleve(): void
    {
        [$charge, $attendus] = $this->visioKlassci();

        $eleves = $this->app->make(VisioNotificationTargets::class)->elevesDemarrage($charge);

        self::assertSame($attendus, self::idsTries($eleves));
    }

    /**
     * Témoin d'école autonome, VERT aujourd'hui : la séance locale lit l'audience
     * (`ElevesDeLaClasse`, `isStudent()`). Même filet que
     * `VisioSeanceLocaleNotificationsTest::test_un_eleve_au_role_alias_est_averti_comme_il_est_admis`,
     * ici au démarrage et pour chaque valeur stockée.
     */
    public function test_le_demarrage_d_une_visio_d_ecole_autonome_avertit_deja_chaque_valeur_stockee(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $classe = $this->classe($ecole);
        $auteur = $this->compte($ecole, Role::Enseignant->value);
        $seance = Seance::factory()->for($ecole)->create([
            'created_by' => $auteur->id,
            'classe_id' => $classe->id,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
        ]);
        $attendus = $this->elevesActifsDe($classe);

        $eleves = $this->app->make(VisioNotificationTargets::class)->elevesDemarrage(['seance_locale_id' => $seance->id]);

        self::assertSame($attendus, self::idsTries($eleves));
    }

    /**
     * Groupe A — `VisioNotificationTargets::enseignant`, branche `klassci_enseignant_id` (`:150`).
     *
     * École KLASSCI seule : une séance d'école autonome n'a pas d'enseignant KLASSCI, son
     * enseignant est l'auteur (`created_by`), lu par `isTeacher()`. Le compte canonique passe en
     * premier : le témoin, puis chaque alias.
     */
    public function test_l_enseignant_klassci_d_une_visio_est_retrouve_sous_chaque_valeur_stockee(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $seance = Seance::factory()->for($ecole)->create();
        $cibles = $this->app->make(VisioNotificationTargets::class);
        $charge = static fn (User $compte): array => ['seance_locale_id' => $seance->id, 'klassci_enseignant_id' => $compte->klassci_id];

        $coordinateur = $this->compte($ecole, 'coordinator');
        self::assertNull($cibles->enseignant($charge($coordinateur)), 'Un autre rôle n\'est pas l\'enseignant.');

        foreach (self::aliasesDe(Role::Enseignant) as $valeur) {
            $enseignant = $this->compte($ecole, $valeur);

            self::assertSame($enseignant->id, $cibles->enseignant($charge($enseignant))?->id, "Enseignant stocké « {$valeur} ».");
        }
    }

    /**
     * Groupe A — `SeanceVisioEnricher::resolveParticipants`, repli local (`:264`).
     *
     * École KLASSCI seule : le repli lit `user_classes`, le miroir des classes KLASSCI, après
     * un appel KLASSCI (ici simulé vide) ; l'appelant exige un `klassci_token`.
     */
    public function test_le_repli_local_des_participants_d_une_seance_klassci_liste_chaque_valeur_stockee_d_eleve(): void
    {
        $this->mock(KlassciProxyService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->once()->andReturn(['data' => []]);
        });
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $seance = Seance::factory()->for($ecole)->create();
        $attendus = $this->miroirDesClasses($ecole, (int) $seance->klassci_classe_id);

        $participants = $this->app->make(SeanceVisioEnricher::class)
            ->resolveParticipants((int) $seance->klassci_classe_id, 'jeton-klassci', $seance);

        self::assertSame($attendus, self::idsTries(collect($participants)));
    }

    /**
     * Groupe A — `VisioParticipantsListService::fetchStudentsFromLocalDb` (`:267`).
     *
     * École KLASSCI seule, comme le site jumeau ci-dessus (`user_classes`).
     *
     * Appel par `ReflectionMethod`, déclaré : la méthode publique `list()` n'atteint ce repli
     * qu'après `SeanceDetailQueryService::getSeanceDetailsArray()` (classe finale, non
     * remplaçable), qui exécute elle-même le repli jumeau `SeanceVisioEnricher:264`. Par
     * `list()`, les deux sites seraient confondus : ce test ne verrouillerait pas `:267` seul.
     */
    public function test_le_repli_local_de_la_liste_de_presence_visio_liste_chaque_valeur_stockee_d_eleve(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $seance = Seance::factory()->for($ecole)->create();
        $attendus = $this->miroirDesClasses($ecole, (int) $seance->klassci_classe_id);
        $repli = new ReflectionMethod(VisioParticipantsListService::class, 'fetchStudentsFromLocalDb');

        $participants = $repli->invoke(
            $this->app->make(VisioParticipantsListService::class),
            (int) $seance->klassci_seance_id,
            (int) $seance->klassci_classe_id,
        );

        self::assertIsArray($participants);
        self::assertSame($attendus, self::idsTries(collect($participants)));
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return $mode === InstitutionMode::Standalone
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
    }

    /**
     * Un compte de l'école sous la valeur `$role` EXACTE, relue en base. Un compte d'école
     * autonome n'a pas d'identité KLASSCI.
     */
    private function compte(Institution $ecole, string $role): User
    {
        $sansKlassci = $ecole->mode === InstitutionMode::Standalone
            ? ['klassci_id' => null, 'klassci_token' => null, 'klassci_enseignant_id' => null]
            : [];
        $compte = User::factory()->for($ecole)->create($sansKlassci + [
            'role' => $role,
            'klassci_tenant_url' => $ecole->klassci_api_url,
        ]);

        self::assertSame($role, DB::table('users')->where('id', $compte->id)->value('role'), 'Le rôle est stocké tel quel.');

        return $compte;
    }

    /**
     * Un compte par valeur stockée du rôle, la canonique comprise.
     *
     * @return list<int>
     */
    private function unParValeurStockee(Institution $ecole, Role $role): array
    {
        $ids = [];
        foreach (self::aliasesDe($role) as $valeur) {
            $ids[] = (int) $this->compte($ecole, $valeur)->id;
        }

        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private static function aliasesDe(Role $role): array
    {
        $valeurs = $role->aliases();
        self::assertContains($role->value, $valeurs);
        self::assertGreaterThan(1, count($valeurs), "La table n'a plus d'alias pour {$role->value} : rien à verrouiller.");

        return $valeurs;
    }

    private function classe(Institution $ecole): Classe
    {
        return $ecole->mode === InstitutionMode::Standalone
            ? Classe::factory()->for($ecole)->create(['klassci_id' => null])
            : Classe::factory()->for($ecole)->create();
    }

    /**
     * Un élève actif par valeur stockée ; hors liste : un enseignant inscrit au pivot.
     *
     * @return list<int>
     */
    private function elevesActifsDe(Classe $classe): array
    {
        $ecole = $classe->institution;
        self::assertInstanceOf(Institution::class, $ecole);
        $actif = ['statut' => ClasseEtudiantStatut::Actif->value];

        $ids = $this->unParValeurStockee($ecole, Role::Etudiant);
        $classe->etudiants()->attach($ids, $actif);
        $classe->etudiants()->attach($this->compte($ecole, 'teacher')->id, $actif);

        return $ids;
    }

    /**
     * Un quiz de la classe ; hors liste : un élève d'une autre classe.
     *
     * @return array{0: Quiz, 1: list<int>}
     */
    private function quizDUneClasse(Institution $ecole): array
    {
        $classe = $this->classe($ecole);
        $attendus = $this->elevesActifsDe($classe);
        $this->classe($ecole)->etudiants()->attach(
            $this->compte($ecole, 'student')->id,
            ['statut' => ClasseEtudiantStatut::Actif->value],
        );
        $quiz = Quiz::factory()->forTeacher($this->compte($ecole, Role::Enseignant->value))->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
        ]);

        return [$quiz, $attendus];
    }

    /**
     * Une séance KLASSCI et la charge de son avertissement ; hors liste : un élève inactif.
     *
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function visioKlassci(): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $classe = $this->classe($ecole);
        $seance = Seance::factory()->for($ecole)->create(['klassci_classe_id' => $classe->klassci_id]);
        $attendus = $this->elevesActifsDe($classe);
        $classe->etudiants()->attach(
            $this->compte($ecole, 'student')->id,
            ['statut' => ClasseEtudiantStatut::Inactif->value],
        );

        return [[
            'seance_locale_id' => $seance->id,
            'klassci_classe_id' => $classe->klassci_id,
            'klassci_enseignant_id' => $seance->klassci_enseignant_id,
            'matiere_nom' => 'Anglais',
        ], $attendus];
    }

    /**
     * Le miroir `user_classes` d'une classe KLASSCI ; hors liste : un enseignant.
     *
     * @return list<int>
     */
    private function miroirDesClasses(Institution $ecole, int $klassciClasseId): array
    {
        $ids = $this->unParValeurStockee($ecole, Role::Etudiant);
        $comptes = [...$ids, (int) $this->compte($ecole, 'teacher')->id];

        foreach ($comptes as $id) {
            UserClass::query()->create([
                'user_id' => $id,
                'klassci_classe_id' => $klassciClasseId,
                'institution_id' => $ecole->id,
            ]);
        }

        return $ids;
    }

    /** @return list<int> */
    private function destinataires(string $type): array
    {
        return self::idsTries(Notification::withoutGlobalScope('institution')->where('type', $type)->pluck('user_id'));
    }

    /**
     * @param  Collection<int, mixed>  $comptes  des `User`, des tableaux portant `id`, ou des ids
     * @return list<int>
     */
    private static function idsTries(Collection $comptes): array
    {
        $ids = $comptes->map(static fn (mixed $compte): int => (int) (match (true) {
            $compte instanceof User => $compte->id,
            is_array($compte) => $compte['id'] ?? null,
            default => $compte,
        }))->all();

        sort($ids);

        return array_values($ids);
    }
}
