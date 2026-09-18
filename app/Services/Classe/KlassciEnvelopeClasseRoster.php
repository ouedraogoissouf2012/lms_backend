<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Services\KlassciProxyService;

/**
 * Roster lu dans l'enveloppe `GET classes/{id}` — et jamais dans
 * `classes/{id}/etudiants`.
 *
 * ## Pourquoi cet endpoint-ci et pas l'autre
 *
 * KLASSCI applique une autorisation PAR CLASSE sur `classes/{id}/etudiants` et
 * répond 403 « Accès non autorisé à cette classe » à TOUS les rôles, superAdmin
 * compris. L'enveloppe `classes/{id}`, elle, répond 200 et porte déjà le bloc
 * `etudiants` : mesuré dans #669 sur les 17 classes, 210 étudiants listés pour
 * 210 déclarés. Le redemander séparément n'est donc pas seulement superflu —
 * c'est le seul des deux appels qui échoue.
 *
 * {@see ClasseDetailsQueryService} a fait ce geste en #669 ; le calcul des
 * résultats d'évaluation y est resté, d'où un 500 permanent sur l'écran
 * « Notes et Résultats ». Cette classe existe pour que le geste ait UN lieu, au
 * lieu d'être recopié au prochain appelant.
 *
 * ## Pourquoi `KlassciProxyService` en direct
 *
 * {@see \App\Services\Sync\Classes\KlassciClassesFetcher::fetchClasseDetails()}
 * émet déjà le même appel, mais appartient au domaine de la SYNCHRONISATION :
 * l'en dépendre ferait entrer un objet de synchro dans un chemin de lecture.
 * Tous les lecteurs du dépôt passent par `requestWithUserToken()` — c'est la
 * convention suivie ici, pas une duplication choisie par facilité.
 *
 * L'URL est volontairement NUE (`classes/{id}`, sans `?with=`) : elle partage
 * ainsi la clé de cache de l'appelant ci-dessus, alors qu'une variante de
 * requête ouvrirait une seconde entrée pour la même donnée.
 */
final class KlassciEnvelopeClasseRoster implements ClasseRoster
{
    /**
     * Même durée que l'appel qu'il remplace : le roster d'une classe ne bouge
     * pas à la minute, et l'écran est consulté en rafale pendant une correction.
     */
    private const TTL_SECONDES = 300;

    public function __construct(private readonly KlassciProxyService $klassci) {}

    public function etudiants(int $classeId, string $userToken): array
    {
        $reponse = $this->klassci->requestWithUserToken(
            $userToken,
            "classes/{$classeId}",
            'GET',
            [],
            self::TTL_SECONDES,
        );

        /** @var array<string, mixed>|null $payload */
        $payload = is_array($reponse['data'] ?? null) ? $reponse['data'] : null;

        return ClasseEnvelope::etudiants($payload);
    }
}
