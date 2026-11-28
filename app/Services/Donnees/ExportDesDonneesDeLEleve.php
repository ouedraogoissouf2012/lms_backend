<?php

declare(strict_types=1);

namespace App\Services\Donnees;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Donnees\Export\SectionCompte;
use App\Services\Donnees\Export\SectionsDeLExport;

/**
 * L'export des données d'un élève (#1705, droit d'accès de la loi 2013-450), fait par l'administration de son école.
 *
 * L'élève est cherché dans l'école de l'acteur, parmi les ÉLÈVES (rôle reconnu) : un compte d'une autre école, ou qui
 * n'est pas un élève, est introuvable (404), du même corps qu'un id absent. Chaque export est journalisé
 * (`eleve.donnees_exportees`, son format), avant d'être rendu.
 */
final class ExportDesDonneesDeLEleve
{
    public const INTROUVABLE = 'Élève introuvable dans cet établissement.';

    public function __construct(
        private readonly SectionsDeLExport $sections,
        private readonly SectionCompte $compte,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{eleve: User, titres: array<string, string>, donnees: array<string, mixed>}
     *
     * @throws BusinessException 404
     */
    public function exporter(User $acteur, int $idEleve, string $format): array
    {
        $ecole = $acteur->institution_id;
        if ($ecole === null) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }
        $eleve = User::query()->withoutGlobalScopes()->whereKey($idEleve)
            ->where('institution_id', $ecole)->whereIn('role', Role::Etudiant->aliases())->first();
        if (! $eleve instanceof User) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }

        $titres = ['compte' => 'Compte'];
        $donnees = ['compte' => $this->compte->presenter($eleve)];
        foreach ($this->sections->toutes() as $section) {
            $titres[$section->cle()] = $section->titre();
            $donnees[$section->cle()] = $section->lignes($eleve, $ecole);
        }
        $this->audit->logSecurityEvent('eleve.donnees_exportees', $eleve, ['format' => $format, 'sections' => count($donnees)]);

        return ['eleve' => $eleve, 'titres' => $titres, 'donnees' => $donnees];
    }
}
