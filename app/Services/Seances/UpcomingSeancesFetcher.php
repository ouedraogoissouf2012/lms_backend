<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\SeancesListQueryService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * UpcomingSeancesFetcher — KLASSCI walker for the "upcoming séances" listing.
 *
 * Extracted from `LMSSeancesQueryController::upcomingSeances` (~196 lines)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Builds the upcoming-séances listing for any role:
 *   1. Resolve the user's OWN matières ({@see UserOwnMatieresResolver}) —
 *      never the tenant catalogue, cf. §1.4.
 *   2. Source the séances of the window from KLASSCI's emploi du temps.
 *   3. Filter by date window, optional classe id, hidden flag (students only).
 *   4. Map to the legacy output shape (programmation + matiere + classe + visio).
 *
 * ## La source a changé — et c'était LE défaut
 *
 * L'étape 2 lisait `matieres/{id}.data.seances_programmees`, sous un commentaire
 * affirmant « endpoint emploi-temps bugué, [...] seances_programmees
 * (fonctionne!) ». Les deux moitiés étaient fausses : le calendrier affichait
 * « Aucune séance programmée » sur un emploi du temps rempli. Mesures et
 * contraintes dans {@see KlassciEmploiTempsSeances}.
 *
 * @see SeancesListQueryService (orchestrator)
 */
final class UpcomingSeancesFetcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ManagerSeancesLocalFetcher $managerFetcher,
        private readonly LocalSeanceLookup $localLookup,
        private readonly UpcomingSeanceMapper $mapper,
        private readonly UserOwnMatieresResolver $ownMatieres,
        private readonly KlassciEmploiTempsSeances $emploiTemps,
        private readonly UpcomingSeanceLocalOverlay $overlay,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $user, string $klassciToken, string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        if ($user->isManager()) {
            return $this->fetchForManager($dateDebut, $dateFin, $teacherId, $classeId);
        }

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        try {
            [$matieres, $seancesParMatiere] = $this->loadMatieresWithSeances($user, $klassciToken, $dateDebut, $dateFin);
            $candidates = $this->collectCandidates($matieres, $seancesParMatiere, $dateDebut, $dateFin, $classeId);
            $seances = $this->assembleVisibleSeances($candidates, $user);

            $this->logger->info('Séances récupérées via emploi-temps', ['count' => $seances->count()]);
        } catch (\Exception $e) {
            // DETTE TRACÉE : cette capture rend une liste VIDE en HTTP 200 quand
            // KLASSCI est injoignable — le calendrier affirme alors « aucune
            // séance » au lieu de signaler la panne. Comportement CONSERVÉ ici :
            // le corriger est un changement de contrat HTTP, qui n'a pas sa
            // place dans un changement de source. Suivi à part.
            $this->logger->error('Erreur récupération séances via emploi-temps', [
                'error' => $e->getMessage(),
            ]);
        }

        // Le lookup est passe EN ARGUMENT : il porte l'etat du prechargement
        // et n'est pas un singleton (cf. UpcomingSeanceLocalOverlay).
        return $this->overlay->apply($seances, $this->localLookup);
    }

    /**
     * Chemin manager : liste servie directement depuis le cache local.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchForManager(string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        $localSeances = $this->managerFetcher->fetch($dateDebut, $dateFin, $teacherId, $classeId);

        $this->logger->info('Séances à venir servies depuis la BDD locale', [
            'count' => $localSeances->count(),
            'teacher_id' => $teacherId,
            'classe_id' => $classeId,
        ]);

        return $localSeances;
    }

    /**
     * Les matières de l'utilisateur, puis les séances de la fenêtre en UN appel
     * `emploi-temps` — quel que soit le nombre de matières.
     *
     * Sans matière, pas de séance : `fetchByMatiere` rend un tableau vide sans
     * interroger KLASSCI, et on ne retombe JAMAIS sur le catalogue du tenant.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<int, list<array<string, mixed>>>}
     */
    private function loadMatieresWithSeances(User $user, string $klassciToken, string $dateDebut, string $dateFin): array
    {
        $matieres = $this->ownMatieres->resolve($user, $klassciToken);

        $matiereIds = KlassciPayload::uniqueIntIds(
            $matieres,
            fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null),
        );

        return [$matieres, $this->emploiTemps->fetchByMatiere($klassciToken, $matiereIds, $dateDebut, $dateFin)];
    }

    /**
     * Phase 2 (#476) : pré-charge l'état local en UN whereIn mutualisé (filtre +
     * visio), puis assemble les séances visibles mappées.
     *
     * @param  list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function assembleVisibleSeances(array $candidates, User $user): Collection
    {
        // Un seul pré-chargement : alimente le filtre archivé/masqué (étudiants) ET
        // l'enrichissement visio. `null` en non-étudiant évite la requête hidden.
        $this->localLookup->preload(
            $this->collectKlassciSeanceIds($candidates),
            $user->isStudent() ? $user : null,
        );

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);
        foreach ($candidates as [$seancesProgrammees, $matiereArr]) {
            $seancesVisibles = $this->rejectHiddenOrArchived($seancesProgrammees, $user);
            $seances = $seances->concat($this->mapper->map($seancesVisibles, $matiereArr));
        }

        return $seances;
    }

    /**
     * Phase 1 (#476) — collecte les couples (séances filtrées date/classe, matière)
     * SANS aucune requête locale. Le filtre archivé/masqué est reporté en phase 2
     * (après pré-chargement), pour éliminer les N+1.
     *
     * @param  Collection<int, array<string, mixed>>  $matieres
     * @param  array<int, list<array<string, mixed>>>  $seancesParMatiere
     * @return list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>
     */
    private function collectCandidates(Collection $matieres, array $seancesParMatiere, string $dateDebut, string $dateFin, ?int $classeId): array
    {
        $candidates = [];
        foreach ($matieres as $matiere) {
            $matiereArr = KlassciPayload::asArray($matiere);
            $matiereId = KlassciPayload::toInt($matiereArr['id'] ?? null);
            if ($matiereId === null || ! isset($seancesParMatiere[$matiereId])) {
                continue;
            }

            // Le filtre de dates est CONSERVÉ bien qu'`emploi-temps` soit déjà
            // interrogé avec la fenêtre : rien ne garantit que KLASSCI l'honore
            // au jour près, et le filtre `classe_id` reste, lui, purement local.
            $seances = collect($seancesParMatiere[$matiereId]);
            $candidates[] = [$this->filterByDateAndClasse($seances, $dateDebut, $dateFin, $classeId), $matiereArr];
        }

        return $candidates;
    }

    /**
     * Filtre par fenêtre de dates + classe optionnelle. Aucune requête locale.
     *
     * @param  Collection<int, array<string, mixed>>  $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByDateAndClasse(Collection $seances, string $dateDebut, string $dateFin, ?int $classeId): Collection
    {
        $filtered = $seances->filter(function (array $seance) use ($dateDebut, $dateFin) {
            $dateSeance = KlassciPayload::toStringOrNull(
                KlassciPayload::asArray($seance['programmation'] ?? null)['date'] ?? null
            );

            return $dateSeance !== null && $dateSeance >= $dateDebut && $dateSeance <= $dateFin;
        });

        if ($classeId) {
            $filtered = $filtered->filter(function (array $seance) use ($classeId) {
                return KlassciPayload::toInt(KlassciPayload::asArray($seance['classe'] ?? null)['id'] ?? null) === $classeId;
            });
        }

        return $filtered;
    }

    /**
     * Tous les klassci_seance_id non nuls des candidats, dédupliqués.
     *
     * @param  list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>  $candidates
     * @return list<int>
     */
    private function collectKlassciSeanceIds(array $candidates): array
    {
        $ids = [];
        foreach ($candidates as [$seances]) {
            foreach ($seances as $seance) {
                $id = KlassciPayload::toInt($seance['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Phase 2 (#476) — pour les étudiants, écarte les séances archivées ou masquées,
     * résolues EN MÉMOIRE depuis le pré-chargement (plus aucune requête par séance).
     * Enseignants/coordinateurs/admins voient tout (comportement inchangé).
     *
     * @param  Collection<int, array<string, mixed>>  $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function rejectHiddenOrArchived(Collection $seances, User $user): Collection
    {
        if (! $user->isStudent()) {
            return $seances;
        }

        return $seances->filter(function (array $seance): bool {
            $kid = KlassciPayload::toInt($seance['id'] ?? null);

            return ! $this->localLookup->isArchived($kid) && ! $this->localLookup->isHidden($kid);
        });
    }
}
