<?php

declare(strict_types=1);

namespace App\Services\Seances\Conflits;

use App\Exceptions\BusinessException;
use App\Exceptions\ConflitDHoraireException;
use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\SeanceCalendarProjection;
use App\Services\Visio\VisioActorAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Une même personne n'anime pas deux visios à la fois, une même classe n'en suit pas deux
 * (#1668, sans salle : le produit est 100 % en ligne).
 *
 * ## Le chevauchement
 *
 * `[début, fin effective)` : `fin_seance`, à défaut début +
 * {@see SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES}, la règle que le calendrier lit
 * déjà. Une seule durée supposée dans le dépôt : deux règles divergeraient. Aucune fin
 * n'est écrite par défaut (D1411). Deux créneaux contigus ne se gênent pas.
 *
 * Seules comptent les séances ACTIVES et non supprimées de l'établissement, bornées
 * explicitement : le scope de l'école ne filtre rien sans tenant.
 *
 * ## Les deux identités
 *
 * - L'enseignant : la règle de propriété des séances,
 *   {@see VisioActorAuthorization::limitToTeacherSeances()} (`created_by`, ou ses
 *   identités KLASSCI pour une séance miroir d'une école basculée). Pas une seconde règle.
 * - La classe : `classe_id`, ou `klassci_classe_id` = son `klassci_id` (#1709), chacune
 *   dans son espace. Aucun test du mode : c'est la donnée qui porte l'identité.
 *
 * ## Le forçage, et la concurrence
 *
 * `forcer_conflit` passe outre pour la gestion ; l'enseignant reçoit 403. La vérification
 * et l'écriture se font sous le verrou de l'enseignant et de la classe ({@see verrouiller()}),
 * dans la transaction de l'appelant : deux créations simultanées ne passent pas toutes deux.
 */
final class ConflitsDHoraire
{
    public const REASON = 'seance_schedule_conflict';

    public const REASON_FORCAGE = 'conflict_override_forbidden';

    public function __construct(private readonly VisioActorAuthorization $proprietes) {}

    /**
     * Le créneau d'une séance, avec sa fin effective et les identités de sa classe.
     */
    public function creneau(
        int $institutionId,
        CarbonImmutable $debut,
        ?CarbonImmutable $fin,
        ?User $enseignant,
        ?int $classeId,
        ?int $seanceExclue = null,
    ): CreneauDeSeance {
        $klassciId = $classeId === null ? null : Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->whereKey($classeId)
            ->value('klassci_id');

        return new CreneauDeSeance(
            $institutionId,
            $debut,
            self::finEffective($debut, $fin),
            $enseignant,
            $classeId,
            is_numeric($klassciId) ? (int) $klassciId : null,
            $seanceExclue,
        );
    }

    /** La fin d'une séance pour le calcul : la sienne, à défaut la durée supposée. */
    public static function finEffective(CarbonImmutable $debut, ?CarbonImmutable $fin): CarbonImmutable
    {
        return $fin ?? $debut->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);
    }

    /**
     * Le verrou de l'enseignant et de la classe. À appeler DANS la transaction de
     * l'écriture, avant {@see exigerAucun()}.
     */
    public function verrouiller(CreneauDeSeance $creneau): void
    {
        if ($creneau->enseignant !== null) {
            User::query()->withoutGlobalScopes()->whereKey($creneau->enseignant->id)->lockForUpdate()->first();
        }
        if ($creneau->classeId !== null) {
            Classe::query()->withoutGlobalScopes()->whereKey($creneau->classeId)->lockForUpdate()->first();
        }
    }

    /**
     * @throws ConflitDHoraireException 409 `seance_schedule_conflict`
     * @throws BusinessException 403 `conflict_override_forbidden`
     */
    public function exigerAucun(CreneauDeSeance $creneau, User $acteur, bool $forcer): void
    {
        $conflits = $this->trouver($creneau);
        if ($conflits === [] || ($forcer && $acteur->isManager())) {
            return;
        }
        if ($forcer) {
            throw new BusinessException('Seule la gestion peut passer outre un conflit d\'horaire.', 403, null, self::REASON_FORCAGE);
        }

        throw new ConflitDHoraireException($conflits);
    }

    /**
     * Les séances qui chevauchent le créneau, par date puis par id, avec leurs motifs.
     *
     * @return list<array{id: int, titre: ?string, debut: string, fin: string, motifs: list<string>}>
     */
    public function trouver(CreneauDeSeance $creneau): array
    {
        /** @var array<int, array{0: Seance, 1: list<string>}> $parSeance */
        $parSeance = [];
        $enseignant = $creneau->enseignant;
        if ($enseignant !== null) {
            $memeEnseignant = $this->chevauchantes($creneau)
                ->where(fn (Builder $siennes) => $this->proprietes->limitToTeacherSeances($siennes, $enseignant));
            foreach ($memeEnseignant->get() as $seance) {
                $parSeance[$seance->id] = [$seance, ['enseignant']];
            }
        }
        if ($creneau->classeId !== null) {
            foreach ($this->chevauchantes($creneau)->where(fn (Builder $q) => $this->deLaClasse($q, $creneau))->get() as $seance) {
                $parSeance[$seance->id] = [$seance, [...($parSeance[$seance->id][1] ?? []), 'classe']];
            }
        }

        return $this->enConflits($parSeance);
    }

    /** @return Builder<Seance> */
    private function chevauchantes(CreneauDeSeance $creneau): Builder
    {
        $debutSansFinAuPlusTot = $creneau->debut->subHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

        return Seance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $creneau->institutionId)
            ->where('is_active', true)
            ->when($creneau->seanceExclue !== null, fn (Builder $q) => $q->whereKeyNot($creneau->seanceExclue))
            ->where('date_seance', '<', $creneau->fin)
            ->where(fn (Builder $finApresLeDebut) => $finApresLeDebut
                ->where('fin_seance', '>', $creneau->debut)
                ->orWhere(fn (Builder $sansFin) => $sansFin
                    ->whereNull('fin_seance')
                    ->where('date_seance', '>', $debutSansFinAuPlusTot)));
    }

    /** @param Builder<Seance> $seances */
    private function deLaClasse(Builder $seances, CreneauDeSeance $creneau): void
    {
        $seances->where('classe_id', $creneau->classeId);
        if ($creneau->classeKlassciId !== null) {
            $seances->orWhere('klassci_classe_id', $creneau->classeKlassciId);
        }
    }

    /**
     * @param  array<int, array{0: Seance, 1: list<string>}>  $parSeance
     * @return list<array{id: int, titre: ?string, debut: string, fin: string, motifs: list<string>}>
     */
    private function enConflits(array $parSeance): array
    {
        uasort($parSeance, static fn (array $a, array $b): int => [$a[0]->date_seance, $a[0]->id] <=> [$b[0]->date_seance, $b[0]->id]);

        return array_values(array_map(static function (array $entree): array {
            [$seance, $motifs] = $entree;
            $debut = CarbonImmutable::parse($seance->date_seance);
            $fin = $seance->fin_seance !== null ? CarbonImmutable::parse($seance->fin_seance) : null;

            return [
                'id' => (int) $seance->id,
                'titre' => is_string($seance->titre) ? $seance->titre : null,
                'debut' => $debut->toIso8601String(),
                'fin' => self::finEffective($debut, $fin)->toIso8601String(),
                'motifs' => $motifs,
            ];
        }, $parSeance));
    }
}
