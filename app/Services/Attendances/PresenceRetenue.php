<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Enums\AttendanceMark;
use App\Models\ESBTPAttendance;
use Illuminate\Database\Eloquent\Builder;

/**
 * La seule règle qui dit ce qu'une ligne de présence compte (#1649, décision de la superviseure du 27/11).
 *
 * - La MARQUE de l'appel l'emporte sur le statut visio quand elle existe : l'enseignant qui marque un élève absent
 *   a le dernier mot, même si l'élève s'est « connecté ».
 * - Sans marque, le statut visio s'applique : connecté ou déconnecté (il est venu), présent ; exclu, absent.
 * - Un statut NUL (ligne d'appel sans visio, #1649) n'est jamais compté présent.
 *
 * Deux formes d'une même règle, liées par un test d'équivalence (`PresenceRetenueTest`) : sur une ligne
 * ({@see de()}, {@see estPresente()}) et en requête ({@see presentes()}). Le rapport et le tableau de bord la lisent ;
 * aucun ne recompte à sa façon. Le calcul unique du TAUX, réutilisé par l'attestation, reste à #1683.
 */
final class PresenceRetenue
{
    /** Les statuts visio d'un élève venu à la séance. */
    private const STATUTS_VENUS = ['connected', 'disconnected'];

    /** Les marques qui comptent présent : présent, et en retard. */
    private const MARQUES_PRESENTES = [AttendanceMark::Present, AttendanceMark::Late];

    /** La présence retenue d'une ligne, ou `null` quand rien ne la dit. */
    public static function de(ESBTPAttendance $ligne): ?AttendanceMark
    {
        if ($ligne->mark instanceof AttendanceMark) {
            return $ligne->mark;
        }

        return match ($ligne->status) {
            'connected', 'disconnected' => AttendanceMark::Present,
            'kicked' => AttendanceMark::Absent,
            default => null,
        };
    }

    public static function estPresente(ESBTPAttendance $ligne): bool
    {
        return in_array(self::de($ligne), self::MARQUES_PRESENTES, true);
    }

    /**
     * La même règle en requête : les lignes qui comptent présentes.
     *
     * @param  Builder<ESBTPAttendance>  $lignes
     * @return Builder<ESBTPAttendance>
     */
    public static function presentes(Builder $lignes): Builder
    {
        $marques = array_map(static fn (AttendanceMark $m): string => $m->value, self::MARQUES_PRESENTES);

        return $lignes->where(static fn (Builder $q) => $q
            ->whereIn($q->qualifyColumn('mark'), $marques)
            ->orWhere(static fn (Builder $sansMarque) => $sansMarque
                ->whereNull($sansMarque->qualifyColumn('mark'))
                ->whereIn($sansMarque->qualifyColumn('status'), self::STATUTS_VENUS)));
    }

    /**
     * La même règle en EXPRESSION SQL, pour le suivi des présences (#1683) : la marque retenue d'une ligne, en
     * `present`, `late`, `absent` ou `excused`. Pour une séance PASSÉE, rien qui dise une présence est une absence :
     * l'expression rend `absent` là où {@see de()} rend `null` (et pour une ligne absente du LEFT JOIN).
     *
     * Les deux noms de colonne sont des identifiants écrits par l'appelant (jamais une saisie) ; les valeurs viennent
     * de l'énumération et de la constante des statuts, sans aucune entrée extérieure.
     */
    public static function expression(string $colonneMarque, string $colonneStatut): string
    {
        $venus = implode(', ', array_map(static fn (string $s): string => "'{$s}'", self::STATUTS_VENUS));

        return "(CASE WHEN {$colonneMarque} IS NOT NULL THEN {$colonneMarque}"
            ." WHEN {$colonneStatut} IN ({$venus}) THEN '".AttendanceMark::Present->value."'"
            ." ELSE '".AttendanceMark::Absent->value."' END)";
    }
}
