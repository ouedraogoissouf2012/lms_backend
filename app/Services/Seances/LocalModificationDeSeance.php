<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Exceptions\ConflitDHoraireException;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Conflits\ConflitsDHoraire;
use App\Services\Visio\VisioActorAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * École autonome (#1121) : l'enseignant ne dépend d'aucun KLASSCI, il modifie le
 * titre, la date et l'heure, la classe d'une séance (décision du user du 17/11).
 *
 * - Qui : la règle de la suppression, {@see VisioActorAuthorization::canManage()}
 *   (l'auteur ou la gestion de l'établissement).
 * - Quand : jamais une séance passée (#1107, `seance_past`), jamais pendant une
 *   visio en cours (`seance_visio_active`) : changer l'heure ou la classe d'un
 *   cours qui se tient n'a pas de sens.
 * - Quoi : les règles de fond de la création ({@see AttributsDeSeanceLocale}). La
 *   matière ne se change pas ; une classe nouvelle doit l'avoir au programme.
 *   Déplacer une séance dans le passé reste permis, comme à la création.
 *
 * Une école autonome venue de KLASSCI garde des séances KLASSCI : elles se
 * modifient comme les autres, l'école ne dépend plus de KLASSCI.
 *
 * #1668 : le type et la fin se modifient aussi. Un changement de début, de fin ou de
 * classe est refusé s'il fait chevaucher la séance ({@see ConflitsDHoraire}) ; la séance
 * ne se gêne pas elle-même. Son enseignant est son auteur (`created_by`) : une séance
 * miroir sans auteur n'est vérifiée que pour sa classe (dette déclarée en PR).
 */
final class LocalModificationDeSeance implements ModificationDeSeance
{
    public const VISIO_ACTIVE_REASON = 'seance_visio_active';

    public const FIN_AVANT_DEBUT_REASON = 'seance_end_before_start';

    public function __construct(
        private readonly LocalSeanceDesignee $seances,
        private readonly VisioActorAuthorization $acteurs,
        private readonly AttributsDeSeanceLocale $attributs,
        private readonly ConflitsDHoraire $conflits,
        private readonly ConnectionInterface $db,
    ) {}

    public function modifier(int $seanceId, User $acteur, array $champs): Seance
    {
        $seance = $this->seances->pour($seanceId, $acteur);

        if (! $seance instanceof Seance) {
            throw new BusinessException('Séance non trouvée', 404);
        }

        $this->exigerLeDroit($seance, $acteur);
        $seance->fill($this->attributsModifies($seance, $acteur, $champs));
        // La règle `boolean` admet true, 1 et "1" : les trois forcent.
        $forcer = filter_var($champs['forcer_conflit'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $this->db->transaction(function () use ($seance, $acteur, $forcer): void {
            if ($seance->isDirty(['date_seance', 'fin_seance', 'classe_id'])) {
                $this->exigerLeCreneau($seance, $acteur, $forcer);
            }
            $seance->save();
        });

        return $seance->refresh();
    }

    public function peutModifier(Seance $ligne, User $lecteur): bool
    {
        return $this->acteurs->canManage($ligne, $lecteur)
            && ! FenetreVisio::lignePassee($ligne, now())
            && ! $ligne->visio_active;
    }

    /** Les refus de {@see peutModifier()}, chacun avec son statut et son motif. */
    private function exigerLeDroit(Seance $seance, User $acteur): void
    {
        if (! $this->acteurs->canManage($seance, $acteur)) {
            throw new BusinessException('Vous ne pouvez pas modifier cette séance.', 403);
        }

        if (FenetreVisio::lignePassee($seance, now())) {
            throw new BusinessException('Séance passée : elle ne se modifie plus.', 409, null, FenetreVisio::REASON);
        }

        if ($seance->visio_active) {
            throw new BusinessException('La visioconférence de cette séance est en cours : elle ne se modifie pas.', 409, null, self::VISIO_ACTIVE_REASON);
        }
    }

    /**
     * Le créneau FINAL de la séance (valeurs reçues, sinon celles de la ligne), sous le verrou
     * de son enseignant et de sa classe.
     *
     * @throws BusinessException 422 `seance_end_before_start`, 403 `conflict_override_forbidden`
     * @throws ConflitDHoraireException 409 `seance_schedule_conflict`
     */
    private function exigerLeCreneau(Seance $seance, User $acteur, bool $forcer): void
    {
        $debut = CarbonImmutable::parse($seance->date_seance);
        $fin = $seance->fin_seance !== null ? CarbonImmutable::parse($seance->fin_seance) : null;
        if ($fin !== null && $fin <= $debut) {
            throw new BusinessException('La fin de la séance doit suivre son début.', 422, null, self::FIN_AVANT_DEBUT_REASON);
        }
        $auteur = $seance->created_by !== null
            ? User::query()->where('institution_id', $seance->institution_id)->find($seance->created_by)
            : null;
        $creneau = $this->conflits->creneau((int) $seance->institution_id, $debut, $fin, $auteur, $seance->classe_id, (int) $seance->id);

        $this->conflits->verrouiller($creneau);
        $this->conflits->exigerAucun($creneau, $acteur, $forcer);
    }

    /**
     * Les seuls champs reçus : un PATCH ne touche pas au reste.
     *
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    private function attributsModifies(Seance $seance, User $acteur, array $champs): array
    {
        $attributs = array_intersect_key($champs, array_flip(['titre', 'classe_nom', 'type_seance']));

        foreach (['date_seance', 'fin_seance'] as $instant) {
            if (array_key_exists($instant, $champs)) {
                $valeur = $champs[$instant];
                $attributs[$instant] = is_string($valeur) && $valeur !== '' ? $this->attributs->instant($valeur) : null;
            }
        }

        if (array_key_exists('classe_id', $champs)) {
            $classeId = $this->attributs->classeDeLEtablissement($champs['classe_id'], $acteur->institution_id);
            $this->attributs->exigerLeCouple($classeId, $seance->matiere_id);
            $attributs['classe_id'] = $classeId;
        }

        return $attributs;
    }
}
