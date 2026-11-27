<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;

/**
 * Compte local (école autonome, supradmin de plateforme) : le compte change son mot de passe (#1667).
 *
 * Le hachage et la révocation sont atomiques. Les AUTRES sessions du compte se ferment (un mot de passe changé parce
 * qu'il a fui ne laisse pas la porte ouverte ailleurs) ; la session courante survit : le compte vient de prouver le
 * mot de passe actuel. L'audit trace l'acte, jamais le mot de passe.
 */
final class LocalChangementDuMotDePasse implements ChangementDuMotDePasse
{
    public const ACTION = 'password_changed_by_self';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hash,
        private readonly AuditLogger $audit,
    ) {}

    public function ouvert(User $compte): void {}

    public function changer(User $compte, string $nouveau, ?int $jetonCourant): void
    {
        $this->db->transaction(function () use ($compte, $nouveau, $jetonCourant): void {
            $compte->forceFill(['password' => $this->hash->make($nouveau)])->save();
            $autres = $compte->tokens();
            $autres->delete();
        });

        $this->audit->logSecurityEvent(self::ACTION, $compte);
    }
}
