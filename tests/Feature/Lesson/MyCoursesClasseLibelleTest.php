<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #939 — `classe.name` de « Mes cours » porte le libellé de la classe.
 *
 * `MyCoursesPresenter` lisait `name ?? nom ?? 'Classe'`. La table `classes`
 * n'a ni `name` ni `nom`, seulement `libelle` (NOT NULL) : la constante
 * sortait toujours. Mesuré sur `6a62abc0` : une classe « L1 Informatique »
 * était rendue `{"id":1,"name":"Classe"}`.
 *
 * Les deux volets sont couverts, sans lire le mode : une classe miroir KLASSCI
 * (inscription par le cache `user_classes`) et une classe locale d'une école
 * autonome (pivot). `libelle` est la colonne des deux.
 *
 * @see app/Services/Lesson/MyCoursesPresenter.php
 */
final class MyCoursesClasseLibelleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'classe miroir KLASSCI (cache user_classes)' => ['klassci'];
        yield 'classe locale d une ecole autonome (pivot)' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_classe_name_porte_le_libelle_de_la_classe(string $volet): void
    {
        $this->disableKlassciMiddleware();

        $ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $classe = Classe::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 7001,
            'libelle' => 'L1 Informatique',
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

        Lesson::factory()->published()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
        ]);

        $this->asTenant($apprenant)
            ->getJson('/api/lessons/my-courses')
            ->assertOk()
            ->assertJsonPath('data.0.classe', [
                'id' => $classe->id,
                'name' => 'L1 Informatique',
            ]);
    }
}
