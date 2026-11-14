<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Le titre d'une séance, dans sa forme de calendrier (`SeanceCalendarProjection`) :
 * lu sur la LIGNE, dans les deux types d'école. Une séance sans titre (absent ou
 * vide) rend `null` : rien n'est inventé, pas même le nom de la matière.
 *
 * - École créée depuis le LMS : le titre que `LocalSeanceCreator` a écrit.
 * - École créée depuis KLASSCI, lue par la gestion (`ManagerSeancesLocalFetcher`) :
 *   le titre que la synchronisation a écrit sur le miroir.
 */
final class TitreDeLaSeanceTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const URL = '/api/lms/seances/upcoming';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    public function test_ecole_du_lms_la_seance_porte_son_titre_et_null_sans_titre(): void
    {
        $ecole = $this->ecole();
        $auteur = $this->membre($ecole, 'enseignant');
        $titree = $this->seanceLocale($ecole, $auteur, 'Révision du chapitre 3');
        $sansTitre = $this->seanceLocale($ecole, $auteur, '');

        $reponse = $this->asTenant($auteur)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, '/lms/seances/upcoming', 'get');
        self::assertSame(
            [$titree->id => 'Révision du chapitre 3', $sansTitre->id => null],
            self::titresParId($reponse->json('data')),
        );
    }

    public function test_ecole_klassci_la_gestion_lit_le_titre_du_miroir_et_null_sans_titre(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->seanceMiroir($ecole, 701, 'Cours magistral');
        $this->seanceMiroir($ecole, 702, null);

        $reponse = $this->asTenant($coordinateur)->getJson(self::URL)->assertOk();

        // La forme KLASSCI désigne la séance par son identifiant KLASSCI.
        self::assertSame(
            [701 => 'Cours magistral', 702 => null],
            self::titresParId($reponse->json('data')),
        );
    }

    /** Une séance locale, telle que `LocalSeanceCreator` l'écrit : un titre, ou '' sans titre. */
    private function seanceLocale(Institution $ecole, User $auteur, string $titre): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'titre' => $titre,
            'matiere_nom' => 'Mathématiques',
            'created_by' => $auteur->id,
            'date_seance' => now()->addDay()->setTime(9, 0),
            'is_active' => true,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    /** Une séance miroir de KLASSCI, telle que la synchronisation l'écrit. */
    private function seanceMiroir(Institution $ecole, int $klassciId, ?string $titre): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'titre' => $titre,
            'matiere_nom' => 'Mathématiques',
            'klassci_seance_id' => $klassciId,
            'date_seance' => now()->addDay()->setTime(9, 0),
            'is_active' => true,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function titresParId(mixed $seances): array
    {
        $titres = [];
        foreach ((array) $seances as $seance) {
            self::assertIsArray($seance);
            self::assertArrayHasKey('titre', $seance, 'la forme du calendrier porte la clé titre');
            $titres[(int) $seance['id']] = $seance['titre'];
        }
        ksort($titres);

        return $titres;
    }
}
