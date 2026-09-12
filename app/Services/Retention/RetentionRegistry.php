<?php

declare(strict_types=1);

namespace App\Services\Retention;

/**
 * Les domaines purgeables connus, indexés par leur clé de ligne de commande.
 *
 * Ajouter un domaine = enregistrer une politique ici. Ni {@see RetentionRunner}
 * ni la commande ne changent — c'est l'article 1 du contrat OCP appliqué à la
 * destruction de données (#690, #697).
 *
 * Le registre reçoit ses politiques par injection plutôt que de les instancier :
 * un test peut donc en fournir une nouvelle et vérifier qu'elle s'exécute sans
 * qu'une seule ligne de la commande ait bougé.
 */
final class RetentionRegistry
{
    /** @var array<string, RetentionPolicy> */
    private array $politiques = [];

    /**
     * @param  iterable<RetentionPolicy>  $politiques
     */
    public function __construct(iterable $politiques = [])
    {
        foreach ($politiques as $politique) {
            $this->politiques[$politique->key()] = $politique;
        }
    }

    public function get(string $key): ?RetentionPolicy
    {
        return $this->politiques[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $cles = array_keys($this->politiques);
        sort($cles);

        return $cles;
    }
}
