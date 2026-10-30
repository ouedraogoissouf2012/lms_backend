<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Psr\Log\LoggerInterface;

/**
 * La fenêtre de passage d'une évaluation, telle que KLASSCI la programme.
 *
 * Extrait de {@see EvaluationAttemptStateService} (garde de taille §1.1) :
 * lire la fenêtre et décider si elle laisse démarrer est une responsabilité
 * à part, la seule de ce flux qui interroge `GET evaluations` chez KLASSCI.
 * Les lectures du payload y sont typées (`rawWindowOf`, `windowDate`) ; la
 * décision d'ouverture est restée telle quelle.
 *
 * La fenêtre se lit dans la liste KLASSCI DE L'ÉLÈVE, par son jeton. Elle ne
 * dit donc rien d'une évaluation qui n'y figure pas (`window = null`) : ce
 * n'est PAS un contrôle d'accès. L'accès est décidé avant, par
 * {@see LearnerEvaluationScope}.
 */
final class EvaluationWindowGate
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Récupère la fenêtre temporelle KLASSCI d'une évaluation, en distinguant
     * « appel échoué » de « pas de fenêtre configurée » (#499) :
     *   - fetched=false            → l'appel KLASSCI a échoué (le caller doit
     *                                fail-closed hors mode entraînement) ;
     *   - fetched=true, window=null → appel réussi, aucune fenêtre (toujours ouverte) ;
     *   - fetched=true, window=[…]  → fenêtre à évaluer par {@see checkWindow}.
     *
     * @return array{fetched: bool, window: ?array<string, mixed>}
     */
    public function fetchWindow(string $klassciToken, ?int $klassciEvaluationId): array
    {
        try {
            $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
            $rawWindow = $this->rawWindowOf($response['data'] ?? null, $klassciEvaluationId);

            return [
                'fetched' => true,
                'window' => is_array($rawWindow) ? KlassciPayload::asArray($rawWindow) : null,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('Window check failed (KLASSCI)', ['error' => $e->getMessage()]);

            return ['fetched' => false, 'window' => null];
        }
    }

    /**
     * Résout le « gate » de fenêtre : renvoie `[erreur, null]` si le démarrage
     * doit être bloqué (fenêtre invérifiable hors entraînement, ou fermée),
     * sinon `[null, window]` pour poursuivre. Centralise la logique fenêtre pour
     * garder `startAttempt` sous la limite §1.1.
     *
     * @return array{0: ?array{status: string, window?: ?array<string, mixed>, message?: string}, 1: ?array<string, mixed>}
     */
    public function resolveWindowGate(
        string $klassciToken,
        Evaluation $evaluation,
        bool $isPracticeMode,
        int $evaluationId,
        ?int $klassciEtudiantId
    ): array {
        $windowResult = $this->fetchWindow($klassciToken, $evaluation->klassci_evaluation_id);

        // Fail-closed : fenêtre non vérifiable (KLASSCI indisponible) hors
        // entraînement → refus, aucune tentative créée (#499).
        if (! $windowResult['fetched'] && ! $isPracticeMode) {
            $this->logger->warning('Démarrage refusé : fenêtre non vérifiable (KLASSCI indisponible)', [
                'evaluation_id' => $evaluationId,
                'klassci_etudiant_id' => $klassciEtudiantId,
            ]);

            return [[
                'status' => 'window_check_failed',
                'message' => "Impossible de vérifier la disponibilité de l'évaluation pour le moment. Veuillez réessayer dans un instant.",
            ], null];
        }

        $window = $windowResult['window'];

        return [$this->checkWindow($window, $isPracticeMode, $evaluationId, $klassciEtudiantId), $window];
    }

    /**
     * @param  ?array<string, mixed>  $window
     * @return ?array{status: string, window?: ?array<string, mixed>, message?: string}
     */
    private function checkWindow(?array $window, bool $isPracticeMode, int $evaluationId, ?int $klassciEtudiantId): ?array
    {
        if (!$window || $window['is_open'] || $isPracticeMode) {
            return null;
        }

        $message = "L'évaluation n'est pas encore ouverte";
        if (!($window['has_started'] ?? true)) {
            $startAt = $this->windowDate($window['start_at'] ?? null);
            $message = $startAt === null ? $message : "L'évaluation ouvrira le {$startAt}";
        } elseif ($window['has_ended'] ?? false) {
            $endAt = $this->windowDate($window['end_at'] ?? null);
            $message = $endAt === null ? $message : "L'évaluation est fermée depuis le {$endAt}";
        }

        $this->logger->warning('Tentative de démarrage hors fenêtre', [
            'evaluation_id' => $evaluationId,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'window' => $window,
        ]);

        return ['status' => 'window_closed', 'window' => $window, 'message' => $message];
    }

    /**
     * La fenêtre brute de cette évaluation dans la liste KLASSCI de l'élève, ou
     * null. Chaque niveau du payload est vérifié avant d'être lu : une forme
     * inattendue donne « pas de fenêtre », comme le faisait l'accès d'origine.
     */
    private function rawWindowOf(mixed $evaluations, ?int $klassciEvaluationId): mixed
    {
        if (! is_array($evaluations)) {
            return null;
        }

        $klassciEval = collect($evaluations)->firstWhere('id', $klassciEvaluationId);
        $programmation = is_array($klassciEval) ? ($klassciEval['programmation'] ?? null) : null;

        return is_array($programmation) ? ($programmation['window'] ?? null) : null;
    }

    /**
     * Une date de la fenêtre, pour le MESSAGE du refus seulement : la décision
     * (ouverte ou fermée) se lit sur `is_open`, `has_started` et `has_ended`,
     * jamais ici. Une chaîne ou un horodatage se convertissent comme avant ; un
     * autre type, une chaîne qui n'est pas une date, ou une date absente, ne
     * donnent aucune date : le refus reste un refus, avec le message générique.
     */
    private function windowDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y à H:i');
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
