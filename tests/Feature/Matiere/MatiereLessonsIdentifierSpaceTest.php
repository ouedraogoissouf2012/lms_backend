<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereLessonsAndStatsBuilder;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * La liste des leçons d'une matière doit être lue dans l'espace LOCAL.
 *
 * ## Le défaut, mesuré dans le navigateur le 2026-09-09
 *
 * `MatiereDetailsQueryService` reçoit l'identifiant de la ROUTE, qui est un
 * `klassci_id` — c'est le contrat de `/lms/matieres/{id}`, et le reste du
 * service le traite bien comme tel ({@see MatiereClassesResolver::fromMirror()}
 * traduit d'abord). {@see MatiereLessonsAndStatsBuilder}, lui, le passait
 * directement à `Lesson::where('matiere_id', ...)` — une colonne LOCALE.
 *
 * Sur le poste de l'utilisateur, « Anglais » porte le `klassci_id` 3 et l'id
 * local 1 ; « Marketing digital » porte le `klassci_id` 1. Conséquence
 * observée, compte `prof.bede.test` :
 *
 *   /matieres/3  → « Anglais »           → « Aucune leçon disponible »
 *   /matieres/1  → « Marketing digital » → affiche la leçon d'ANGLAIS
 *
 * C'est le pendant, en LECTURE, du défaut d'écriture corrigé par
 * `matiere_id_local` ({@see MatiereLocalIdentityInResponseTest}). L'écriture
 * range désormais la leçon sur la bonne matière ; la lecture la cherchait
 * toujours sur la mauvaise. Une leçon correctement créée devient donc
 * invisible là où elle est, et visible là où elle n'est pas — sans aucune
 * erreur, comme la corruption d'origine.
 *
 * ## Pourquoi la collision est CONSTRUITE, jamais supposée
 *
 * Un test qui se contenterait de deux matières sans faire coïncider un id
 * local avec un `klassci_id` serait vert avant comme après le correctif : il
 * ne mesurerait rien. La matière piège est donc créée EN PREMIER pour capturer
 * son id local réel, et « Anglais » reçoit ce nombre comme `klassci_id`.
 *
 * Aucun id n'est écrit en dur : les codés en dur passent sous SQLite et
 * échouent sous MySQL dès le deuxième test du fichier (auto-increment non
 * réinitialisé). La CI fait tourner les deux moteurs.
 */
#[CoversClass(MatiereLessonsAndStatsBuilder::class)]
final class MatiereLessonsIdentifierSpaceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-test';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * Le cas réel : la matière visée est désignée par son `klassci_id`, et ce
     * nombre est aussi l'id LOCAL d'une autre matière.
     */
    public function test_the_lessons_of_the_targeted_matiere_are_returned(): void
    {
        ['visee' => $visee, 'klassciId' => $klassciId] = $this->deuxMatieresEnCollision();

        $attendue = $this->leconSur($visee, 'Leçon de la matière visée');

        $titres = $this->titresDesLeconsRendues($klassciId);

        self::assertContains(
            $attendue->title,
            $titres,
            'la leçon de la matière visée est absente : la liste est lue dans le mauvais espace',
        );
    }

    /**
     * Le versant destructeur du même défaut : la leçon d'une AUTRE matière ne
     * doit jamais apparaître ici. C'est ce que l'utilisateur voyait à l'écran.
     */
    public function test_a_lesson_of_the_colliding_matiere_never_leaks_in(): void
    {
        ['piege' => $piege, 'klassciId' => $klassciId] = $this->deuxMatieresEnCollision();

        $intruse = $this->leconSur($piege, 'Leçon d\'une autre matière');

        $titres = $this->titresDesLeconsRendues($klassciId);

        self::assertNotContains(
            $intruse->title,
            $titres,
            'la leçon d\'une autre matière fuit dans la liste — collision des deux espaces',
        );
    }

    /**
     * Matière non miroitée : aucune leçon locale ne peut lui appartenir. Rendre
     * une liste vide est le seul résultat honnête ; retomber sur l'espace local
     * recréerait la collision qu'on supprime.
     */
    public function test_an_unmirrored_matiere_yields_no_lesson(): void
    {
        $orpheline = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 9001,
            'libelle' => 'Matière non miroitée',
        ]);

        $this->leconSur($orpheline, 'Leçon rattachée ailleurs');

        // 9999 n'est le `klassci_id` d'aucune matière de cette institution.
        self::assertSame([], $this->titresDesLeconsRendues(9999));
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * Construit la collision réelle : la matière piège d'abord, pour connaître
     * son id LOCAL ; la matière visée ensuite, qui prend ce nombre comme
     * `klassci_id`. L'identifiant de route désigne alors deux matières
     * différentes selon l'espace dans lequel on le lit.
     *
     * @return array{piege: Matiere, visee: Matiere, klassciId: int}
     */
    private function deuxMatieresEnCollision(): array
    {
        $piege = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 9002,
            'libelle' => 'Matière occupant l\'id local',
        ]);

        $visee = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $piege->id,
            'libelle' => 'Matière visée par la route',
        ]);

        return ['piege' => $piege, 'visee' => $visee, 'klassciId' => $piege->id];
    }

    private function leconSur(Matiere $matiere, string $titre): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'title' => $titre,
        ]);
    }

    /**
     * @return list<string>
     */
    private function titresDesLeconsRendues(int $klassciMatiereId): array
    {
        $enseignant = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
            'klassci_token' => self::TOKEN,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($klassciMatiereId): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, 'matieres/'.$klassciMatiereId, 'GET')
                ->andReturn(['data' => [
                    'matiere' => ['id' => $klassciMatiereId, 'nom' => 'Matière visée par la route', 'code' => 'X1'],
                    'combinaisons' => [],
                    'enseignants' => [],
                    'evaluations' => [],
                ]]);
        });

        $reponse = app(MatiereDetailsQueryService::class)
            ->getDetailsForUser($klassciMatiereId, $enseignant);

        self::assertNotNull($reponse);

        return array_values(array_map(
            static fn (array $lecon): string => (string) ($lecon['title'] ?? ''),
            $reponse['lessons'],
        ));
    }
}
