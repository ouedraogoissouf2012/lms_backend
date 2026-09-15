<?php

declare(strict_types=1);

namespace App\Services\Activation;

use App\Exceptions\BusinessException;

/**
 * Compose le lien d'activation remis au supradmin (#803, ADR-803-02).
 *
 * ## Pourquoi cette classe existe au lieu d'un `url()` dans le contrôleur
 *
 * `url()` construit une adresse sur `app.url`, c'est-à-dire l'hôte de l'API.
 * Or l'écran d'activation est une page de l'application WEB : un lien bâti
 * ainsi mène à un hôte où aucune page d'activation n'existe.
 *
 * Le défaut serait invisible à la relecture et invisible aux tests — ils
 * extraient le jeton de l'URL sans jamais la suivre. Il n'apparaîtrait que chez
 * le destinataire, hors de portée de tout journal, sous la forme d'un compte
 * qu'on ne peut plus ouvrir.
 *
 * ## Fail-closed, délibérément
 *
 * Aucun repli sur `app.url`. Sans `ACTIVATION_URL_FRONT`, la validation échoue
 * bruyamment — même discipline que `KlassciConfigResolver::requireBaseUrl()`,
 * qui refuse plutôt que de deviner une cible. (Cité en prose : un {@see} vers
 * une classe KLASSCI deviendrait un import réel sous Pint, et ce service
 * dépendrait nominalement d'un monde avec lequel il n'a rien à voir.)
 *
 * Le refus survient DANS la transaction de validation : rien n'est écrit, la
 * demande reste en attente, et l'exploitant corrige sa configuration avant de
 * rejouer. L'alternative — écrire l'école puis remettre un lien mort — laisserait
 * un établissement sans accès et une demande marquée validée.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class ActivationLinkBuilder
{
    private const CHEMIN = '/activation/';

    /**
     * @throws BusinessException si l'URL de l'application web n'est pas configurée
     */
    public function pour(string $jetonClair): string
    {
        $base = config('activation.url_front');

        if (! is_string($base) || trim($base) === '') {
            throw new BusinessException(
                "L'adresse de l'application web n'est pas configurée (ACTIVATION_URL_FRONT) : "
                ."impossible de produire un lien d'activation utilisable.",
                503,
            );
        }

        return rtrim(trim($base), '/').self::CHEMIN.$jetonClair;
    }
}
