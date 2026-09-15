<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jeton d'activation à usage unique (#803, ADR-803-02).
 *
 * N'utilise PAS `BelongsToInstitution` : le jeton suit son titulaire, qui porte
 * déjà son institution. L'ajouter au périmètre multi-tenant ferait dépendre la
 * consommation d'un tenant résolu — or l'activation est un chemin ANONYME, par
 * définition antérieur à toute authentification.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash Empreinte SHA-256 ; le clair n'est jamais stocké.
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property-read User|null $user
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
class ActivationToken extends Model
{
    /**
     * Aucun champ n'est remplissable en masse : un jeton n'est jamais construit
     * depuis une charge utile client. Seul le service d'émission l'écrit, et il
     * pose chaque attribut explicitement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Utilisable : ni consommé, ni périmé.
     *
     * Les deux conditions sont distinctes et toutes deux nécessaires —
     * l'expiration seule laisserait rejouer le lien dans sa fenêtre.
     */
    public function estUtilisable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
