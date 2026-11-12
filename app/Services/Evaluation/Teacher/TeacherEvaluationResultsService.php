<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\Classe\ClasseEnvelope;
use App\Services\Classe\ClasseRoster;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Services\Seances\KlassciPayload;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Résultats agrégés enseignant — split §22 du god-controller
 * (`EvaluationTeacherController::getResultsByClass`, ~126 l).
 *
 * Construit la liste exhaustive des étudiants d'une classe KLASSCI (et
 * non plus seulement ceux ayant soumis) avec, pour chacun, sa dernière
 * soumission "live" (les essais marqués `[PRACTICE]` dans le feedback
 * sont exclus — convention historique du controller original) puis
 * agrège les statistiques de classe (moyenne, min/max, taux de
 * participation).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * `KlassciProxyService`, `EvaluationEnrichmentService` et PSR-3
 * `LoggerInterface` sont injectés par constructor. Aucun `app()`, aucune
 * Facade `Log::`.
 *
 * ## Comportement préservé
 *
 * Logique déplacée verbatim depuis le controller — mêmes try/catch,
 * mêmes filtres `feedback NOT LIKE [PRACTICE]%`, même payload renvoyé.
 * Le caller (`EvaluationTeacherController`) se contente de `response()
 * ->json($result['payload'], $result['status'])`.
 *
 * ## Source de l'école créée depuis KLASSCI (D72, E3)
 *
 * Le roster et la présentation de l'évaluation viennent de KLASSCI. La forme de
 * la réponse ({@see ResultatsDeClasse}) et le rattachement d'une copie à son
 * élève ({@see DernieresCopies}) sont partagés avec la source locale
 * ({@see LocalEvaluationResultsReader}).
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationTeacherController.php
 */
final class TeacherEvaluationResultsService implements EvaluationResultsReader
{
    public function __construct(
        private readonly ClasseRoster $roster,
        private readonly EvaluationEnrichmentService $enrichmentService,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Construit le payload "résultats par classe" pour une évaluation.
     *
     * Retourne un array `{status, payload}` consommable directement par le
     * controller (cf. pattern des autres services Evaluation).
     *
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function getResultsByClass(int $evaluationId, User $teacher): array
    {
        try {
            $evaluation = Evaluation::with(['questions', 'submissions'])->find($evaluationId);

            if ($evaluation === null) {
                return ResultatsDeClasse::introuvable();
            }

            $this->logger->info('📊 Récupération résultats évaluation', [
                'evaluation_id' => $evaluationId,
                'classe_id'     => $evaluation->klassci_classe_id,
                'teacher_id'    => $teacher->id,
            ]);

            $teacherToken = $teacher->klassci_token;
            if (! is_string($teacherToken) || $teacherToken === '') {
                return [
                    'status'  => 401,
                    'payload' => MissingKlassciTokenException::payload(),
                ];
            }

            // #669 : le roster est lu dans l'enveloppe `classes/{id}`. L'endpoint
            // dedie `classes/{id}/etudiants` est soumis a une autorisation PAR
            // CLASSE et repond 403 a TOUS les roles, superAdmin compris : il
            // rendait cet ecran definitivement inaccessible, et le catch
            // ci-dessous presentait ce refus comme une panne du LMS.
            $etudiants = $this->roster->etudiants((int) $evaluation->klassci_classe_id, $teacherToken);

            $this->logger->info('👥 Étudiants de la classe', [
                'total_etudiants' => count($etudiants),
            ]);

            return $this->assemblerReponse($evaluation, $etudiants, $teacherToken);
        } catch (RuntimeException $e) {
            // Un refus (4xx) ou une indisponibilite KLASSCI n'est pas une
            // defaillance du LMS. L'ecraser en 500 rendait un probleme de droits
            // indiscernable d'une panne — pour l'utilisateur comme pour la
            // supervision — et privait le client du 503 + `Retry-After` prevu par
            // #243/#685. Le controleur la traduit via RendersKlassciBackedErrors.
            throw $e;
        } catch (Throwable $e) {
            $this->journal->error('❌ Erreur récupération résultats évaluation', $e, [
                'evaluation_id' => $evaluationId,
            ]);

            return ResultatsDeClasse::echec();
        }
    }

    /**
     * Assemble la reponse de succes : enrichissement, lignes par etudiant, tri,
     * statistiques.
     *
     * Extraite de {@see getResultsByClass()} parce que le cliquet de longueur de
     * methode (§5) a refuse qu elle grossisse encore — elle etait a 64 lignes de
     * dette tracee. Ce bloc etait le seul du corps a avoir une unite propre : il
     * ne decide rien, il PRESENTE ce qui a deja ete obtenu. Le rogner de
     * commentaires aurait satisfait la garde sans rien ameliorer.
     *
     * @param  array<int, array<string, mixed>>  $etudiants
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function assemblerReponse(Evaluation $evaluation, array $etudiants, string $teacherToken): array
    {
        $evaluationEnrichie = $this->enrichmentService->enrich(collect([$evaluation]), $teacherToken)[0];

        $resultats = $this->buildResultats($evaluation, $etudiants);
        $statistiques = ResultatsDeClasse::statistiques($resultats, count($etudiants));

        $this->logger->info('✅ Résultats calculés', [
            'total_etudiants' => $statistiques['total_etudiants'],
            'soumis'          => $statistiques['etudiants_soumis'],
            'moyenne'         => $statistiques['moyenne_classe'],
        ]);

        return ResultatsDeClasse::reponse($evaluationEnrichie, $resultats, $statistiques);
    }

    /**
     * Construit la liste des résultats pour TOUS les étudiants d'une classe
     * (pas uniquement ceux ayant soumis), avec leur dernière soumission "live".
     *
     * @param  array<int, array<string, mixed>>  $etudiants  Liste KLASSCI
     * @return array<int, array<string, mixed>>
     */
    private function buildResultats(Evaluation $evaluation, array $etudiants): array
    {
        // #503 — préchargements en amont pour éliminer le N+1 : users locaux
        // par email (1 requête), et copies depuis la collection DÉJÀ
        // eager-loaded (0 requête, cf. `Evaluation::with('submissions')`).
        $usersByEmail = $this->preloadUsersByEmail($etudiants);
        $copies = DernieresCopies::de($evaluation);

        $resultats = [];

        foreach ($etudiants as $etudiant) {
            $email     = KlassciPayload::toStringOrNull($etudiant['email'] ?? null) ?? '';
            $userLocal = $email !== '' ? $usersByEmail->get($email) : null;

            // L'auteur local (`student_id`) ; à défaut, le `klassci_id` du
            // compte local, puis l'ID KLASSCI direct (C26).
            $copie = $copies->pour(
                $userLocal?->id,
                $userLocal?->klassci_id,
                KlassciPayload::toInt($etudiant['id'] ?? null),
            );

            $resultats[] = ResultatsDeClasse::ligne(
                $etudiant['id'],
                $etudiant['nom'] ?? '',
                $etudiant['prenom'] ?? '',
                ClasseEnvelope::identiteAffichable($etudiant),
                $copie,
            );
        }

        return $resultats;
    }

    /**
     * Users locaux indexés par email, en une seule requête (#503).
     *
     * @param  array<int, array<string, mixed>>  $etudiants
     * @return \Illuminate\Support\Collection<string, User>
     */
    private function preloadUsersByEmail(array $etudiants): \Illuminate\Support\Collection
    {
        $emails = array_values(array_filter(
            array_map(fn (array $e): string => KlassciPayload::toStringOrNull($e['email'] ?? null) ?? '', $etudiants),
            fn (string $email): bool => $email !== ''
        ));

        /** @var \Illuminate\Support\Collection<string, User> $users */
        $users = User::query()->whereIn('email', $emails)->get()->keyBy('email');

        return $users;
    }
}
