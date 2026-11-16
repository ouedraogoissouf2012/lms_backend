<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * La fenêtre temporelle de la visio d'une séance, écrite UNE fois : l'affichage
 * ({@see SeanceVisioEnricher::buildVisioWindow()}) et les actions (démarrer, activer,
 * rebasculer, rejoindre) la lisent ici, et nulle part ailleurs.
 *
 * - Démarrer : de `début − 15 min` à `fin + 30 min` (`can_start`). Au-delà, la séance
 *   est PASSÉE : le serveur refuse, 409 `seance_past` (signalé par le user).
 * - Rejoindre : la visio démarrée à temps reste joignable 4 h (`is_accessible`) ; un
 *   cours qui déborde n'est pas coupé.
 *
 * La fin d'une séance : la fin RÉELLE si on la connaît (programmation KLASSCI, ou
 * `seances.fin_seance` qui la garde), sinon `début + 2 h` pour une séance LOCALE
 * (l'estimation de l'affichage, {@see SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES}).
 * Une ligne KLASSCI sans fin connue n'est jamais déclarée passée : échec ouvert,
 * plutôt qu'une fin inventée qui couperait un cours.
 */
final class FenetreVisio
{
    public const AVANT_MINUTES = 15;

    public const APRES_MINUTES = 30;

    /** Durée pendant laquelle une visio démarrée reste joignable. */
    public const VISIO_ACTIVE_HEURES = 4;

    public const REASON = 'seance_past';

    public const MESSAGE = 'Séance passée : la visio ne peut plus être démarrée.';

    public static function peutDemarrer(CarbonInterface $debut, CarbonInterface $fin, CarbonInterface $maintenant): bool
    {
        return $maintenant->greaterThanOrEqualTo($debut->copy()->subMinutes(self::AVANT_MINUTES))
            && ! self::estPassee($fin, $maintenant);
    }

    /** La séance est passée : sa fin est dépassée de plus de la marge. */
    public static function estPassee(CarbonInterface $fin, CarbonInterface $maintenant): bool
    {
        return $maintenant->greaterThan($fin->copy()->addMinutes(self::APRES_MINUTES));
    }

    /** `is_in_window` : de `début − 15 min` à la fin, sans la marge. */
    public static function dansLaFenetre(CarbonInterface $debut, CarbonInterface $fin, CarbonInterface $maintenant): bool
    {
        return $maintenant->greaterThanOrEqualTo($debut->copy()->subMinutes(self::AVANT_MINUTES))
            && ! $maintenant->greaterThan($fin);
    }

    /** La visio, active, a démarré il y a moins de 4 h. */
    public static function visioEncoreActive(bool $active, mixed $demarreeLe, CarbonInterface $maintenant): bool
    {
        $debut = $active ? self::instant($demarreeLe) : null;

        return $debut !== null && $maintenant->lessThan($debut->copy()->addHours(self::VISIO_ACTIVE_HEURES));
    }

    /** Une ligne `seances` dont la fin, connue, est dépassée de plus de la marge. */
    public static function lignePassee(Seance $ligne, CarbonInterface $maintenant): bool
    {
        $fin = self::finDe($ligne);

        return $fin !== null && self::estPassee($fin, $maintenant);
    }

    /** Rejoindre : `is_accessible` faux, du côté du passé (fin dépassée, visio plus active depuis moins de 4 h). */
    public static function visioInaccessible(Seance $ligne, CarbonInterface $maintenant): bool
    {
        $fin = self::finDe($ligne);

        return $fin !== null && $maintenant->greaterThan($fin)
            && ! self::visioEncoreActive($ligne->visio_status === 'active', $ligne->visio_started_at, $maintenant);
    }

    /** La fin d'une ligne : réelle si connue, estimée pour une séance LOCALE, sinon inconnue. */
    public static function finDe(Seance $ligne): ?CarbonInterface
    {
        $reelle = self::instant($ligne->fin_seance);
        if ($reelle !== null) {
            return $reelle;
        }

        $debut = $ligne->klassci_seance_id === null ? self::instant($ligne->date_seance) : null;

        return $debut?->copy()->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);
    }

    /**
     * Les bornes d'une séance de l'emploi du temps KLASSCI (`programmation`) :
     * `[début, fin réelle]`, chacune `null` si KLASSCI se tait.
     *
     * @param  array<string, mixed>  $seance
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null}
     */
    public static function bornesKlassci(array $seance): array
    {
        $programmation = SeanceProgrammationNormalizer::blocKlassci(KlassciPayload::asArray($seance['programmation'] ?? null));

        return [self::instant($programmation['heure_debut'] ?? null), self::instant($programmation['heure_fin'] ?? null)];
    }

    /**
     * Une séance de l'emploi du temps KLASSCI est passée : sa fin réelle, sinon
     * `début + 2 h` comme l'affichage ({@see SeanceHoraireResolver::fenetreOuverture()}).
     *
     * @param  array<string, mixed>  $seance
     */
    public static function seanceKlassciPassee(array $seance, CarbonInterface $maintenant): bool
    {
        [$debut, $fin] = self::bornesKlassci($seance);
        $fin ??= $debut?->copy()->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

        return $fin !== null && self::estPassee($fin, $maintenant);
    }

    /**
     * Les séances d'une liste « à venir » qui ne sont pas passées. Chaque élément porte
     * `programmation.heure_debut` / `heure_fin` (ISO) : le projeté local, le cache de la
     * gestion et l'emploi du temps KLASSCI ont tous cette forme.
     *
     * @param  iterable<array-key, array<string, mixed>>  $seances
     * @return list<array<string, mixed>>
     */
    public static function sansLesPassees(iterable $seances, CarbonInterface $maintenant): array
    {
        $gardees = [];
        foreach ($seances as $seance) {
            // Déjà normalisée par son producteur : lue telle quelle. La réaligner sur
            // sa date ramènerait la veille la fin d'un cours qui passe minuit.
            $programmation = KlassciPayload::asArray($seance['programmation'] ?? null);
            $debut = self::instant($programmation['heure_debut'] ?? null);
            $fin = self::instant($programmation['heure_fin'] ?? null)
                ?? $debut?->copy()->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

            if ($fin === null || ! self::estPassee($fin, $maintenant)) {
                $gardees[] = $seance;
            }
        }

        return $gardees;
    }

    /** @return array{status: int, payload: array{success: false, message: string, reason: string}} */
    public static function refus(): array
    {
        return ['status' => 409, 'payload' => ['success' => false, 'message' => self::MESSAGE, 'reason' => self::REASON]];
    }

    private static function instant(mixed $valeur): ?CarbonInterface
    {
        if ($valeur instanceof CarbonInterface) {
            return $valeur;
        }
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur);
        } catch (Throwable) {
            return null;
        }
    }
}
