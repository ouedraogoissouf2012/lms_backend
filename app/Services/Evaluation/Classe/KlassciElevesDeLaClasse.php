<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\User;
use App\Services\Classe\ClasseEnvelope;
use App\Services\Classe\ClasseRoster;
use App\Services\Classe\ComptesDuRoster;
use App\Services\Seances\KlassciPayload;

/**
 * #1676, école KLASSCI : le roster de KLASSCI fait foi, lu par {@see ClasseRoster} (le chemin déjà
 * prouvé par les résultats d'une évaluation, #669), avec le jeton du LECTEUR. Chaque élève est
 * rattaché à son compte local par {@see ComptesDuRoster}, la même règle.
 *
 * Une classe sans identité KLASSCI (créée dans le LMS après le détachement) n'a pas de roster
 * KLASSCI : ses inscriptions locales répondent ({@see LocalElevesDeLaClasse}).
 */
final class KlassciElevesDeLaClasse implements ElevesDeLaClasse
{
    public function __construct(
        private readonly ClasseRoster $roster,
        private readonly LocalElevesDeLaClasse $locaux,
    ) {}

    public function de(Classe $classe, User $lecteur): array
    {
        if (! is_numeric($classe->klassci_id)) {
            return $this->locaux->de($classe, $lecteur);
        }

        $jeton = $lecteur->klassci_token;
        if (! is_string($jeton) || $jeton === '') {
            throw MissingKlassciTokenException::forUser((int) $lecteur->id);
        }

        $etudiants = $this->roster->etudiants((int) $classe->klassci_id, $jeton);
        $comptes = ComptesDuRoster::de($etudiants, $classe->institution_id);

        return array_values(array_map(static function (array $etudiant) use ($comptes): EleveDeLaClasse {
            $compte = $comptes->pour($etudiant);
            $identites = array_values(array_unique(array_filter(
                [KlassciPayload::toInt($compte?->klassci_id), KlassciPayload::toInt($etudiant['id'] ?? null)],
                static fn (?int $id): bool => $id !== null,
            )));

            return new EleveDeLaClasse(
                $compte !== null ? (int) $compte->id : null,
                $identites,
                ClasseEnvelope::identiteAffichable($etudiant),
            );
        }, $etudiants));
    }
}
