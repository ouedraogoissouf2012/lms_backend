<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #962 — `matiere.name` de « Mes cours » porte le libellé de la matière, dans
 * la liste des cours ET dans le filtre des matières.
 *
 * `MyCoursesPresenter` lisait `name ?? libelle ?? 'Matière'`. La table
 * `matieres` n'a pas de colonne `name`, seulement `libelle` (NOT NULL) : le
 * premier terme valait toujours null, la constante ne pouvait pas sortir.
 *
 * Ce test ne peut PAS être vu rouge sur le code d'origine : l'ancien repli
 * rendait déjà `libelle`, c'est précisément pourquoi il était mort. Il fige le
 * contrat ; sa preuve vient de la falsification (constante à la place de
 * `libelle`, ou repli `name ?? 'Matière'` sans `libelle` : rouge).
 *
 * Les deux volets sont couverts, sans lire le mode : une matière miroir KLASSCI
 * (`klassci_id` posé) et une matière locale d'une école autonome (`klassci_id`
 * null, #848). `libelle` est la colonne des deux.
 *
 * @see app/Services/Lesson/MyCoursesPresenter.php
 */
final class MyCoursesMatiereLibelleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'matiere miroir KLASSCI' => ['klassci'];
        yield 'matiere locale d une ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_matiere_name_porte_le_libelle_de_la_matiere(string $volet): void
    {
        $this->disableKlassciMiddleware();

        $ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $classe = Classe::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 7001,
        ]);
        $matiere = Matiere::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 8001,
            'libelle' => 'Algorithmique',
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
                'classe_nom' => 'L1 Informatique',
                'institution_id' => $ecole->id,
                'synced_at' => now(),
            ]);
        }

        Lesson::factory()->published()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
        ]);

        $attendu = ['id' => $matiere->id, 'name' => 'Algorithmique'];

        $this->asTenant($apprenant)
            ->getJson('/api/lessons/my-courses')
            ->assertOk()
            ->assertJsonPath('data.0.matiere', $attendu)
            ->assertJsonPath('filters.matieres', [$attendu]);
    }
}
