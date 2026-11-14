<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;
use App\Services\Seances\KlassciPayload;
use App\Services\TenantManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;

/**
 * École KLASSCI : la liste COMPLÈTE d'une classe fait foi. Un élève qui n'y est plus
 * (changement de classe) y passe `inactif`. Sinon, sa ligne resterait `actif` pour
 * toujours, puisque l'upsert ne lit que les élèves listés.
 *
 * - Liste non prouvée complète ({@see ListeKlassciDesEleves::estComplete()}) : rien,
 *   et l'écart est journalisé (nombres et id de classe, sans identité d'élève).
 * - Les absents se jugent sur la liste REÇUE : un élève listé dont l'écriture a
 *   échoué n'est pas absent.
 * - Écriture CONDITIONNELLE (`statut = actif`), bornée à la classe et aux lignes de
 *   l'établissement courant ou héritées sans établissement, jamais celles d'un autre.
 * - Jamais de suppression : l'élève qui revient dans la liste repasse `actif` par
 *   l'upsert ({@see ClasseStudentsSynchronizer}).
 * - Un élève sans identifiant KLASSCI (inscrit avant une bascule du mode autonome
 *   vers KLASSCI) n'est pas jugé par la liste KLASSCI : sa ligne n'est pas touchée.
 */
final class ReconciliationKlassci implements ReconciliationDesInscriptions
{
    public const INCOMPLETE = 'Liste des eleves incomplete : aucune inscription desactivee';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function reconcilier(Classe $classe, ListeKlassciDesEleves $liste): void
    {
        $listes = $liste->identifiants();

        if ($listes === null || ! $liste->estComplete()) {
            $this->logger->warning(self::INCOMPLETE, [
                'classe_id' => $classe->id,
                'recus' => $liste->nombreRecu(),
                'annonces' => KlassciPayload::toInt($liste->effectifAnnonce()),
            ]);

            return;
        }

        $institutionId = $this->tenantManager->getResolved();
        $desactivees = $this->db->table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('statut', 'actif')
            ->where(static fn (Builder $ligne): Builder => $ligne
                ->where('institution_id', $institutionId)
                ->orWhereNull('institution_id'))
            ->whereIn('user_id', $this->db->table('users')
                ->select('id')
                ->whereNotNull('klassci_id')
                ->whereNotIn('klassci_id', $listes))
            ->update(['statut' => 'inactif', 'updated_at' => now()]);

        if ($desactivees > 0) {
            $this->logger->info('Inscriptions absentes de la liste complete desactivees', [
                'classe_id' => $classe->id,
                'desactivees' => $desactivees,
            ]);
        }
    }
}
