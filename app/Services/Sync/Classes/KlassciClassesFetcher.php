<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Enums\Role;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Support\Logging\CaughtExceptionLog;

/**
 * KlassciClassesFetcher
 *
 * Récupère la liste des classes (avec étudiants détaillés quand possible) depuis
 * KLASSCI selon le rôle de l'utilisateur. Extrait verbatim de
 * `ClasseSyncService::fetchClassesFromKlassci()` lors du split SRP (§1.1).
 * Batché pour issue #517 (H5, N+1 HTTP) : les boucles séquentielles
 * `classes/{id}` / `matieres/{id}` sont remplacées par `fetchManyClassesDetails`
 * / `fetchManyMatieresDetails` (pool `Http::pool`, issue #135).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService` et `CaughtExceptionLog` (journal
 * des exceptions attrapées).
 * Pas de Facade `Log::` dans le code métier.
 *
 * ## Comportement préservé
 *
 * 1. Essai prioritaire de `GET /classes` (coordinateurs / superAdmin).
 *    Pour chaque classe retournée, on récupère en UN pool batch les détails
 *    (`GET /classes/{id}`) afin d'obtenir les étudiants. En cas d'échec sur
 *    les détails (id absent du map batch), on conserve les infos basiques de
 *    la classe.
 *
 * 2. Fallback selon `$userRole` :
 *    - `enseignant` / `teacher` -> `GET /me/teacher-dashboard` puis, en UN
 *      pool batch, `GET /matieres/{id}` pour toutes les matières, afin d'en
 *      déduire les classes (déduplique par id).
 *
 * Le fallback n'est déclenché QUE si `/classes` lève une exception (typiquement
 * 403). C'est le comportement historique attendu par
 * `ClasseSyncService::syncUserClasses()`.
 *
 * ## L'élève : sa seule classe, par la forme mesurée (#886, ADR-886-01)
 *
 * `etudiant` -> `GET /me/dashboard` -> `[data.classe]`, la forme prouvée au login
 * (#969), et rien d'autre :
 * - pas de `GET /classes` d'abord : son effet avec le jeton d'un élève n'a jamais
 *   été mesuré, et la liste des classes de l'école n'est pas la sienne ;
 * - pas de `me/student-dashboard` : jamais mesuré non plus ;
 * - le bloc `etudiants` est retiré : la liste vue par un élève ne fait pas
 *   autorité sur l'effectif de sa classe.
 *
 * @see ClasseSyncService::syncUserClasses() — orchestrateur appelant
 */
final class KlassciClassesFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Récupérer les classes depuis Klassci selon le rôle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $klassciToken, string $userRole): array
    {
        if (Role::tryFromString($userRole) === Role::Etudiant) {
            return $this->fetchStudentClasses($klassciToken);
        }

        // Essayer d'abord /classes (pour coordinateurs et superAdmin)
        // Si ça échoue, essayer les autres endpoints spécifiques au rôle
        try {
            return $this->fetchAllClassesWithDetails($klassciToken);
        } catch (\Exception $e) {
            // Si /classes échoue (403, etc.), essayer les autres endpoints
            $this->journal->info('Endpoint /classes non accessible, essai selon rôle', $e, [
                'role' => $userRole,
            ]);
        }

        return $this->fetchByRole($klassciToken, $userRole);
    }

    /**
     * Récupère les détails bruts d'une classe par son ID Klassci.
     *
     * Retourne le `data` brut de `GET /classes/{id}` (typiquement
     * `{classe: {...}, etudiants: [...]}`) ou `null` si l'API ne renvoie pas
     * de payload. Utilisé par `ClasseSyncService::syncClasseById()`.
     *
     * @return array<string, mixed>|null
     */
    public function fetchClasseDetails(string $klassciToken, int $klassciClasseId): ?array
    {
        $response = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "classes/{$klassciClasseId}",
            'GET'
        );

        return $response['data'] ?? null;
    }

    /**
     * Tente `GET /classes` puis enrichit en UN pool batch (#517) via
     * `GET /classes/{id}` pour récupérer les étudiants. Un échec du batch
     * lui-même (pas un échec par id — déjà toléré par `KlassciBatchFetcher`,
     * mais une panne config/connectivité KLASSCI) est capturé ICI et dégradé
     * vers les infos basiques déjà obtenues de `/classes` : il ne doit PAS
     * remonter jusqu'au `try/catch` de `fetch()`, qui interprète toute
     * exception comme "`/classes` inaccessible" et bascule à tort vers le
     * fallback par rôle — perdant les classes déjà listées avec succès.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllClassesWithDetails(string $klassciToken): array
    {
        $response = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'classes',
            'GET'
        );

        /** @var array<int, array<string, mixed>> $classes */
        $classes = $response['data'] ?? [];

        try {
            $detailsMap = $this->fetchManyClasseDetails($classes, $klassciToken);
        } catch (\Exception $e) {
            $this->journal->warning('Erreur récupération détails classes (batch) — infos basiques conservées', $e);

            return $classes;
        }

        $detailedClasses = [];
        foreach ($classes as $classe) {
            $id = KlassciPayload::toInt($classe['id'] ?? null);
            $details = $id !== null ? ($detailsMap[$id] ?? null) : null;
            $data = $details !== null ? KlassciPayload::asArray($details['data'] ?? null) : [];
            $classeDetails = $data['classe'] ?? null;

            // L'API retourne { "data": { "classe": {...}, "etudiants": [...], "matieres": [...] }}
            //
            // `matieres` était jeté ici — le défaut exact de `syncClasseById`,
            // une couche plus haut. Le porter coûte ZÉRO appel réseau (la
            // réponse est déjà en main) et permet d'amorcer le miroir
            // `classe_matiere` dès la connexion, donc indépendamment des
            // séances et de la disponibilité de KLASSCI au moment où
            // l'enseignant ouvre sa page.
            if (is_array($classeDetails)) {
                // `etudiants` et `matieres` : portés SEULEMENT si le payload les
                // contient. Un bloc absent n'est pas une liste vide.
                if (isset($data['etudiants']) && is_array($data['etudiants'])) {
                    $classeDetails['etudiants'] = $data['etudiants'];
                }

                // Portée SEULEMENT si le payload la contient — jamais défautée
                // à `[]`. Un tableau vide affirmerait « cette classe n'a aucune
                // matière » là où la vérité est « KLASSCI ne l'a pas dit ».
                // Confondre les deux est la faute qui transforme un
                // réconciliateur en effaceur : c'est ainsi qu'un archiveur
                // détruit ce qu'il ne voit pas.
                if (isset($data['matieres']) && is_array($data['matieres'])) {
                    $classeDetails['matieres'] = $data['matieres'];
                }

                $detailedClasses[] = $classeDetails;
            } else {
                // Détails absents du batch (id échoué / non résoluble) : garder les infos basiques
                $detailedClasses[] = $classe;
            }
        }

        return $detailedClasses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<int, array<string, mixed>>
     */
    private function fetchManyClasseDetails(array $classes, string $klassciToken): array
    {
        $classeIds = KlassciPayload::uniqueIntIds($classes, fn (array $classe): ?int => KlassciPayload::toInt($classe['id'] ?? null));

        return $classeIds === [] ? [] : $this->klassciService->fetchManyClassesDetails($classeIds, $klassciToken);
    }

    /**
     * Fallback selon le rôle quand `GET /classes` n'est pas accessible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchByRole(string $klassciToken, string $userRole): array
    {
        if (Role::tryFromString($userRole) === Role::Enseignant) {
            return $this->fetchTeacherClasses($klassciToken);
        }

        return [];
    }

    /**
     * La classe d'un étudiant, par `GET /me/dashboard` -> `data.classe`, la forme
     * mesurée au login (#969). Sans classe identifiable : aucune.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchStudentClasses(string $klassciToken): array
    {
        $response = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');
        $data = $response['data'] ?? null;
        $classe = is_array($data) ? ($data['classe'] ?? null) : null;

        if (! is_array($classe) || ! is_numeric($classe['id'] ?? null)) {
            return [];
        }

        unset($classe['etudiants']);

        /** @var array<string, mixed> $classe */
        return [$classe];
    }

    /**
     * Récupère les classes d'un enseignant via `/me/teacher-dashboard` puis,
     * en UN pool batch (#517), les détails de chaque matière (déduplique les
     * classes par id).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTeacherClasses(string $klassciToken): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        /** @var array<int, array<string, mixed>> $matieres */
        $matieres = $dashboard['data']['matieres'] ?? [];
        $matiereIds = KlassciPayload::uniqueIntIds($matieres, fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null));

        $detailsMap = $matiereIds === []
            ? []
            : $this->klassciService->fetchManyMatieresDetails($matiereIds, $klassciToken);

        $classesMap = [];
        foreach ($detailsMap as $matiereDetails) {
            $classe = KlassciPayload::asArray($matiereDetails['data'] ?? null)['classe'] ?? null;
            $classeId = is_array($classe) ? KlassciPayload::toInt($classe['id'] ?? null) : null;
            if ($classeId !== null && ! isset($classesMap[$classeId])) {
                $classesMap[$classeId] = $classe;
            }
        }

        return array_values($classesMap);
    }
}
