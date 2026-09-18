<?php

declare(strict_types=1);

namespace App\Services\Classe;

/**
 * « Qui sont les étudiants de cette classe ? » — la question, séparée du chemin
 * par lequel on y répond.
 *
 * ## Pourquoi une interface pour une seule implémentation
 *
 * L'implémentation est `final`, comme 88 % des services du dépôt : un double de
 * test ne peut donc pas en hériter. Le dépôt a déjà tranché ce cas en #578 —
 * {@see \App\Services\Klassci\KlassciTargetResolver} est une interface d'un seul
 * membre extraite d'une classe `final` pour la rendre substituable sans
 * contourner le typage. Même geste ici : l'appelant dépend de la question, pas
 * du serveur qui y répond, et un établissement autonome pourra y répondre
 * localement sans qu'aucun appelant ne change.
 *
 * ## Ce que cette abstraction ne promet PAS
 *
 * Elle ne promet pas de réussir. Un refus ou une panne amont remonte en
 * {@see \RuntimeException} portant le statut, que les contrôleurs traduisent via
 * {@see \App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors} (#270).
 * Avaler l'échec ici le transformerait en « classe vide » — un zéro fabriqué,
 * indiscernable d'une mesure.
 */
interface ClasseRoster
{
    /**
     * Étudiants de la classe, tels que la source fait foi.
     *
     * Toujours un tableau : l'appelant distingue « aucun étudiant » d'une classe
     * introuvable par le STATUT de l'échec (404), jamais par une liste vide —
     * même contrat que {@see ClasseEnvelope::etudiants()}.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException refus (4xx) ou indisponibilité de la source.
     */
    public function etudiants(int $classeId, string $userToken): array;
}
