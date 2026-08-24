<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #540 — répare la RACINE de la famille « scope tenant contre index unique ».
 *
 * ## Le vrai défaut
 *
 * `2026_02_11_000002_add_institution_id_to_all_tables` a ajouté `institution_id`
 * **nullable et sans backfill** ; `2026_08_15_140000_add_institution_id_foreign_keys`
 * l'a délibérément laissée nullable (comptes plateforme, #583). Toute ligne
 * antérieure à février 2026 porte donc `institution_id = NULL`.
 *
 * Or le global scope `BelongsToInstitution` filtre sur cette colonne, tandis que
 * les index uniques des tables de tentatives ne la connaissent pas. Les deux
 * couches ne voient donc pas le même jeu de lignes :
 *
 *   - le calcul `max + 1` ignore une tentative héritée que l'index, lui, fait
 *     respecter → violation d'unicité, **409 définitif** pour l'étudiant ;
 *   - une tentative rendue par une lecture non scopée devient introuvable pour
 *     la lecture scopée suivante → **404** sur l'action d'après.
 *
 * ## Pourquoi réparer la DONNÉE plutôt que chaque requête
 *
 * Retirer le global scope requête par requête corrige des symptômes : chaque
 * nouveau site de lecture devra y repenser, et l'asymétrie entre ce que compte
 * le quota et ce que lit l'étudiant réapparaîtra. Renseigner `institution_id`
 * rend l'invariant VRAI : scope et index redeviennent d'accord partout, y
 * compris dans le code qui n'existe pas encore.
 *
 * L'institution d'une tentative n'est jamais ambiguë — elle est celle de son
 * parent (quiz, évaluation, knowledge-check). Aucune heuristique n'est requise.
 *
 * Les lectures d'espace de clés conservent malgré tout leur
 * `withoutGlobalScope` : si un parent sans institution (contenu plateforme)
 * produisait un jour une tentative à NULL, l'accord avec l'index resterait
 * garanti.
 */
return new class extends Migration
{
    /**
     * Table de tentatives → [clé étrangère vers le parent, table parente].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ATTEMPT_TABLES = [
        'quiz_attempts' => ['quiz_id', 'quizzes'],
        'evaluation_submissions' => ['evaluation_id', 'evaluations'],
        'knowledge_check_attempts' => ['knowledge_check_id', 'knowledge_checks'],
    ];

    /** Taille de lot — borne l'empreinte mémoire sur une grosse table. */
    private const CHUNK = 500;

    public function up(): void
    {
        foreach (self::ATTEMPT_TABLES as $table => [$foreignKey, $parentTable]) {
            $this->backfill($table, $foreignKey, $parentTable);
        }
    }

    /**
     * `down()` volontairement inerte : re-vider `institution_id` ne restaurerait
     * rien d'utile et re-casserait l'invariant. Correctif de données, pas
     * changement de schéma réversible.
     */
    public function down(): void
    {
        // Aucune action : voir le commentaire ci-dessus.
    }

    /**
     * Le parcours porte sur la table PARENTE, que la migration ne modifie pas :
     * son prédicat reste stable pendant toute l'itération. Parcourir les
     * tentatives en `chunk()` tout en les mettant à jour ferait sauter l'offset
     * d'autant de lignes que l'on en répare — le piège déjà rencontré sur le
     * backfill de `student_id`.
     */
    private function backfill(string $table, string $foreignKey, string $parentTable): void
    {
        DB::table($parentTable)
            ->whereNotNull('institution_id')
            ->select('id', 'institution_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($parents) use ($table, $foreignKey): void {
                foreach ($parents as $parent) {
                    DB::table($table)
                        ->whereNull('institution_id')
                        ->where($foreignKey, $parent->id)
                        ->update(['institution_id' => $parent->institution_id]);
                }
            });
    }
};
