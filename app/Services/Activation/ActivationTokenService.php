<?php

declare(strict_types=1);

namespace App\Services\Activation;

use App\Models\ActivationToken;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Émission et consommation des jetons d'activation (#803, ADR-803-02).
 *
 * Le produit n'ayant aucun canal de courriel, aucun mot de passe n'est généré
 * ni transmis : on remet un lien à usage unique, que le titulaire consomme pour
 * poser SON secret. Le jour où un canal existera, ce même jeton partira par
 * courriel — le transport change, le mécanisme non.
 *
 * ## Le clair n'existe qu'une fois, en mémoire
 *
 * {@see self::emettre()} est le SEUL endroit où le jeton en clair existe. La
 * base n'en garde qu'une empreinte : même un accès en lecture à la table ne
 * permet pas de fabriquer un lien valide.
 *
 * ## Pourquoi SHA-256 et non bcrypt
 *
 * La recherche se fait PAR l'empreinte. Bcrypt, salé, imposerait de parcourir
 * toute la table pour retrouver un jeton. Le compromis est sans risque ici : le
 * jeton est un aléa de 64 caractères produit par {@see Str::random()}, pas un
 * mot de passe humain — ni faible entropie, ni réutilisation à craindre.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class ActivationTokenService
{
    private const LONGUEUR = 64;

    /**
     * Émet un jeton pour ce compte et rend le CLAIR, une seule fois.
     *
     * Tout jeton vivant du même compte est d'abord consommé : un lien réémis
     * doit invalider le précédent, sinon un lien égaré resterait utilisable.
     */
    public function emettre(User $user): string
    {
        $this->invaliderLesPrecedents($user);

        $clair = Str::random(self::LONGUEUR);

        $cle = $user->getKey();

        // `getKey()` rend `mixed` : on RESTREINT au lieu de convertir. Une clé
        // non entière signalerait un schéma inattendu ; l'écrire telle quelle
        // produirait un jeton orphelin plutôt qu'une panne lisible.
        if (! is_int($cle)) {
            throw new RuntimeException('Identifiant utilisateur inattendu.');
        }

        $jeton = new ActivationToken;
        $jeton->user_id = $cle;
        $jeton->token_hash = $this->empreinte($clair);
        $jeton->expires_at = now()->addDays($this->validiteEnJours());
        $jeton->save();

        return $clair;
    }

    /**
     * Consomme le jeton et rend son titulaire, ou `null` si le jeton est
     * inconnu, déjà consommé ou périmé.
     *
     * Les trois cas rendent `null` volontairement : distinguer « inconnu » de
     * « déjà servi » dirait à un inconnu qu'un lien a existé.
     */
    public function consommer(string $clair): ?User
    {
        $jeton = ActivationToken::query()
            ->where('token_hash', $this->empreinte($clair))
            ->first();

        if (! $jeton instanceof ActivationToken || ! $jeton->estUtilisable()) {
            return null;
        }

        $jeton->consumed_at = now();
        $jeton->save();

        return $jeton->user()->withoutGlobalScopes()->first();
    }

    private function invaliderLesPrecedents(User $user): void
    {
        ActivationToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    private function empreinte(string $clair): string
    {
        return hash('sha256', $clair);
    }

    private function validiteEnJours(): int
    {
        $jours = config('activation.validite_jours', 7);

        return is_int($jours) && $jours > 0 ? $jours : 7;
    }
}
