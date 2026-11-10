<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D72 (E1) — une évaluation ne connaît que des identifiants KLASSCI.
 *
 * `evaluations` ne porte que `klassci_classe_id`, `klassci_matiere_id` et
 * `klassci_enseignant_id`. Dans une école créée depuis le LMS, ces trois
 * valeurs sont NULL partout : une évaluation n'y aurait ni classe, ni matière,
 * ni auteur. Et un id local rangé sous un nom `klassci_*` mélangerait deux
 * espaces (D132).
 *
 * Trois colonnes LOCALES, sur le patron de `seances.classe_id` (#846) :
 * - `classe_id`, `matiere_id` : la classe et la matière locales, remplies par
 *   la source locale, NULL pour une évaluation d'une école créée depuis KLASSCI ;
 * - `enseignant_id` : l'auteur (`users.id`), écrit par les DEUX sources à
 *   chaque création. Les lignes existantes gardent NULL : leur propriété reste
 *   portée par `klassci_enseignant_id`.
 *
 * Nullables et sans clé étrangère, comme #846 : la cohérence avec
 * l'établissement est vérifiée à l'écriture (`LocalEvaluationProgramming`).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
return new class extends Migration
{
    private const COLONNES = [
        'classe_id' => ['après' => 'klassci_classe_id', 'note' => 'classe LOCALE (école créée depuis le LMS). Nulle pour une évaluation KLASSCI.'],
        'matiere_id' => ['après' => 'klassci_matiere_id', 'note' => 'matière LOCALE (école créée depuis le LMS). Nulle pour une évaluation KLASSCI.'],
        'enseignant_id' => ['après' => 'klassci_enseignant_id', 'note' => 'auteur (users.id), écrit à chaque création. Nul pour une ligne d\'avant D72.'],
    ];

    private const INDEX = [
        'evaluations_institution_classe_index' => ['institution_id', 'classe_id'],
        'evaluations_institution_enseignant_index' => ['institution_id', 'enseignant_id'],
    ];

    public function up(): void
    {
        foreach (self::COLONNES as $colonne => $definition) {
            if (! Schema::hasColumn('evaluations', $colonne)) {
                Schema::table('evaluations', function (Blueprint $table) use ($colonne, $definition): void {
                    $table->unsignedBigInteger($colonne)
                        ->nullable()
                        ->after($definition['après'])
                        ->comment('D72 — '.$definition['note']);
                });
            }
        }

        foreach (self::INDEX as $nom => $colonnes) {
            if (! Schema::hasIndex('evaluations', $nom)) {
                Schema::table('evaluations', function (Blueprint $table) use ($nom, $colonnes): void {
                    $table->index($colonnes, $nom);
                });
            }
        }
    }

    public function down(): void
    {
        $locales = DB::table('evaluations')
            ->where(fn ($q) => $q->whereNotNull('classe_id')->orWhereNotNull('matiere_id'))
            ->count();

        if ($locales > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$locales} évaluation(s) portent une classe ou une matière locale, "
                .'et aucune colonne ne pourrait la conserver (D72).'
            );
        }

        foreach (array_keys(self::INDEX) as $nom) {
            if (Schema::hasIndex('evaluations', $nom)) {
                Schema::table('evaluations', function (Blueprint $table) use ($nom): void {
                    $table->dropIndex($nom);
                });
            }
        }

        foreach (array_keys(self::COLONNES) as $colonne) {
            if (Schema::hasColumn('evaluations', $colonne)) {
                Schema::table('evaluations', function (Blueprint $table) use ($colonne): void {
                    $table->dropColumn($colonne);
                });
            }
        }
    }
};
