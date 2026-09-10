<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Concerns\NormalizesQueryBooleans;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le contrat de {@see NormalizesQueryBooleans}, isolé de tout endpoint.
 *
 * Deux garanties, et la seconde est la plus importante :
 *
 *  1. les formes textuelles qu'une URL peut porter sont converties ;
 *  2. **tout le reste est laissé INTACT**, pour que la règle `boolean` de
 *     Laravel puisse le refuser. C'est ce qui distingue « accepter le
 *     transport » de « accepter n'importe quoi » — un `$request->boolean()`
 *     transformerait une faute de frappe en `false` sans le dire.
 */
#[CoversTrait(NormalizesQueryBooleans::class)]
final class NormalizesQueryBooleansTest extends TestCase
{
    /**
     * @param  mixed  $attendu  La valeur après normalisation.
     */
    #[DataProvider('valeurs')]
    public function test_it_converts_only_what_it_recognises(mixed $entree, mixed $attendu): void
    {
        $requete = $this->requeteAvec(['flag' => $entree]);

        self::assertSame($attendu, $requete->input('flag'));
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function valeurs(): array
    {
        return [
            // Converties — ce qu'une chaîne de requête transporte réellement.
            "'true'" => ['true', true],
            "'false'" => ['false', false],
            "'on'" => ['on', true],
            "'off'" => ['off', false],
            "'yes'" => ['yes', true],
            "'no'" => ['no', false],
            'casse indifferente' => ['FaLsE', false],
            'espaces de bord ignores' => ['  true  ', true],

            // Laissées INTACTES — la règle `boolean` tranchera.
            "'0' deja accepte par Laravel" => ['0', '0'],
            "'1' deja accepte par Laravel" => ['1', '1'],
            'faute de frappe' => ['flase', 'flase'],
            'valeur arbitraire' => ['nawak', 'nawak'],
            'chaine vide' => ['', ''],
            'nombre non booleen' => ['2', '2'],
        ];
    }

    /**
     * Un champ absent ne doit pas être inventé : le rendre présent
     * transformerait une règle `sometimes` en règle toujours évaluée.
     */
    public function test_an_absent_field_is_not_created(): void
    {
        $requete = $this->requeteAvec([]);

        self::assertFalse($requete->has('flag'));
    }

    /**
     * Seuls les champs NOMMÉS sont touchés. Normaliser tout ce qui ressemble à
     * un booléen convertirait aussi un champ texte dont `'true'` est une
     * valeur légitime.
     */
    public function test_an_unlisted_field_is_left_alone(): void
    {
        $requete = $this->requeteAvec(['flag' => 'true', 'commentaire' => 'false']);

        self::assertTrue($requete->input('flag'));
        self::assertSame('false', $requete->input('commentaire'));
    }

    /**
     * Une valeur non textuelle (booléen réel d'un corps JSON) traverse sans
     * être retouchée.
     */
    public function test_a_real_boolean_passes_through(): void
    {
        $requete = $this->requeteAvec(['flag' => true]);

        self::assertTrue($requete->input('flag'));
    }

    /**
     * @param  array<string, mixed>  $entrees
     */
    private function requeteAvec(array $entrees): FormRequest
    {
        $requete = new class extends FormRequest
        {
            use NormalizesQueryBooleans;

            public function normaliser(): void
            {
                $this->normalizeQueryBooleans(['flag']);
            }
        };

        $requete->merge($entrees);
        $requete->normaliser();

        return $requete;
    }
}
