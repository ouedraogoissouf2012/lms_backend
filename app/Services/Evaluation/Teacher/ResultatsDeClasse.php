<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\EvaluationSubmission;

/**
 * La forme des résultats d'une évaluation par élève, écrite une fois pour les
 * deux sources (D72, E3) : la ligne d'un élève, le tri, les statistiques de
 * classe et l'enveloppe de réponse.
 *
 * Extraite de `TeacherEvaluationResultsService`, sans changement : la source
 * locale rend la même forme, et deux copies finiraient par diverger.
 */
final class ResultatsDeClasse
{
    /** L'élève figure au roster de la classe. */
    public const INSCRIT = 'active';

    /**
     * La ligne d'un élève, avec sa dernière copie officielle s'il en a une.
     *
     * `inscription` : {@see self::INSCRIT} pour un élève du roster, `null` pour
     * l'auteur d'une copie hors du roster ({@see self::horsRoster()}).
     *
     * @return array<string, mixed>
     */
    public static function ligne(mixed $etudiantId, mixed $nom, mixed $prenom, string $nomComplet, ?EvaluationSubmission $copie, ?string $inscription): array
    {
        return [
            'etudiant_id'          => $etudiantId,
            'etudiant_nom'         => $nom ?? '',
            'etudiant_prenom'      => $prenom ?? '',
            'etudiant_nom_complet' => $nomComplet,
            'note'                 => $copie?->note_sur_20,
            'score'                => $copie?->score,
            'status'               => $copie->status ?? 'non_passee',
            'submitted_at'         => $copie?->submitted_at,
            'attempt'              => $copie?->attempt,
            'feedback'             => $copie?->feedback,
            'inscription'          => $inscription,
        ];
    }

    /**
     * Les lignes des copies qu'aucun élève du roster ne réclame
     * ({@see DernieresCopies::horsRoster()}) : aucune copie remise n'est jamais
     * invisible pour l'enseignant. Nommées comme dans la liste des copies.
     *
     * @param  list<EvaluationSubmission>  $copies
     * @param  \Closure(EvaluationSubmission): mixed  $identifiant  l'`etudiant_id` de la ligne, dans l'espace d'ids du roster
     * @return list<array<string, mixed>>
     */
    public static function horsRoster(array $copies, ?int $etablissement, \Closure $identifiant): array
    {
        $nom = NomsDesAuteurs::de(collect($copies), $etablissement);

        return array_map(
            static fn (EvaluationSubmission $copie): array => self::ligne($identifiant($copie), $nom($copie), '', $nom($copie), $copie, null),
            $copies,
        );
    }

    /**
     * Agrège les stats de classe à partir des résultats individuels.
     *
     * Les statuts `soumis` ET `corrige` comptent comme "soumis" (le
     * corrigé reste une participation valide).
     *
     * `total_etudiants` compte toutes les lignes : le roster et les auteurs hors
     * roster. Un auteur hors roster a toujours une copie : il n'est jamais
     * compté parmi les absents.
     *
     * @param  array<int, array<string, mixed>>  $resultats
     * @return array<string, mixed>
     */
    public static function statistiques(array $resultats, int $totalEtudiants): array
    {
        $soumissions = collect($resultats)->whereIn('status', ['soumis', 'corrige']);
        $notes       = $soumissions->pluck('note')->filter();

        return [
            'total_etudiants'      => $totalEtudiants,
            'etudiants_soumis'     => $soumissions->count(),
            'etudiants_en_cours'   => collect($resultats)->where('status', 'en_cours')->count(),
            // C28 : copies remises hors délai, ou échues sans remise (statut lu).
            'etudiants_hors_delai' => collect($resultats)->where('status', 'verrouille')->count(),
            'etudiants_non_passes' => collect($resultats)->where('status', 'non_passee')->count(),
            'taux_participation'   => $totalEtudiants > 0
                ? round(($soumissions->count() / $totalEtudiants) * 100, 2)
                : 0,
            'moyenne_classe'       => self::arrondi($notes->avg()),
            'note_max'             => self::arrondi($notes->max()),
            'note_min'             => self::arrondi($notes->min()),
        ];
    }

    /**
     * La réponse de succès, lignes triées par nom complet (l'ordre que le
     * frontend attend).
     *
     * @param  array<string, mixed>  $evaluation  l'évaluation présentée
     * @param  array<int, array<string, mixed>>  $resultats
     * @param  array<string, mixed>  $statistiques
     * @return array{status:int, payload:array<string, mixed>}
     */
    public static function reponse(array $evaluation, array $resultats, array $statistiques): array
    {
        usort($resultats, static fn (array $a, array $b): int
            => strcmp(self::nomComplet($a), self::nomComplet($b)));

        return [
            'status'  => 200,
            'payload' => [
                'success' => true,
                'data'    => [
                    'evaluation'   => $evaluation,
                    'resultats'    => $resultats,
                    'statistiques' => $statistiques,
                ],
            ],
        ];
    }

    /**
     * Une note agrégée, à deux décimales ; `null` sans note (aucune copie notée).
     */
    private static function arrondi(mixed $note): ?float
    {
        return is_numeric($note) ? round((float) $note, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    private static function nomComplet(array $ligne): string
    {
        return is_string($ligne['etudiant_nom_complet']) ? $ligne['etudiant_nom_complet'] : '';
    }

    /**
     * @return array{status:int, payload:array<string, mixed>}
     */
    public static function introuvable(): array
    {
        return ['status' => 404, 'payload' => ['success' => false, 'message' => 'Évaluation non trouvée']];
    }

    /**
     * Le corps d'une défaillance du LMS : jamais le détail de l'exception.
     *
     * @return array{status:int, payload:array<string, mixed>}
     */
    public static function echec(): array
    {
        return ['status' => 500, 'payload' => [
            'success' => false,
            'message' => 'Erreur lors de la récupération des résultats',
            'error'   => 'Une erreur est survenue.',
        ]];
    }
}
