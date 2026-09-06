<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciEmploiTempsSeances;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Les règles de la source `emploi-temps`, vérifiées SUR LA CLASSE QUI LES PORTE.
 *
 * ## Pourquoi ce fichier existe en plus du test de fonctionnalité
 *
 * Le test bout en bout `TeachingSeancesEmploiTempsSourceTest` ne peut pas
 * démontrer le tri par matière : le collecteur boucle ensuite sur les matières
 * du tableau de bord et écarte une seconde fois ce qui n'y figure pas. Retirer
 * le filtre d'ici laissait donc la suite VERTE — vérifié par sabotage.
 *
 * La règle est testée ici, à l'unité, là où elle vit réellement.
 */
#[CoversClass(KlassciEmploiTempsSeances::class)]
final class KlassciEmploiTempsSeancesTest extends TestCase
{
    private const TOKEN = 'jeton-porteur';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LA règle : KLASSCI accepte `matiere_id` et l'IGNORE (mesuré le 2026-09-05,
     * `?matiere_id=3` renvoyait de l'Algorithme et du Marketing digital). Sans
     * tri local, une séance étrangère s'afficherait sous la mauvaise matière.
     */
    public function test_a_seance_of_an_unrequested_matiere_is_dropped(): void
    {
        $groupees = $this->source([
            $this->entree(seanceId: 1, matiereId: 11),
            $this->entree(seanceId: 2, matiereId: 99),
        ])->fetchByMatiere(self::TOKEN, [11], '2026-01-01', '2026-12-31');

        self::assertSame([11], array_keys($groupees));
        self::assertCount(1, $groupees[11]);
        self::assertSame(1, $groupees[11][0]['id']);
    }

    /**
     * Une séance sans matière exploitable ne peut être rattachée à rien : elle
     * ne doit surtout pas atterrir dans un groupe arbitraire.
     */
    public function test_a_seance_without_a_usable_matiere_is_dropped(): void
    {
        $groupees = $this->source([
            ['id' => 1, 'programmation' => ['date_seance' => '2026-06-26']],
            $this->entree(seanceId: 2, matiereId: 11),
        ])->fetchByMatiere(self::TOKEN, [11], '2026-01-01', '2026-12-31');

        self::assertCount(1, $groupees[11]);
        self::assertSame(2, $groupees[11][0]['id']);
    }

    /**
     * Sans matière, il n'y a rien à demander : on n'ouvre même pas la connexion.
     * C'est aussi ce qui garantit qu'on ne retombe jamais sur le catalogue.
     */
    public function test_no_matiere_means_no_call_at_all(): void
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $proxy->shouldNotReceive('getEmploiTemps');

        $source = new KlassciEmploiTempsSeances($proxy);

        self::assertSame([], $source->fetchByMatiere(self::TOKEN, [], '2026-01-01', '2026-12-31'));
    }

    /**
     * La fenêtre est transmise telle quelle : interrogé sans bornes,
     * `emploi-temps` ne rend que la semaine courante.
     */
    public function test_the_window_is_forwarded_verbatim(): void
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $proxy->shouldReceive('getEmploiTemps')
            ->once()
            ->with(self::TOKEN, ['date_debut' => '2026-03-01', 'date_fin' => '2026-09-30'])
            ->andReturn(['data' => []]);

        $groupees = (new KlassciEmploiTempsSeances($proxy))
            ->fetchByMatiere(self::TOKEN, [11], '2026-03-01', '2026-09-30');

        self::assertSame([], $groupees, 'Une fenêtre sans séance ne doit rien inventer.');
    }

    /**
     * L'adaptation complète l'entrée sans jamais la reconstruire : une clé que
     * KLASSCI ajouterait demain doit survivre au passage.
     */
    public function test_adapting_completes_the_entry_without_shrinking_it(): void
    {
        $adaptee = KlassciEmploiTempsSeances::adapt([
            'id' => 7,
            'titre' => 'Cours du matin',
            'salle' => ['id' => 3, 'nom' => 'Salle B12'],
            'programmation' => ['date_seance' => '2026-06-26', 'duree_minutes' => 120],
        ]);

        self::assertSame('2026-06-26', $adaptee['programmation']['date']);
        self::assertSame('Salle B12', $adaptee['programmation']['salle']);
        self::assertSame('Cours du matin', $adaptee['titre'], 'Une clé inconnue ne doit pas être perdue.');
        self::assertSame(120, $adaptee['programmation']['duree_minutes']);
    }

    /**
     * `date_cours` est le repli observé quand `date_seance` manque.
     */
    public function test_date_cours_is_the_fallback_when_date_seance_is_missing(): void
    {
        $adaptee = KlassciEmploiTempsSeances::adapt([
            'programmation' => ['date_cours' => '2026-07-02'],
        ]);

        self::assertSame('2026-07-02', $adaptee['programmation']['date']);
    }

    /**
     * KLASSCI a déjà changé d'avis une fois sur la forme de `salle` : les deux
     * sont acceptées, et une salle déjà placée dans `programmation` fait foi.
     */
    public function test_both_shapes_of_salle_are_accepted(): void
    {
        self::assertSame('Amphi', KlassciEmploiTempsSeances::adapt(['salle' => 'Amphi'])['programmation']['salle']);
        self::assertNull(KlassciEmploiTempsSeances::adapt([])['programmation']['salle']);
        self::assertSame('Deja posee', KlassciEmploiTempsSeances::adapt([
            'salle' => ['nom' => 'Racine'],
            'programmation' => ['salle' => 'Deja posee'],
        ])['programmation']['salle']);
    }

    /**
     * Une panne de KLASSCI ne doit PAS se traduire par « aucune séance » : la
     * liste vide en HTTP 200 ferait mentir le calendrier. L'erreur remonte.
     */
    public function test_a_klassci_failure_is_never_swallowed_into_an_empty_list(): void
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $proxy->shouldReceive('getEmploiTemps')->andThrow(new RuntimeException('KLASSCI injoignable'));

        $this->expectException(RuntimeException::class);

        (new KlassciEmploiTempsSeances($proxy))
            ->fetchByMatiere(self::TOKEN, [11], '2026-01-01', '2026-12-31');
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @param  list<array<string, mixed>>  $entrees
     */
    private function source(array $entrees): KlassciEmploiTempsSeances
    {
        $proxy = Mockery::mock(KlassciProxyService::class, function (MockInterface $mock) use ($entrees): void {
            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => $entrees]);
        });

        return new KlassciEmploiTempsSeances($proxy);
    }

    /**
     * Forme RÉELLE d'une entrée `emploi-temps`, relevée le 2026-09-05.
     *
     * @return array<string, mixed>
     */
    private function entree(int $seanceId, int $matiereId): array
    {
        return [
            'id' => $seanceId,
            'matiere' => ['id' => $matiereId, 'nom' => 'Matiere '.$matiereId],
            'classe' => ['id' => 101, 'nom' => 'B2 COM'],
            'salle' => ['id' => 7, 'nom' => 'Salle B12'],
            'programmation' => [
                'date_seance' => '2026-06-26',
                'heure_debut' => '2026-06-26T08:00:00.000000Z',
                'heure_fin' => '2026-06-26T10:00:00.000000Z',
            ],
        ];
    }
}
