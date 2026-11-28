<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Enums\EtatCodeInscription;
use App\Models\Classe;
use App\Services\Catalogue\CapaciteDeClasse;

/**
 * Une ligne de la collection des classes locales (#905), sortie du contrôleur de liste pour
 * que l'archivage (#1652) rende la MÊME ligne : l'écran la remplace sur place, sans relire la
 * page.
 *
 * `effectif` est la CAPACITÉ (#1115, #1358) : `null` quand elle n'est pas renseignée, jamais un
 * zéro qui se lirait « aucune place ». Le code ne sort en clair que s'il est actif.
 * `archivee_le` : la date d'archive, `null` pour une classe active (#1652). `description` : pour
 * que l'écran préremplisse sa modification sans relire la fiche (front#659).
 */
final class ClasseLocaleEnListe
{
    /** @return array{id: int, libelle: string|null, code: string|null, description: string|null, training_session_id: int|null, effectif: int|null, code_inscription: array{etat: string, valeur: ?string}, archivee_le: string|null} */
    public static function de(Classe $classe): array
    {
        $etat = EtatCodeInscription::depuis($classe->code_inscription, $classe->code_inscription_revoque_le);

        return [
            'id' => $classe->id,
            'libelle' => $classe->libelle,
            'code' => $classe->code,
            'description' => $classe->description,
            'training_session_id' => $classe->training_session_id,
            'effectif' => CapaciteDeClasse::lue($classe),
            'code_inscription' => ['etat' => $etat->value, 'valeur' => $etat->valeurVisible($classe->code_inscription)],
            'archivee_le' => $classe->archivee_le?->toIso8601String(),
        ];
    }
}
