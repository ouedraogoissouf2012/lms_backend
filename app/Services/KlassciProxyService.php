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
    private string $baseUrl;
    private ?string $token;
    private int $cacheTTL;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.klassci.url');
        $this->token = config('services.klassci.token');
        $this->cacheTTL = config('services.klassci.cache_ttl', 300);
        $this->timeout = config('services.klassci.timeout', 30);
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

            // Ajouter le token si disponible
            if ($this->token) {
                $request->withToken($this->token);
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
     * Génère une clé de cache unique
     */
    private function generateCacheKey(string $endpoint, array $params): string
    {
        $paramsHash = md5(json_encode($params));
        return "klassci_api_{$endpoint}_{$paramsHash}";
    }

    /**
     * Invalide le cache pour un endpoint
     */
    private function invalidateCache(string $endpoint): void
    {
        // Invalider toutes les clés commençant par l'endpoint
        Cache::flush(); // Simplifié pour l'instant

        Log::info("Cache invalidé pour endpoint: {$endpoint}");
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
     * Récupère les enseignants
     */
    public function getEnseignants(): array
    {
        return $this->get('enseignants', [], 3600); // Cache 1h
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
     * Récupère les évaluations
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
     * Sauvegarde les notes d'une évaluation
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
}
