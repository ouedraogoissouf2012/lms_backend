<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Classe;
use App\Models\Matiere;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

/**
 * Une matière d'école KLASSCI dont la page propose des classes (#1103).
 *
 * Dans une école KLASSCI, les classes d'une matière sont celles de sa page
 * (`classes_concernees`) : séances, miroir `classe_matiere`, KLASSCI en dernier
 * recours. Ici, KLASSCI (simulé) connaît la matière sans séance, et le miroir
 * porte les classes : la page les propose, et la création d'une leçon les
 * accepte.
 */
trait SimuleLaMatiereKlassci
{
    protected function matiereKlassciProposant(Matiere $matiere, Classe ...$classes): void
    {
        $this->miroirLieALaMatiere($matiere, ...$classes);
        $this->klassciConnaitLaMatiere((int) $matiere->klassci_id);
    }

    /**
     * Le miroir `classe_matiere` d'une école KLASSCI, tel que la synchronisation
     * l'écrit : le programme n'y est pas composé à la main
     * (`LocalClasseMatiereLinker` le refuse, et c'est sa règle).
     */
    protected function miroirLieALaMatiere(Matiere $matiere, Classe ...$classes): void
    {
        foreach ($classes as $classe) {
            DB::table('classe_matiere')->insert([
                'classe_id' => $classe->id,
                'matiere_id' => $matiere->id,
                'institution_id' => $matiere->institution_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** KLASSCI répond pour cette matière, sans séance ; à tout autre appel, une liste vide. */
    protected function klassciConnaitLaMatiere(int $klassciMatiereId): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($klassciMatiereId): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(
                static fn (string $jeton, string $endpoint): array => $endpoint === "matieres/{$klassciMatiereId}"
                    ? ['data' => ['matiere' => ['id' => $klassciMatiereId, 'nom' => 'Matière'], 'combinaisons' => [], 'enseignants' => []]]
                    : ['data' => []],
            );
            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => []]);
        });
    }
}
