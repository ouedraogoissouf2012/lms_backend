<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use App\Services\Admission\PorteDesAdmissions;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

/**
 * L'administration d'une école autonome inscrit un élève existant dans une classe, et l'en retire (#1619).
 *
 * - Inscrire : l'entrée décidée par l'école, {@see AdmissionParLEcole} (archive, Période close, déjà inscrit, capacité :
 *   409 tels quels), dans une transaction. Une ancienne adhésion est réactivée, jamais doublée.
 * - Retirer : l'adhésion active se ferme, motif `retrait` ({@see AdhesionDeLEcole::clore()}) ; le dossier, les notes et
 *   les présences demeurent.
 * - Droit : celui des INSCRIPTIONS, {@see PorteDesAdmissions} (403 `enrolment_managed_by_klassci` en école KLASSCI,
 *   comme les demandes de #1817), puis classe et élève bornés à l'école (404, même corps). Une classe miroir de
 *   l'école : 403 `catalogue_managed_by_klassci`, un refus motivé ({@see AdhesionDeLEcole::classeLocale()}).
 */
final class InscriptionParLAdministration
{
    public const MOTIF_RETRAIT = 'retrait';

    public function __construct(
        private readonly PorteDesAdmissions $porte,
        private readonly AdhesionDeLEcole $adhesions,
        private readonly AdmissionParLEcole $admission,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{eleve_id: int, classe_id: int}
     *
     * @throws BusinessException 403, 404, 409 (ceux de l'admission)
     * @throws ValidationException 422 si le compte n'est pas un élève
     */
    public function inscrire(User $acteur, int $classeId, int $eleveId): array
    {
        $ecole = $this->porte->etablissementDe($acteur);
        $classe = $this->adhesions->classeLocale($ecole, $classeId);
        $eleve = $this->adhesions->eleve($ecole, $eleveId);
        if ($eleve->asRoleEnum() !== Role::Etudiant) {
            throw ValidationException::withMessages(['eleve_id' => 'Ce compte n\'est pas un élève.']);
        }

        $this->db->transaction(fn () => $this->admission->admettre($eleve, $classe));

        return ['eleve_id' => $eleve->id, 'classe_id' => $classe->id];
    }

    /**
     * @return array{eleve_id: int, classe_id: int}
     *
     * @throws BusinessException 403, 404, 409 `enrolment_not_active`
     */
    public function retirer(User $acteur, int $classeId, int $eleveId): array
    {
        $ecole = $this->porte->etablissementDe($acteur);
        $classe = $this->adhesions->classeLocale($ecole, $classeId);
        $eleve = $this->adhesions->eleve($ecole, $eleveId);

        $this->db->transaction(function () use ($classe, $eleve): void {
            Classe::query()->withoutGlobalScopes()->whereKey($classe->id)->lockForUpdate()->first();
            $this->adhesions->exigerActive($classe, $eleve);
            $this->adhesions->clore($classe, $eleve, self::MOTIF_RETRAIT);
        });

        return ['eleve_id' => $eleve->id, 'classe_id' => $classe->id];
    }
}
