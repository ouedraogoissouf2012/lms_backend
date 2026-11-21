<?php

declare(strict_types=1);

namespace App\Support\Periode;

use Illuminate\Support\Carbon;

/**
 * La fenêtre d'inscription d'une Période (#1208) : `enrollment_opens_at` et
 * `enrollment_closes_at`, lues au JOUR, bornes INCLUSIVES, dans le fuseau de
 * l'application (UTC, l'heure légale de la Côte d'Ivoire).
 *
 * Une seule comparaison, lue par deux lecteurs qui ne peuvent donc plus diverger :
 * - la porte des codes (`ClasseOuverteParCode`) ;
 * - la phase (`TrainingSessionPhase`).
 *
 * ## Une borne nulle ne tranche rien
 *
 * Elle n'est ni « pas encore ouverte » ni « déjà fermée ». Chaque lecteur dit ce
 * que le nul vaut pour lui, et ils diffèrent volontairement :
 * - la PORTE : nul = pas de borne. Les cinq dates sont facultatives ; une école
 *   qui n'en saisit aucune doit pouvoir inscrire dès la publication ;
 * - la PHASE : sans ouverture, pas d'« inscriptions ouvertes ». Son principe est
 *   que les dates absentes ne font pas basculer de phase (ADR-711-04).
 */
final class FenetreDInscription
{
    public function __construct(
        private readonly ?Carbon $ouverture,
        private readonly ?Carbon $fermeture,
    ) {}

    /** Aujourd'hui est AVANT le jour d'ouverture ; le jour même est ouvert dès minuit. */
    public function pasEncoreOuverte(): bool
    {
        return $this->ouverture !== null
            && Carbon::today()->lessThanOrEqualTo($this->ouverture->copy()->startOfDay());
    }

    /** Aujourd'hui est APRÈS le jour de fermeture ; le jour même reste ouvert jusqu'à minuit. */
    public function dejaFermee(): bool
    {
        return $this->fermeture !== null
            && Carbon::today()->greaterThanOrEqualTo($this->fermeture->copy()->startOfDay());
    }
}
