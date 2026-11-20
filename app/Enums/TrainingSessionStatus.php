<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une Période — la décision HUMAINE (#800, ADR-711-04).
 *
 * ## Ce qui se stocke, et ce qui se dérive
 *
 * Ce statut est stocké parce qu'il traduit un choix : publier, annuler, clore.
 * La **phase** — à venir, inscriptions ouvertes, en cours, terminée — n'est pas
 * ici : elle se déduit des cinq dates, et n'a donc aucune colonne. Mélanger les
 * deux imposerait un job nocturne pour faire avancer l'étiquette, qui finirait
 * par diverger des dates en silence.
 *
 * Canvas ne stocke que des décisions humaines ; Masteriyo dérive les phases des
 * dates. Le mélange est le défaut que cette séparation évite.
 *
 * ## « Reportée » n'est pas un état
 *
 * Reporter une Période, c'est rebaser son calendrier — une opération, pas un
 * statut. L'ajouter ici créerait un état dont personne ne saurait sortir.
 *
 * @see docs/adr/2026-09-06-711-04-status-phase.md
 */
enum TrainingSessionStatus: string
{
    case Brouillon = 'brouillon';
    case Publiee = 'publiee';
    case Annulee = 'annulee';
    case Cloturee = 'cloturee';
    case Archivee = 'archivee';
    case Purgee = 'purgee';

    /**
     * Une Période annulée, clôturée, archivée ou purgée ne reçoit plus personne
     * (#897) : on n'y rattache plus de classe (`RattachementDeClasse`, #1208). Une
     * décision humaine, lue du statut ; la fenêtre de dates est une autre règle.
     *
     * Une Période en brouillon n'est PAS fermée : on y rattache des classes pendant
     * qu'on la prépare. Qu'elle n'ouvre pas encore les codes, c'est
     * {@see self::ouvreLesCodes()}.
     */
    public function fermeLesInscriptions(): bool
    {
        return match ($this) {
            self::Annulee, self::Cloturee, self::Archivee, self::Purgee => true,
            self::Brouillon, self::Publiee => false,
        };
    }

    /**
     * Les codes d'inscription des classes de cette Période ouvrent-ils quelque
     * chose (#1208) ? Seule la PUBLICATION engage des inscriptions : une Période
     * en brouillon n'ouvre pas les codes (décision du user, 20/11), une Période
     * close non plus (#897). Lue par la seule porte des codes,
     * `ClasseOuverteParCode` ; la fenêtre de dates s'y ajoute.
     */
    public function ouvreLesCodes(): bool
    {
        return $this === self::Publiee;
    }

    /**
     * Les statuts que la liste de gestion écarte (#1208). Une Période archivée n'y
     * revient que sur demande ; une purgée jamais : la purge n'est pas une décision
     * humaine, c'est la fin d'une politique de rétention.
     *
     * @return list<self>
     */
    public static function horsDeLaListe(bool $avecLesArchivees): array
    {
        return $avecLesArchivees ? [self::Purgee] : [self::Archivee, self::Purgee];
    }
}
