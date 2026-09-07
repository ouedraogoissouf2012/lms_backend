<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Matiere;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereClassesExtractor;
use App\Services\Matiere\MatiereSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Les séances d'une matière viennent de l'emploi du temps (#740) — et sans
 * elles, un enseignant ne peut pas créer de leçon.
 *
 * ## La chaîne, mesurée de bout en bout le 2026-09-06
 *
 * 1. `MatiereSeancesFetcher` lisait `matieres/{id}.seances_programmees`, une clé
 *    que KLASSCI laisse **toujours vide**.
 * 2. `MatiereClassesExtractor::fromSeances()` dérive les classes d'une matière
 *    **à partir de ses séances** : zéro séance → zéro classe.
 * 3. Le frontend construit alors `classe_id: classes?.[0]?.id || null`.
 * 4. `StoreLessonRequest::authorize()` exige qu'une classe existe localement.
 *    Laravel exécute `authorize()` AVANT la validation, si bien qu'un champ
 *    manquant ne rend pas « champ requis » mais **403 This action is
 *    unauthorized** — un message qui désigne une autorisation là où le problème
 *    est une donnée absente.
 *
 * Résultat observé : « Erreur lors de la création : This action is
 * unauthorized » sur toute tentative de création de leçon.
 *
 * Ce fichier verrouille le maillon 1, celui qui casse toute la chaîne.
 */
#[CoversClass(MatiereSeancesFetcher::class)]
final class MatiereSeancesFromEmploiTempsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-enseignant';

    private const MATIERE = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE défaut : la matière n'a aucune séance parce qu'on lisait la clé morte.
     */
    public function test_a_teacher_matiere_gets_its_seances_from_the_timetable(): void
    {
        $payload = $this->fetchFor($this->teacher());

        self::assertCount(1, $payload['seances'], 'Les séances doivent venir de l\'emploi du temps.');
        self::assertSame(5001, $payload['seances'][0]['id']);
    }

    /**
     * LE maillon qui débloque la création de leçon : sans classe dérivée, le
     * frontend envoie `classe_id: null` et l'autorisation refuse.
     */
    public function test_the_classes_of_the_matiere_can_be_derived_again(): void
    {
        $payload = $this->fetchFor($this->teacher());

        $classes = MatiereClassesExtractor::fromSeances($payload['seances_enrichies']);

        self::assertNotSame([], $classes, 'Sans classe dérivée, la création de leçon rend 403.');
        self::assertSame(101, $classes[0]['id']);
        self::assertSame('B2 COM', $classes[0]['nom']);
    }

    /**
     * KLASSCI ignore le filtre `matiere_id` : une séance d'une autre matière ne
     * doit jamais apparaître ici, sans quoi la leçon serait rattachée à la
     * mauvaise classe.
     */
    public function test_a_seance_of_another_matiere_is_excluded(): void
    {
        $payload = $this->fetchFor($this->teacher(), [
            $this->entree(6001, matiereId: 99, classeId: 777),
        ]);

        self::assertSame([], $payload['seances']);
    }

    /**
     * Le chemin étudiant partage la même source : sa matière doit aussi porter
     * ses séances.
     */
    public function test_the_student_path_uses_the_same_source(): void
    {
        $payload = $this->fetchFor($this->student(), null, 'me/dashboard');

        self::assertCount(1, $payload['seances']);
    }

    // ───────────────────── Fixtures ─────────────────────

    private function teacher(): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'enseignant',
            'klassci_token' => self::TOKEN,
        ]);
    }

    private function student(): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'etudiant',
            'klassci_token' => self::TOKEN,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $entrees
     * @return array{seances: array<int, array<string, mixed>>, seances_enrichies: array<int, array<string, mixed>>}
     */
    private function fetchFor(User $user, ?array $entrees = null, string $dashboard = 'me/teacher-dashboard'): array
    {
        $entrees ??= [$this->entree(5001, matiereId: self::MATIERE, classeId: 101)];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($entrees, $dashboard): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, $dashboard, 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => self::MATIERE, 'nom' => 'Anglais']]]]);

            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => $entrees]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });

        return $this->app->make(MatiereSeancesFetcher::class)
            ->fetchSeancesForUser($user, self::MATIERE, self::TOKEN, ['id' => self::MATIERE, 'nom' => 'Anglais']);
    }

    /**
     * Forme RÉELLE d'une entrée `emploi-temps`, relevée le 2026-09-05.
     *
     * @return array<string, mixed>
     */
    private function entree(int $seanceId, int $matiereId, int $classeId): array
    {
        return [
            'id' => $seanceId,
            'matiere' => ['id' => $matiereId, 'nom' => 'Anglais'],
            'classe' => ['id' => $classeId, 'nom' => 'B2 COM'],
            'salle' => ['id' => 7, 'nom' => 'Salle B12'],
            'programmation' => [
                'date_seance' => '2026-06-26',
                'heure_debut' => '2026-06-26T08:00:00.000000Z',
                'heure_fin' => '2026-06-26T10:00:00.000000Z',
            ],
        ];
    }
}
