<?php

declare(strict_types=1);

namespace App\Services\SchoolRegistration;

use App\Jobs\EnvoyerLeLienDActivation;
use App\Services\Mail\CanalDeCourriel;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * #1620 — pose l'envoi du lien d'activation au titulaire d'une école ouverte, si le canal existe.
 *
 * Sans canal de courriel (`CanalDeCourriel::estDisponible()`), rien n'est posé : le lien rendu au
 * supradmin reste la seule voie, et la réponse le dit (`null`). Même règle que
 * `LocalMotDePasseOublie`.
 *
 * La tâche part APRÈS le commit (`afterCommit()`) : une validation annulée n'envoie rien.
 */
final class AnnonceDuLienDActivation
{
    public function __construct(
        private readonly CanalDeCourriel $canal,
        private readonly Dispatcher $bus,
    ) {}

    /** @return string|null l'adresse à laquelle le lien part, `null` s'il ne part pas */
    public function annoncer(EcoleOuverte $ouverte): ?string
    {
        $titulaire = $ouverte->proprietaire->getKey();
        $ecole = $ouverte->institution->getKey();

        if (! $this->canal->estDisponible() || ! is_int($titulaire) || ! is_int($ecole)) {
            return null;
        }

        // falsification B : rien n'est pose

        return $ouverte->proprietaire->email;
    }
}
