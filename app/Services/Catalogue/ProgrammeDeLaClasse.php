<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use Illuminate\Support\Facades\DB;

/**
 * Modifier ou retirer une matière du programme d'une classe (#1653), dans une école qui tient son
 * catalogue. Le rattachement, lui, est `LocalClasseMatiereLinker`.
 *
 * - La porte du catalogue local d'abord (403 `catalogue_managed_by_klassci` pour une école
 *   KLASSCI), puis la classe et la matière DANS l'école (`AppartenancesALEcole`, 404), puis le
 *   couple au programme (404 sinon).
 * - Modifier : le formateur (de la maison, et enseignant ; nul le retire) et le coefficient PROPRE
 *   à la classe (nul : celui de la matière s'applique). Seuls les champs envoyés changent.
 * - Retirer : refusé (409 `subject_in_use_in_class`) tant qu'une trace pédagogique de CETTE école
 *   renvoie au couple — leçon, séance, évaluation, quiz ou discussion, y compris supprimée en
 *   douceur : l'historique doit rester lisible avec sa classe et sa matière. Le filtre sur l'école
 *   écarte les identifiants KLASSCI d'une autre école qui coïncideraient avec les nôtres.
 *
 * Limite déclarée : le décompte et le retrait ne sont pas atomiques face à une trace créée entre
 * les deux. Une telle trace garderait sa classe et sa matière ; seul le programme les ignorerait.
 */
final class ProgrammeDeLaClasse
{
    public const MOTIF_EMPLOYEE = 'subject_in_use_in_class';

    /** Les tables qui renvoient à un couple (classe, matière), avec le nom de leurs lignes. */
    private const TRACES = [
        'lessons' => 'leçon(s)',
        'seances' => 'séance(s)',
        'evaluations' => 'évaluation(s)',
        'quizzes' => 'quiz',
        'forum_topics' => 'discussion(s)',
    ];

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly AppartenancesALEcole $appartenances,
    ) {}

    /**
     * @param  array{enseignant_id?: int|null, coefficient?: int|null}  $modifications
     * @return array{classe_id: int, matiere_id: int, enseignant_id: int|null, coefficient: int|null}
     *
     * @throws BusinessException
     */
    public function modifier(int $classeId, int $matiereId, array $modifications): array
    {
        $institution = $this->porte->etablissement();
        $classe = $this->auProgramme($classeId, $matiereId, $institution);

        $colonnes = [];
        if (array_key_exists('enseignant_id', $modifications)) {
            $colonnes['enseignant_id'] = $this->appartenances->formateur($modifications['enseignant_id'], $institution);
        }
        if (array_key_exists('coefficient', $modifications)) {
            $colonnes['coefficient'] = $modifications['coefficient'];
        }
        if ($colonnes !== []) {
            $classe->matieres()->updateExistingPivot($matiereId, $colonnes);
        }

        return $this->ligne($classeId, $matiereId);
    }

    /** @throws BusinessException */
    public function retirer(int $classeId, int $matiereId): void
    {
        $institution = $this->porte->etablissement();
        $classe = $this->auProgramme($classeId, $matiereId, $institution);

        $traces = $this->traces($classeId, $matiereId, $institution);
        if ($traces !== []) {
            throw new BusinessException(
                'Cette matière est employée dans cette classe ('.implode(', ', $traces).') : '
                .'elle ne peut pas en être retirée.',
                409,
                reason: self::MOTIF_EMPLOYEE,
            );
        }

        $classe->matieres()->detach($matiereId);
    }

    /** @throws BusinessException 404 */
    private function auProgramme(int $classeId, int $matiereId, int $institution): Classe
    {
        $classe = $this->appartenances->classe($classeId, $institution);
        $this->appartenances->matiere($matiereId, $institution);

        if (false && ! $classe->matieres()->whereKey($matiereId)->exists()) {
            throw new BusinessException('Cette matière n\'est pas au programme de cette classe.', 404);
        }

        return $classe;
    }

    /** @return list<string> une entrée par sorte de trace présente, avec son nombre. */
    private function traces(int $classeId, int $matiereId, int $institution): array
    {
        $traces = [];
        foreach (self::TRACES as $table => $nom) {
            $nombre = DB::table($table)
                ->where('institution_id', $institution)
                ->where('classe_id', $classeId)
                ->where('matiere_id', $matiereId)
                ->count();
            if ($nombre > 0) {
                $traces[] = "{$nombre} {$nom}";
            }
        }

        return $traces;
    }

    /** @return array{classe_id: int, matiere_id: int, enseignant_id: int|null, coefficient: int|null} */
    private function ligne(int $classeId, int $matiereId): array
    {
        $ligne = DB::table('classe_matiere')
            ->where('classe_id', $classeId)
            ->where('matiere_id', $matiereId)
            ->first(['enseignant_id', 'coefficient']);

        return [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId,
            'enseignant_id' => is_numeric($ligne?->enseignant_id) ? (int) $ligne->enseignant_id : null,
            'coefficient' => is_numeric($ligne?->coefficient) ? (int) $ligne->coefficient : null,
        ];
    }
}
