<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;

/**
 * Vérité locale (#712) : zéro appel HTTP, fail-secure si la séance n'a pas de
 * classe.
 *
 * ## Deux classes, parce que la séance déclare son espace (ADR-846-01, #917)
 *
 * Une séance créée dans le LMS porte sa classe LOCALE (`classe_id`) ; une séance
 * miroitée porte sa classe KLASSCI (`klassci_classe_id`). La classe locale passe
 * d'abord, comme dans l'accès aux enregistrements (`SeanceRecordingAccessService`).
 * Seul `LocalSeanceCreator` écrit `classe_id`, et jamais avec `klassci_classe_id` :
 * une séance miroitée suit donc son chemin d'avant, inchangé.
 *
 * - Classe locale : bornée à l'établissement de la séance, et l'inscription passe
 *   par `EnrollmentSource` (le composite), comme l'exige ADR-803-03.
 * - Classe KLASSCI : le miroir local dont `klassci_id` vaut `klassci_classe_id`,
 *   et son pivot `classe_etudiant`.
 */
final class LocalClasseAudienceSource implements ClasseAudienceSource
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    public function designatesClasse(Seance $seance): bool
    {
        return is_numeric($seance->classe_id) || is_numeric($seance->klassci_classe_id);
    }

    public function containsStudent(Seance $seance, User $student): bool
    {
        if ($seance->institution_id !== $student->institution_id) {
            return false;
        }

        if (is_numeric($seance->classe_id)) {
            return $this->parLaClasseLocale((int) $seance->classe_id, $seance, $student);
        }

        if (! is_numeric($seance->klassci_classe_id)) {
            return false;
        }

        return Classe::query()
            ->where('institution_id', $student->institution_id)
            ->where('klassci_id', (int) $seance->klassci_classe_id)
            ->whereHas(
                'etudiantsActifs',
                static fn ($query) => $query->where('users.id', $student->id),
            )
            ->exists();
    }

    private function parLaClasseLocale(int $classeId, Seance $seance, User $student): bool
    {
        $deLEtablissement = Classe::query()
            ->whereKey($classeId)
            ->where('institution_id', $seance->institution_id)
            ->exists();

        return $deLEtablissement && in_array($classeId, $this->inscriptions->localClasseIdsFor($student), true);
    }
}
