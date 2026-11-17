<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;

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
 */
final class LocalModificationDeSeance implements ModificationDeSeance
{
    public const VISIO_ACTIVE_REASON = 'seance_visio_active';

    public function __construct(
        private readonly LocalSeanceDesignee $seances,
        private readonly VisioActorAuthorization $acteurs,
        private readonly AttributsDeSeanceLocale $attributs,
    ) {}

    public function modifier(int $seanceId, User $acteur, array $champs): Seance
    {
        $seance = $this->seances->pour($seanceId, $acteur);

        if (! $seance instanceof Seance) {
            throw new BusinessException('Séance non trouvée', 404);
        }

        $this->exigerLeDroit($seance, $acteur);
        $seance->fill($this->attributsModifies($seance, $acteur, $champs))->save();

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
     * Les seuls champs reçus : un PATCH ne touche pas au reste.
     *
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    private function attributsModifies(Seance $seance, User $acteur, array $champs): array
    {
        $attributs = array_intersect_key($champs, array_flip(['titre', 'classe_nom']));

        if (array_key_exists('date_seance', $champs)) {
            $attributs['date_seance'] = $this->attributs->instant($champs['date_seance']);
        }

        if (array_key_exists('classe_id', $champs)) {
            $classeId = $this->attributs->classeDeLEtablissement($champs['classe_id'], $acteur->institution_id);
            $attributs['classe_id'] = $classeId;
        }

        return $attributs;
    }
}
