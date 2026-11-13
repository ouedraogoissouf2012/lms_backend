<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\FiltreDeListe;
use App\Services\Evaluation\KlassciCatalogueDesEvaluations;
use App\Services\Evaluation\LocalCatalogueDesEvaluations;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * D72, E4b (C27) : la liste (`GET /evaluations`) et la fiche (`GET /evaluations/{id}`)
 * d'une école créée depuis le LMS, sans catalogue KLASSCI.
 *
 * Avant : les filtres `classe_id` et `matiere_id` portaient sur `klassci_classe_id`
 * et `klassci_matiere_id`, nuls dans cette école : filtrer ne rendait RIEN. Et la
 * liste comme la fiche appelaient `getClasses` et `getMatieres` chez KLASSCI pour
 * tout appelant.
 *
 * ARRÊT 2 validé : un port `CatalogueDesEvaluations`, résolu à chaque appel ;
 * `colonne()` ne rend qu'un nom pris dans une liste fermée.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class ListeEtFicheSansCatalogueKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    // ───────────────────────── école créée depuis le LMS

    public function test_ecole_du_lms_le_filtre_classe_porte_sur_la_classe_locale_sans_klassci(): void
    {
        [$enseignant, $sixiemeA, $sixiemeB, $maths] = $this->ecoleDuLms();
        $deLaA = $this->evaluation($sixiemeA, $maths, $enseignant);
        $this->evaluation($sixiemeB, $maths, $enseignant);
        $this->aucunAppelKlassci();

        $ids = $this->ids($this->asTenant($enseignant)->getJson('/api/evaluations?classe_id='.$sixiemeA->getKey())->assertOk()->json('data'));

        self::assertSame([$deLaA->getKey()], $ids);
    }

    public function test_ecole_du_lms_le_filtre_matiere_porte_sur_la_matiere_locale_sans_klassci(): void
    {
        [$enseignant, $sixiemeA, , $maths] = $this->ecoleDuLms();
        $physique = $this->matiere(Institution::query()->findOrFail($sixiemeA->institution_id), 'Physique');
        $deMaths = $this->evaluation($sixiemeA, $maths, $enseignant);
        $this->evaluation($sixiemeA, $physique, $enseignant);
        $this->aucunAppelKlassci();

        $ids = $this->ids($this->asTenant($enseignant)->getJson('/api/evaluations?matiere_id='.$maths->getKey())->assertOk()->json('data'));

        self::assertSame([$deMaths->getKey()], $ids);
    }

    public function test_ecole_du_lms_la_fiche_ne_demande_rien_a_klassci_ni_pour_l_enseignant_ni_pour_l_eleve(): void
    {
        [$enseignant, $sixiemeA, , $maths] = $this->ecoleDuLms();
        $eleve = $this->membre(Institution::query()->findOrFail($sixiemeA->institution_id), 'etudiant');
        $eleve->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->inscrire($eleve, $sixiemeA, 'actif');
        $evaluation = $this->evaluation($sixiemeA, $maths, $enseignant);
        $this->aucunAppelKlassci();

        foreach ([$enseignant, $eleve] as $qui) {
            $this->asTenant($qui)->getJson('/api/evaluations/'.$evaluation->getKey())->assertOk()
                ->assertJsonPath('data.id', $evaluation->getKey())
                ->assertJsonPath('data.classe.nom', '6e A')
                ->assertJsonPath('data.matiere.nom', 'Maths');
        }
    }

    // ───────────────────────── école créée depuis KLASSCI, inchangée

    public function test_ecole_klassci_le_filtre_porte_sur_l_identite_klassci_et_le_catalogue_est_lu(): void
    {
        [$enseignant, $ecole] = $this->enseignantKlassci();
        $de55 = $this->evaluationKlassci($ecole, $enseignant, 55);
        $this->evaluationKlassci($ecole, $enseignant, 56);
        $this->catalogueKlassci(attendu: true);

        $ids = $this->ids($this->asTenant($enseignant)->getJson('/api/evaluations?classe_id=55')->assertOk()->json('data'));

        self::assertSame([$de55->getKey()], $ids);
    }

    // ───────────────────────── règle 3

    public function test_la_meme_route_sert_une_ecole_klassci_puis_une_ecole_du_lms(): void
    {
        [$enseignantKlassci, $ecoleKlassci] = $this->enseignantKlassci();
        $de55 = $this->evaluationKlassci($ecoleKlassci, $enseignantKlassci, 55);
        [$enseignant, $sixiemeA, , $maths] = $this->ecoleDuLms();
        $locale = $this->evaluation($sixiemeA, $maths, $enseignant);
        $this->catalogueKlassci(attendu: true);

        self::assertSame([$de55->getKey()], $this->ids($this->asTenant($enseignantKlassci)->getJson('/api/evaluations?classe_id=55')->assertOk()->json('data')));
        self::assertSame([$locale->getKey()], $this->ids($this->asTenant($enseignant)->getJson('/api/evaluations?classe_id='.$sixiemeA->getKey())->assertOk()->json('data')));
    }

    // ───────────────────────── la liste fermée des colonnes

    /** `colonne()` ne rend qu'un nom pris dans une liste fermée : aucune chaîne libre jusqu'au SQL. */
    public function test_les_colonnes_des_filtres_sont_une_liste_fermee(): void
    {
        $permises = ['classe_id', 'matiere_id', 'klassci_classe_id', 'klassci_matiere_id'];

        foreach ([app(KlassciCatalogueDesEvaluations::class), app(LocalCatalogueDesEvaluations::class)] as $source) {
            foreach (FiltreDeListe::cases() as $filtre) {
                self::assertContains($source->colonne($filtre), $permises);
            }
        }
        self::assertSame(['classe', 'matiere'], array_map(static fn (FiltreDeListe $f): string => $f->value, FiltreDeListe::cases()));
        self::assertSame('classe_id', app(LocalCatalogueDesEvaluations::class)->colonne(FiltreDeListe::Classe));
        self::assertSame('klassci_matiere_id', app(KlassciCatalogueDesEvaluations::class)->colonne(FiltreDeListe::Matiere));
    }

    // ───────────────────────── harnais

    /** @return list<mixed> */
    private function ids(mixed $lignes): array
    {
        self::assertIsArray($lignes);
        $ids = array_map(static fn (array $ligne): mixed => $ligne['id'], $lignes);
        sort($ids);

        return $ids;
    }

    /** @return array{User, Classe, Classe, Matiere} */
    private function ecoleDuLms(): array
    {
        $ecole = $this->ecole();

        return [$this->membre($ecole, 'enseignant'), $this->classe($ecole, '6e A'), $this->classe($ecole, '6e B'), $this->matiere($ecole, 'Maths')];
    }

    private function evaluation(Classe $classe, Matiere $matiere, User $auteur): Evaluation
    {
        // Comme `LocalEvaluationProgramming` l'écrit : ids et libellés locaux, aucune identité KLASSCI.
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(), 'classe_nom' => $classe->libelle,
            'matiere_id' => $matiere->getKey(), 'matiere_nom' => $matiere->libelle,
            'enseignant_id' => $auteur->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
            'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => true, 'is_online' => true, 'status' => 'planifiee',
            'date_evaluation' => now()->addDay(), 'duree_minutes' => 60,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return $evaluation;
    }

    /** @return array{User, Institution} */
    private function enseignantKlassci(): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton', 'klassci_enseignant_id' => 42])->save();
        $this->disableKlassciMiddleware();

        return [$enseignant, $ecole];
    }

    private function evaluationKlassci(Institution $ecole, User $enseignant, int $classeKlassci): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_classe_id' => $classeKlassci, 'klassci_matiere_id' => 9, 'klassci_enseignant_id' => 42,
            'classe_id' => null, 'matiere_id' => null, 'enseignant_id' => $enseignant->getKey(),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return $evaluation;
    }

    /** Le catalogue KLASSCI, dont l'école KLASSCI DOIT se servir. */
    private function catalogueKlassci(bool $attendu): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($attendu): void {
            $appel = $mock->shouldReceive('getClasses')->andReturn(['data' => [['id' => 55, 'name' => 'B2 COM']]]);
            if ($attendu) {
                $appel->atLeast()->once();
            }
            $mock->shouldReceive('getMatieres')->andReturn(['data' => [['id' => 9, 'nom' => 'Maths']]]);
        });
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getClasses', 'getMatieres');
        });
    }
}
