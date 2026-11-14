<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Psr\Log\LoggerInterface;

/**
 * La fenêtre de passage d'une évaluation, et la décision de laisser démarrer.
 *
 * Extrait de {@see EvaluationAttemptStateService} (garde de taille §1.1) : lire
 * la fenêtre et décider si elle laisse démarrer est une responsabilité à part.
 *
 * D72, E2b : la fenêtre vient d'une source qui dépend du type d'école
 * ({@see EvaluationWindowSource}), résolue À CHAQUE APPEL par la fabrique :
 * ce service est reçu par le constructeur d'EvaluationAttemptStateService, que
 * le contrôleur mémoïse (ADR-EI-01, règle 3 ; même patron que
 * {@see LearnerEvaluationScope}). La décision d'ouverture est restée telle
 * quelle.
 */
final class EvaluationWindowGate
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RosterAuthorityFactory $sources,
    ) {}

    /**
     * Un refus qui précède toute lecture (la source KLASSCI exige le jeton de
     * l'élève : `no_token`), ou null.
     *
     * @return array{status: string}|null
     */
    public function refusAvantLecture(User $eleve): ?array
    {
        return $this->source()->refusAvantLecture($eleve);
    }

    /**
     * La fenêtre de l'évaluation, en distinguant « lecture échouée » de « pas
     * de fenêtre configurée » (#499) :
     *   - fetched=false            → la lecture a échoué (le caller doit
     *                                fail-closed hors mode entraînement) ;
     *   - fetched=true, window=null → lecture réussie, aucune fenêtre (toujours ouverte) ;
     *   - fetched=true, window=[…]  → fenêtre à évaluer par {@see checkWindow}.
     *
     * @return array{fetched: bool, window: ?array<string, mixed>}
     */
    public function fetchWindow(User $eleve, Evaluation $evaluation): array
    {
        return $this->source()->fenetre($eleve, $evaluation);
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
        User $eleve,
        Evaluation $evaluation,
        bool $isPracticeMode,
        int $evaluationId,
        ?int $klassciEtudiantId
    ): array {
        $windowResult = $this->fetchWindow($eleve, $evaluation);

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

    private function source(): EvaluationWindowSource
    {
        return $this->sources->sourceForCurrentTenant(KlassciEvaluationWindowSource::class, LocalEvaluationWindowSource::class);
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
