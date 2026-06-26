<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Exceptions\KlassciAccountConflictException;
use App\Models\User;
use Psr\Log\LoggerInterface;

/**
 * Garde-fou : refuse d'assigner un email déjà détenu par un AUTRE compte de la
 * même institution lors du sync KLASSCI.
 *
 * Extrait de {@see KlassciUserSynchronizer} (issue #275, §1.1) — comportement
 * inchangé.
 *
 * ## Issue #253 — email = un compte à vie
 *
 * Côté KLASSCI un email identifie un compte à vie : un conflit est donc toujours
 * une anomalie de données. On échoue proprement (409 via
 * {@see KlassciAccountConflictException}) AVANT l'écriture, plutôt que de laisser
 * la contrainte unique `(email, institution_id)` remonter en 500 ou d'écraser
 * silencieusement l'autre compte.
 */
final class KlassciEmailConflictGuard
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param  string  $email            Email cible (vide = no-op)
     * @param  int|null  $institutionId   Institution scopée
     * @param  int|null  $currentUserId   Compte en cours de sync (exclu du contrôle)
     *
     * @throws KlassciAccountConflictException si un autre compte détient l'email.
     */
    public function assertNotOwnedByAnother(string $email, ?int $institutionId, ?int $currentUserId): void
    {
        if ($email === '') {
            return;
        }

        $query = User::withoutGlobalScope('institution')
            ->where('email', $email)
            ->where('institution_id', $institutionId);

        if ($currentUserId !== null) {
            $query->where('id', '!=', $currentUserId);
        }

        $conflict = $query->first();
        if ($conflict === null) {
            return;
        }

        // Anomalie de données : tracée pour réconciliation admin. On hash l'email
        // (PII) et on garde les klassci_id pour corréler sans exposer le clair.
        $this->logger->error('Conflit email au sync KLASSCI — compte non synchronisé', [
            'email_hash'             => substr(hash('sha256', $email), 0, 8),
            'institution_id'         => $institutionId,
            'conflicting_user_id'    => $conflict->id,
            'conflicting_klassci_id' => $conflict->klassci_id,
            'incoming_user_id'       => $currentUserId,
        ]);

        throw new KlassciAccountConflictException(
            'Conflit de compte : cet email est déjà associé à un autre compte de l\'institution.'
        );
    }
}
