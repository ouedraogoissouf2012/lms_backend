<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le type d'une séance (#1668) : la liste de KLASSCI (CM, TD, TP, projet, travail
 * personnel, examen), fermée. Une séance sans type reste à `null` : rien n'est fabriqué.
 *
 * ## Depuis KLASSCI : seules les valeurs sans ambiguïté
 *
 * La charge de l'emploi du temps porte `type_cours`. La seule valeur observée en
 * production (25/11, D1411) est « course », qui ne dit pas lequel des types c'est. Seules
 * CM, TD et TP, sigles sans autre lecture possible, sont reprises, quelle que soit la
 * casse. Toute autre valeur donne `null`.
 */
enum TypeDeSeance: string
{
    case Cm = 'cm';
    case Td = 'td';
    case Tp = 'tp';
    case Projet = 'projet';
    case TravailPersonnel = 'travail_personnel';
    case Examen = 'examen';

    /** Les sigles de KLASSCI repris sans ambiguïté. */
    private const SIGLES_KLASSCI = ['cm' => self::Cm, 'td' => self::Td, 'tp' => self::Tp];

    public static function depuisKlassci(mixed $typeCours): ?self
    {
        if (! is_string($typeCours)) {
            return null;
        }

        return self::SIGLES_KLASSCI[strtolower(trim($typeCours))] ?? null;
    }
}
