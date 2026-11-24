<?php

declare(strict_types=1);

namespace App\Services\Seances;

/**
 * La séance désignée dans l'espace d'identifiants de l'école, et lui SEUL (#1154, étape C ;
 * ADR-760-01) : un id porte un seul sens par porte.
 *
 * - École KLASSCI : l'id KLASSCI, sans repli local ({@see KlassciSeanceDesigneeStricte}) ;
 * - école autonome : l'id local, son seul espace ({@see LocalSeanceDesignee}).
 *
 * Elle sert la porte historique de suppression, dont le seul appelant est le calendrier de
 * gestion. {@see SeanceDesignee} reste, pour les routes qui reçoivent encore deux espaces
 * (désactivation, modification ; issue sœur de l'étape D).
 */
interface SeanceDesigneeStrictement extends SeanceDesignee {}
