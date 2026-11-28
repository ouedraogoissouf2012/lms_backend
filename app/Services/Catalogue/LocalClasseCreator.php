<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeClasseDejaEmploye;
use App\Models\Classe;
use Illuminate\Validation\ValidationException;

/**
 * Créer une classe SANS KLASSCI (#848).
 *
 * ## Le manque que ce service comble
 *
 * Avant lui, le seul créateur d'une `Classe` dans tout `app/` était
 * `ClasseSyncService:238` — la synchronisation KLASSCI. Une école autonome
 * n'avait donc aucune classe, jamais. Et l'import CSV, qui crée pourtant de
 * vrais comptes (`ImportApplyService:147`), sort en silence quand la classe est
 * introuvable (`:165-170`) : il produisait des apprenants qui n'appartenaient à
 * rien.
 *
 * ## La garde est ICI, au point d'écriture
 *
 * Par `PorteDuCatalogueLocal` (#910), la seule porte du catalogue local : elle
 * dit pourquoi la garde vit au point d'appel, et dans quel ordre.
 *
 * ## Le code vide devient nul, et ce n'est pas cosmétique
 *
 * `classes.code` porte désormais un unique `(institution_id, code)`. SQL
 * autorise les `NULL` en doublon mais pas les chaînes vides : sans cette
 * normalisation, la DEUXIÈME classe créée sans code échouerait en collision.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalClasseCreator
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly RattachementDeClasse $rattachement,
    ) {}

    /**
     * La classe peut naître rattachée à une Période (#1208), validée AVANT l'écriture :
     * une Période refusée ne laisse aucune classe à moitié créée.
     *
     * @param  array{libelle: string, code?: string|null, description?: string|null, effectif?: int|null, training_session_id?: int|null}  $donnees
     *
     * @throws BusinessException si l'établissement ne tient pas son catalogue,
     *                           ou si aucun tenant n'est résolu.
     * @throws ValidationException 422 sur `training_session_id`.
     * @throws CodeDeClasseDejaEmploye 422 sur `code`, déjà employé dans l'établissement (#1521).
     */
    public function creer(array $donnees): Classe
    {
        $institution = $this->porte->etablissement();
        $periode = $this->rattachement->periodeQuiRecoit($institution, $donnees['training_session_id'] ?? null);

        // #1521 : la base tranche l'unique `(institution_id, code)`, sans SELECT préalable ; sa
        // violation, et elle SEULE, devient une saisie invalide (`CodeDeClasse`, partagé avec #1652).
        return CodeDeClasse::ecrire(fn (): Classe => Classe::create([
            'libelle' => $donnees['libelle'],
            'code' => CodeDeClasse::normalise($donnees['code'] ?? null),
            'description' => $donnees['description'] ?? null,
            'effectif' => $donnees['effectif'] ?? 0,
            'institution_id' => $institution,
            'training_session_id' => $periode,
            // JAMAIS d'identifiant KLASSCI : une classe locale n'en a pas, et lui
            // en inventer un la rendrait indiscernable d'une classe miroitée.
            'klassci_id' => null,
        ]));
    }
}
