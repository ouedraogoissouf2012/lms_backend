<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Psr\Log\LoggerInterface;

/**
 * Authentification locale LMS (comptes supradmin + users déjà sync depuis KLASSCI).
 *
 * ## Issue #120 — Extrait de `AuthController::login()` étape 1 (528 lignes → orchestrateur)
 *
 * Logique extraite des lignes 110-141 de l'ancien `AuthController.php`. C'est
 * la première étape du flow login : avant d'aller chercher sur KLASSCI, on
 * tente le login local (pour les supradmin LMS qui n'existent pas dans KLASSCI,
 * et comme fast-path pour les users déjà synchronisés qui ont un password local).
 *
 * ## Recherche cross-institution
 *
 * Utilise `withoutGlobalScope('institution')` car :
 * - Le **supradmin** est cross-institution par nature (n'appartient à aucune
 *   institution spécifique).
 * - Au moment du login, le tenant n'est pas encore résolu — on ne peut pas
 *   scoper par institution.
 *
 * Cette ouverture est sûre car compensée par `Hash::check($password)` qui
 * exige le mot de passe correct du user trouvé.
 *
 * ## DI strict (§1.6 D)
 *
 * - `Hasher` (contract Illuminate) pour `Hash::check()` — pas de Facade
 * - `LoggerInterface` (PSR-3) pour les logs — pas de Facade `Log::`
 *
 * Mockable trivialement en test unit via `Mockery::mock(Hasher::class)`.
 *
 * @see app/Http/Controllers/API/AuthController.php (avant refactor) lignes 110-141
 * @see .claude/specs/auth-controller-refactor/design.md §2.1
 */
class LocalLmsAuthenticator
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Nombre de comptes homonymes au-delà duquel la demande est refusée sans
     * calcul (#796).
     *
     * Mesuré : une vérification bcrypt coûte ~442 ms, et `/login` accepte 10
     * requêtes par minute. Sans plafond, quiconque peut créer des comptes — un
     * import suffit — transforme le login en amplificateur CPU : 100 homonymes
     * fabriqués valent 442 s de calcul par minute et par IP.
     *
     * Trois, parce qu'une collision légitime en compte deux (la même personne
     * dans deux établissements) et qu'une marge ne coûte rien. Ce plafond ne
     * peut PAS régresser sur l'existant : avant, un seul de N homonymes pouvait
     * se connecter ; désormais les trois premiers le peuvent.
     */
    private const MAX_CANDIDATES = 3;

    /**
     * Tente une authentification locale par email ou nom.
     *
     * @return User|null null si aucun compte ne correspond, ou si plusieurs
     *                   correspondent — l'ambiguïté n'est jamais tranchée au
     *                   hasard.
     */
    public function attemptLocalAuth(string $identifier, string $password): ?User
    {
        $candidates = $this->candidatesFor($identifier);

        if (count($candidates) > self::MAX_CANDIDATES) {
            // Refus AVANT tout calcul : c'est là qu'est la protection.
            $this->logger->warning('Login local refusé : trop de comptes partagent cet identifiant', [
                'candidats' => count($candidates),
            ]);

            return null;
        }

        $matches = $this->matching($candidates, $password);

        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                // Plusieurs comptes partageant identifiant ET mot de passe est un
                // signal d'exploitation autant qu'un accident : il doit se voir.
                // L'identifiant n'est pas journalisé — il vient d'un inconnu, et
                // le journal n'a pas à devenir le canal par lequel il écrit.
                $this->logger->warning('Login local refusé : identifiant ambigu', [
                    'correspondances' => count($matches),
                ]);
            }

            return null;
        }

        $user = $matches[0];

        $this->logger->info('Authentification locale LMS réussie', [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);

        return $user;
    }

    /**
     * Comptes susceptibles de porter cet identifiant.
     *
     * L'email est interrogé EN PREMIER et seul : c'est l'identifiant le plus
     * spécifique, et le faire primer empêche qu'un compte dont le `name` vaut
     * l'email d'un autre détourne la recherche. C'est aussi ce qui garde le
     * chemin nominal — un seul compte concerné — à une seule vérification.
     *
     * La recherche reste inter-institution : le supradmin n'appartient à aucune
     * institution, et le tenant n'est pas encore résolu au moment du login.
     *
     * Une ligne de plus que le plafond est chargée, juste assez pour SAVOIR
     * qu'il est dépassé sans ramener une table entière en mémoire.
     *
     * @return list<User>
     */
    private function candidatesFor(string $identifier): array
    {
        // `array_values` : `Collection::all()` rend un tableau dont les clés ne
        // sont pas garanties séquentielles, et le niveau 9 refuse de le tenir
        // pour une `list`. Réindexer est plus honnête qu'élargir le type promis.
        $byEmail = array_values(
            User::withoutGlobalScope('institution')
                ->where('email', $identifier)
                ->limit(self::MAX_CANDIDATES + 1)
                ->get()
                ->all()
        );

        if ($byEmail !== []) {
            return $byEmail;
        }

        return array_values(
            User::withoutGlobalScope('institution')
                ->where('name', $identifier)
                ->limit(self::MAX_CANDIDATES + 1)
                ->get()
                ->all()
        );
    }

    /**
     * Le mot de passe est le départageur : la seule information que seul le bon
     * compte possède. L'identifiant est ambigu par construction, le secret non.
     *
     * @param  list<User>  $candidates
     * @return list<User>
     */
    private function matching(array $candidates, string $password): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            // Les users créés depuis KLASSCI ont password = Hash::make(uniqid())
            // — non utilisable. Cette branche protège contre un login local
            // accidentel sur un user qui devrait passer par KLASSCI.
            if (! is_string($candidate->password) || $candidate->password === '') {
                continue;
            }

            if ($this->hasher->check($password, $candidate->password)) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }
}
