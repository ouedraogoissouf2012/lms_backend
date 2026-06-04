<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetchers secondaires « détails classe » — emploi du temps, évaluations,
 * matières. Tous appliquent une dégradation gracieuse : un échec amont KLASSCI
 * est loggé en `warning` et le bloc est renvoyé vide.
 *
 * Extrait de {@see ClasseDetailsQueryService} (split-20) pour respecter §1.1.
 */
final class ClasseSecondaryDataFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Emploi du temps lundi → dimanche de la semaine courante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEmploiTempsSemaine(int $classeId, string $klassciToken): array
    {
        $startOfWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
        $endOfWeek = Carbon::now()->endOfWeek()->format('Y-m-d');

        try {
            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "emploi-temps?classe_id={$classeId}&date_debut={$startOfWeek}&date_fin={$endOfWeek}",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $emploi */
            $emploi = $response['data'] ?? [];
            return $emploi;
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération emploi du temps', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

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
                ->filter(static function (array $eval) use ($classeId): bool {
                    $classeData = $eval['classe'] ?? null;
                    return is_array($classeData)
                        && isset($classeData['id'])
                        && $classeData['id'] === $classeId;
                })
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
     * Matières disponibles pour la combinaison filière+niveau de la classe.
     * Filière ou niveau manquant → warning + [].
     *
     * @param  array<string, mixed>  $classe
     * @return array<int, array<string, mixed>>
     */
    public function fetchMatieres(array $classe, int $classeId, string $klassciToken): array
    {
        if (!isset($classe['filiere']['id']) || !isset($classe['niveau']['id'])) {
            $this->logger->warning('Impossible de récupérer les matières - filière ou niveau manquant', [
                'classe_id' => $classeId,
                'has_filiere' => isset($classe['filiere']),
                'has_niveau' => isset($classe['niveau']),
            ]);

            return [];
        }

        try {
            $filiereId = $classe['filiere']['id'];
            $niveauId = $classe['niveau']['id'];

            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres?filiere_id={$filiereId}&niveau_id={$niveauId}",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $matieres */
            $matieres = $response['data'] ?? [];
            return $matieres;
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération matières', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
