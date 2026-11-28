<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\EtatDeValidation;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\ClasseEtudiant;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Database\DatabaseManager;

/**
 * Valider, annuler ou garder sous réserve une inscription (#1658, ligne `classe_etudiant`), gestion d'une école
 * autonome. La validation est un état À CÔTÉ du statut : seul le statut porte l'accès.
 *
 * - Valider : depuis `en_attente` seulement (409 `etat_de_validation`), sans réserve non levée (409
 *   `reserve_non_levee`), s'il reste une place (409 `class_full`) ; l'accès s'ouvre (`actif`).
 * - Annuler (motif requis) : l'accès se ferme comme une fin d'adhésion (#1652) : `inactif`, daté, `motif_fin`
 *   `annulation`. L'historique demeure.
 * - Réserve : une condition et la pièce attendue ; sa levée.
 * - Droit : `PorteDuCatalogueLocal` (403 en école KLASSCI). Une inscription d'une autre école : 404, même corps. Audit.
 */
final class ValidationDesInscriptions
{
    public const INTROUVABLE = 'Inscription introuvable dans cet établissement.';

    public const ETAT = 'etat_de_validation';

    public const RESERVE = 'reserve_non_levee';

    public const MOTIF_ANNULATION = 'annulation';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly StudentEnrolmentService $inscriptions,
        private readonly AuditLogger $audit,
        private readonly DatabaseManager $db,
    ) {}

    /** @return array<string, mixed> */
    public function valider(User $acteur, int $id): array
    {
        return $this->db->transaction(function () use ($acteur, $id): array {
            // L'ordre des verrous de `entrer` (#1652) : la classe d'abord, la ligne ensuite. Inversé, une validation et une
            // adhésion concurrentes à la même classe pourraient s'interbloquer.
            $classe = Classe::query()->withoutGlobalScopes()->whereKey($this->ligne($id, verrou: false)->classe_id)->lockForUpdate()->firstOrFail();
            $ligne = $this->ligne($id);
            if ($ligne->validation !== EtatDeValidation::EnAttente->value) {
                throw new BusinessException('Seule une inscription en attente se valide.', 409, reason: self::ETAT);
            }
            if ($ligne->reserve_condition !== null && $ligne->reserve_levee_le === null) {
                throw new BusinessException('Une réserve n\'est pas levée : l\'inscription ne se valide pas encore.', 409, reason: self::RESERVE);
            }
            $this->inscriptions->exigerUnePlace($classe);

            return $this->ecrire($ligne, [
                'statut' => ClasseEtudiantStatut::Actif->value, 'validation' => EtatDeValidation::Validee->value,
                'valide_le' => now(), 'valide_par' => $acteur->id,
            ], 'inscription.validee');
        });
    }

    /** @return array<string, mixed> */
    public function annuler(User $acteur, int $id, string $motif): array
    {
        return $this->db->transaction(function () use ($acteur, $id, $motif): array {
            $ligne = $this->ligne($id);
            if ($ligne->validation === EtatDeValidation::Annulee->value) {
                throw new BusinessException('Cette inscription est déjà annulée.', 409, reason: self::ETAT);
            }

            return $this->ecrire($ligne, [
                'statut' => ClasseEtudiantStatut::Inactif->value, 'validation' => EtatDeValidation::Annulee->value,
                'ends_on' => now()->toDateString(), 'motif_fin' => self::MOTIF_ANNULATION,
                'annule_le' => now(), 'annule_par' => $acteur->id, 'motif_annulation' => $motif,
            ], 'inscription.annulee');
        });
    }

    /** @return array<string, mixed> */
    public function reserver(int $id, string $condition, ?string $piece): array
    {
        return $this->db->transaction(fn (): array => $this->ecrire($this->ligne($id), [
            'reserve_condition' => $condition, 'reserve_piece' => $piece, 'reserve_levee_le' => null, 'reserve_levee_par' => null,
        ], 'inscription.reserve'));
    }

    /** @return array<string, mixed> */
    public function leverReserve(User $acteur, int $id): array
    {
        return $this->db->transaction(function () use ($acteur, $id): array {
            $ligne = $this->ligne($id);
            if ($ligne->reserve_condition === null) {
                throw new BusinessException('Cette inscription ne porte aucune réserve.', 409, reason: self::ETAT);
            }

            return $this->ecrire($ligne, ['reserve_levee_le' => now(), 'reserve_levee_par' => $acteur->id], 'inscription.reserve_levee');
        });
    }

    /**
     * @param  array<string, mixed>  $fiche  validée : redoublant, etablissement_origine, affectation
     * @return array<string, mixed>
     */
    public function fiche(int $id, array $fiche): array
    {
        return $this->db->transaction(fn (): array => $this->ecrire($this->ligne($id), $fiche, 'inscription.fiche'));
    }

    /** @return array{a_valider: bool} */
    public function regler(bool $aValider): array
    {
        $ecole = Institution::query()->findOrFail($this->porte->etablissement());
        $ecole->forceFill(['inscriptions_a_valider' => $aValider])->save();

        return ['a_valider' => $aValider];
    }

    /**
     * La ligne, VERROUILLÉE : appelée dans la transaction de chaque geste, elle sérialise deux gestes concurrents
     * (une annulation n'est jamais écrasée par une validation qui l'aurait lue « en attente »).
     *
     * @throws BusinessException 403 (porte), 404 hors de l'école
     */
    private function ligne(int $id, bool $verrou = true): ClasseEtudiant
    {
        $requete = ClasseEtudiant::query()->where('id', $id)->where('institution_id', $this->porte->etablissement());
        $ligne = ($verrou ? $requete->lockForUpdate() : $requete)->first();
        if (! $ligne instanceof ClasseEtudiant) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }

        return $ligne;
    }

    /**
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private function ecrire(ClasseEtudiant $ligne, array $valeurs, string $action): array
    {
        $this->db->table('classe_etudiant')->where('id', $ligne->id)->update($valeurs + ['updated_at' => now()]);
        $this->audit->logSecurityEvent($action, $ligne, array_diff_key($valeurs, array_flip(['valide_par', 'annule_par', 'reserve_levee_par'])));

        return self::forme($ligne->refresh());
    }

    /** @return array<string, mixed> */
    private static function forme(ClasseEtudiant $l): array
    {
        return [
            'id' => (int) $l->id, 'classe_id' => (int) $l->classe_id, 'eleve_id' => (int) $l->user_id,
            'statut' => $l->statut, 'validation' => $l->validation,
            'reserve' => $l->reserve_condition === null ? null : [
                'condition' => $l->reserve_condition, 'piece' => $l->reserve_piece, 'levee' => $l->reserve_levee_le !== null,
            ],
            'redoublant' => $l->redoublant === null ? null : (bool) $l->redoublant,
            'etablissement_origine' => $l->etablissement_origine, 'affectation' => $l->affectation,
            'motif_annulation' => $l->motif_annulation,
        ];
    }
}
