<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\DispenseDEvaluation;
use App\Models\Evaluation;
use App\Models\User;

/**
 * #1698 (C2) : dispenser un élève d'une évaluation, ou lever sa dispense. Ce qui en découle (la
 * note sort de tous les calculs) vit dans `CopiesFinales` ; ici, seulement QUI est dispensé.
 *
 * L'élève doit être de la classe de l'évaluation, lue comme le bilan la lit
 * ({@see ElevesDeLaClasse}) : les inscriptions actives d'une école du LMS, le roster de KLASSCI
 * sinon. Accorder et lever sont idempotents : une seconde dispense remplace le motif de la
 * première, lever une dispense absente ne change rien.
 */
final class DispensesDEvaluation
{
    public function __construct(private readonly ElevesDeLaClasse $eleves) {}

    /** L'évaluation, dans l'établissement de l'auteur (la requête l'a déjà autorisée). */
    public function evaluation(int $id, User $auteur): Evaluation
    {
        $evaluation = Evaluation::query()->withoutGlobalScope('institution')
            ->where('institution_id', $auteur->institution_id)
            ->find($id);

        if (! $evaluation instanceof Evaluation) {
            throw new BusinessException('Évaluation introuvable dans cet établissement.', 404);
        }

        return $evaluation;
    }

    /** L'élève désigné, s'il est de la classe de l'évaluation ; 422 sinon. */
    public function eleve(Evaluation $evaluation, User $auteur, ?int $compte, ?int $identiteKlassci): EleveDeLaClasse
    {
        foreach ($this->eleves->de(self::classe($evaluation), $auteur) as $eleve) {
            if (($compte !== null && $eleve->compte === $compte)
                || ($compte === null && $identiteKlassci !== null && in_array($identiteKlassci, $eleve->identitesKlassci, true))) {
                return $eleve;
            }
        }

        throw new BusinessException('Cet élève n\'est pas de la classe de l\'évaluation.', 422, null, 'student_not_in_class');
    }

    public function accorder(Evaluation $evaluation, EleveDeLaClasse $eleve, User $auteur, ?string $motif): DispenseDEvaluation
    {
        $dispense = self::existante($evaluation, $eleve) ?? new DispenseDEvaluation([
            'institution_id' => (int) $evaluation->institution_id,
            'evaluation_id' => (int) $evaluation->id,
            'student_id' => $eleve->compte,
            'klassci_etudiant_id' => $eleve->identitesKlassci[0] ?? null,
        ]);
        $dispense->fill(['accordee_par' => is_int($auteur->getKey()) ? $auteur->getKey() : null, 'motif' => $motif])->save();

        return $dispense;
    }

    public function lever(Evaluation $evaluation, EleveDeLaClasse $eleve): void
    {
        self::existante($evaluation, $eleve)?->delete();
    }

    public static function existante(Evaluation $evaluation, EleveDeLaClasse $eleve): ?DispenseDEvaluation
    {
        return DispenseDEvaluation::query()->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->where('evaluation_id', $evaluation->id)
            ->get()
            ->first(static fn (DispenseDEvaluation $dispense): bool => $eleve->estDesignePar($dispense));
    }

    /** La classe de l'évaluation : son id local, sinon le miroir de son identité KLASSCI. */
    private static function classe(Evaluation $evaluation): Classe
    {
        $id = is_numeric($evaluation->classe_id)
            ? (int) $evaluation->classe_id
            : Classe::localIdForKlassciId($evaluation->klassci_classe_id, $evaluation->institution_id);
        $classe = $id === null ? null : Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->find($id);

        if (! $classe instanceof Classe) {
            throw new BusinessException('L\'évaluation n\'est rattachée à aucune classe de cet établissement.', 422, null, 'evaluation_without_class');
        }

        return $classe;
    }
}
