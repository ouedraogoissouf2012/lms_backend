<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetcher secondaire « détails classe » — évaluations programmées. Dégradation
 * gracieuse : un échec amont KLASSCI est loggé en `warning` et le bloc est
 * renvoyé vide.
 *
 * Extrait de {@see ClasseDetailsQueryService} (split-20) pour respecter §1.1.
 *
 * Ne restent ici que les blocs qui exigent RÉELLEMENT un appel séparé. Les
 * matières et l'emploi du temps ont quitté cette classe : KLASSCI les livre
 * déjà dans l'enveloppe `classes/{id}` ({@see ClasseEnvelope}), et pour les
 * matières l'appel séparé était en plus FAUX — le catalogue
 * `matieres?filiere_id=…&niveau_id=…` ignore ses filtres et renvoyait les 452
 * matières de tout l'établissement.
 */
final class ClasseSecondaryDataFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Évaluations programmées filtrées sur l'identifiant de classe.
     * `classe` peut être null/scalaire (données KLASSCI incohérentes) — gardé
     * par is_array() avant déréférencement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEvaluations(int $classeId, string $klassciToken): array
    {
        try {
            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'evaluations',
                'GET'
            );

            /** @var array<int, array<string, mixed>> $evaluationsData */
            $evaluationsData = $response['data'] ?? [];

            return collect($evaluationsData)
                ->filter(fn (array $eval): bool => $this->referencesClasse($eval, $classeId))
                ->values()
                ->all();
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération évaluations', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * L'évaluation référence-t-elle la classe demandée ?
     *
     * L'identifiant est comparé APRÈS normalisation numérique. KLASSCI renvoie
     * aujourd'hui un entier, mais rien dans ses payloads JSON ne l'impose : en
     * comparaison stricte, une seule livraison en chaîne (« 1 ») faisait tomber
     * le filtre à zéro et l'écran affichait « aucune évaluation » — sans erreur
     * ni trace. Une référence inexploitable (absente, scalaire, non numérique)
     * n'apparie jamais : on ne devine pas une correspondance.
     *
     * @param  array<string, mixed>  $eval
     */
    private function referencesClasse(array $eval, int $classeId): bool
    {
        $classeData = $eval['classe'] ?? null;

        if (!is_array($classeData)) {
            return false;
        }

        $id = $classeData['id'] ?? null;

        if (is_int($id)) {
            return $id === $classeId;
        }

        return is_string($id) && ctype_digit($id) && (int) $id === $classeId;
    }
}
