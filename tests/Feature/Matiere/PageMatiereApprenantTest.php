<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Sur la page d'une matière (`GET /lms/matieres/{id}`), un apprenant ne lit que
 * les leçons PUBLIÉES de SES classes : la règle de « Mes cours »
 * (`LessonReadScope::limitToAudience`), dans les deux types d'école.
 *
 * - École créée depuis KLASSCI : ses classes viennent du cache `user_classes`
 *   (#482). Sans inscription, il ne lit aucune leçon.
 * - École créée depuis le LMS : ses classes viennent de ses inscriptions
 *   actives.
 *
 * Vraie route, vrai jeton porteur.
 */
final class PageMatiereApprenantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const CLASSE_A = 7101;

    private const CLASSE_B = 7102;

    private Institution $ecole;

    private Matiere $matiereKlassci;

    private Classe $classeA;

    private Classe $classeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_ecole_klassci_l_apprenant_ne_lit_que_les_lecons_publiees_de_sa_classe(): void
    {
        $this->ecoleKlassci();
        $eleve = $this->eleveKlassci();
        $this->inscritParLeCache($eleve, self::CLASSE_A);
        [$sienne] = $this->troisLecons($this->matiereKlassci);

        $page = $this->asTenant($eleve)->getJson('/api/lms/matieres/'.$this->matiereKlassci->klassci_id)->assertOk();

        self::assertSame([$sienne->id], $this->ids($page->json('data.lessons')));
    }

    public function test_ecole_klassci_un_apprenant_sans_classe_ne_lit_aucune_lecon(): void
    {
        $this->ecoleKlassci();
        $eleve = $this->eleveKlassci();
        $this->troisLecons($this->matiereKlassci);

        $page = $this->asTenant($eleve)->getJson('/api/lms/matieres/'.$this->matiereKlassci->klassci_id)->assertOk();

        self::assertSame([], $this->ids($page->json('data.lessons')));
    }

    public function test_ecole_klassci_la_gestion_lit_toujours_toutes_les_lecons(): void
    {
        $this->ecoleKlassci();
        $lecons = $this->troisLecons($this->matiereKlassci);
        $coordinateur = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'coordinateur', 'klassci_token' => 'jeton']);

        $page = $this->asTenant($coordinateur)->getJson('/api/lms/matieres/'.$this->matiereKlassci->klassci_id)->assertOk();

        self::assertSame($this->ids(array_map(static fn (Lesson $l): array => ['id' => $l->id], $lecons)), $this->ids($page->json('data.lessons')));
    }

    public function test_ecole_lms_l_apprenant_ne_lit_que_les_lecons_publiees_de_sa_classe(): void
    {
        $ecole = $this->ecole();
        $prof = $this->membre($ecole, 'enseignant');
        $eleve = $this->membre($ecole, 'etudiant');
        [$sixieme, $cinquieme] = [$this->classe($ecole, '6e A'), $this->classe($ecole, '5e B')];
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $prof);
        $this->programme($cinquieme, $maths, $prof);
        $this->inscrire($eleve, $sixieme, 'actif');
        $sienne = $this->lecon($sixieme, $maths, $prof, 'published');
        $this->lecon($cinquieme, $maths, $prof, 'published');
        $this->lecon($sixieme, $maths, $prof, 'draft');

        $page = $this->asTenant($eleve)->getJson('/api/lms/matieres/'.$maths->id)->assertOk();

        self::assertSame([$sienne->id], $this->ids($page->json('data.lessons')));
    }

    private function ecoleKlassci(): void
    {
        $this->ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $this->matiereKlassci = Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 4500 + $this->ecole->id]);
        $this->classeA = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::CLASSE_A]);
        $this->classeB = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::CLASSE_B]);
        $klassciId = (int) $this->matiereKlassci->klassci_id;

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($klassciId): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(
                static fn (string $jeton, string $chemin): array => $chemin === "matieres/{$klassciId}"
                    ? ['data' => ['matiere' => ['id' => $klassciId, 'nom' => 'Matière', 'code' => 'M1'], 'combinaisons' => [], 'enseignants' => [], 'evaluations' => []]]
                    : ['data' => []],
            );
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }

    private function eleveKlassci(): User
    {
        return User::factory()->student()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => 920101,
            'klassci_token' => 'jeton-eleve',
        ]);
    }

    private function inscritParLeCache(User $eleve, int $klassciClasseId): void
    {
        UserClass::create([
            'user_id' => $eleve->id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => 'Classe',
            'institution_id' => $this->ecole->id,
            'synced_at' => now(),
        ]);
    }

    /**
     * La publiée de la classe A, la publiée de la classe B, le brouillon de la
     * classe A — dans cet ordre.
     *
     * @return array{0: Lesson, 1: Lesson, 2: Lesson}
     */
    private function troisLecons(Matiere $matiere): array
    {
        $prof = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant']);
        $attributs = ['institution_id' => $this->ecole->id, 'matiere_id' => $matiere->id, 'enseignant_id' => $prof->id];

        return [
            Lesson::factory()->published()->create($attributs + ['classe_id' => $this->classeA->id]),
            Lesson::factory()->published()->create($attributs + ['classe_id' => $this->classeB->id]),
            Lesson::factory()->create($attributs + ['classe_id' => $this->classeA->id, 'status' => 'draft']),
        ];
    }

    /** @return list<int> */
    private function ids(mixed $lignes): array
    {
        $ids = array_map(static fn (mixed $ligne): int => (int) (is_array($ligne) ? ($ligne['id'] ?? 0) : 0), (array) $lignes);
        sort($ids);

        return $ids;
    }
}
