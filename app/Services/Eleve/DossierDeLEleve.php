<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use App\Exceptions\BusinessException;
use App\Exceptions\DoublonDEleve;
use App\Models\DossierEleve;
use App\Models\Institution;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Le dossier d'un élève (#1657) : qui le lit et comment, et son écriture dans une école autonome.
 *
 * - L'élève se cherche dans l'école de l'acteur, parmi les ÉLÈVES : sinon introuvable (404), le même refus pour une
 *   autre école, un compte qui n'est pas un élève, ou un id absent.
 * - Lecture : la gestion et l'élève lui-même voient tout. Un enseignant d'une classe de l'élève (les classes de la
 *   source d'inscription de l'école) ne voit que nom, prénoms, matricule et photo (loi 2013-450). Tout autre : 403.
 * - Écriture : école autonome seulement (`PorteDuCatalogueLocal`, 403 sinon). Le nom vit dans le dossier ;
 *   `users.name` en est recalculé, pour ce seul compte. Doublon possible : 409, sauf confirmation explicite.
 */
final class DossierDeLEleve
{
    public const DOSSIER_ABSENT = 'dossier_absent';

    private const REDUIT = ['matricule', 'nom', 'prenoms', 'photo_url'];

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly EnrollmentSource $inscriptions,
        private readonly GenerateurDeMatricule $matricules,
        private readonly PresentationDuDossier $presentation,
        private readonly DoublonsDEleve $doublons,
        private readonly DatabaseManager $db,
    ) {}

    /** @throws BusinessException 404 */
    public function eleve(User $acteur, int $eleveId): User
    {
        $eleve = is_int($acteur->institution_id)
            ? User::query()->withoutGlobalScopes()->whereKey($eleveId)->where('institution_id', $acteur->institution_id)->first()
            : null;

        if (! $eleve instanceof User || ! $eleve->isStudent()) {
            throw new BusinessException('Élève introuvable dans cet établissement.', 404);
        }

        return $eleve;
    }

    /**
     * @return array{eleve_id: int, source: string, dossier: array<string, mixed>|null}
     *
     * @throws BusinessException 403, 404
     */
    public function lire(User $acteur, int $eleveId): array
    {
        ['eleve' => $eleve, 'complet' => $complet] = $this->lecteur($acteur, $eleveId);

        $local = $this->porte->ouvertePour(Institution::query()->find($eleve->institution_id));
        $dossier = $local ? $this->presentation->locale($eleve) : $this->presentation->klassci($eleve);

        return [
            'eleve_id' => (int) $eleve->id,
            'source' => $local ? 'lms' : 'klassci',
            'dossier' => $dossier === null || $complet ? $dossier : array_intersect_key($dossier, array_flip(self::REDUIT)),
        ];
    }

    /**
     * Le dossier EXISTANT d'un élève de l'école de l'acteur : ses tuteurs et sa photo en dépendent.
     *
     * @throws BusinessException 404, 409 `dossier_absent`
     */
    public function dossierExistant(User $acteur, int $eleveId): DossierEleve
    {
        $eleve = $this->eleve($acteur, $eleveId);
        $dossier = DossierEleve::query()->withoutGlobalScopes()->where('user_id', $eleve->id)->first();

        if (! $dossier instanceof DossierEleve) {
            throw new BusinessException('Créez d\'abord le dossier de l\'élève.', 409, reason: self::DOSSIER_ABSENT);
        }

        return $dossier;
    }

    /**
     * La règle de LECTURE du dossier, en un seul lieu (le dossier et sa photo la partagent) : la gestion et l'élève
     * lui-même lisent tout (`complet`), un enseignant d'une de ses classes en partie ; tout autre : 403.
     *
     * @return array{eleve: User, complet: bool}
     *
     * @throws BusinessException 403, 404
     */
    public function lecteur(User $acteur, int $eleveId): array
    {
        $eleve = $this->eleve($acteur, $eleveId);
        $complet = $acteur->isManager() || $acteur->is($eleve);
        if (! $complet && ! $this->enseigneA($acteur, $eleve)) {
            throw new BusinessException('Ce dossier n\'est pas accessible.', 403);
        }

        return ['eleve' => $eleve, 'complet' => $complet];
    }

    /**
     * @param  array<string, mixed>  $identite  validée par `EcrireDossierRequest`
     * @return array{cree: bool, dossier: array<string, mixed>|null}
     *
     * @throws BusinessException 403, 404
     * @throws DoublonDEleve 409 `doublon_possible`, avec les candidats
     */
    public function ecrire(User $acteur, int $eleveId, array $identite, bool $confirmerDoublon): array
    {
        $ecole = $this->porte->etablissement();
        $eleve = $this->eleve($acteur, $eleveId);

        if (! $confirmerDoublon) {
            $candidats = $this->doublons->pour($ecole, $eleve, $identite);
            if ($candidats !== []) {
                throw new DoublonDEleve($candidats);
            }
        }

        $cree = $this->db->transaction(function () use ($ecole, $eleve, $identite): bool {
            $dossier = DossierEleve::query()->where('user_id', $eleve->id)->lockForUpdate()->first();
            $cree = ! $dossier instanceof DossierEleve;
            $valeurs = $identite + ['institution_id' => $ecole, 'user_id' => $eleve->id];
            if ($cree && ! is_string($valeurs['matricule'] ?? null)) {
                $gabarit = Institution::query()->whereKey($ecole)->value('matricule_gabarit');
                $valeurs['matricule'] = $this->matricules->suivant($ecole, is_string($gabarit) ? $gabarit : null, Carbon::now());
            }
            // Seuls les champs reçus changent : un matricule absent reste celui du dossier.
            $cree ? DossierEleve::query()->create($valeurs) : $dossier->update($valeurs);

            $relu = DossierEleve::query()->where('user_id', $eleve->id)->firstOrFail();
            $eleve->forceFill(['name' => Str::squish("{$relu->prenoms} {$relu->nom}")])->save();

            return $cree;
        });

        return ['cree' => $cree, 'dossier' => $this->presentation->locale($eleve)];
    }

    /** L'acteur enseigne-t-il dans une classe de l'élève ? */
    private function enseigneA(User $acteur, User $eleve): bool
    {
        if (! $acteur->isTeacher()) {
            return false;
        }

        return array_intersect($this->inscriptions->classeIdsForTeacher($acteur), $this->inscriptions->localClasseIdsFor($eleve)) !== [];
    }
}
