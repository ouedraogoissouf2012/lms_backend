<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\User;
use App\Services\Eleve\PhotoDeLEleve;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory as Disques;
use Illuminate\Database\ConnectionInterface;

/**
 * La photo de l'élève (#1803), dans l'export (#1705) : ses MÉTADONNÉES seulement (type, taille, date du dépôt) et la
 * mention qu'elle est remise À PART. Ni le binaire ni le chemin de stockage n'entrent dans le JSON. Sans photo, ou si
 * le fichier n'est plus sur le disque : aucune ligne.
 */
final class SectionPhoto implements SectionDExport
{
    public const REMISE = 'Le fichier de la photo est remis à part, avec cet export ; il n\'est pas inclus ici.';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Disques $disques,
    ) {}

    public function cle(): string
    {
        return 'photo';
    }

    public function titre(): string
    {
        return 'Photo';
    }

    public function lignes(User $eleve, int $ecole): array
    {
        $chemin = $this->db->table('dossiers_eleves')
            ->where('user_id', $eleve->getKey())->where('institution_id', $ecole)->value('photo_chemin');
        $disque = $this->disques->disk(PhotoDeLEleve::DISQUE);
        if (! is_string($chemin) || $chemin === '' || ! $disque->exists($chemin)) {
            return [];
        }

        $type = $disque->mimeType($chemin);

        return [[
            'type' => is_string($type) ? $type : null,
            'taille' => $disque->size($chemin),
            'deposee_le' => (new DateTimeImmutable)->setTimestamp($disque->lastModified($chemin))->format(DATE_ATOM),
            'remise' => self::REMISE,
        ]];
    }
}
