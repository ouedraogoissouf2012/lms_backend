<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * QUI avertir d'une visio : les élèves de la séance, son enseignant, et
 * l'établissement qui les borne. Le job tourne sans tenant ; tout se lit sur la
 * séance désignée par son id local (`seance_locale_id`).
 *
 * Extrait de {@see VisioNotificationDispatcher} (§1.1 ≤300 l), qui garde l'envoi
 * et la garde d'idempotence.
 */
final class VisioNotificationTargets
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClasseAudienceSource $audience,
    ) {}

    /**
     * Résout les étudiants à notifier pour la programmation (avec logs détaillés).
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    public function elevesProgrammation(int $seanceId, array $seanceData): Collection
    {
        $students = collect();

        if (! isset($seanceData['klassci_classe_id'])) {
            return $this->elevesDeLaSeanceLocale($seanceData);
        }

        $classe = $this->resolveClasse($seanceData);

        if (! $classe) {
            $this->logger->warning('Classe non trouvée pour notification', [
                'klassci_classe_id' => $seanceData['klassci_classe_id'],
            ]);

            return $students;
        }

        $students = User::query()
            ->whereHas('classes', function ($query) use ($classe) {
                $query->where('classes.id', $classe->id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->where('role', 'etudiant')
            ->get();

        $this->logger->info('Étudiants trouvés pour notification visio', [
            'classe_id' => $classe->id,
            'students_count' => $students->count(),
        ]);

        return $students;
    }

    /**
     * Résout les étudiants pour le démarrage (silencieux, pas de logs détaillés).
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    public function elevesDemarrage(array $seanceData): Collection
    {
        if (! isset($seanceData['klassci_classe_id'])) {
            return $this->elevesDeLaSeanceLocale($seanceData);
        }

        $classe = $this->resolveClasse($seanceData);

        if (! $classe) {
            return collect();
        }

        return User::query()
            ->whereHas('classes', function ($query) use ($classe) {
                $query->where('classes.id', $classe->id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->where('role', 'etudiant')
            ->get();
    }

    /**
     * Une séance sans classe KLASSCI : ses élèves sont ceux de l'audience de
     * l'admission, lue sur la séance que l'appelant désigne par son id LOCAL
     * (`seance_locale_id`). Sans cet id, rien n'est deviné : l'identifiant du job
     * peut être un identifiant KLASSCI, qui désignerait une autre séance.
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    private function elevesDeLaSeanceLocale(array $seanceData): Collection
    {
        $id = self::idLocal($seanceData);

        // Hors contexte HTTP le scope est inerte ; l'audience borne la classe à l'établissement de la séance.
        $seance = $id === null ? null : Seance::query()->withoutGlobalScope('institution')->find($id);

        return $seance === null ? collect() : $this->audience->studentsOf($seance);
    }

    /**
     * L'id LOCAL de la séance (`seances.id`), que chaque source déclare : la clé de
     * la garde d'idempotence, et la marque des notifications. Null pour un job mis
     * en file avant que la source ne le déclare.
     *
     * @param  array<string, mixed>  $seanceData
     */
    public static function idLocal(array $seanceData): ?int
    {
        $id = $seanceData['seance_locale_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * L'enseignant à avertir, cherché dans l'établissement de la séance : son
     * enseignant KLASSCI (`klassci_enseignant_id`), sinon l'AUTEUR de la séance
     * désignée par son id local (`created_by`), s'il est enseignant.
     *
     * @param  array<string, mixed>  $seanceData
     */
    public function enseignant(array $seanceData): ?User
    {
        $klassci = $seanceData['klassci_enseignant_id'] ?? null;
        $id = self::idLocal($seanceData);
        $etablissement = $klassci === null && $id === null ? null : $this->etablissementDe($seanceData);

        if ($etablissement === null) {
            return null;
        }

        $comptes = User::query()->withoutGlobalScope('institution')->where('institution_id', $etablissement);

        if ($klassci !== null) {
            return $comptes->where('klassci_id', $klassci)->where('role', 'enseignant')->first();
        }

        $auteur = Seance::query()->withoutGlobalScope('institution')->whereKey($id)->value('created_by');
        $compte = is_numeric($auteur) ? $comptes->whereKey((int) $auteur)->first() : null;

        return $compte?->isTeacher() === true ? $compte : null;
    }

    /**
     * La classe KLASSCI de la séance, cherchée dans l'établissement de la séance
     * (#473) : `klassci_id` n'est unique que par établissement.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function resolveClasse(array $seanceData): ?Classe
    {
        $etablissement = $this->etablissementDe($seanceData);

        return $etablissement === null ? null : Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->where('klassci_id', $seanceData['klassci_classe_id'])
            ->first();
    }

    /**
     * L'établissement de la séance, lu sur la SÉANCE désignée par son id local, jamais
     * sur la charge : le job tourne sans tenant (le worker oublie celui de la requête).
     * Sans id local (job mis en file avant), repli sur `institution_id` de la charge ;
     * sans l'un ni l'autre, null : rien n'est envoyé plutôt que deviné.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function etablissementDe(array $seanceData): ?int
    {
        $id = self::idLocal($seanceData);
        $etablissement = $id === null
            ? $seanceData['institution_id'] ?? null
            : Seance::query()->withoutGlobalScope('institution')->whereKey($id)->value('institution_id');

        if (! is_numeric($etablissement)) {
            $this->logger->warning('Avertissement visio sans établissement : aucun envoi');

            return null;
        }

        return (int) $etablissement;
    }
}
