<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service Proxy pour l'API KLASSCI
 *
 * Ce service gère toutes les communications avec l'API KLASSCI backend
 * avec cache intelligent et gestion d'erreurs
 */
class KlassciProxyService
{
    private ?string $baseUrl = null;
    private ?string $token = null;
    private int $cacheTTL;
    private int $timeout;
    private bool $configResolved = false;

    public function __construct()
    {
        $this->cacheTTL = config('services.klassci.cache_ttl', 300);
        $this->timeout = config('services.klassci.timeout', 30);
    }

    /**
     * Résolution lazy de la config KLASSCI.
     * Priorité :
     *   1. Token personnel de l'utilisateur connecté (KLASSCI login)
     *   2. Token système de l'institution matchant klassci_tenant_url de l'utilisateur
     *      (pour les comptes créés via auth locale sans token personnel)
     *   3. Token système global (supradmin, routes publiques sans user)
     */
    private function resolveConfig(): void
    {
        if ($this->configResolved) {
            return;
        }

        $user = auth('sanctum')->user();

        // Priorité 1 : token personnel de l'utilisateur connecté
        if ($user && $user->klassci_token) {
            $tenantUrl = $user->klassci_tenant_url;

            // Fallback : extraire l'URL depuis klassci_data si klassci_tenant_url est null (anciens comptes)
            if (!$tenantUrl && $user->klassci_data) {
                $klassciData = is_array($user->klassci_data) ? $user->klassci_data : json_decode($user->klassci_data, true);
                $tenantUrl = $klassciData['_lms_tenant_url'] ?? null;
            }

            if ($tenantUrl) {
                $this->baseUrl = $tenantUrl;
                $this->token   = $user->klassci_token;
                $this->configResolved = true;

                // Mettre à jour klassci_tenant_url en base si manquant (migration silencieuse)
                if (!$user->klassci_tenant_url) {
                    $user->updateQuietly(['klassci_tenant_url' => $tenantUrl]);
                }
                return;
            }
        }

        // Priorité 2 : token de l'institution correspondant au tenant_url de l'utilisateur
        // Cas : auth locale (compte manuel) avec klassci_tenant_url défini mais sans token personnel
        if ($user && $user->klassci_tenant_url) {
            $tenantUrl = $user->klassci_tenant_url;
            $institution = \Illuminate\Support\Facades\Cache::remember(
                'institution_by_url_' . md5($tenantUrl),
                3600,
                fn () => \App\Models\Institution::where('klassci_api_url', $tenantUrl)
                    ->where('is_active', true)
                    ->first()
            );

            if ($institution && $institution->klassci_api_token) {
                $this->baseUrl = $institution->klassci_api_url;
                $this->token   = $institution->klassci_api_token;
                $this->configResolved = true;

                Log::info('KLASSCI resolveConfig: using institution token (no personal token)', [
                    'user_id'     => $user->id,
                    'institution' => $institution->slug,
                ]);
                return;
            }
        }

        // Priorité 3 : token système de l'institution (supradmin, routes sans user)
        $tenantManager = app(TenantManager::class);
        $config = $tenantManager->klassciConfig();
        $this->baseUrl = $config['url'] ?? config('services.klassci.url');
        $this->token   = $config['token'] ?? config('services.klassci.token');
        $this->configResolved = true;
    }

    /**
     * GET Request avec cache intelligent
     *
     * @param string $endpoint L'endpoint API (ex: 'classes', 'matieres')
     * @param array $params Paramètres query string
     * @param int|null $customTTL TTL cache personnalisé (en secondes)
     * @return array
     */
    public function get(string $endpoint, array $params = [], ?int $customTTL = null): array
    {
        $cacheKey = $this->generateCacheKey($endpoint, $params);
        $ttl = $customTTL ?? $this->cacheTTL;

        return Cache::remember($cacheKey, $ttl, function () use ($endpoint, $params) {
            return $this->makeRequest('GET', $endpoint, $params);
        });
    }

    /**
     * POST Request (pas de cache)
     *
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    public function post(string $endpoint, array $data): array
    {
        // Invalider le cache lié à cet endpoint
        $this->invalidateCache($endpoint);

        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * PUT Request (pas de cache)
     *
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    public function put(string $endpoint, array $data): array
    {
        $this->invalidateCache($endpoint);

        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * DELETE Request
     *
     * @param string $endpoint
     * @return array
     */
    public function delete(string $endpoint): array
    {
        $this->invalidateCache($endpoint);

        return $this->makeRequest('DELETE', $endpoint);
    }

    /**
     * Requête HTTP générique
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $this->resolveConfig();
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        try {
            Log::info("KLASSCI API Request", [
                'method' => $method,
                'url' => $url,
                'params' => $method === 'GET' ? $data : []
            ]);

            $request = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]);

            // SSL : désactivé si env local OU si configuré via KLASSCI_SSL_VERIFY=false
            if (str_starts_with($url, 'https://') && !config('services.klassci.ssl_verify', true)) {
                $request = $request->withoutVerifying();
            }

            // Ajouter le token si disponible (fix: chaînage correct)
            if ($this->token) {
                $request = $request->withToken($this->token);
            }

            // Exécuter la requête selon la méthode
            $response = match($method) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'DELETE' => $request->delete($url),
                default => throw new \Exception("Méthode HTTP non supportée: {$method}")
            };

            // Vérifier le statut
            if ($response->failed()) {
                Log::error("KLASSCI API Error", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                throw new \Exception(
                    "Erreur API KLASSCI: " . $response->status() . " - " . $response->body()
                );
            }

            $result = $response->json();

            Log::info("KLASSCI API Response", [
                'success' => $result['success'] ?? false
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("KLASSCI API Exception", [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint
            ]);

            throw $e;
        }
    }

    /**
     * Génère une clé de cache unique par tenant.
     * Priorité : tenant_url de l'utilisateur connecté > slug institution > 'default'.
     * Cela évite la pollution cross-institution du cache.
     */
    private function generateCacheKey(string $endpoint, array $params): string
    {
        $tenantKey = $this->resolveTenantCacheKey();
        $paramsHash = md5(json_encode($params));

        // Inclut le timestamp d'invalidation pour invalider automatiquement le cache
        $invalidationKey = "klassci_{$tenantKey}_invalidated_at";
        $invalidatedAt = Cache::get($invalidationKey, 0);

        return "klassci_{$tenantKey}_{$endpoint}_{$paramsHash}_{$invalidatedAt}";
    }

    /**
     * Invalide le cache pour les endpoints KLASSCI.
     *
     * Strategy HYBRID (2024 Laravel best practices):
     * 1. Essayer Cache::tags() — invalidation ciblée (Redis/Memcached)
     * 2. Fallback Cache::forget() — invalidation universelle (tous drivers)
     *
     * Multi-tenant safe: scope par institution_id + endpoint
     * Production-grade: gère tous les drivers (Redis, Memcached, File, Database)
     *
     * Why not just Cache::forget():
     * - Besoin de connaître les clés exactes (params hash problématique)
     * - Tags = pattern moderne recommandé 2024 (Laravel docs + OWASP)
     *
     * Why fallback:
     * - File driver ne supporte pas tags (need fallback)
     * - Cache::forever(timestamp) permet versioning en cas de tags fail
     */
    private function invalidateCache(string $endpoint): void
    {
        $tenantKey = $this->resolveTenantCacheKey();
        $cacheDriver = config('cache.default');

        // PRIMARY: Cache::tags() si le driver le supporte (Redis, Memcached)
        if ($this->driverSupportsTagging($cacheDriver)) {
            try {
                Cache::tags([
                    "klassci_{$endpoint}",      // Tag par endpoint (classes, matieres, evaluations)
                    "institution_{$tenantKey}"  // Tag par tenant (multi-tenant safety)
                ])->flush();

                Log::info('KLASSCI cache invalidated (tags)', [
                    'endpoint' => $endpoint,
                    'tenant' => $tenantKey,
                    'driver' => $cacheDriver
                ]);

                return;
            } catch (\Exception $e) {
                Log::warning('KLASSCI cache invalidation failed (tags)', [
                    'endpoint' => $endpoint,
                    'tenant' => $tenantKey,
                    'driver' => $cacheDriver,
                    'error' => $e->getMessage()
                ]);
                // Fall through to fallback
            }
        }

        // FALLBACK: Cache::forget() pattern pour tous les drivers
        // Invalide le timestamp, force recalc de toutes les clés du tenant
        $invalidationKey = "klassci_{$tenantKey}_invalidated_at";
        Cache::forget($invalidationKey);

        // Stocker nouveau timestamp pour les clés futures
        Cache::forever($invalidationKey, now()->timestamp);

        Log::info('KLASSCI cache invalidated (forget)', [
            'endpoint' => $endpoint,
            'tenant' => $tenantKey,
            'driver' => $cacheDriver
        ]);
    }

    /**
     * Vérifier si le driver supporte les tags.
     * Tags sont supportés: redis, memcached
     * Tags ne sont PAS supportés: file, database, dynamodb
     */
    private function driverSupportsTagging(string $driver): bool
    {
        return in_array($driver, ['redis', 'memcached'], true);
    }

    /**
     * Retourne une clé de cache unique par institution LMS.
     *
     * On utilise le slug de l'institution (TenantManager) — toujours unique par école.
     *
     * IMPORTANT : on n'utilise PAS md5(klassci_tenant_url) car plusieurs écoles peuvent
     * partager le même serveur KLASSCI → même URL → même md5 → pollution cross-institution.
     * Le slug LMS est la seule clé garantie unique par école.
     */
    private function resolveTenantCacheKey(): string
    {
        $slug = app(TenantManager::class)->slug();
        return $slug ?? 'default';
    }

    // ============================================
    // MÉTHODES SPÉCIFIQUES POUR CHAQUE ENDPOINT
    // ============================================

    /**
     * Récupère la structure organisationnelle (filières, niveaux)
     */
    public function getStructure(): array
    {
        return $this->get('structure', [], 3600); // Cache 1h
    }

    /**
     * Récupère toutes les classes actives
     */
    public function getClasses(array $filters = []): array
    {
        return $this->get('classes', $filters, 600); // Cache 10min
    }

    /**
     * Récupère les étudiants d'une classe
     */
    public function getClasseEtudiants(int $classeId, ?int $anneeId = null): array
    {
        $params = $anneeId ? ['annee_id' => $anneeId] : [];
        return $this->get("classes/{$classeId}/etudiants", $params, 300); // Cache 5min
    }

    /**
     * Récupère les matières
     */
    public function getMatieres(array $filters = []): array
    {
        return $this->get('matieres', $filters, 600); // Cache 10min
    }

    /**
     * Récupère les détails d'une matière
     */
    public function getMatiereDetails(int $id): array
    {
        return $this->get("matieres/{$id}", [], 600); // Cache 10min
    }

    /**
     * Récupère les enseignants (format simple)
     */
    public function getEnseignants(): array
    {
        return $this->get('enseignants', [], 3600); // Cache 1h
    }

    /**
     * Récupère les enseignants avec détails enrichis (matières, classes, statistiques)
     * @param bool $withDetails Si true, retourne les données enrichies
     * @return array
     */
    public function getEnseignantsEnrichis(bool $withDetails = true): array
    {
        $params = $withDetails ? ['with_details' => 'true'] : [];
        return $this->get('enseignants', $params, 600); // Cache 10min (plus court car données plus volatiles)
    }

    /**
     * Récupère les filières
     */
    public function getFilieres(): array
    {
        return $this->get('filieres', [], 3600); // Cache 1h
    }

    /**
     * Récupère les niveaux d'études
     */
    public function getNiveauxEtudes(): array
    {
        return $this->get('niveaux-etudes', [], 3600); // Cache 1h
    }

    /**
     * Récupère les évaluations depuis KLASSCI /api/lms/evaluations
     */
    public function getEvaluations(array $filters = []): array
    {
        return $this->get('evaluations', $filters, 300); // Cache 5min
    }

    /**
     * Récupère l'emploi du temps
     */
    public function getEmploiTemps(array $filters = []): array
    {
        return $this->get('emploi-temps', $filters, 600); // Cache 10min
    }

    /**
     * Sauvegarde les notes d'une évaluation vers KLASSCI /api/lms/evaluations/{id}/notes
     */
    public function saveNotes(int $evaluationId, array $notes): array
    {
        return $this->post("evaluations/{$evaluationId}/notes", [
            'notes' => $notes
        ]);
    }

    /**
     * Enregistre les présences d'un cours
     */
    public function savePresences(int $coursId, array $presences): array
    {
        return $this->post("cours/{$coursId}/presences", [
            'presences' => $presences
        ]);
    }

    /**
     * Met à jour le statut d'un cours
     */
    public function updateCoursStatut(int $coursId, string $statut, ?string $commentaire = null): array
    {
        return $this->put("cours/{$coursId}/statut", [
            'statut' => $statut,
            'commentaire' => $commentaire
        ]);
    }

    /**
     * Test de connexion à l'API
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->get('structure');
            return isset($response['success']) && $response['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Requête avec token utilisateur spécifique (pour endpoints personnels)
     *
     * @param string $userToken Token KLASSCI de l'utilisateur
     * @param string $endpoint L'endpoint API
     * @param string $method Méthode HTTP (GET, POST, etc.)
     * @param array $data Données à envoyer
     * @return array
     * @throws \Exception
     */
    public function requestWithUserToken(string $userToken, string $endpoint, string $method = 'GET', array $data = []): array
    {
        $this->resolveConfig();
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        try {
            Log::info("KLASSCI API Request (User Token)", [
                'method' => $method,
                'url' => $url,
                'has_user_token' => !empty($userToken)
            ]);

            $request = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]);

            // SSL : désactivé si configuré via KLASSCI_SSL_VERIFY=false
            if (str_starts_with($url, 'https://') && !config('services.klassci.ssl_verify', true)) {
                $request = $request->withoutVerifying();
            }

            $request = $request->withToken($userToken);

            // Exécuter la requête selon la méthode
            $response = match($method) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'DELETE' => $request->delete($url),
                default => throw new \Exception("Méthode HTTP non supportée: {$method}")
            };

            // Vérifier le statut
            if ($response->failed()) {
                Log::error("KLASSCI API Error (User Token)", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                throw new \Exception(
                    "Erreur API KLASSCI: " . $response->status() . " - " . $response->body()
                );
            }

            $result = $response->json();

            Log::info("KLASSCI API Response (User Token)", [
                'success' => $result['success'] ?? false
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("KLASSCI API Exception (User Token)", [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint
            ]);

            throw $e;
        }
    }
}
