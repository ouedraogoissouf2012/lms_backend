<?php

declare(strict_types=1);

namespace App\Services\Klassci\Concerns;

/**
 * Catalogue des 15 méthodes endpoint sémantiques KLASSCI.
 *
 * ## Pourquoi un trait
 *
 * Audit `spec-architect` PR 2 MEDIUM-1 : `KlassciProxyService` dépassait §1.1
 * (300 lignes) à cause des 15 méthodes endpoint (`getStructure`, `getMatieres`,
 * etc.) qui sont des wrappers thin (`get` + endpoint + TTL).
 *
 * Plusieurs solutions ont été évaluées :
 *
 * - **Classe séparée `KlassciEndpointCatalog`** : crée un cycle DI
 *   (`KlassciProxyService` → `EndpointCatalog` → `RawClient` = `KlassciProxyService`).
 *   Ou duplique la logique cache+memo (anti-pattern).
 * - **Suppression des wrappers, callers utilisent `get('structure', [], 3600)`** :
 *   break-change massif sur 12+ controllers, perte de la sémantique métier (TTL
 *   par défaut sensé, signature typée).
 * - **Trait** (cette solution) : extrait le code dans un fichier dédié sans
 *   créer de cycle, sans casser les callers. Les 15 méthodes restent visibles
 *   sur `KlassciProxyService` (back-compat 100%), mais leur code vit ici.
 *
 * ## Contrat
 *
 * Le contrat exact attendu de la classe hôte est déclaré par les méthodes
 * `abstract` en fin de trait — c'est là qu'il est vérifié par PHP, et non dans
 * cette prose.
 *
 * ## Classification des endpoints (#591) — à respecter pour tout ajout
 *
 * - **Lié à l'identité** → `requestWithUserToken($userToken, …)` : KLASSCI fait
 *   varier la réponse selon le porteur. La clé de cache dérive du hash du
 *   porteur ; le raccourci **exige** donc le jeton en premier paramètre — sans
 *   quoi la réponse du 1ᵉʳ appelant fuite à tout le tenant (#568, #591).
     * Aujourd'hui : `evaluations`, `emploi-temps`, `matieres`, `matieres/{id}`.
 * - **Tenant-partagé** → `get()` : charge utile prouvément identique pour tout
 *   le tenant. Clé de cache globale, taux de hit maximal.
 *   Aujourd'hui : `structure`, `filieres`, `niveaux-etudes`, `enseignants`.
 *
 * ⚠️ **Non tranché — ne pas citer en exemple de « tenant-partagé »** :
 * `classes/{id}/etudiants` passe encore par `get()` (autorisation KLASSCI
 * contournable par cache hit — #617).
 *
 * En cas de doute, choisir la variante par porteur : une clé par porteur sur une
 * donnée tenant-wide ne coûte que du taux de hit ; une clé globale sur une
 * donnée liée à l'identité **fuit**.
 *
 * Garde exécutable de cette règle :
 * {@see \Tests\Unit\Services\Klassci\KlassciEndpointClassificationGuardTest}.
 *
 * @see \App\Services\KlassciProxyService
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §7 (table TTL)
 */
trait HasKlassciEndpointShortcuts
{
    /**
     * @return array<string, mixed>
     */
    public function getStructure(): array
    {
        return $this->get('structure', [], 3600);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getClasses(array $filters = []): array
    {
        return $this->get('classes', $filters, 600);
    }

    /**
     * ⚠️ #591 (audit `spec-security`) — **classification non tranchée**. Le roster
     * d'une classe est mis en cache sous une clé tenant-globale alors que
     * `/api/proxy/classes/{id}/etudiants` (`routes/api/core.php:83`) est ouverte à
     * tous les rôles. Si KLASSCI est le seul garde d'autorisation, un appelant
     * NON autorisé peut obtenir le roster par *cache hit* sans que son jeton
     * n'atteigne jamais KLASSCI — le cache devient un contournement
     * d'autorisation. Non corrigé ici (hors périmètre #591), issue de suivi à
     * ouvrir. NE PAS citer cette méthode en exemple de « tenant-partagé ».
     *
     * @return array<string, mixed>
     */
    public function getClasseEtudiants(int $classeId, ?int $anneeId = null): array
    {
        $params = $anneeId ? ['annee_id' => $anneeId] : [];

        return $this->get("classes/{$classeId}/etudiants", $params, 300);
    }

    /**
     * #616 — même classe de fuite que {@see self::getEvaluations()}.
     * KLASSCI scope les matières selon le porteur ; le reste du dépôt les
     * consomme déjà avec le jeton perso. Clé de cache dérivée du porteur.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getMatieres(string $userToken, array $filters = []): array
    {
        return $this->requestWithUserToken($userToken, 'matieres', 'GET', $filters, 600);
    }

    /**
     * #616 — même raisonnement que {@see self::getMatieres()}.
     *
     * @return array<string, mixed>
     */
    public function getMatiereDetails(string $userToken, int $id): array
    {
        return $this->requestWithUserToken($userToken, "matieres/{$id}", 'GET', [], 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnseignants(): array
    {
        return $this->get('enseignants', [], 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnseignantsEnrichis(bool $withDetails = true): array
    {
        $params = $withDetails ? ['with_details' => 'true'] : [];

        return $this->get('enseignants', $params, 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilieres(): array
    {
        return $this->get('filieres', [], 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getNiveauxEtudes(): array
    {
        return $this->get('niveaux-etudes', [], 3600);
    }

    /**
     * #591 — ressource **liée à l'identité** : KLASSCI scope la liste selon le
     * porteur (les évaluations d'un enseignant ne sont pas celles d'un étudiant)
     * et `/api/proxy/evaluations` est ouverte à TOUS les rôles
     * (`routes/api/core.php:94`). Passer par `get()` produisait une clé de cache
     * tenant-globale : la réponse du 1ᵉʳ appelant était servie à tout le tenant
     * — fuite d'identité, même classe que #568 (`/auth/me`).
     *
     * Le porteur est un paramètre **obligatoire** et non une valeur devinée :
     * c'est ce qui rend l'isolation vérifiable par le typage plutôt que par la
     * vigilance de l'appelant (cf. § Classification, et design.md §1.1).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getEvaluations(string $userToken, array $filters = []): array
    {
        return $this->requestWithUserToken($userToken, 'evaluations', 'GET', $filters, 300);
    }

    /**
     * #591 — même raisonnement que {@see self::getEvaluations()} : l'emploi du
     * temps renvoyé dépend du porteur (étudiant de sa classe, enseignant de ses
     * cours). Clé de cache dérivée du porteur, jamais globale au tenant.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getEmploiTemps(string $userToken, array $filters = []): array
    {
        return $this->requestWithUserToken($userToken, 'emploi-temps', 'GET', $filters, 600);
    }

    /**
     * @param  array<int, array<string, mixed>>  $notes
     * @return array<string, mixed>
     */
    public function saveNotes(int $evaluationId, array $notes): array
    {
        return $this->post("evaluations/{$evaluationId}/notes", [
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $presences
     * @return array<string, mixed>
     */
    public function savePresences(int $coursId, array $presences): array
    {
        return $this->post("cours/{$coursId}/presences", [
            'presences' => $presences,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCoursStatut(int $coursId, string $statut, ?string $commentaire = null): array
    {
        return $this->put("cours/{$coursId}/statut", [
            'statut'      => $statut,
            'commentaire' => $commentaire,
        ]);
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->get('structure');

            return isset($response['success']) && (bool) $response['success'];
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    abstract public function get(string $endpoint, array $params = [], ?int $customTTL = null): array;

    /**
     * Variante de {@see self::get()} dont la clé de cache dérive du hash du
     * porteur — obligatoire pour toute ressource dont KLASSCI fait varier la
     * réponse selon l'identité du porteur (#591).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    abstract public function requestWithUserToken(
        string $userToken,
        string $endpoint,
        string $method = 'GET',
        array $data = [],
        ?int $customTTL = null,
    ): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function post(string $endpoint, array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function put(string $endpoint, array $data): array;
}
