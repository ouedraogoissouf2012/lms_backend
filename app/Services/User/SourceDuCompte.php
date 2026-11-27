<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Contracts\Container\Container;

/**
 * La source qui fait foi pour le compte LUI-MÊME (#1667) : son mot de passe, son profil.
 *
 * `RosterAuthorityFactory::sourceForAccount` rend la source KLASSCI pour un compte sans établissement. Or le
 * supradmin de PLATEFORME n'en a pas, et son compte est LOCAL (il se connecte par le mot de passe du LMS) : il serait
 * renvoyé à tort vers KLASSCI. Il reçoit donc la source locale ; tout autre compte, celle de son école.
 */
final class SourceDuCompte
{
    public function __construct(
        private readonly RosterAuthorityFactory $autorites,
        private readonly Container $container,
    ) {}

    public function motDePasse(User $compte): ChangementDuMotDePasse
    {
        return $compte->isPlatformSupradmin()
            ? $this->container->make(LocalChangementDuMotDePasse::class)
            : $this->autorites->sourceForAccount($compte, KlassciChangementDuMotDePasse::class, LocalChangementDuMotDePasse::class);
    }

    public function profil(User $compte): ModificationDuProfil
    {
        return $compte->isPlatformSupradmin()
            ? $this->container->make(LocalModificationDuProfil::class)
            : $this->autorites->sourceForAccount($compte, KlassciModificationDuProfil::class, LocalModificationDuProfil::class);
    }
}
