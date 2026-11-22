<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Audit\AuditLogger;
use Psr\Log\LoggerInterface;

/**
 * Le jeton API KLASSCI d'une école : quand il s'écrit, et quand il s'efface.
 *
 * ## Il s'écrit par ici, jamais par `$fillable` (#1332)
 *
 * Un secret affectable en masse s'écrirait depuis n'importe quel tableau qui porte la
 * clé : l'alias `klassci_api_token` reste donc hors de `Institution::$fillable`, et
 * `fill()` le jette. Il est posé ici, par le mutateur, qui le chiffre. Un jeton vide
 * (`null` ou `''`) ne touche pas l'ancien : l'écran annonce « laisser vide pour
 * conserver l'existant ».
 *
 * ## Il s'efface quand l'école se détache (#1213)
 *
 * Décision du user (22/11). Un jeton gardé après un détachement partirait, au
 * réattachement, vers une cible qui n'est peut-être plus la sienne :
 * - l'URL est vidée : effacé, même si un jeton est saisi dans la même requête, car
 *   sans URL il n'a pas de cible ;
 * - l'URL change sans nouveau jeton : effacé. Avec un nouveau jeton, c'est celui de
 *   la nouvelle cible, et il est gardé. « Change » se lit au caractère près, sur
 *   l'URL déjà rendue canonique par son mutateur (sans « / » final, #1331) : la même
 *   URL saisie avec un « / » final ne change pas ;
 * - l'école bascule en autonome : effacé.
 *
 * Chaque effacement RÉEL laisse une trace d'audit qui dit pourquoi, jamais la valeur.
 * Une école qui n'avait pas de jeton n'en laisse aucune.
 */
final class JetonKlassciDeLEcole
{
    public const EFFACE = 'institution.klassci_token_erased';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * À appeler après `fill()` et avant `save()`. Rend la raison d'un effacement, ou `null`.
     */
    public function poser(Institution $ecole, mixed $saisi): ?string
    {
        $jeton = is_string($saisi) && $saisi !== '' ? $saisi : null;
        $raison = $this->raisonDuDetachement($ecole, $jeton);

        if ($raison !== null) {
            return $this->effacer($ecole, $raison);
        }

        if ($jeton !== null) {
            $ecole->klassci_api_token = $jeton;
        }

        return null;
    }

    /**
     * À appeler avant `save()`, au changement de mode. Rend la raison d'un effacement, ou `null`.
     */
    public function auBasculement(Institution $ecole, InstitutionMode $vers): ?string
    {
        return $vers === InstitutionMode::Standalone ? $this->effacer($ecole, 'bascule_autonome') : null;
    }

    /** À appeler APRÈS `save()` : n'est tracé que ce qui a réellement été écrit. */
    public function tracer(Institution $ecole, ?string $raison): void
    {
        if ($raison === null) {
            return;
        }

        $this->audit->logSecurityEvent(self::EFFACE, $ecole, ['raison' => $raison]);
        $this->logger->info('Institution KLASSCI token erased', ['id' => $ecole->id, 'raison' => $raison]);
    }

    private function raisonDuDetachement(Institution $ecole, ?string $jeton): ?string
    {
        if (! $ecole->exists || ! $ecole->isDirty('klassci_api_url')) {
            return null;
        }

        if ($ecole->klassci_api_url === null || $ecole->klassci_api_url === '') {
            return 'url_videe';
        }

        return 'url_changee';
    }

    /** Efface, et ne rend une raison que s'il y avait un jeton à effacer. */
    private function effacer(Institution $ecole, string $raison): ?string
    {
        $avait = $ecole->getRawOriginal('klassci_api_token_encrypted') !== null;
        $ecole->klassci_api_token = null;

        return $avait ? $raison : null;
    }
}
