<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renseigne `evaluation_submissions.student_id` là où il est resté nul.
 *
 * La colonne existe depuis `2026_04_30_add_student_id_to_evaluation_submissions`
 * mais n'a jamais été écrite : `/start` ne posait que `klassci_etudiant_id`.
 * Toutes les lignes antérieures portent donc `student_id = NULL`.
 *
 * Or la propriété d'une copie se lit désormais par cette colonne
 * ({@see \App\Models\EvaluationSubmission}). Sans ce rattrapage, une copie
 * commencée avant le déploiement deviendrait introuvable pour son auteur — un
 * élève en cours d'épreuve perdrait sa tentative.
 *
 * ## Pourquoi en PHP plutôt qu'un UPDATE ... FROM
 *
 * La syntaxe d'un UPDATE joint diffère entre SQLite (dev/test) et MySQL (prod).
 * Une boucle explicite se comporte identiquement sur les deux, et laisse
 * remonter le compte réellement écrit.
 *
 * ## Pourquoi l'appariement exige UN seul candidat
 *
 * Rien n'impose l'unicité de `users.klassci_id`. Apparier un homonyme
 * attribuerait la copie d'un élève à un autre — précisément le genre de dégât
 * que ce lot corrige. En cas d'ambiguïté la ligne est laissée telle quelle et
 * comptée à part : mieux vaut une copie non rattachée qu'une copie mal
 * rattachée.
 *
 * Rejouable : ne touche que les lignes encore nulles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rattachees = 0;
        $ambigues = 0;
        $sansCorrespondance = 0;

        DB::table('evaluation_submissions')
            ->whereNull('student_id')
            ->orderBy('id')
            ->chunkById(500, function ($copies) use (&$rattachees, &$ambigues, &$sansCorrespondance): void {
                foreach ($copies as $copie) {
                    $candidats = DB::table('users')
                        ->where('klassci_id', $copie->klassci_etudiant_id)
                        ->where('institution_id', $copie->institution_id)
                        ->whereNull('deleted_at')
                        ->pluck('id');

                    if ($candidats->count() > 1) {
                        $ambigues++;

                        continue;
                    }

                    if ($candidats->isEmpty()) {
                        $sansCorrespondance++;

                        continue;
                    }

                    DB::table('evaluation_submissions')
                        ->where('id', $copie->id)
                        ->update(['student_id' => $candidats->first()]);
                    $rattachees++;
                }
            });

        Log::info('Rétro-remplissage student_id sur evaluation_submissions', [
            'rattachees' => $rattachees,
            'ambigues' => $ambigues,
            'sans_correspondance' => $sansCorrespondance,
        ]);
    }

    /**
     * Irréversible, et le dire vaut mieux que le simuler : une fois la colonne
     * renseignée, rien ne distingue les lignes écrites ici de celles que
     * `/start` écrit désormais nativement. Les remettre toutes à nul
     * détruirait la propriété des copies récentes.
     */
    public function down(): void {}
};
