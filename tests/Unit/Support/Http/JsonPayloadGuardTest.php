<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http;

use App\Exceptions\UnserializablePayloadException;
use App\Support\Http\JsonPayloadGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * Garde de sérialisabilité #360 — comportement isolé de `JsonPayloadGuard`
 * (le branchement dans le trait est couvert par RespondsWithJsonHostileInputsTest).
 */
#[CoversClass(JsonPayloadGuard::class)]
final class JsonPayloadGuardTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function serializableValueProvider(): array
    {
        $object = new stdClass;
        $object->x = 1;

        return [
            'null' => [null],
            'scalaires' => [42],
            'chaîne' => ['fn () => 1 (texte, pas une Closure)'],
            'tableau imbriqué sain' => [['a' => ['b' => [1, 2, ['c' => 'd']]]]],
            'objet (non pénétré)' => [$object],
            'callable-string (pas une Closure)' => ['strtoupper'],
            'first-class callable array (pas une Closure)' => [[JsonPayloadGuard::class, 'rejectClosures']],
        ];
    }

    #[DataProvider('serializableValueProvider')]
    public function test_les_valeurs_serialisables_passent_sans_exception(mixed $value): void
    {
        JsonPayloadGuard::rejectClosures($value, 'data');

        $this->addToAssertionCount(1); // aucune exception = succès
    }

    public function test_closure_a_la_racine_jette_avec_le_chemin_racine(): void
    {
        $this->expectException(UnserializablePayloadException::class);
        $this->expectExceptionMessage('`data`');

        JsonPayloadGuard::rejectClosures(fn (): int => 1, 'data');
    }

    public function test_closure_imbriquee_jette_avec_le_chemin_complet(): void
    {
        $this->expectException(UnserializablePayloadException::class);
        $this->expectExceptionMessage('`meta.pages.0.loader`');

        JsonPayloadGuard::rejectClosures(['pages' => [['loader' => fn (): int => 1]]], 'meta');
    }

    public function test_closure_dans_un_objet_n_est_pas_detectee_perimetre_documente(): void
    {
        // Périmètre assumé : on ne pénètre pas les objets (leur sérialisation
        // passe par jsonSerialize()/toArray() — voir docblock de la garde).
        $carrier = new stdClass;
        $carrier->cb = fn (): int => 1;

        JsonPayloadGuard::rejectClosures(['wrapped' => $carrier], 'data');

        $this->addToAssertionCount(1);
    }

    public function test_profondeur_superieure_a_512_n_explose_pas_la_pile(): void
    {
        // Au-delà de 512 niveaux la garde s'arrête : c'est json_encode qui
        // jettera son `Maximum stack depth exceeded` canonique. La garde ne
        // doit jamais s'effondrer avant lui, même sur un tableau adversarial.
        $deep = 'leaf';
        for ($i = 0; $i < 5000; $i++) {
            $deep = ['n' => $deep];
        }

        JsonPayloadGuard::rejectClosures($deep, 'data');

        $this->addToAssertionCount(1);
    }
}
