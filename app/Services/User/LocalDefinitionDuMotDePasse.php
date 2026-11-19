<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;

/**
 * École autonome : le mot de passe local est le seul, l'administration le définit (#1275).
 *
 * Le hachage et la révocation des jetons sont atomiques : un mot de passe changé ne laisse
 * aucune session ouverte, et une session révoquée ne survit pas à un mot de passe inchangé.
 * L'audit trace l'acte, jamais le mot de passe.
 */
final class LocalDefinitionDuMotDePasse implements DefinitionDuMotDePasse
{
    public const ACTION = 'password_set_by_manager';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hash,
        private readonly AuditLogger $audit,
    ) {}

    public function definir(User $cible, string $motDePasse): void
    {
        $this->db->transaction(function () use ($cible, $motDePasse): void {
            $cible->forceFill(['password' => $this->hash->make($motDePasse)])->save();
            $cible->tokens()->delete();
        });

        $this->audit->logSecurityEvent(self::ACTION, $cible);
    }
}
