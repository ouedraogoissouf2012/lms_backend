<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Une séance se modifie (#1121, décision du user du 17/11).
 *
 * - **École autonome** : l'enseignant ne dépend d'aucun KLASSCI ; il modifie le
 *   titre, la date et l'heure, la classe (`PUT|PATCH /lms/seances/{seanceId}`).
 *   Qui modifie : la règle de la suppression, l'auteur ou la gestion. Les règles de
 *   la création s'appliquent : la classe de l'établissement, au programme de la
 *   matière de la séance. Une séance passée reste verrouillée (#1107), une visio en
 *   cours aussi.
 * - **École KLASSCI** : la séance se modifie dans KLASSCI, le LMS la récupère. Le
 *   LMS refuse, avec le motif `seance_managed_by_klassci`, y compris pour une
 *   séance créée localement.
 *
 * Chaque carte de séance porte `can_be_edited`, calculé pour le lecteur à la même
 * source que la modification : le front n'a rien à déduire.
 *
 * Chaque refus laisse la ligne INCHANGÉE. Vrai jeton.
 */
final class ModifierUneSeanceTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private User $auteur;

    private Matiere $maths;

    private Classe $sixiemeA;

    private Classe $sixiemeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── école autonome

    public function test_l_auteur_modifie_le_titre_la_date_et_la_classe(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        // Un décalage autre que celui de l'application : il fait foi (D4).
        $iso = Carbon::now('+02:00')->addDays(5)->setTime(10, 30)->format('Y-m-d\TH:i:sP');

        $reponse = $this->modifier($this->auteur, $seance, [
            'titre' => 'Révision des fractions',
            'date_seance' => $iso,
            'classe_id' => $this->sixiemeB->id,
            'classe_nom' => '6e B',
        ]);

        $reponse->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.titre', 'Révision des fractions');
        $relue = $seance->fresh();
        self::assertNotNull($relue);
        self::assertSame('Révision des fractions', $relue->titre);
        self::assertSame($this->sixiemeB->id, $relue->classe_id);
        self::assertSame('6e B', $relue->classe_nom);
        self::assertSame(
            Carbon::parse($iso)->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s'),
            Carbon::parse($relue->getRawOriginal('date_seance'))->format('Y-m-d H:i:s'),
            'L\'instant reçu avec son décalage est rangé dans le fuseau de l\'application, comme à la création.',
        );
        self::assertSame($this->maths->id, $relue->matiere_id, 'La matière ne se change pas.');
    }

    public function test_patch_ne_change_que_ce_qu_il_recoit(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $avant = $this->ligne($seance);

        $this->asTenant($this->auteur)->patchJson("/api/lms/seances/{$seance->id}", ['titre' => 'Nouveau titre'])->assertOk();

        $apres = $this->ligne($seance);
        self::assertSame('Nouveau titre', $apres['titre']);
        unset($avant['titre'], $avant['updated_at'], $apres['titre'], $apres['updated_at']);
        self::assertSame($avant, $apres);
    }

    public function test_la_gestion_modifie(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();

        foreach (['coordinateur', 'admin'] as $role) {
            $this->modifier($this->membre($this->ecole, $role), $seance, ['titre' => "Par $role"])->assertOk();
            self::assertSame("Par $role", $seance->fresh()?->titre);
        }
    }

    /** Déclaré : déplacer une séance dans le passé reste permis, comme à la création. */
    public function test_deplacer_une_seance_dans_le_passe_reste_permis(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $hier = Carbon::now()->subDay()->setTime(9, 0)->utc()->format('Y-m-d\TH:i:s\Z');

        $this->modifier($this->auteur, $seance, ['date_seance' => $hier])->assertOk();
    }

    public function test_un_collegue_enseignant_est_refuse_sans_motif(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->membre($this->ecole, 'enseignant'), $seance, ['titre' => 'Pirate']), 403, null);
    }

    public function test_l_eleve_est_refuse(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->membre($this->ecole, 'etudiant'), $seance, ['titre' => 'Pirate']), 403, null);
    }

    public function test_une_seance_d_une_autre_ecole_est_introuvable(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $voisine = $this->ecole(InstitutionMode::Standalone);

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->membre($voisine, 'coordinateur'), $seance, ['titre' => 'Pirate']), 404, null);
    }

    public function test_une_classe_d_une_autre_ecole_est_refusee(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $ailleurs = $this->classe($this->ecole(InstitutionMode::Standalone), '6e Z');

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->auteur, $seance, ['classe_id' => $ailleurs->id]), 404, 'class_out_of_scope');
    }

    public function test_une_classe_hors_du_programme_de_la_matiere_est_refusee(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $sansMaths = $this->classe($this->ecole, '5e C');

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->auteur, $seance, ['classe_id' => $sansMaths->id]), 422, 'subject_not_in_class_program');
    }

    public function test_une_seance_passee_est_verrouillee(): void
    {
        $seance = $this->ecoleAutonomeEtSeance(['date_seance' => Carbon::now()->subDays(3)]);

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->auteur, $seance, ['titre' => 'Trop tard']), 409, 'seance_past');
    }

    public function test_une_seance_dont_la_visio_est_en_cours_est_verrouillee(): void
    {
        $seance = $this->ecoleAutonomeEtSeance([
            'date_seance' => Carbon::now()->subMinutes(10),
            'visio_enabled' => true,
            'visio_active' => true,
            'visio_status' => 'active',
            'visio_started_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->auteur, $seance, ['titre' => 'Pendant']), 409, 'seance_visio_active');
    }

    public function test_une_date_sans_decalage_est_refusee(): void
    {
        $seance = $this->ecoleAutonomeEtSeance();
        $avant = $this->ligne($seance);

        $this->modifier($this->auteur, $seance, ['date_seance' => '2026-12-01 10:00:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_seance');

        self::assertSame($avant, $this->ligne($seance));
    }

    // ───────────────────────── école KLASSCI

    public function test_ecole_klassci_une_seance_klassci_est_refusee_avec_son_motif(): void
    {
        [$ecole, $enseignant] = $this->ecoleKlassci();
        $seance = Seance::factory()->forInstitution($ecole)->create([
            'klassci_enseignant_id' => $enseignant->klassci_id,
            'date_seance' => Carbon::now()->addDays(4),
        ]);

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($enseignant, $seance, ['titre' => 'Dans le LMS']), 403, 'seance_managed_by_klassci');
        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($this->membre($ecole, 'coordinateur'), $seance, ['titre' => 'Dans le LMS']), 403, 'seance_managed_by_klassci');
    }

    public function test_ecole_klassci_une_seance_creee_dans_le_lms_est_refusee_aussi(): void
    {
        [$ecole, $enseignant] = $this->ecoleKlassci();
        $seance = $this->seanceLocale($ecole, $enseignant, null, null, Carbon::now()->addDays(4));

        $this->assertRefus($seance, fn (): TestResponse => $this->modifier($enseignant, $seance, ['titre' => 'Dans le LMS']), 403, 'seance_managed_by_klassci');
    }

    public function test_ecole_klassci_un_identifiant_inconnu_est_introuvable(): void
    {
        [, $enseignant] = $this->ecoleKlassci();

        $this->asTenant($enseignant)->putJson('/api/lms/seances/987654', ['titre' => 'Rien'])->assertNotFound();
    }

    // ───────────────────────── le marqueur des cartes

    public function test_ecole_autonome_chaque_carte_dit_si_le_lecteur_peut_la_modifier(): void
    {
        $future = $this->ecoleAutonomeEtSeance();
        $passee = $this->seanceLocale($this->ecole, $this->auteur, $this->sixiemeA, $this->maths, Carbon::now()->subDays(3));
        $enCours = $this->seanceLocale($this->ecole, $this->auteur, $this->sixiemeA, $this->maths, Carbon::now()->subMinutes(10), [
            'visio_enabled' => true, 'visio_active' => true, 'visio_status' => 'active', 'visio_started_at' => Carbon::now()->subMinutes(5),
        ]);

        $miennes = $this->parId($this->asTenant($this->auteur)->getJson('/api/lms/seances/my-teaching')->assertOk()->json('data'));
        self::assertTrue($miennes[$future->id]['can_be_edited']);
        self::assertFalse($miennes[$passee->id]['can_be_edited']);
        self::assertFalse($miennes[$enCours->id]['can_be_edited']);

        $gestion = $this->parId($this->asTenant($this->membre($this->ecole, 'coordinateur'))->getJson('/api/lms/seances/upcoming?days=30')->assertOk()->json('data'));
        self::assertTrue($gestion[$future->id]['can_be_edited']);

        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->sixiemeA, 'actif');
        $siennes = $this->parId($this->asTenant($eleve)->getJson('/api/lms/seances/my-classes')->assertOk()->json('data'));
        self::assertFalse($siennes[$future->id]['can_be_edited']);
    }

    public function test_ecole_klassci_aucune_carte_n_est_modifiable(): void
    {
        [$ecole, $enseignant] = $this->ecoleKlassci();
        $locale = $this->seanceLocale($ecole, $enseignant, null, null, Carbon::now()->addDays(3));
        $miroir = Seance::factory()->forInstitution($ecole)->create(['date_seance' => Carbon::now()->addDays(3)]);

        // La source KLASSCI exige le jeton personnel, même pour lire les lignes locales.
        $coordinateur = User::factory()->for($ecole)->create(['role' => 'coordinateur', 'klassci_token' => 'jeton-klassci', 'last_klassci_sync' => now()]);
        $gestion = $this->asTenant($coordinateur)->getJson('/api/lms/seances/upcoming?days=30')->assertOk()->json('data');
        self::assertNotEmpty($gestion);
        foreach ($gestion as $carte) {
            self::assertFalse($carte['can_be_edited'], 'Carte de gestion '.json_encode($carte['id']));
        }

        $this->mock(KlassciProxyService::class, function (MockInterface $proxy): void {
            $proxy->shouldReceive('requestWithUserToken')
                ->with('jeton-klassci', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => 11, 'nom' => 'Anglais']]]]);
            $proxy->shouldReceive('getEmploiTemps')->andReturn(['data' => [$this->entreeEmploiDuTemps()]]);
            $proxy->shouldReceive('fetchManyClassesDetails')->andReturn([101 => ['data' => ['classe' => ['places_occupees' => 30]]]]);
        });

        $siennes = $this->asTenant($enseignant)->getJson('/api/lms/seances/my-teaching')->assertOk()->json('data');
        self::assertNotEmpty($siennes);
        foreach ($siennes as $carte) {
            self::assertFalse($carte['can_be_edited'], 'Carte enseignant '.json_encode($carte['id']));
        }

        unset($locale, $miroir);
    }

    // ───────────────────────── harnais

    /**
     * École autonome : l'auteur, deux classes où il enseigne les maths, et sa séance
     * de maths en 6e A, dans quatre jours.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function ecoleAutonomeEtSeance(array $attributs = []): Seance
    {
        $this->ecole = $this->ecole(InstitutionMode::Standalone);
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->maths = $this->matiere($this->ecole, 'Maths');
        $this->sixiemeA = $this->classe($this->ecole, '6e A');
        $this->sixiemeB = $this->classe($this->ecole, '6e B');
        $this->programme($this->sixiemeA, $this->maths, $this->auteur);
        $this->programme($this->sixiemeB, $this->maths, $this->auteur);

        $date = $attributs['date_seance'] ?? Carbon::now()->addDays(4)->setTime(8, 0);
        unset($attributs['date_seance']);

        return $this->seanceLocale($this->ecole, $this->auteur, $this->sixiemeA, $this->maths, $date, $attributs);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceLocale(Institution $ecole, User $auteur, ?Classe $classe, ?Matiere $matiere, mixed $date, array $attributs = []): Seance
    {
        return Seance::factory()->forInstitution($ecole)->create([
            'titre' => 'Les fractions',
            'date_seance' => $date,
            'created_by' => $auteur->id,
            'classe_id' => $classe?->id,
            'classe_nom' => $classe?->libelle,
            'matiere_id' => $matiere?->id,
            'matiere_nom' => $matiere?->libelle,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            ...$attributs,
        ]);
    }

    /** @return array{0: Institution, 1: User} */
    private function ecoleKlassci(): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = User::factory()->for($ecole)->create([
            'role' => 'enseignant',
            'klassci_id' => 5550,
            'klassci_enseignant_id' => 5550,
            'klassci_token' => 'jeton-klassci',
            'last_klassci_sync' => now(),
        ]);

        return [$ecole, $enseignant];
    }

    /** @return array<string, mixed> */
    private function entreeEmploiDuTemps(): array
    {
        $jour = Carbon::now()->addDays(2);

        return [
            'id' => 7001,
            'classe' => ['id' => 101, 'nom' => 'B2 COM'],
            'matiere' => ['id' => 11, 'nom' => 'Anglais'],
            'salle' => ['id' => 7, 'nom' => 'Salle B12', 'capacite' => 40],
            'programmation' => [
                'date_seance' => $jour->toDateString(),
                'date_cours' => $jour->toDateString(),
                'heure_debut' => $jour->copy()->setTime(8, 0)->utc()->toIso8601ZuluString(),
                'heure_fin' => $jour->copy()->setTime(10, 0)->utc()->toIso8601ZuluString(),
                'duree_minutes' => 120,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $champs
     * @return TestResponse<Response>
     */
    private function modifier(User $compte, Seance $seance, array $champs): TestResponse
    {
        return $this->asTenant($compte)->putJson("/api/lms/seances/{$seance->id}", $champs);
    }

    /**
     * La ligne est photographiée AVANT la requête : un refus ne la change pas.
     *
     * @param  callable(): TestResponse<Response>  $requete
     */
    private function assertRefus(Seance $seance, callable $requete, int $statut, ?string $motif): void
    {
        $avant = $this->ligne($seance);
        $reponse = $requete();
        self::assertSame($statut, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame($motif, $reponse->json('reason'), 'motif');
        self::assertSame($avant, $this->ligne($seance), 'La ligne est inchangée.');
    }

    /** @return array<string, mixed> */
    private function ligne(Seance $seance): array
    {
        return (array) Seance::query()->withoutGlobalScopes()->toBase()->where('id', $seance->id)->first();
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function parId(mixed $cartes): array
    {
        self::assertIsArray($cartes);

        return array_column($cartes, null, 'id');
    }
}
