<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Matiere;
use App\Models\User;

/**
 * École KLASSCI : les classes de la page matière, calculées comme la page les
 * calcule ({@see MatiereDetailsQueryService} : la matière, ses séances, puis
 * {@see MatiereClassesResolver}). Les GET KLASSCI passent par le cache du
 * proxy : juste après l'affichage de la page, ils ne repartent pas.
 *
 * Une matière sans identifiant KLASSCI, ou que KLASSCI ne connaît pas, n'a
 * aucune classe à proposer.
 */
final class KlassciClassesDeLaMatiere implements ClassesDeLaMatiere
{
    public function __construct(
        private readonly MatiereInfoFetcher $infoFetcher,
        private readonly MatiereSeancesFetcher $seancesFetcher,
        private readonly MatiereClassesResolver $classesResolver,
    ) {}

    public function idsPour(Matiere $matiere, User $compte): array
    {
        $klassciToken = $compte->klassci_token;
        if (! is_string($klassciToken) || $klassciToken === '') {
            throw MissingKlassciTokenException::forUser($compte->id);
        }

        if (! is_numeric($matiere->klassci_id)) {
            return [];
        }

        $klassciMatiereId = (int) $matiere->klassci_id;
        $info = $this->infoFetcher->fetchMatiereInfo($klassciToken, $klassciMatiereId);
        if ($info === null) {
            return [];
        }

        $seances = $this->seancesFetcher->fetchSeancesForUser($compte, $klassciMatiereId, $klassciToken, $info['matiereData']);
        $institutionId = is_int($compte->institution_id) ? $compte->institution_id : null;

        return array_values(array_map(
            static fn (array $classe): int => $classe['id'],
            $this->classesResolver->resolve($seances['seances_enrichies'], $klassciMatiereId, $institutionId, $compte),
        ));
    }
}
