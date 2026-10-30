<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio;

use App\Services\Visio\VisioStatusRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La table état de la visio → motif du refus (ADR-906-01).
 *
 * @see VisioStatusRefusal
 */
final class VisioStatusRefusalTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function etats(): iterable
    {
        yield 'active : aucun refus' => ['active', null];
        yield 'programmee : pas encore demarree' => ['programmee', 'visio_not_started'];
        yield 'terminee : terminee' => ['terminee', 'visio_ended'];
        yield 'sans visio' => [null, 'visio_not_enabled'];
        yield 'etat hors enum : jamais sans motif' => ['inconnu', 'visio_not_enabled'];
    }

    #[DataProvider('etats')]
    public function test_chaque_etat_a_son_motif(?string $etat, ?string $motif): void
    {
        self::assertSame($motif, VisioStatusRefusal::reasonFor($etat));
    }

    public function test_chaque_motif_a_un_message_qui_dit_la_verite(): void
    {
        self::assertSame("La visioconférence n'a pas encore démarré", VisioStatusRefusal::messageFor('visio_not_started'));
        self::assertSame('La visioconférence est terminée', VisioStatusRefusal::messageFor('visio_ended'));
        self::assertSame('Visioconférence non activée pour cette séance', VisioStatusRefusal::messageFor('visio_not_enabled'));
    }
}
