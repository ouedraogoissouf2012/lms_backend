<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Les tuteurs de l'élève (#1797), par le lien `dossier_tuteur` de SON dossier : ils ne portent pas de `user_id`.
 * Colonne par colonne, bornés à l'école (le dossier ET le tuteur), comme toute section (#1705).
 */
final class SectionTuteurs implements SectionDExport
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function cle(): string
    {
        return 'tuteurs';
    }

    public function titre(): string
    {
        return 'Tuteurs';
    }

    public function lignes(User $eleve, int $ecole): array
    {
        $tuteurs = $this->db->table('dossier_tuteur')
            ->join('dossiers_eleves', 'dossiers_eleves.id', '=', 'dossier_tuteur.dossier_eleve_id')
            ->join('tuteurs', 'tuteurs.id', '=', 'dossier_tuteur.tuteur_id')
            ->where('dossiers_eleves.user_id', $eleve->getKey())
            ->where('dossiers_eleves.institution_id', $ecole)
            ->where('tuteurs.institution_id', $ecole)
            ->orderBy('dossier_tuteur.id')
            ->get([
                'dossier_tuteur.relation', 'tuteurs.nom', 'tuteurs.telephone', 'tuteurs.courriel', 'tuteurs.profession',
                'tuteurs.adresse', 'dossier_tuteur.created_at as lie_le',
            ]);

        return array_values($tuteurs->map(static fn (object $ligne): array => (array) $ligne)->all());
    }
}
