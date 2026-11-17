<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Matiere;
use App\Services\Catalogue\CoupleDuProgramme;
use Carbon\Carbon;

/**
 * Les règles de fond d'une séance locale, écrites UNE fois : la création
 * ({@see LocalSeanceCreator}, #710, #846) et la modification
 * ({@see LocalModificationDeSeance}, #1121) les appellent, sans copie.
 *
 * Chaque lecture est bornée EXPLICITEMENT à l'établissement : le scope
 * multi-tenant est fail-OPEN, s'y fier serait dépendre d'une garde qui se tait
 * quand aucun tenant n'est résolu. Les refus ne distinguent pas « n'existe pas »
 * de « appartient à une autre école » : les séparer dirait à un établissement
 * quels identifiants existent chez ses voisins.
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
final class AttributsDeSeanceLocale
{
    public function __construct(private readonly CoupleDuProgramme $couple) {}

    /**
     * L'instant de la séance, rangé dans le fuseau de l'application : un
     * décalage fait foi (D4), il n'est pas ignoré par le format de la base.
     */
    public function instant(mixed $date): string
    {
        return is_string($date) && $date !== ''
            ? Carbon::parse($date)->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s')
            : '';
    }

    /**
     * La classe, de l'établissement, ou `null` sans identifiant.
     *
     * @throws BusinessException 404 `class_out_of_scope`
     */
    public function classeDeLEtablissement(mixed $classeId, ?int $institution): ?int
    {
        if (! is_numeric($classeId)) {
            return null;
        }

        $existe = Classe::query()->withoutGlobalScopes()
            ->where('id', (int) $classeId)
            ->where('institution_id', $institution)
            ->exists();

        if (! $existe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404, null, 'class_out_of_scope');
        }

        return (int) $classeId;
    }

    /**
     * La matière, de l'établissement, ou `null` sans identifiant.
     *
     * @throws BusinessException 404 `subject_out_of_scope`
     */
    public function matiereDeLEtablissement(mixed $matiereId, ?int $institution): ?Matiere
    {
        if (! is_numeric($matiereId)) {
            return null;
        }

        $matiere = Matiere::query()->withoutGlobalScopes()
            ->whereKey((int) $matiereId)
            ->where('institution_id', $institution)
            ->first();

        if ($matiere === null) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404, null, 'subject_out_of_scope');
        }

        return $matiere;
    }

    /**
     * Avec une classe ET une matière, la matière est au programme de la classe :
     * la règle du couple, partagée avec la programmation d'une évaluation.
     *
     * @throws BusinessException 422 `subject_not_in_class_program`
     */
    public function exigerLeCouple(?int $classeId, ?int $matiereId): void
    {
        if ($classeId !== null && $matiereId !== null && ! $this->couple->estProgramme($classeId, $matiereId)) {
            throw new BusinessException(
                'Cette matière n\'est pas au programme de cette classe.',
                422,
                null,
                'subject_not_in_class_program',
            );
        }
    }
}
