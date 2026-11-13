<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
 *
 * La classe est désignée en UN endroit ({@see classeDe()}) : l'admission et les
 * notifications lisent la même.
 */
final class LocalClasseAudienceSource implements ClasseAudienceSource
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    public function designatesClasse(Seance $seance): bool
    {
        return $this->classeDe($seance) !== null;
    }

    public function containsStudent(Seance $seance, User $student): bool
    {
        if ($seance->institution_id !== $student->institution_id) {
            return false;
        }

        $laClasse = $this->classeDe($seance);

        if ($laClasse === null) {
            return false;
        }

        if (is_numeric($seance->classe_id)) {
            return $laClasse->exists()
                && in_array((int) $seance->classe_id, $this->inscriptions->localClasseIdsFor($student), true);
        }

        return $laClasse
            ->whereHas(
                'etudiantsActifs',
                static fn ($query) => $query->where('users.id', $student->id),
            )
            ->exists();
    }

    /**
     * Les élèves actifs de la classe de la séance, selon la règle de l'admission :
     * de l'établissement de la séance, et élèves au sens du prédicat de rôle
     * (`isStudent()`, comme `ParticipantValidationService`), jamais un rôle écrit
     * en dur. Un élève admis est donc toujours averti, et un compte refusé à
     * l'entrée ne l'est pas.
     *
     * Le composite d'inscriptions répond « quelles classes pour cet élève », pas
     * « quels élèves pour cette classe » : la liste est lue sur le pivot
     * `classe_etudiant`, celui que sa jambe locale lit (dette déclarée).
     */
    public function studentsOf(Seance $seance): Collection
    {
        $classe = $this->classeDe($seance)?->first();

        if ($classe === null) {
            return collect();
        }

        return $classe->etudiantsActifs()
            ->where('users.institution_id', $seance->institution_id)
            ->get()
            ->filter(static fn (User $eleve): bool => $eleve->isStudent())
            ->values();
    }

    /**
     * La classe que la séance désigne, bornée explicitement à l'établissement de
     * la séance : le scope multi-tenant est inerte dans un job, où les
     * notifications sont envoyées.
     *
     * @return Builder<Classe>|null
     */
    private function classeDe(Seance $seance): ?Builder
    {
        $classes = Classe::query()->where('institution_id', $seance->institution_id);

        if (is_numeric($seance->classe_id)) {
            return $classes->whereKey((int) $seance->classe_id);
        }

        if (is_numeric($seance->klassci_classe_id)) {
            return $classes->where('klassci_id', (int) $seance->klassci_classe_id);
        }

        return null;
    }
}
