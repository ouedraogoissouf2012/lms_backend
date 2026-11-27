<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeMatiereDejaEmploye;
use App\Models\Matiere;

/**
 * Créer une matière SANS KLASSCI (#797, #848).
 *
 * ## Le manque que ce service comble
 *
 * Avant lui, le seul créateur d'une `Matiere` du dépôt était
 * `ClasseMatieresSynchronizer` — la synchronisation KLASSCI, qui écrit d'ailleurs
 * par `new Matiere()` + `save()`, si bien qu'un `grep` sur `updateOrCreate` ne le
 * voyait pas. Une école autonome n'avait donc aucune matière, jamais : un
 * formateur n'avait rien à enseigner.
 *
 * La classe était livrée par #860 ; la matière est le second des trois lots de
 * l'ADR, et le premier à exiger une migration avant son écrivain.
 *
 * ## La garde est ICI, au point d'écriture
 *
 * Par `PorteDuCatalogueLocal` (#910), la seule porte du catalogue local : elle
 * dit pourquoi la garde vit au point d'appel, et dans quel ordre.
 *
 * ## Le code (#1653)
 *
 * Unique dans l'établissement pour une matière LOCALE, et un code vide devient
 * NULL : `CodeDeMatiere` les tient, commun à la création et à la modification.
 * (Jusqu'à #1653 ce service n'en posait aucun : aucun accès par code n'existait ;
 * l'écran de #654 a montré qu'un admin distingue ses matières par leur code.)
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalMatiereCreator
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
    ) {}

    /**
     * @param  array{libelle: string, code?: string|null, description?: string|null, coefficient?: int|null, credit?: int|null}  $donnees
     *
     * @throws BusinessException si l'établissement ne tient pas son catalogue,
     *                           ou si aucun tenant n'est résolu.
     * @throws CodeDeMatiereDejaEmploye 422 sur `code`, déjà employé dans l'établissement (#1653).
     */
    public function creer(array $donnees): Matiere
    {
        $institution = $this->porte->etablissement();

        return CodeDeMatiere::ecrire(fn (): Matiere => Matiere::create([
            'libelle' => $donnees['libelle'],
            'code' => CodeDeMatiere::normalise($donnees['code'] ?? null),
            'description' => $donnees['description'] ?? null,
            'coefficient' => $donnees['coefficient'] ?? 1,
            'credit' => $donnees['credit'] ?? 1,
            'institution_id' => $institution,
            // JAMAIS d'identifiant KLASSCI inventé : il rendrait la matière
            // indiscernable d'une matière miroitée, et la ferait entrer dans les
            // correspondances par `whereIn('klassci_id', …)` où elle n'a rien à
            // faire.
            'klassci_id' => null,
        ]));
    }
}
