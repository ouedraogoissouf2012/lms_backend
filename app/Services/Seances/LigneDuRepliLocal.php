<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use App\Services\Visio\VisioActorAuthorization;
use Psr\Log\LoggerInterface;

/**
 * La règle du repli du détail ({@see SeanceVisioEnricher::loadFromLocalDbFallback()}),
 * quand KLASSCI ne rend pas la séance : la ligne locale trouvée n'est décrite qu'à
 * qui gère la séance ({@see VisioActorAuthorization::canManage()}) et à un élève de
 * sa classe ({@see ClasseAudienceSource::containsStudent()}). Une séance archivée
 * n'est jamais décrite à un élève.
 *
 * Une ligne non retenue est comptée au journal (la séance et le rôle, sans
 * identité) ; le repli répond alors comme pour une séance inexistante.
 */
final class LigneDuRepliLocal
{
    public const NON_RETENUE = 'Repli local du detail : seance non retenue';

    public function __construct(
        private readonly VisioActorAuthorization $autorisation,
        private readonly ClasseAudienceSource $audience,
        private readonly LoggerInterface $logger,
    ) {}

    public function retient(Seance $ligne, int $seanceId, User $user): bool
    {
        if ($user->isStudent() && ! $ligne->is_active) {
            return false;
        }

        if ($this->autorisation->canManage($ligne, $user) || $this->audience->containsStudent($ligne, $user)) {
            return true;
        }

        $this->logger->info(self::NON_RETENUE, ['seance_id' => $seanceId, 'role' => $user->isStaff() ? 'staff' : 'apprenant']);

        return false;
    }
}
