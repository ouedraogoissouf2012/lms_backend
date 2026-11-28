<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\TypeDeDocument;
use App\Exceptions\BusinessException;
use App\Models\DocumentEmis;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Database\ConnectionInterface;

/**
 * Émettre un document de scolarité pour un élève (#1662) : certificat de scolarité, attestation de fréquentation.
 *
 * - Le droit vient de la capacité (`PorteDuCatalogueLocal`). Dans une école KLASSCI, les documents officiels relèvent
 *   de KLASSCI, l'ERP qui fait autorité : deux certificats numérotés par deux systèmes seraient une source de
 *   litige → 403 `catalogue_managed_by_klassci`.
 * - L'élève est de l'école (404 sinon) et a une inscription ACTIVE dans une de ses classes (409
 *   `student_not_enrolled` sinon) ; la plus récente fait foi.
 * - Le numéro (`CS-2026-0001`) est tiré sous le verrou de l'école : deux émissions simultanées ne reçoivent pas le
 *   même numéro, que la base refuserait de toute façon (unique `(institution_id, numero)`).
 * - L'émission est inscrite au journal d'audit.
 *
 * L'attestation ne porte pas de taux de présence : aucun n'est mesuré par élève aujourd'hui (la règle de présence
 * retenue vit dans le lot 6). C'est une dette tracée, pas un zéro fabriqué.
 */
final class EmissionDeDocument
{
    public const NON_INSCRIT = 'student_not_enrolled';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LONGUEUR_DU_CODE = 12;

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws BusinessException 403, 404, 409 */
    public function emettre(User $acteur, int $eleveId, TypeDeDocument $type): DocumentEmis
    {
        $ecole = $this->porte->etablissement();
        $eleve = User::query()->withoutGlobalScopes()->whereKey($eleveId)->where('institution_id', $ecole)->first();

        if (! $eleve instanceof User) {
            throw new BusinessException('Élève introuvable dans cet établissement.', 404);
        }

        $inscription = $this->inscriptionActive($ecole, $eleve);

        $document = new DocumentEmis;
        $this->db->transaction(function () use ($document, $ecole, $eleve, $type, $inscription, $acteur): void {
            $etablissement = Institution::query()->whereKey($ecole)->lockForUpdate()->firstOrFail();

            $document->forceFill([
                'institution_id' => $ecole,
                'eleve_id' => $eleve->id,
                'type' => $type,
                'numero' => $this->prochainNumero($ecole, $type),
                'code_verification' => $this->code(),
                'contenu' => [
                    'ecole' => (string) $etablissement->name,
                    'titulaire' => (string) $eleve->name,
                ] + $inscription,
                'emis_par' => $acteur->id,
            ])->save();
        });

        $this->audit->logModelEvent('document_emis', $document, null, [
            'type' => $type->value, 'numero' => $document->numero, 'eleve_id' => $eleve->id,
        ]);

        return $document;
    }

    /**
     * @return array{classe: string, periode: string|null, inscrit_le: string|null}
     *
     * @throws BusinessException 409
     */
    private function inscriptionActive(int $ecole, User $eleve): array
    {
        $ligne = $this->db->table('classe_etudiant')
            ->join('classes', 'classes.id', '=', 'classe_etudiant.classe_id')
            ->leftJoin('training_sessions', 'training_sessions.id', '=', 'classes.training_session_id')
            ->where('classe_etudiant.user_id', $eleve->id)
            ->where('classes.institution_id', $ecole)
            ->where('classe_etudiant.statut', ClasseEtudiantStatut::Actif->value)
            ->orderByDesc('classe_etudiant.date_inscription')->orderByDesc('classe_etudiant.id')
            ->first(['classes.libelle as classe', 'training_sessions.libelle as periode', 'classe_etudiant.date_inscription as inscrit_le']);

        if ($ligne === null) {
            throw new BusinessException('Cet élève n\'a aucune inscription active dans l\'établissement.', 409, reason: self::NON_INSCRIT);
        }

        $periode = $ligne->periode ?? null;
        $inscritLe = $ligne->inscrit_le ?? null;

        return [
            'classe' => is_scalar($ligne->classe ?? null) ? (string) $ligne->classe : '',
            'periode' => is_scalar($periode) ? (string) $periode : null,
            'inscrit_le' => is_scalar($inscritLe) ? substr((string) $inscritLe, 0, 10) : null,
        ];
    }

    /** Sous le verrou de l'école (posé par l'appelant). */
    private function prochainNumero(int $ecole, TypeDeDocument $type): string
    {
        $prefixe = sprintf('%s-%s-', $type->prefixe(), now()->format('Y'));
        $dernier = DocumentEmis::query()->withoutGlobalScopes()
            ->where('institution_id', $ecole)
            ->where('numero', 'like', $prefixe.'%')
            ->max('numero');

        $rang = is_string($dernier) ? (int) substr($dernier, strlen($prefixe)) : 0;

        return $prefixe.str_pad((string) ($rang + 1), 4, '0', STR_PAD_LEFT);
    }

    private function code(): string
    {
        $code = '';
        for ($i = 0; $i < self::LONGUEUR_DU_CODE; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
