<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Enums\CorrectionRelease;
use App\Models\Concerns\FinDeLEvaluation;
use App\Models\Evaluation;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * La règle d'ouverture du corrigé d'une évaluation, à un élève (#1699), écrite en un seul lieu.
 * Elle sert « ma copie », le seul chemin qui révèle le corrigé.
 *
 * Le mode est choisi par l'enseignant ({@see CorrectionRelease}). Le PLANCHER vaut pour tous les
 * modes : jamais avant la fermeture de l'évaluation pour tous. Sinon, une publication anticipée
 * montrerait le corrigé à un élève qui n'a pas encore composé.
 *
 * La fermeture se lit dans la source de fenêtre de l'école ({@see EvaluationWindowGate}) :
 * - `end_at` programmé par KLASSCI, sinon la fin de la ligne ({@see FinDeLEvaluation::finPrevue()}) ;
 * - une fenêtre illisible (KLASSCI injoignable, pas de jeton) ou une évaluation sans fin connue
 *   gardent le corrigé fermé : on échoue fermé, comme le démarrage (#499).
 *
 * La fenêtre n'est lue que si le mode peut ouvrir le corrigé : `never`, ou `with_results` sans
 * notes publiées, ne coûtent aucun appel à KLASSCI.
 *
 * Avant : 7 jours fixes après la remise de CHAQUE élève.
 */
final class OuvertureDuCorrige
{
    private const FERME = ['ouvert' => false, 'ouvre_le' => null];

    public function __construct(private readonly EvaluationWindowGate $fenetres) {}

    /**
     * @return array{ouvert: bool, ouvre_le: CarbonInterface|null} `ouvre_le` : l'instant d'ouverture
     *                                                             s'il est connu, passé ou à venir
     */
    public function pour(User $eleve, Evaluation $evaluation, CarbonInterface $maintenant): array
    {
        $mode = $evaluation->correction_release ?? CorrectionRelease::WithResults;
        if ($mode === CorrectionRelease::Never
            || ($mode === CorrectionRelease::WithResults && ! ResultatsVisibles::pour($evaluation))) {
            return self::FERME;
        }

        $fermeture = $this->fermeture($eleve, $evaluation);
        $choisie = $mode === CorrectionRelease::AtDate ? $evaluation->correction_release_at : $fermeture;
        if ($fermeture === null || $choisie === null) {
            return self::FERME;
        }

        $ouvreLe = $choisie->greaterThan($fermeture) ? $choisie : $fermeture;

        return ['ouvert' => $maintenant->greaterThanOrEqualTo($ouvreLe), 'ouvre_le' => $ouvreLe];
    }

    private function fermeture(User $eleve, Evaluation $evaluation): ?CarbonInterface
    {
        $lecture = $this->fenetres->fetchWindow($eleve, $evaluation);
        if (! $lecture['fetched']) {
            return null;
        }

        return self::instant($lecture['window']['end_at'] ?? null) ?? $evaluation->finPrevue();
    }

    private static function instant(mixed $valeur): ?CarbonInterface
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur);
        } catch (InvalidFormatException) {
            // Une fin illisible n'est pas une fin : la ligne prend le relais.
            return null;
        }
    }
}
