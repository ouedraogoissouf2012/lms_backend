<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Traits;

use App\Models\Institution;
use App\Models\Matiere;
use App\Models\Traits\ResolvesMirroredIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Lesson\KlassciIdentifierDualityTest;
use Tests\TestCase;

/**
 * La source unique de vérité sur « cet identifiant désigne-t-il une ligne de
 * cette institution, et laquelle ? ».
 *
 * Le trait est éprouvé à travers `Matiere`, l'un des deux modèles qui le
 * déclarent : c'est ainsi qu'il est réellement utilisé, et cela vérifie du
 * même coup que le contrat `MirroredFromKlassci` est bien branché.
 *
 * @see ResolvesMirroredIdentifier
 * @see KlassciIdentifierDualityTest
 */
final class ResolvesMirroredIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    public function test_a_local_identifier_resolves_to_itself(): void
    {
        $matiere = $this->matiere(klassciId: 5001);

        self::assertSame($matiere->id, Matiere::localIdFor($matiere->id, $this->institution->id));
    }

    /**
     * Le cas qui manquait à `matiere_id` : l'espace KLASSCI doit se traduire
     * vers l'identifiant LOCAL, jamais être renvoyé tel quel — `lessons.matiere_id`
     * est une clé locale (cf. la fuite entre enseignants de #707).
     */
    public function test_a_klassci_identifier_resolves_to_the_local_identifier(): void
    {
        $matiere = $this->matiere(klassciId: 5001);

        self::assertSame($matiere->id, Matiere::localIdFor(5001, $this->institution->id));
    }

    /**
     * LE point que le `orWhere` laissait indéterminé.
     *
     * Rien n'interdit que l'id local d'une ligne soit égal au `klassci_id`
     * d'une autre : ce sont deux espaces de numérotation indépendants. Avec un
     * `where(id)->orWhere(klassci_id)`, la ligne renvoyée dépendait de l'ordre
     * du moteur — donc du hasard, et différemment sous SQLite et sous MySQL.
     * L'espace LOCAL est prioritaire, et ce test l'impose.
     */
    public function test_the_local_space_wins_when_both_spaces_collide(): void
    {
        $visee = $this->matiere(klassciId: 9001);
        $leurre = $this->matiere(klassciId: (int) $visee->id);

        self::assertSame($visee->id, Matiere::localIdFor($visee->id, $this->institution->id));
        self::assertNotSame($leurre->id, Matiere::localIdFor($visee->id, $this->institution->id));
    }

    /**
     * Le `klassci_id` n'est unique QUE par institution : deux établissements
     * portent légitimement la matière 5001. Sans ce bornage, résoudre
     * reviendrait à franchir la frontière du tenant.
     */
    public function test_a_row_of_another_institution_never_resolves(): void
    {
        $ailleurs = Institution::factory()->create();
        Matiere::factory()->create(['institution_id' => $ailleurs->id, 'klassci_id' => 5001]);

        self::assertNull(Matiere::localIdFor(5001, $this->institution->id));
    }

    public function test_an_unknown_identifier_resolves_to_nothing(): void
    {
        self::assertNull(Matiere::localIdFor(4242, $this->institution->id));
    }

    /**
     * Une entrée non numérique ne doit produire ni exception ni requête
     * absurde : le contrôle de forme appartient à `PositiveInteger`, la
     * résolution se contente de ne rien trouver.
     */
    #[DataProvider('entreesNonNumeriques')]
    public function test_a_non_numeric_input_resolves_to_nothing(mixed $entree): void
    {
        self::assertNull(Matiere::localIdFor($entree, $this->institution->id));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function entreesNonNumeriques(): array
    {
        return [
            'null' => [null],
            'chaîne vide' => [''],
            'texte' => ['abc'],
            'tableau' => [[1]],
        ];
    }

    /**
     * L'INVARIANT qui justifie à lui seul ce trait : valider et résoudre
     * répondent à la MÊME question. Tant que les deux réponses sortent d'ici,
     * une requête ne peut plus être acceptée par la validation puis échouer à
     * la résolution — le scénario exact qui a coûté une soirée.
     */
    public function test_existence_and_resolution_can_never_disagree(): void
    {
        $matiere = $this->matiere(klassciId: 5001);
        $tenant = $this->institution->id;

        foreach ([$matiere->id, 5001, 4242, null, 'abc'] as $identifiant) {
            self::assertSame(
                Matiere::localIdFor($identifiant, $tenant) !== null,
                Matiere::existsFor($identifiant, $tenant),
                'Divergence entre existsFor() et localIdFor() pour : '.var_export($identifiant, true),
            );
        }
    }

    /**
     * LE pendant indispensable de `localIdFor()`.
     *
     * `localIdFor()` sert les identifiants venus d'un CLIENT, dont on ignore
     * l'espace, et tranche alors en faveur du local. Mais un identifiant lu
     * dans un payload KLASSCI n'a, lui, aucune ambiguïté : son espace est
     * connu. Le faire passer par le résolveur dual, c'est réintroduire
     * volontairement le hasard qu'il sert à supprimer.
     *
     * Ce test le prouve sur la collision : sur les MÊMES données, les deux
     * méthodes rendent des lignes DIFFÉRENTES — et c'est exactement ce qu'on
     * attend d'elles.
     */
    public function test_a_known_klassci_identifier_never_falls_back_to_the_local_space(): void
    {
        $visee = $this->matiere(klassciId: 9001);
        $leurre = $this->matiere(klassciId: (int) $visee->id);
        $tenant = $this->institution->id;

        // L'entrée vaut à la fois un id LOCAL (celui de $visee) et un
        // `klassci_id` (celui de $leurre). Chaque méthode lit son espace.
        self::assertSame($leurre->id, Matiere::localIdForKlassciId($visee->id, $tenant));
        self::assertSame($visee->id, Matiere::localIdFor($visee->id, $tenant));
    }

    /**
     * La stricte ne connaît QUE l'espace KLASSCI : un id purement local ne lui
     * dit rien. C'est la contrepartie de sa précision.
     */
    public function test_a_purely_local_identifier_is_unknown_to_the_strict_lookup(): void
    {
        $matiere = $this->matiere(klassciId: 5001);

        self::assertNull(Matiere::localIdForKlassciId($matiere->id, $this->institution->id));
        self::assertSame($matiere->id, Matiere::localIdForKlassciId(5001, $this->institution->id));
    }

    /**
     * Le bornage tenant vaut pour les deux méthodes, sans exception.
     */
    public function test_the_strict_lookup_is_bounded_to_the_institution_too(): void
    {
        $ailleurs = Institution::factory()->create();
        Matiere::factory()->create(['institution_id' => $ailleurs->id, 'klassci_id' => 5001]);

        self::assertNull(Matiere::localIdForKlassciId(5001, $this->institution->id));
    }

    #[DataProvider('entreesNonNumeriques')]
    public function test_the_strict_lookup_ignores_a_non_numeric_input(mixed $entree): void
    {
        self::assertNull(Matiere::localIdForKlassciId($entree, $this->institution->id));
    }

    private function matiere(int $klassciId): Matiere
    {
        return Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
        ]);
    }
}
