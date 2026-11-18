<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciEnseignantIdResolver;
use App\Services\Seances\AutoClose\Rules\TeacherDisconnectionRule;
use App\Services\Seances\AutoClose\SeanceClosingService;
use App\Services\Seances\Sync\Cursor\SeanceSyncPosition;
use App\Services\Seances\Sync\Cursor\TeacherCursorStream;
use App\Services\Sync\Classes\ClasseStudentsSynchronizer;
use App\Services\Sync\Classes\KlassciClassesFetcher;
use App\Services\TenantManager;
use ArrayObject;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeance;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #1198 — un rôle stocké sous un ALIAS est le même rôle que sa valeur canonique.
 *
 * La table des alias est `Role::aliases()` : `teacher` est un enseignant, `student` et
 * `étudiant` des élèves, `coordinator` un coordinateur. Plusieurs lectures de la synchro
 * KLASSCI et de la visio comparent pourtant la SEULE valeur canonique (`'enseignant'`,
 * `'etudiant'`, `Role::Enseignant->value`) : un compte à alias y est invisible.
 *
 * Défaut latent (0 alias stocké en production au 17/11), mais un alias s'est écrit
 * par la gestion jusqu'à #1246, et les comptes anciens peuvent en porter. Les
 * fabriques n'écrivent que des valeurs canoniques : chaque alias est créé ici
 * explicitement, avec son témoin canonique, vu aujourd'hui comme demain.
 *
 * - Groupe A, VERROU : rouge sur le code d'avant #1198, vert après. Curseur de synchro
 *   des séances, synchro des élèves d'une classe, récupération des classes, backfill
 *   et résolveur de l'identifiant d'enseignant, libellé du journal d'entrée en visio.
 * - Groupe B, TÉMOIN : déjà vert, la liste recopiée contient l'alias. Repli enseignant
 *   du fetcher, règle de déconnexion de l'enseignant et date de fin de fermeture.
 *
 * Les deux volets (école KLASSCI, école du LMS) partout où le site ne dépend pas du
 * mode. La synchro des élèves d'une classe et le fetcher n'existent que pour une école
 * KLASSCI : leurs tests le disent.
 */
final class AliasDeLaSynchroKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeance;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const ELEVE_KLASSCI = 8811;

    private const EMAIL_ELEVE = 'awa.traore@ecole-1198.test';

    private const KLASSCI_ID_DU_BACKFILL = 9101;

    private const ENSEIGNANT_DU_PAYLOAD = 42;

    private const KLASSCI = 'https://klassci.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.klassci.url' => self::KLASSCI]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function voletsEtEnseignants(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, enseignant (témoin canonique)" => [$mode, 'enseignant'];
            yield "{$volet}, teacher (alias)" => [$mode, 'teacher'];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function rolesEleve(): iterable
    {
        yield 'etudiant (témoin canonique)' => ['etudiant'];
        yield 'student (alias)' => ['student'];
        yield 'étudiant (alias)' => ['étudiant'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function rolesEnseignant(): iterable
    {
        yield 'enseignant (témoin canonique)' => ['enseignant'];
        yield 'teacher (alias)' => ['teacher'];
    }

    /**
     * A — `TeacherCursorStream::query()` filtre `Role::Enseignant->value` : un enseignant
     * stocké `teacher` sort du flux, ses séances ne sont plus synchronisées. Le curseur
     * est cross-tenant et ne lit pas le mode : les deux volets.
     */
    #[DataProvider('voletsEtEnseignants')]
    public function test_le_curseur_de_synchro_voit_l_enseignant_quel_que_soit_son_alias(
        InstitutionMode $mode,
        string $role,
    ): void {
        $enseignant = User::factory()->for($this->ecoleDuVolet($mode))->create([
            'role' => $role,
            'klassci_token' => 'jeton-de-synchro',
        ]);

        $flux = (new TeacherCursorStream)
            ->after(SeanceSyncPosition::startOfCycle(CarbonImmutable::now()))
            ->pluck('id')
            ->all();

        self::assertSame([$enseignant->id], $flux, "Un enseignant stocké « {$role} » doit être dans le curseur.");
    }

    /**
     * A — `ClasseStudentsSynchronizer`, repli par email : `where('role', 'etudiant')`
     * manque un élève stocké `student` ou `étudiant`. Le compte existant n'est ni
     * rattaché (`klassci_id`) ni inscrit ; la création qui suit heurte l'unique
     * `(email, institution_id)` et l'élève disparaît de sa classe.
     *
     * École KLASSCI seulement : la synchro des élèves d'une classe vient de KLASSCI.
     */
    #[DataProvider('rolesEleve')]
    public function test_la_synchro_de_classe_retrouve_par_email_l_eleve_quel_que_soit_son_alias(string $role): void
    {
        $ecole = $this->ecoleDuVolet(InstitutionMode::Klassci);
        app(TenantManager::class)->set($ecole);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id]);
        // Compte créé localement, pas encore lié à KLASSCI : seul l'email le désigne.
        $eleve = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_id' => null,
            'email' => self::EMAIL_ELEVE,
        ]);

        $stats = app(ClasseStudentsSynchronizer::class)->sync($classe, [[
            'id' => self::ELEVE_KLASSCI,
            'nom' => 'Traoré',
            'prenom' => 'Awa',
            'email' => self::EMAIL_ELEVE,
        ]], 'jeton');

        $comptes = User::withoutGlobalScope('institution')->where('email', self::EMAIL_ELEVE);
        self::assertSame(
            self::ELEVE_KLASSCI,
            (int) User::withoutGlobalScope('institution')->whereKey($eleve->id)->value('klassci_id'),
            "L'élève stocké « {$role} » doit être retrouvé par son email et rattaché à KLASSCI.",
        );
        self::assertSame(1, $comptes->count(), 'Aucun doublon du compte.');
        self::assertTrue(
            DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->exists(),
            'Le compte existant est inscrit à sa classe.',
        );
        self::assertSame(1, $stats['enrollments_created']);
    }

    /**
     * A — `KlassciClassesFetcher::fetch()` ne prend la branche élève que pour
     * `'etudiant'` : un élève stocké `student` ou `étudiant` part vers `GET /classes`,
     * la liste des classes de l'école, au lieu de `GET /me/dashboard`, la sienne.
     *
     * École KLASSCI seulement : le fetcher interroge KLASSCI. Aucun tenant résolu :
     * la cible est `services.klassci.url`.
     */
    #[DataProvider('rolesEleve')]
    public function test_le_fetcher_prend_la_branche_eleve_quel_que_soit_son_alias(string $role): void
    {
        $appels = $this->klassciSimule();

        $classes = app(KlassciClassesFetcher::class)->fetch('jeton-eleve', $role);

        self::assertSame(
            [self::KLASSCI.'/me/dashboard'],
            $appels->getArrayCopy(),
            "Un élève stocké « {$role} » lit sa seule classe, par me/dashboard.",
        );
        self::assertSame([['id' => 41, 'libelle' => 'Terminale D']], $classes);
    }

    /**
     * B, témoin — le repli par rôle de `KlassciClassesFetcher::fetchByRole()` liste déjà
     * `['enseignant', 'teacher']` : quand `GET /classes` est refusé, un `teacher` passe
     * par `me/teacher-dashboard` puis le lot des matières.
     *
     * École KLASSCI seulement, comme le test précédent.
     */
    #[DataProvider('rolesEnseignant')]
    public function test_le_fetcher_prend_deja_la_branche_enseignant_pour_l_alias(string $role): void
    {
        $appels = $this->klassciSimule();

        $classes = app(KlassciClassesFetcher::class)->fetch('jeton-enseignant', $role);

        self::assertSame([
            self::KLASSCI.'/classes',
            self::KLASSCI.'/me/teacher-dashboard',
            self::KLASSCI.'/matieres/10',
        ], $appels->getArrayCopy());
        self::assertSame([['id' => 30, 'libelle' => 'Terminale S1']], $classes);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: string|null}> */
    public static function voletsEtEnseignantsDuBackfill(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, role enseignant (témoin canonique)" => [$mode, 'enseignant', null];
            yield "{$volet}, role teacher (alias)" => [$mode, 'teacher', null];
            yield "{$volet}, klassci_role enseignant (témoin canonique)" => [$mode, 'coordinateur', 'enseignant'];
            yield "{$volet}, klassci_role teacher (alias)" => [$mode, 'coordinateur', 'teacher'];
        }
    }

    /**
     * A — `klassci:backfill-enseignant-id` : sans `enseignant_id` dans le blob, le repli
     * sur `klassci_id` n'est accordé qu'à `role` ou `klassci_role` strictement égal à
     * `'enseignant'`. La commande parcourt toute la table, sans tenant ni mode.
     */
    #[DataProvider('voletsEtEnseignantsDuBackfill')]
    public function test_le_backfill_donne_son_klassci_id_a_l_enseignant_quel_que_soit_son_alias(
        InstitutionMode $mode,
        string $role,
        ?string $klassciRole,
    ): void {
        $compte = User::factory()->for($this->ecoleDuVolet($mode))->create([
            'role' => $role,
            'klassci_role' => $klassciRole,
            'klassci_id' => self::KLASSCI_ID_DU_BACKFILL,
        ]);
        // Harnais de BackfillEnseignantIdCommandTest : le blob n'a pas d'`enseignant_id`,
        // seul le repli par rôle peut remplir la colonne.
        DB::table('users')->where('id', $compte->id)->update([
            'klassci_enseignant_id' => null,
            'klassci_data' => json_encode(['enseignant_data' => ['nb_matieres' => 0], 'nom' => 'Bede']),
        ]);

        $this->artisan('klassci:backfill-enseignant-id')->assertExitCode(0);

        self::assertSame(
            self::KLASSCI_ID_DU_BACKFILL,
            User::withoutGlobalScope('institution')->find($compte->id)?->klassci_enseignant_id,
            "role « {$role} », klassci_role « {$klassciRole} » : l'identifiant d'enseignant reste vide.",
        );
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: int|null}> */
    public static function voletsEtRolesDuPayload(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, enseignant (témoin canonique)" => [$mode, 'enseignant', self::ENSEIGNANT_DU_PAYLOAD];
            yield "{$volet}, teacher (alias)" => [$mode, 'teacher', self::ENSEIGNANT_DU_PAYLOAD];
            yield "{$volet}, etudiant (témoin négatif)" => [$mode, 'etudiant', null];
        }
    }

    /**
     * A — `KlassciEnseignantIdResolver::resolve()` ne replie sur l'`id` du payload que
     * pour `role === 'enseignant'`. Le résolveur ne lit que le payload, jamais le mode :
     * les deux volets portent le compte réparé par `healIfNull()`. Le témoin négatif
     * prouve que l'assertion départage les rôles.
     */
    #[DataProvider('voletsEtRolesDuPayload')]
    public function test_le_resolveur_donne_l_identifiant_d_enseignant_quel_que_soit_l_alias(
        InstitutionMode $mode,
        string $role,
        ?int $attendu,
    ): void {
        // KLASSCI écrit le rôle LMS sous sa valeur canonique ; seul le payload porte l'alias.
        $compte = User::factory()->for($this->ecoleDuVolet($mode))->create([
            'role' => Role::tryFromString($role)?->value,
            'klassci_enseignant_id' => null,
        ]);
        $payload = ['role' => $role, 'id' => self::ENSEIGNANT_DU_PAYLOAD];
        $resolveur = app(KlassciEnseignantIdResolver::class);

        self::assertSame($attendu, $resolveur->resolve($payload), "Payload KLASSCI de rôle « {$role} ».");

        $resolveur->healIfNull($compte, $payload);

        self::assertSame($attendu, User::withoutGlobalScope('institution')->find($compte->id)?->klassci_enseignant_id);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: string}> */
    public static function voletsEtLibellesDuJournal(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, enseignant (témoin canonique)" => [$mode, 'enseignant', 'Enseignant'];
            yield "{$volet}, teacher (alias)" => [$mode, 'teacher', 'Enseignant'];
            yield "{$volet}, coordinateur (témoin canonique)" => [$mode, 'coordinateur', 'Coordinateur (observateur)'];
            yield "{$volet}, coordinator (alias)" => [$mode, 'coordinator', 'Coordinateur (observateur)'];
        }
    }

    /**
     * A — `VisioParticipantSessionService::join()`, `match ($user->role)` : un `teacher`
     * ou un `coordinator` est journalisé « Étudiant ». Sans effet métier (l'observateur
     * est décidé par `isCoordinator()`), mais le journal d'audit ment. Vrai jeton
     * porteur, vraie route ; le journal est lu tel qu'il est ÉCRIT.
     */
    #[DataProvider('voletsEtLibellesDuJournal')]
    public function test_le_journal_d_entree_en_visio_nomme_le_role_quel_que_soit_son_alias(
        InstitutionMode $mode,
        string $role,
        string $libelle,
    ): void {
        $this->disableKlassciMiddleware();
        $this->captureWrittenLog();
        $ecole = $this->ecoleDuVolet($mode);
        // Identité d'enseignant de la séance (KLASSCI) et auteur (LMS) : sans effet pour
        // un coordinateur, admis comme gestionnaire.
        $compte = $this->compte($ecole, $role, ['klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI]);
        $seance = $this->visio($ecole, $compte);

        $reponse = $this->asTenant($compte)->postJson("/api/lms/seances/{$seance->id}/join");

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame(["{$libelle} rejoint visio - participation enregistrée"], $this->entreesEnVisio($compte));
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: bool}> */
    public static function voletsEtPresences(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "{$volet}, enseignant (témoin canonique)" => [$mode, 'enseignant', true];
            yield "{$volet}, teacher (alias)" => [$mode, 'teacher', true];
            yield "{$volet}, etudiant (témoin négatif)" => [$mode, 'etudiant', false];
        }
    }

    /**
     * B, témoin — `TeacherDisconnectionRule::appliesTo()` cherche la présence de
     * l'enseignant par `whereIn('role', ['enseignant', 'teacher'])` : parti depuis
     * 10 minutes, un `teacher` ferme déjà la séance. Le témoin négatif prouve que la
     * règle départage les rôles.
     */
    #[DataProvider('voletsEtPresences')]
    public function test_la_regle_de_deconnexion_reconnait_deja_l_enseignant_a_alias(
        InstitutionMode $mode,
        string $role,
        bool $ferme,
    ): void {
        $maintenant = Carbon::parse('2026-11-18 10:00:00');
        $seance = $this->seanceQuitteePar($mode, $role, $maintenant->copy()->subMinutes(10));

        self::assertSame($ferme, app(TeacherDisconnectionRule::class)->appliesTo($seance, $maintenant));
    }

    /**
     * B, témoin — `SeanceClosingService`, raison `teacher_disconnected` : la fin est le
     * départ de l'enseignant, trouvé par la même liste recopiée. Sans lui, la fin
     * serait « maintenant » : l'assertion départage les deux.
     */
    #[DataProvider('voletsEtEnseignants')]
    public function test_la_fermeture_date_deja_la_fin_au_depart_de_l_enseignant_a_alias(
        InstitutionMode $mode,
        string $role,
    ): void {
        $maintenant = Carbon::parse('2026-11-18 10:00:00');
        $depart = $maintenant->copy()->subMinutes(7);
        $seance = $this->seanceQuitteePar($mode, $role, $depart);

        app(SeanceClosingService::class)->close($seance, 'teacher_disconnected', $maintenant);

        self::assertSame(
            $depart->toDateTimeString(),
            Seance::withoutGlobalScope('institution')->findOrFail($seance->id)->visio_ended_at?->toDateTimeString(),
        );
    }

    /** L'école du volet, comme `BuildsVisioSeance` la lit : une école du LMS n'a pas d'URL KLASSCI. */
    private function ecoleDuVolet(InstitutionMode $mode): Institution
    {
        return $mode === InstitutionMode::Standalone
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
    }

    /**
     * KLASSCI simulé par UNE closure qui répond selon le chemin et note chaque URL.
     * `GET /classes` est refusé (403), comme pour un compte sans droit sur la liste.
     *
     * @return ArrayObject<int, string>
     */
    private function klassciSimule(): ArrayObject
    {
        /** @var ArrayObject<int, string> $appels */
        $appels = new ArrayObject;
        Http::preventStrayRequests();
        Http::fake(static function (Request $requete) use ($appels) {
            $appels[] = $requete->url();

            return match (parse_url($requete->url(), PHP_URL_PATH)) {
                '/me/dashboard' => Http::response(['success' => true, 'data' => [
                    'classe' => ['id' => 41, 'libelle' => 'Terminale D', 'etudiants' => [['id' => 1]]],
                ]]),
                '/me/teacher-dashboard' => Http::response(['success' => true, 'data' => [
                    'matieres' => [['id' => 10, 'nom' => 'Maths']],
                ]]),
                '/matieres/10' => Http::response(['success' => true, 'data' => [
                    'classe' => ['id' => 30, 'libelle' => 'Terminale S1'],
                ]]),
                default => Http::response(['success' => false, 'message' => 'Accès refusé'], 403),
            };
        });

        return $appels;
    }

    /** Une visio active, et la présence close d'un compte de ce rôle, parti à `$depart`. */
    private function seanceQuitteePar(InstitutionMode $mode, string $role, Carbon $depart): Seance
    {
        $ecole = $this->ecoleDuVolet($mode);
        $seance = Seance::factory()->visioActive()->create(['institution_id' => $ecole->id]);
        $compte = User::factory()->for($ecole)->create(['role' => $role]);
        ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => $compte->id,
            'institution_id' => $ecole->id,
            'status' => 'disconnected',
            'joined_at' => $depart->copy()->subMinutes(30),
            'left_at' => $depart,
        ]);

        return $seance;
    }

    /**
     * Les messages d'entrée en visio écrits pour ce compte.
     *
     * @return list<string>
     */
    private function entreesEnVisio(User $compte): array
    {
        $messages = [];
        foreach ($this->journal->getRecords() as $record) {
            if (str_contains($record->message, 'rejoint visio') && ($record->context['user_id'] ?? null) === $compte->id) {
                $messages[] = $record->message;
            }
        }

        return $messages;
    }
}
