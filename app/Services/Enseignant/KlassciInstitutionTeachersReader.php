<?php

declare(strict_types=1);

namespace App\Services\Enseignant;

use App\Models\LmsEnseignantCache;
use App\Models\User;
use App\Services\Klassci\TeacherDirectory\LocalTeacherDirectory;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use App\Services\KlassciProxyService;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Support\Facades\Log;

/**
 * Les enseignants d'une école créée depuis KLASSCI : la logique des deux
 * contrôleurs d'avant le lot 3b, déplacée telle quelle.
 *
 * - L'annuaire relaie `getEnseignants()`, se replie sur la base locale quand
 *   KLASSCI n'en rend aucun, et sert la réponse selon la grille.
 * - La liste enrichie relaie `getEnseignantsEnrichis()`, réchauffe le cache
 *   local avec les détails, sert les lignes selon la grille, et y joint le
 *   `meta` de KLASSCI.
 *
 * La grille est appliquée APRÈS le cache de KLASSCI, commun à l'établissement
 * (`TeacherDirectoryVisibility::envelopeFor`).
 */
final class KlassciInstitutionTeachersReader implements InstitutionTeachersReader
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly TeacherDirectoryVisibility $visibility,
        private readonly LocalTeacherDirectory $localDirectory,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function annuaire(User $lecteur): array
    {
        $data = $this->klassciService->getEnseignants();

        if (empty($data['data'])) {
            Log::info('KLASSCI retourne 0 enseignants, utilisation BDD locale');

            return $this->localDirectory->forReader($lecteur);
        }

        return $this->visibility->envelopeFor($lecteur, $data);
    }

    public function listeEnrichie(User $lecteur, bool $details): array
    {
        $startTime = microtime(true);

        Log::info('[LMS Enseignants KLASSCI] Récupération depuis API externe', [
            'with_details' => $details,
        ]);

        $response = $this->klassciService->getEnseignantsEnrichis($details);

        if (! isset($response['success']) || ! $response['success']) {
            $message = $response['message'] ?? null;

            return ['success' => false, 'message' => is_string($message) ? $message : 'Erreur KLASSCI', 'data' => [], 'meta' => []];
        }

        if ($details) {
            /** @var array<int, array<string, mixed>> $enseignants */
            $enseignants = is_array($response['data'] ?? null) ? $response['data'] : [];
            $this->warmEnseignantCache($enseignants);
        }

        $servie = $this->visibility->envelopeFor($lecteur, $response);
        $lignes = $servie['data'] ?? [];

        return ['success' => true, 'data' => is_array($lignes) ? $lignes : [], 'meta' => $this->buildMeta($servie, $startTime)];
    }

    /**
     * Rechauffe le cache local des enseignants — opportuniste par nature : un
     * echec d'ecriture ne doit jamais faire echouer la requete qui, elle, a
     * deja obtenu sa donnee de KLASSCI.
     *
     * @param  array<int, array<string, mixed>>  $enseignants
     */
    private function warmEnseignantCache(array $enseignants): void
    {
        foreach ($enseignants as $enseignant) {
            // Un id KLASSCI entier, ou en chaîne numérique. Tout autre n'est pas
            // mis en cache : il lèverait une TypeError que ce repli n'attrape pas.
            $id = $enseignant['id'] ?? null;
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : null);
            if ($id === null) {
                continue;
            }

            try {
                LmsEnseignantCache::store($id, $enseignant, 10);
            } catch (\Exception $cacheErr) {
                $this->journal->warning('[LMS Enseignants KLASSCI] Cache store failed', $cacheErr, [
                    'enseignant_id' => $id,
                ]);
            }
        }
    }

    /**
     * Fusionne le `meta` renvoye par KLASSCI avec les indicateurs propres au LMS.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<string, mixed>
     */
    private function buildMeta(array $response, float $startTime): array
    {
        $klassciMeta = $response['meta'] ?? [];

        return array_merge(is_array($klassciMeta) ? $klassciMeta : [], [
            'source' => 'klassci_externe',
            'lms_cache_enabled' => true,
            'lms_performance' => [
                'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ],
        ]);
    }
}
