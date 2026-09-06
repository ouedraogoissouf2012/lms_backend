<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Services\KlassciProxyService;

/**
 * Source des séances d'un porteur : l'emploi du temps KLASSCI, adapté au
 * contrat interne.
 *
 * ## Pourquoi cette classe existe
 *
 * Le LMS lisait ses séances dans `matieres/{id}.data.seances_programmees`. Cette
 * clé est **systématiquement vide** chez KLASSCI. Mesuré le 2026-09-05 avec un
 * jeton enseignant réel : `matieres/3` renvoyait `seances_programmees: []` et,
 * dans le MÊME corps de réponse, `statistiques.seances.total_programmees: 28`.
 * KLASSCI se contredit lui-même, et le LMS affichait « Séances 0 ».
 *
 * `emploi-temps` répond, lui, avec les séances réelles. C'est la bonne API.
 *
 * ## Trois contraintes MESURÉES, pas supposées
 *
 * 1. **Sans fenêtre, `emploi-temps` ne rend que la semaine courante.** La
 *    fenêtre est donc un paramètre obligatoire, jamais un défaut implicite.
 * 2. **KLASSCI ignore le paramètre `matiere_id`.** `?matiere_id=3` renvoyait des
 *    séances d'Algorithme et de Marketing digital. Le tri par matière est à la
 *    charge du LMS — d'où `$matiereIds` ici, et non un filtre envoyé au serveur.
 * 3. **La forme du payload diffère de `seances_programmees`** : la date est
 *    `programmation.date_seance`, et `salle` est un objet à la RACINE de la
 *    séance. {@see self::adapt()} réconcilie les deux formes.
 *
 * ## Ce que la classe ne fait pas
 *
 * Elle n'avale aucune erreur. Une indisponibilité de KLASSCI doit remonter :
 * `SeancesListQueryService` en porte le statut. Rendre une liste vide en HTTP 200
 * ferait mentir le calendrier — l'utilisateur croirait n'avoir aucune séance.
 */
final class KlassciEmploiTempsSeances
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Les séances de la fenêtre, adaptées et regroupées par identifiant KLASSCI
     * de matière. Les matières absentes de `$matiereIds` sont écartées (cf.
     * contrainte 2) ; une matière sans séance est simplement absente du tableau.
     *
     * @param  list<int>  $matiereIds  Matières du porteur — le filtre est LOCAL.
     * @return array<int, list<array<string, mixed>>>
     */
    public function fetchByMatiere(string $klassciToken, array $matiereIds, string $dateDebut, string $dateFin): array
    {
        if ($matiereIds === []) {
            return [];
        }

        $retenues = array_flip($matiereIds);
        $groupees = [];

        foreach ($this->walk($klassciToken, $dateDebut, $dateFin) as $entree) {
            $matiereId = KlassciPayload::toInt(
                KlassciPayload::asArray($entree['matiere'] ?? null)['id'] ?? null
            );

            if ($matiereId === null || ! isset($retenues[$matiereId])) {
                continue;
            }

            $groupees[$matiereId][] = self::adapt($entree);
        }

        return $groupees;
    }

    /**
     * Aligne une entrée `emploi-temps` sur le contrat qu'attendent les
     * consommateurs — celui de `seances_programmees`.
     *
     * L'entrée d'origine est **conservée** et seulement complétée : toute clé
     * que KLASSCI ajouterait survit au passage. On ne reconstruit pas un objet
     * appauvri à partir des seules clés connues aujourd'hui.
     *
     * @param  array<string, mixed>  $seance
     * @return array<string, mixed>
     */
    public static function adapt(array $seance): array
    {
        $programmation = KlassciPayload::asArray($seance['programmation'] ?? null);

        // `date_seance` est la date réelle ; `date_cours` est le repli observé
        // sur les entrées où la première manque.
        $programmation['date'] ??= $programmation['date_seance']
            ?? $programmation['date_cours']
            ?? null;

        $programmation['salle'] ??= self::salleNom($seance['salle'] ?? null);

        $seance['programmation'] = $programmation;

        return $seance;
    }

    /**
     * `salle` est un objet `{id, nom, capacite}` à la racine de la séance, là où
     * `seances_programmees` portait une chaîne dans `programmation`. Les deux
     * formes sont acceptées : KLASSCI a déjà changé d'avis une fois.
     */
    private static function salleNom(mixed $salle): ?string
    {
        if (is_array($salle)) {
            return KlassciPayload::toStringOrNull($salle['nom'] ?? null);
        }

        return KlassciPayload::toStringOrNull($salle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function walk(string $klassciToken, string $dateDebut, string $dateFin): array
    {
        $reponse = $this->klassciService->getEmploiTemps($klassciToken, [
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
        ]);

        return KlassciPayload::listOfArrays($reponse['data'] ?? null);
    }
}
