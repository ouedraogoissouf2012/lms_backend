<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\EvaluationSubmissionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

/**
 * L'échéance d'une {@see \App\Models\EvaluationSubmission} (C28) : le SEUL lieu qui
 * dit si une tentative est échue, en PHP comme en SQL.
 *
 * Décision de l'utilisateur : une copie remise après l'échéance « est refusée mais
 * gardée en brouillon et verrouillée pour toute modification ».
 *
 * - `ends_at` est écrit au démarrage (`EcheanceDeTentative`) et FIGÉ : une
 *   prolongation ne touche que les tentatives suivantes (Q6). Nul pour
 *   l'entraînement, et pour une copie d'avant ce lot : pas d'échéance.
 * - Une marge de {@see self::MARGE_SECONDES} s couvre la remise automatique du
 *   front, qui part jusqu'à 30 s après la fin de la fenêtre (Q2).
 * - Le statut `verrouille` est ÉCRIT à la remise refusée (`RemiseDeCopie`). Une
 *   tentative ouverte échue, jamais remise, se LIT verrouillée partout : liste,
 *   résultats, ma copie, notation (Q4, aucune commande périodique). La lecture
 *   n'écrit rien.
 * - Une copie verrouillée, écrite ou lue, compte comme une tentative.
 *
 * @property EvaluationSubmissionStatus|string|null $status
 * @property Carbon|null $ends_at
 */
trait EcheanceDeLaCopie
{
    /** Le délai de grâce après l'échéance, en secondes. */
    public const MARGE_SECONDES = 60;

    /** Échue : une échéance posée, dépassée de plus que la marge. */
    public function estEchue(?CarbonInterface $maintenant = null): bool
    {
        $echeance = $this->ends_at;

        return $echeance instanceof CarbonInterface
            && ($maintenant ?? Carbon::now())->greaterThan($echeance->copy()->addSeconds(self::MARGE_SECONDES));
    }

    /**
     * Le statut LU : une tentative ouverte échue se lit `verrouille`. L'écriture
     * et les requêtes gardent le statut stocké (`getRawOriginal`).
     *
     * @return Attribute<string|null, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (mixed $stocke): ?string {
            if (! is_string($stocke)) {
                return null;
            }

            return $stocke === EvaluationSubmissionStatus::EnCours->value && $this->estEchue()
                ? EvaluationSubmissionStatus::Verrouille->value
                : $stocke;
        });
    }

    /**
     * Les tentatives encore ouvertes : `en_cours` et pas échues.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOuverte(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), EvaluationSubmissionStatus::EnCours->value)
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('ends_at'))
                ->orWhere($q->qualifyColumn('ends_at'), '>=', self::limiteDesEchues()));
    }

    /**
     * Les tentatives qui comptent pour les reprises et le quota : remises,
     * corrigées, verrouillées, et ouvertes échues.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTentativeComptee(Builder $query): Builder
    {
        $statut = $query->qualifyColumn('status');

        return $query->where(fn (Builder $q) => $q
            ->whereIn($statut, [
                EvaluationSubmissionStatus::Soumis->value,
                EvaluationSubmissionStatus::Corrige->value,
                EvaluationSubmissionStatus::Verrouille->value,
            ])
            ->orWhere(fn (Builder $echue) => $echue
                ->where($statut, EvaluationSubmissionStatus::EnCours->value)
                ->where($q->qualifyColumn('ends_at'), '<', self::limiteDesEchues())));
    }

    /** Une échéance strictement antérieure à cet instant est échue (même règle qu'{@see estEchue()}). */
    private static function limiteDesEchues(): CarbonInterface
    {
        return Carbon::now()->subSeconds(self::MARGE_SECONDES);
    }
}
