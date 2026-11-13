<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Lot S-matière — une séance locale connaît sa matière par son id, et plus
 * seulement par un nom libre. La matière vient de l'établissement de l'auteur ;
 * avec une classe, elle doit être au programme de cette classe (la règle du
 * couple, partagée avec la programmation d'une évaluation). Son libellé fait foi.
 */
final class SeanceMatiereLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const CREATION = '/api/lms/seances';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Carbon::setTestNow('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function modes(): iterable
    {
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
    }

    /**
     * La séance locale porte l'id de sa matière, et son libellé : le nom libre
     * envoyé est ignoré quand un id est donné. C'est la même règle dans les
     * deux types d'école, une séance locale l'étant dans les deux.
     */
    #[DataProvider('modes')]
    public function test_une_seance_creee_avec_sa_matiere_porte_son_id_et_son_libelle(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programmeSelon($mode, $classe, $maths, $enseignant);

        $this->asTenant($enseignant)
            ->postJson(self::CREATION, $this->corps(['classe_id' => $classe->id, 'matiere_id' => $maths->id, 'matiere_nom' => 'Nom libre']))
            ->assertCreated()
            ->assertJsonPath('data.matiere_id', $maths->id)
            ->assertJsonPath('data.matiere_nom', 'Maths');

        // L'id LOCAL vit sous `matiere_id`, jamais sous un nom `klassci_*` (D132).
        $this->assertDatabaseHas('seances', ['matiere_id' => $maths->id, 'klassci_matiere_id' => null]);
    }

    public function test_la_liste_sert_l_id_et_le_libelle_de_la_matiere(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $enseignant);
        $this->asTenant($enseignant)
            ->postJson(self::CREATION, $this->corps(['classe_id' => $classe->id, 'matiere_id' => $maths->id]))
            ->assertCreated();
        $maths->forceFill(['libelle' => 'Mathématiques'])->save();

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson('/api/lms/seances/upcoming')
            ->assertOk()
            ->assertJsonPath('data.0.matiere', ['id' => $maths->id, 'libelle' => 'Mathématiques', 'nom' => 'Mathématiques', 'code' => null]);
    }

    public function test_une_matiere_sans_classe_est_acceptee(): void
    {
        $ecole = $this->ecole();
        $maths = $this->matiere($ecole, 'Maths');

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->postJson(self::CREATION, $this->corps(['matiere_id' => $maths->id]))
            ->assertCreated()
            ->assertJsonPath('data.matiere_id', $maths->id)
            ->assertJsonPath('data.classe_id', null);
    }

    public function test_sans_matiere_rien_ne_change(): void
    {
        $ecole = $this->ecole();

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->postJson(self::CREATION, $this->corps(['matiere_nom' => 'Nom libre']))
            ->assertCreated()
            ->assertJsonPath('data.matiere_id', null)
            ->assertJsonPath('data.matiere_nom', 'Nom libre');
    }

    /** Même refus que pour une classe d'une autre école : la réponse ne dit pas quels ids existent ailleurs. */
    public function test_la_matiere_d_une_autre_ecole_est_introuvable(): void
    {
        $ecole = $this->ecole();
        $ailleurs = $this->matiere($this->ecole(), 'Maths');

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->postJson(self::CREATION, $this->corps(['matiere_id' => $ailleurs->id]))
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Matière introuvable dans cet établissement.', 'reason' => 'subject_out_of_scope']);
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_une_matiere_hors_du_programme_de_la_classe_est_refusee(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $svt = $this->matiere($ecole, 'SVT');

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->postJson(self::CREATION, $this->corps(['classe_id' => $classe->id, 'matiere_id' => $svt->id]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'subject_not_in_class_program');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /**
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     */
    private function corps(array $champs): array
    {
        return ['titre' => 'Cours de mardi', 'date_seance' => '2026-11-12T09:00:00+00:00'] + $champs;
    }

    /**
     * Le programme de la classe, écrit par son vrai auteur dans chaque type
     * d'école : le service de gestion dans l'école créée depuis le LMS ; la
     * synchronisation dans l'école créée depuis KLASSCI, où le service local
     * refuse de composer un programme qui vient de KLASSCI. La ligne est celle
     * qu'écrit `ClasseMatieresSynchronizer::link()`.
     */
    private function programmeSelon(InstitutionMode $mode, Classe $classe, Matiere $matiere, User $enseignant): void
    {
        if ($mode === InstitutionMode::Standalone) {
            $this->programme($classe, $matiere, $enseignant);

            return;
        }

        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $classe->institution_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
