<?php

declare(strict_types=1);

namespace App\Services\Seances;

use Carbon\Carbon;

/**
 * Les bornes horaires d'une séance — et la distinction entre ce qu'on AFFICHE
 * et ce qui OUVRE la porte de la visio.
 *
 * ## Pourquoi cette classe existe
 *
 * Deux questions vivaient mêlées dans l'orchestrateur, et leur réponse n'est
 * pas la même :
 *
 * - **« combien de temps dure cette séance ? »** — une donnée. Si l'amont ne
 *   la fournit pas, la réponse honnête est *on ne sait pas*.
 * - **« jusqu'à quand la visio reste-t-elle joignable ? »** — une décision. Il
 *   lui faut une borne, toujours, sinon la séance est réputée terminée dès
 *   qu'elle commence.
 *
 * Les confondre produit l'un des deux défauts : soit on invente une durée et on
 * l'affiche comme un fait (#870 : `duree_minutes = 120` pour tout le monde),
 * soit on laisse la fenêtre vide et la visio devient inatteignable.
 *
 * ## Le piège que cette classe referme
 *
 * `Carbon::parse(null)` rend **maintenant**, sans lever d'erreur. Une séance
 * dont l'heure de fin est inconnue se voyait donc attribuer l'écart entre son
 * début et l'instant de la requête — une durée qui changeait à chaque
 * rafraîchissement.
 *
 * Le repli local est exactement dans ce cas : `seances` porte `date_seance`,
 * un `timestamp` qui donne le DÉBUT réel, mais aucune colonne de fin.
 *
 * Vérifié par tests/Feature/Seances/SeanceDetailSourceTest.php.
 */
final class SeanceHoraireResolver
{
    /**
     * Durée d'ouverture retenue quand l'amont ne fournit pas d'heure de fin.
     *
     * Ce n'est **pas** une donnée : elle ne sort jamais dans `duree_minutes`.
     * Elle borne seulement la fenêtre pendant laquelle la visio reste
     * joignable. Déclarée ici, nommée, et à un seul endroit — au lieu d'un
     * littéral enfoui dans une charge utile.
     */
    private const OUVERTURE_PAR_DEFAUT_HEURES = 2;

    /**
     * La durée à AFFICHER, ou `null` quand elle est inconnue.
     *
     * @param  array<string, mixed>  $programmation
     */
    public function dureeMinutes(array $programmation): ?int
    {
        $debut = $programmation['heure_debut'] ?? null;
        $fin = $programmation['heure_fin'] ?? null;

        if (! is_string($debut) || ! is_string($fin)) {
            return null;
        }

        return (int) Carbon::parse($debut)->diffInMinutes(Carbon::parse($fin));
    }

    /**
     * Les deux bornes de la fenêtre d'OUVERTURE, toujours renseignées.
     *
     * Le début manquant est un cas théorique — `date_seance` est peuplée sur
     * les six séances de production, mesure du 22/09/2026 — mais le traiter
     * évite que l'absence de donnée devienne une erreur fatale.
     *
     * @param  array<string, mixed>  $programmation
     * @return array{0: Carbon, 1: Carbon}
     */
    public function fenetreOuverture(array $programmation): array
    {
        $debut = $programmation['heure_debut'] ?? null;
        $fin = $programmation['heure_fin'] ?? null;

        $heureDebut = is_string($debut) ? Carbon::parse($debut) : now();
        $heureFin = is_string($fin)
            ? Carbon::parse($fin)
            : $heureDebut->copy()->addHours(self::OUVERTURE_PAR_DEFAUT_HEURES);

        return [$heureDebut, $heureFin];
    }
}
