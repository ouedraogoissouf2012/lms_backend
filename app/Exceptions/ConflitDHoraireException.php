<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Le refus d'un créneau qui chevauche d'autres séances (#1668) : un 409
 * `seance_schedule_conflict` qui NOMME les séances, sous `conflicts`, pour que l'écran
 * dise lesquelles. Une {@see BusinessException} ne porte qu'un message et un motif : ce
 * refus porte en plus sa liste, d'où sa classe.
 *
 * Le message est une constante sûre pour le client ({@see self::MESSAGE}) : il ne cite
 * aucune donnée, et le contrôleur le lit par elle, jamais par `getMessage()`.
 */
final class ConflitDHoraireException extends RuntimeException
{
    public const MESSAGE = 'Ce créneau chevauche une autre séance du même enseignant ou de la même classe.';

    /**
     * @param  list<array{id: int, titre: ?string, debut: string, fin: string, motifs: list<string>}>  $conflits
     */
    public function __construct(public readonly array $conflits)
    {
        parent::__construct(self::MESSAGE, 409);
    }
}
