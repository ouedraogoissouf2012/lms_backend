<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Résout les matières de CET utilisateur — jamais le catalogue de l'établissement.
 *
 * ## §1.4 PRODUCTION_STANDARDS.md — le défaut corrigé
 *
 * {@see UpcomingSeancesFetcher} lisait `GET matieres`, qui renvoie TOUT le
 * catalogue du tenant (452 matières mesurées sur `presentation`) alors que
 * l'enseignant concerné n'en enseigne que 6. Le batch tentait donc de résoudre
 * des centaines de matières étrangères. Trace réelle du 04/09/2026, pour UNE
 * seule fiche classe (request_id `eecf63b1`) :
 *
 *     10:17:23  Récupération séances à venir
 *     ...       219 × « KLASSCI batch fetch failed »
 *     10:19:23  Maximum execution time of 120 seconds exceeded
 *
 * Le commentaire d'origine annonçait pourtant « les matières de l'utilisateur ».
 *
 * ## La source correcte existait déjà
 *
 * Les dashboards portent cette liste, et c'est le motif DÉJÀ suivi ailleurs :
 *   - enseignant → `me/teacher-dashboard` → `data.matieres`
 *     ({@see \App\Services\Matiere\MyMatieresQueryService}, {@see \App\Services\Matiere\MatiereSeancesFetcher})
 *   - étudiant   → `me/dashboard` → `data.cours`
 *     ({@see StudentClassesSeancesFetcher}, dont le commentaire dit déjà
 *     « Utiliser les matières du dashboard au lieu de faire un nouvel appel API »)
 *
 * Forme vérifiée en direct avant transposition : les matières du dashboard
 * portent `id`, `nom` et `code` — les trois seuls champs que consomme
 * {@see UpcomingSeanceMapper} (0 manquant sur 6).
 *
 * ## Ce qu'on ne fait PAS
 *
 * Une liste vide n'est jamais un motif de repli sur le catalogue : sans matière,
 * l'utilisateur n'a pas de séance. Y retomber recréerait exactement le blocage
 * de 2 minutes que cette classe existe pour empêcher.
 *
 * Le manager (coordinateur/admin) ne passe pas par ici : il est servi depuis la
 * base locale en amont ({@see UpcomingSeancesFetcher::fetchForManager()}).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.4 (zéro N+1 HTTP) · §1.6 D (DI strict)
 */
final class UserOwnMatieresResolver
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function resolve(User $user, string $klassciToken): Collection
    {
        [$endpoint, $cle] = $user->isStudent()
            ? ['me/dashboard', 'cours']
            : ['me/teacher-dashboard', 'matieres'];

        $dashboard = $this->klassciService->requestWithUserToken($klassciToken, $endpoint, 'GET');

        $data = KlassciPayload::asArray($dashboard['data'] ?? null);

        /** @var Collection<int, array<string, mixed>> $matieres */
        $matieres = collect(KlassciPayload::listOfArrays($data[$cle] ?? null));

        $this->logger->info('Matières de l\'utilisateur résolues', [
            'endpoint' => $endpoint,
            'count' => $matieres->count(),
        ]);

        return $matieres;
    }
}
