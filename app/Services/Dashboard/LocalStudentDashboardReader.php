<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Evaluation\Student\LocalLearnerEvaluationAudience;
use App\Services\Evaluation\Student\LocalMatieresDesNotes;
use App\Services\Evaluation\Student\StudentGradesAggregator;
use App\Support\Catalogue\SourceLocale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Le tableau de bord de l'élève dans une école créée depuis le LMS : la forme de
 * `me/dashboard`, lue en local, sans aucun appel à KLASSCI, même pour un compte
 * porteur d'un jeton. Composé des lecteurs existants :
 *
 * - `classe` : la première des classes que lui reconnaît la source d'inscriptions,
 *   bornée à son établissement (la plus ancienne inscription s'il en a plusieurs) ;
 *   `filiere` et `niveau` valent `null`, le LMS ne les tient pas ;
 * - `cours` : les matières au programme de ses classes actives ;
 * - `quiz` : l'audience de ses évaluations ({@see LocalLearnerEvaluationAudience}, E2a) ;
 * - `notes` et `moyenne_generale` : {@see StudentGradesAggregator} (E4a), la note
 *   cachée (C30) exclue ;
 * - `taux_presence` : `null`, non mesuré : il n'est pas fabriqué à 0.
 *
 * Des inscriptions, toutes inactives (D57) : refus `enrolment_not_active`, le motif
 * de l'inscription. Aucune inscription : un tableau vide, état normal (E2a).
 *
 * Une seule règle d'admission, celle des leçons : le port {@see EnrollmentSource},
 * sans prédicat propre au tableau. Déclaré : ce port retombe sur le miroir
 * `user_classes` dès qu'aucune ligne de pivot n'est ACTIVE ; un élève aux lignes
 * toutes inactives qui garde ce miroir (école passée de KLASSCI au mode autonome)
 * est donc admis ici comme dans ses leçons. Le correctif est à la racine, le port.
 */
final class LocalStudentDashboardReader implements StudentDashboardReader
{
    public function __construct(
        private readonly EnrollmentSource $inscriptions,
        private readonly LocalLearnerEvaluationAudience $audience,
        private readonly StudentGradesAggregator $notes,
        private readonly LocalMatieresDesNotes $matieresDesNotes,
    ) {}

    public function dashboardFor(User $student): array
    {
        $classes = $this->classesActives($student);

        if ($classes->isEmpty() && $this->aDesInscriptions($student)) {
            throw new BusinessException(
                'Votre inscription n\'est pas active. Adressez-vous à votre établissement.',
                409,
                reason: 'enrolment_not_active',
            );
        }

        $notes = $classes->isEmpty() ? ['matieres' => [], 'moyenne_generale' => null]
            : $this->notes->getGradesAggregatedByMatiere($student, $this->matieresDesNotes);

        return [
            'success' => true,
            'data' => [
                'classe' => self::classe($classes->first()),
                'cours' => $this->cours($classes, $student),
                'quiz' => $classes->isEmpty() ? [] : $this->quiz($student),
                'notes' => self::notesRecentes($notes['matieres']),
                'statistiques' => ['moyenne_generale' => $notes['moyenne_generale'], 'taux_presence' => null],
            ],
            'meta' => ['source' => SourceLocale::LMS_LOCAL],
        ];
    }

    /** @return Collection<int, Classe> */
    private function classesActives(User $student): Collection
    {
        $ids = $this->inscriptions->localClasseIdsFor($student);

        if ($ids === []) {
            return new Collection;
        }

        // Le pivot ne sert qu'à l'ORDRE : une classe venue du miroir n'y a pas de ligne.
        return Classe::query()
            ->select('classes.*')
            ->leftJoin('classe_etudiant', static fn (JoinClause $pivot): JoinClause => $pivot
                ->on('classe_etudiant.classe_id', '=', 'classes.id')
                ->where('classe_etudiant.user_id', $student->id))
            ->where((new Classe)->qualifyColumn('institution_id'), $student->institution_id)
            ->whereKey($ids)
            ->orderBy('classe_etudiant.date_inscription')
            ->orderBy('classes.id')
            ->get();
    }

    /** Une inscription au pivot dans son établissement, quel que soit son statut. */
    private function aDesInscriptions(User $student): bool
    {
        return $student->classes()->where((new Classe)->qualifyColumn('institution_id'), $student->institution_id)->exists();
    }

    /** @return array<string, mixed>|null */
    private static function classe(?Classe $classe): ?array
    {
        return $classe === null ? null : [
            'id' => $classe->id,
            'name' => $classe->libelle,
            'libelle' => $classe->libelle,
            'code' => $classe->code,
            'filiere' => null,
            'niveau' => null,
        ];
    }

    /**
     * @param  Collection<int, Classe>  $classes
     * @return array<int, array{id: int, nom: string, libelle: string}>
     */
    private function cours(Collection $classes, User $student): array
    {
        if ($classes->isEmpty()) {
            return [];
        }

        $programme = DB::table('classe_matiere')->whereIn('classe_id', $classes->modelKeys())->select('matiere_id');

        return Matiere::query()->withoutGlobalScope('institution')
            ->where('institution_id', $student->institution_id)
            ->whereIn('id', $programme)
            ->orderBy('libelle')
            ->get(['id', 'libelle'])
            ->map(static fn (Matiere $m): array => ['id' => (int) $m->id, 'nom' => (string) $m->libelle, 'libelle' => (string) $m->libelle])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function quiz(User $student): array
    {
        return $this->audience->visibleFor($student)
            ->orderBy('date_evaluation')
            ->get()
            ->map(static fn (Evaluation $e): array => [
                'id' => $e->id,
                'titre' => $e->titre,
                'type' => $e->type,
                'date_evaluation' => $e->date_evaluation,
            ])
            ->values()
            ->all();
    }

    /**
     * Les notes de l'agrégat, la plus récente remise d'abord : seules celles qui
     * portent une note (une note cachée, C30, n'en porte pas).
     *
     * @param  array<int, array<string, mixed>>  $matieres
     * @return list<array<string, mixed>>
     */
    private static function notesRecentes(array $matieres): array
    {
        $notes = [];
        foreach ($matieres as $matiere) {
            foreach ((array) ($matiere['evaluations'] ?? []) as $ligne) {
                if (! is_array($ligne) || ! isset($ligne['note']) || ! is_numeric($ligne['note'])) {
                    continue;
                }
                $notes[] = [
                    'id' => $ligne['evaluation_id'],
                    'evaluation' => ['id' => $ligne['evaluation_id'], 'titre' => $ligne['titre']],
                    'matiere' => ['id' => $matiere['matiere_id'], 'name' => $matiere['matiere_nom']],
                    'note' => (float) $ligne['note'],
                    '_remise' => self::instant($ligne['date_soumission'] ?? null),
                ];
            }
        }
        usort($notes, static fn (array $a, array $b): int => strcmp($b['_remise'], $a['_remise']));

        return array_map(static function (array $note): array {
            unset($note['_remise']);

            return $note;
        }, $notes);
    }

    /** Un instant comparable par chaînes (ISO 8601), vide sans date. */
    private static function instant(mixed $valeur): string
    {
        return $valeur instanceof \DateTimeInterface ? $valeur->format(\DATE_ATOM) : (is_string($valeur) ? $valeur : '');
    }
}
