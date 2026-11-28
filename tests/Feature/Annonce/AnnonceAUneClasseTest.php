<?php

declare(strict_types=1);

namespace Tests\Feature\Annonce;

use App\Enums\InstitutionMode;
use App\Jobs\EnvoyerLAnnonceParCourriel;
use App\Models\Annonce;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserClass;
use App\Notifications\AnnonceDeClasseParCourriel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1650 : le personnel annonce à TOUTE une classe, `POST /api/annonces`. Sous vrai jeton, dans les deux types d'école.
 *
 * Avant : la seule création manuelle (`POST /admin/notifications/create`) visait UN compte, et le rôle `admin` n'y avait
 * pas accès. Aucune annonce à une classe n'existait.
 *
 * - L'annonce est un ENREGISTREMENT (`annonces`) ; chaque élève inscrit et actif de la classe reçoit une notification
 *   `annonce` qui le désigne (`data.annonce_id`).
 * - Les destinataires viennent de la source d'inscription de l'école (`ElevesDeLaClasse`, `EnrollmentSource`). Dans une
 *   école KLASSCI, un élève inscrit par la synchronisation (`user_classes`) en fait partie.
 * - L'enseignant n'annonce qu'à SES classes (`classeIdsForTeacher`). Une classe d'une autre école est introuvable (404),
 *   du même corps qu'un id absent.
 * - `classe_id` est l'id de l'ESPACE SOURCE de l'école (`ClasseDeLEspaceSource`, comme `/lms/classes/{classe}/resultats`) :
 *   l'id KLASSCI dans une école KLASSCI, celui que donnent ses écrans de classe ; l'id local dans une école autonome.
 *   L'annonce garde l'id local.
 * - Le courriel ne part que si le canal existe (`CanalDeCourriel`) : une tâche en file, qui écrit à chaque destinataire.
 */
final class AnnonceAUneClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/annonces';

    private const URL = '/api/annonces';

    private const CORPS = ['titre' => 'Sortie au musée', 'message' => 'Rendez-vous vendredi à 8 h devant le portail.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        config(['mail.default' => 'log']);
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_l_admin_annonce_a_la_classe_et_seuls_ses_eleves_inscrits_la_recoivent(InstitutionMode $mode): void
    {
        [$ecole, $classe, $inscrits] = $this->classeAvecEleves($mode);
        $admin = $this->membre($ecole, 'admin');

        $reponse = $this->asTenant($admin)->postJson(self::URL, ['classe_id' => $this->idSource($classe)] + self::CORPS);

        // La réponse rend l'id reçu (espace source) ; l'annonce garde l'id LOCAL.
        $reponse->assertCreated()
            ->assertJsonPath('data.classe_id', $this->idSource($classe))
            ->assertJsonPath('data.titre', 'Sortie au musée')
            ->assertJsonPath('data.destinataires', 2)
            ->assertJsonPath('data.courriel', false);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');

        $annonce = Annonce::query()->withoutGlobalScopes()->findOrFail($reponse->json('data.id'));
        self::assertSame([$ecole->getKey(), $classe->getKey(), $admin->getKey(), 2], [
            $annonce->institution_id, $annonce->classe_id, $annonce->auteur_id, $annonce->destinataires,
        ]);

        $notifications = Notification::query()->withoutGlobalScopes()->where('type', 'annonce')->orderBy('user_id')->get();
        self::assertSame(array_map(static fn (User $u): int => $u->getKey(), $inscrits), $notifications->pluck('user_id')->all());
        foreach ($notifications as $notification) {
            self::assertSame('Sortie au musée', $notification->title);
            self::assertSame(self::CORPS['message'], $notification->message);
            self::assertSame($annonce->getKey(), $notification->data['annonce_id'] ?? null);
            self::assertSame($ecole->getKey(), $notification->institution_id);
        }
    }

    public function test_le_coordinateur_annonce(): void
    {
        [$ecole, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS)
            ->assertCreated()->assertJsonPath('data.destinataires', 2);
    }

    #[DataProvider('volets')]
    public function test_l_enseignant_annonce_a_sa_classe_mais_pas_a_une_autre(InstitutionMode $mode): void
    {
        [$ecole, $classe] = $this->classeAvecEleves($mode);
        $enseignant = $this->membre($ecole, 'enseignant');
        $this->enseigneDans($enseignant, $classe);
        $autre = $this->classe($ecole, '3e B');
        if ($mode === InstitutionMode::Klassci) {
            $autre->forceFill(['klassci_id' => 7003])->save();
        }

        $this->asTenant($enseignant)->postJson(self::URL, ['classe_id' => $this->idSource($classe)] + self::CORPS)->assertCreated();

        $refus = $this->asTenant($enseignant)->postJson(self::URL, ['classe_id' => $this->idSource($autre)] + self::CORPS);
        $refus->assertForbidden();
        $this->assertMatchesOpenApiResponse($refus, self::CHEMIN, 'post');
        self::assertSame(1, Annonce::query()->withoutGlobalScopes()->count());
    }

    /**
     * École KLASSCI : ses écrans désignent une classe par son id KLASSCI (`/lms/teacher/classes`, `/lms/classes/{id}`).
     * L'id LOCAL n'y désigne rien : introuvable, comme sur `/lms/classes/{classe}/resultats` (#1676).
     */
    public function test_ecole_klassci_l_id_local_d_une_classe_est_introuvable(): void
    {
        [$ecole, $classe] = $this->classeAvecEleves(InstitutionMode::Klassci);

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS);

        $reponse->assertNotFound();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertRienDEnvoye();
    }

    public function test_403_un_eleve_n_annonce_pas(): void
    {
        [$ecole, $classe, $inscrits] = $this->classeAvecEleves(InstitutionMode::Standalone);

        $reponse = $this->asTenant($inscrits[0])->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS);

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertRienDEnvoye();
    }

    /** L'isolation : une classe d'une autre école est introuvable, du même corps qu'un id absent. */
    public function test_404_une_classe_d_une_autre_ecole_comme_un_id_absent(): void
    {
        [, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);
        $ailleurs = $this->membre($this->ecole(), 'admin');

        $autreEcole = $this->asTenant($ailleurs)->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS);
        $absente = $this->asTenant($ailleurs)->postJson(self::URL, ['classe_id' => 999999] + self::CORPS);

        $autreEcole->assertNotFound();
        $absente->assertNotFound();
        self::assertSame($absente->json(), $autreEcole->json());
        $this->assertMatchesOpenApiResponse($autreEcole, self::CHEMIN, 'post');
        $this->assertRienDEnvoye();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'sans classe' => [self::CORPS, 'classe_id'],
            'classe non entière' => [['classe_id' => 'six'] + self::CORPS, 'classe_id'],
            'sans titre' => [['classe_id' => 0, 'message' => 'Texte'], 'titre'],
            'titre trop long' => [['classe_id' => 0, 'titre' => str_repeat('a', 151), 'message' => 'Texte'], 'titre'],
            'sans message' => [['classe_id' => 0, 'titre' => 'Titre'], 'message'],
            'message trop long' => [['classe_id' => 0, 'titre' => 'Titre', 'message' => str_repeat('a', 2001)], 'message'],
            'titre blanc' => [['classe_id' => 0, 'titre' => '   ', 'message' => 'Texte'], 'titre'],
        ];
    }

    /** @param  array<string, mixed>  $corps */
    #[DataProvider('saisiesRefusees')]
    public function test_422_une_saisie_refusee_n_envoie_rien(array $corps, string $champ): void
    {
        [$ecole, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);
        if (($corps['classe_id'] ?? null) === 0) {
            $corps['classe_id'] = $classe->getKey();
        }

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->postJson(self::URL, $corps);

        $reponse->assertUnprocessable()->assertJsonValidationErrors($champ);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertRienDEnvoye();
    }

    public function test_une_classe_sans_eleve_garde_l_annonce_sans_destinataire(): void
    {
        $ecole = $this->ecole();
        $vide = $this->classe($ecole, '2nde C');

        $this->asTenant($this->membre($ecole, 'admin'))->postJson(self::URL, ['classe_id' => $vide->getKey()] + self::CORPS)
            ->assertCreated()->assertJsonPath('data.destinataires', 0);

        self::assertSame(1, Annonce::query()->withoutGlobalScopes()->count());
        self::assertSame(0, Notification::query()->withoutGlobalScopes()->where('type', 'annonce')->count());
    }

    public function test_avec_un_canal_de_courriel_une_tache_ecrit_aux_destinataires(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        [$ecole, $classe, $inscrits] = $this->classeAvecEleves(InstitutionMode::Standalone);

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS);

        $reponse->assertCreated()->assertJsonPath('data.courriel', true);
        Queue::assertPushed(EnvoyerLAnnonceParCourriel::class, 1);
        Queue::assertPushed(EnvoyerLAnnonceParCourriel::class, fn (EnvoyerLAnnonceParCourriel $tache): bool => $tache->annonceId === $reponse->json('data.id')
            && $tache->destinataires === array_map(static fn (User $u): int => $u->getKey(), $inscrits));
    }

    public function test_sans_canal_de_courriel_aucune_tache(): void
    {
        Queue::fake();
        [$ecole, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);

        $this->asTenant($this->membre($ecole, 'admin'))->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS)
            ->assertCreated()->assertJsonPath('data.courriel', false);

        Queue::assertNotPushed(EnvoyerLAnnonceParCourriel::class);
    }

    public function test_la_tache_ecrit_a_chaque_destinataire_et_a_eux_seuls(): void
    {
        NotificationFacade::fake();
        [$ecole, $classe, $inscrits, $horsClasse] = $this->classeAvecEleves(InstitutionMode::Standalone);
        $annonce = Annonce::query()->create([
            'institution_id' => $ecole->getKey(), 'classe_id' => $classe->getKey(), 'auteur_id' => $this->membre($ecole, 'admin')->getKey(),
            'titre' => 'Sortie au musée', 'message' => self::CORPS['message'], 'destinataires' => 2,
        ]);
        // Un compte d'une autre école, glissé dans la liste : la tâche le refuse.
        $intrus = $this->membre($this->ecole(), 'etudiant');

        (new EnvoyerLAnnonceParCourriel($annonce->getKey(), [...array_map(static fn (User $u): int => $u->getKey(), $inscrits), $intrus->getKey()]))->handle();

        NotificationFacade::assertSentTo($inscrits, AnnonceDeClasseParCourriel::class);
        NotificationFacade::assertNotSentTo([$horsClasse, $intrus], AnnonceDeClasseParCourriel::class);
        NotificationFacade::assertSentToTimes($inscrits[0], AnnonceDeClasseParCourriel::class, 1);
    }

    public function test_la_limite_de_debit_borne_les_annonces(): void
    {
        [$ecole, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);
        $admin = $this->membre($ecole, 'admin');

        for ($i = 0; $i < 10; $i++) {
            $this->asTenant($admin)->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS)->assertCreated();
        }

        $this->asTenant($admin)->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS)->assertTooManyRequests();
        self::assertSame(10, Annonce::query()->withoutGlobalScopes()->count());
    }

    public function test_401_sans_jeton(): void
    {
        [, $classe] = $this->classeAvecEleves(InstitutionMode::Standalone);

        $reponse = $this->postJson(self::URL, ['classe_id' => $classe->getKey()] + self::CORPS);

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertRienDEnvoye();
    }

    // ───────────────────────── harnais

    /**
     * Une classe, deux élèves inscrits et actifs, un ancien inscrit (inactif), et un élève d'une autre classe.
     * École KLASSCI : la classe porte son id KLASSCI, et le second élève n'est inscrit QUE par la synchronisation
     * (`user_classes`), ce que lit la source composite.
     *
     * @return array{0: Institution, 1: Classe, 2: list<User>, 3: User}
     */
    private function classeAvecEleves(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $classe = $this->classe($ecole, '3e A');
        $autre = $this->classe($ecole, '4e A');
        [$awa, $yao, $ancien, $horsClasse] = [
            $this->membre($ecole, 'etudiant'), $this->membre($ecole, 'etudiant'),
            $this->membre($ecole, 'etudiant'), $this->membre($ecole, 'etudiant'),
        ];

        $this->inscrire($awa, $classe, 'actif');
        $this->inscrire($ancien, $classe, 'inactif');
        $this->inscrire($horsClasse, $autre, 'actif');
        if ($mode === InstitutionMode::Klassci) {
            $classe->forceFill(['klassci_id' => 7001])->save();
            UserClass::query()->create([
                'user_id' => $yao->getKey(), 'klassci_classe_id' => 7001, 'classe_nom' => '3e A',
                'institution_id' => $ecole->getKey(), 'synced_at' => now(),
            ]);
        } else {
            $this->inscrire($yao, $classe, 'actif');
        }

        $inscrits = [$awa, $yao];
        usort($inscrits, static fn (User $a, User $b): int => $a->getKey() <=> $b->getKey());

        return [$ecole, $classe, $inscrits, $horsClasse];
    }

    /** L'enseignant enseigne une matière de la classe (`classe_matiere.enseignant_id`), dans les deux volets. */
    private function enseigneDans(User $enseignant, Classe $classe): void
    {
        $matiere = $this->matiere(Institution::query()->findOrFail($classe->institution_id), 'Histoire');
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->getKey(), 'matiere_id' => $matiere->getKey(), 'enseignant_id' => $enseignant->getKey(),
            'institution_id' => $classe->institution_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** L'id de la classe dans l'espace SOURCE de son école : KLASSCI s'il existe, local sinon (#1676). */
    private function idSource(Classe $classe): int
    {
        return (int) ($classe->klassci_id ?? $classe->getKey());
    }

    private function assertRienDEnvoye(): void
    {
        self::assertSame(0, Annonce::query()->withoutGlobalScopes()->count());
        self::assertSame(0, Notification::query()->withoutGlobalScopes()->where('type', 'annonce')->count());
    }

    /** Brevo, tel que posé en production (`CanalDeCourriel::estDisponible()`). */
    private function brancherLeCanal(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.mailers.smtp.username' => 'compte@brevo.test',
            'mail.from.address' => 'ne-pas-repondre@lms.test',
            'activation.url_front' => 'https://lms.test',
        ]);
    }
}
