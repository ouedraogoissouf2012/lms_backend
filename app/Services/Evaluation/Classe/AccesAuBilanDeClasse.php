<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseDeLEspaceSource;
use App\Services\Classe\ClassesEnseignees;

/**
 * #1676 : qui lit le bilan d'une classe, et quoi.
 *
 * - Le coordinateur et l'admin de l'école : la classe entière.
 * - L'enseignant : la classe entière, s'il y est désigné formateur d'une matière
 *   ({@see ClassesEnseignees}, la règle de « Mes classes ») ; sinon 403.
 * - L'élève : SA seule ligne, sans aucune donnée sur un autre élève. Son rang n'y figure que si
 *   l'école l'autorise (`settings.rang_visible_eleve`, faux par défaut). Hors de la classe : 403.
 * - Une classe d'une autre école, ou inexistante : 404 (bornage explicite par l'établissement).
 *   Dans une école KLASSCI, aussi tant que le miroir local de la classe n'existe pas (déclaré).
 */
final class AccesAuBilanDeClasse
{
    public const COMPLET = 'complet';

    public const ELEVE = 'eleve';

    public function __construct(private readonly ClasseDeLEspaceSource $classes) {}

    /** La classe désignée par l'identifiant de l'espace SOURCE de l'école ({@see ClasseDeLEspaceSource}). */
    public function classe(int $id, User $lecteur): Classe
    {
        $classe = is_numeric($lecteur->institution_id) ? $this->classes->trouver($id, (int) $lecteur->institution_id) : null;

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404, null, 'class_out_of_scope');
        }

        return $classe;
    }

    public function portee(Classe $classe, User $lecteur): string
    {
        if ($lecteur->isManager()) {
            return self::COMPLET;
        }

        if ($lecteur->isTeacher()) {
            if (ClassesEnseignees::par(Classe::query()->withoutGlobalScope('institution')->whereKey($classe->id), (int) $lecteur->id, (int) $classe->institution_id)->exists()) {
                return self::COMPLET;
            }

            throw new BusinessException('Vous n\'enseignez pas dans cette classe.', 403, null, 'class_not_taught');
        }

        if ($lecteur->isStudent()) {
            return self::ELEVE;
        }

        throw new BusinessException('Accès refusé.', 403);
    }

    /**
     * Ce que l'élève lit : sa ligne seule, sans statistiques ni couverture de la classe.
     *
     * @param  array<string, mixed>  $bilan
     * @return array<string, mixed>
     */
    public function pourLEleve(array $bilan, Classe $classe, User $eleve): array
    {
        $lignes = is_array($bilan['eleves'] ?? null) ? $bilan['eleves'] : [];
        $sienne = array_values(array_filter($lignes, static fn ($ligne): bool => is_array($ligne) && ($ligne['compte_id'] ?? null) === (int) $eleve->id));

        if ($sienne === []) {
            throw new BusinessException('Vous n\'êtes pas élève de cette classe.', 403, null, 'not_in_class');
        }

        if (! self::rangVisible($classe)) {
            unset($sienne[0]['rang']);
        }
        $bilan['eleves'] = $sienne;
        unset($bilan['statistiques'], $bilan['couverture']);

        return $bilan;
    }

    private static function rangVisible(Classe $classe): bool
    {
        // Par le modèle : `value()` rendrait le JSON brut de la colonne, sans le cast `array`.
        $reglages = Institution::query()->find($classe->institution_id, ['id', 'settings'])?->settings;

        return is_array($reglages) && ($reglages['rang_visible_eleve'] ?? false) === true;
    }
}
