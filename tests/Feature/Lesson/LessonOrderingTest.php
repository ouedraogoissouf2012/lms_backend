<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #937 — l'ordre des leçons est TOTAL : aucune page ne double ni ne perd une leçon.
 *
 * ## Le défaut
 *
 * `Lesson::scopeOrdered()` triait sur `order` puis `created_at`. `order` vaut 0
 * à la création (seule une mise à jour l'écrit), et `created_at` n'a que la
 * seconde : des égalités, il y en a. Le manuel MySQL 8.0 (§10.2.1.19, « LIMIT
 * Query Optimization ») est explicite : des lignes égales sur l'ORDER BY
 * peuvent sortir dans n'importe quel ordre, et seule une colonne unique rend
 * l'ordre déterministe. Avec `LIMIT … OFFSET`, une leçon peut alors figurer
 * sur deux pages, ou sur aucune.
 *
 * ## Ce que chaque test peut prouver, et où
 *
 * - Le test du tri porte sur la REQUÊTE construite, indépendante du moteur :
 *   c'est lui qui rougit sans le correctif, ici comme en CI.
 * - Le test des pages est vert sous SQLite même sans le correctif : le
 *   `rowid` y départage les égalités par hasard d'implémentation. Il garde le
 *   contrat de bout en bout, et c'est la jambe MySQL de la CI qui l'éprouve
 *   vraiment.
 *
 * Les deux volets sont couverts : école klassci (inscription par le cache
 * `user_classes`) et école standalone (pivot local), sans lire le mode.
 *
 * @see app/Models/Lesson.php scopeOrdered()
 */
final class LessonOrderingTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const LECONS = 7;

    private const PAR_PAGE = 3;

    public function test_le_tri_se_termine_par_l_identifiant(): void
    {
        $tri = array_map(
            static fn (array $critere): string => $critere['column'].' '.$critere['direction'],
            Lesson::query()->ordered()->getQuery()->orders ?? [],
        );

        self::assertSame(['order asc', 'created_at asc', 'id asc'], $tri);
    }

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci (cache user_classes)' => ['klassci'];
        yield 'ecole standalone (pivot local)' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_les_pages_couvrent_chaque_lecon_exactement_une_fois(string $volet): void
    {
        $this->disableKlassciMiddleware();
        [$apprenant, $attendus] = $this->apprenantEtLeconsAEgalite($volet);

        $vus = [];
        $dernierePage = (int) ceil(self::LECONS / self::PAR_PAGE);
        for ($page = 1; $page <= $dernierePage; $page++) {
            $reponse = $this->asTenant($apprenant)
                ->getJson('/api/lessons/my-courses?per_page='.self::PAR_PAGE.'&page='.$page)
                ->assertOk()
                ->assertJsonPath('meta.last_page', $dernierePage);

            foreach ($reponse->json('data') as $cours) {
                $vus[] = $cours['id'];
            }
        }

        // Exactement une fois chacune, dans l'ordre des identifiants : toutes
        // les leçons ont le même `order` et le même `created_at`.
        self::assertSame($attendus, $vus);
    }

    /**
     * Sept leçons publiées, TOUTES à égalité sur `order` (0, la valeur de
     * production) et sur `created_at` (même seconde).
     *
     * @return array{0: User, 1: list<int>}
     */
    private function apprenantEtLeconsAEgalite(string $volet): array
    {
        $ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $classe = Classe::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 7001,
        ]);
        $apprenant = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'etudiant',
            'klassci_id' => $volet === 'standalone' ? null : 910001,
        ]);

        if ($volet === 'standalone') {
            $classe->etudiants()->attach($apprenant->id, ['statut' => 'actif']);
        } else {
            UserClass::create([
                'user_id' => $apprenant->id,
                'klassci_classe_id' => 7001,
                'classe_nom' => $classe->libelle,
                'institution_id' => $ecole->id,
                'synced_at' => now(),
            ]);
        }

        Carbon::setTestNow('2026-10-20 08:00:00');
        /** @var list<int> $ids */
        $ids = Lesson::factory()->published()->count(self::LECONS)->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
            'order' => 0,
        ])->pluck('id')->sort()->values()->all();
        Carbon::setTestNow();

        return [$apprenant, $ids];
    }
}
