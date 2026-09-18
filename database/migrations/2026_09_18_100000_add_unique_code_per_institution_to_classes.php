<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #848 — `classes.code` est nullable et ne porte AUCUN index unique.
 *
 * `ImportApplyService:165` retrouve une classe par `where('code', $code)->first()`.
 * Deux classes d'un même établissement partageant un code feraient donc inscrire
 * des apprenants dans la mauvaise, **sans lever d'erreur** : `first()` en choisit
 * une, silencieusement.
 *
 * Le défaut est inatteignable tant que les codes viennent de KLASSCI. Il devient
 * atteignable dès qu'un humain les saisit — c'est-à-dire dès que la création
 * locale d'une classe existe. L'unique appartient donc au lot qui l'ouvre, pas à
 * un lot d'après.
 *
 * ## Un unique SIMPLE suffit, et c'est mesuré
 *
 * L'invariant est partiel : « un seul code par établissement, PARMI les classes
 * qui en portent un ». Les classes sans code doivent rester illimitées — elles
 * sont la totalité des classes venues de KLASSCI.
 *
 * `ADR-848-01` prescrivait une colonne générée, sur le modèle de #541. **C'était
 * une erreur de transposition** : #541 en avait besoin parce que sa condition
 * portait sur une AUTRE colonne (`deleted_at IS NULL`), invisible d'un unique sur
 * `klassci_evaluation_id`. Ici la condition porte sur la colonne elle-même, et
 * SQL autorise déjà les `NULL` en doublon dans un index unique.
 *
 * Vérifié plutôt que supposé, sur SQLite :
 *
 *     3 classes sans code dans le même établissement   -> acceptées
 *     même code dans deux établissements               -> accepté
 *     même code deux fois dans un établissement        -> UNIQUE constraint failed
 *
 * La jambe MySQL de la CI vérifie le même invariant sur l'autre moteur.
 *
 * `classes` n'a pas de `deleted_at` : aucune ligne à faire sortir de la
 * contrainte, donc aucun drapeau de vivacité à maintenir.
 *
 * ## Pourquoi `institution_id` dans la clé
 *
 * Deux établissements sont indépendants et peuvent légitimement nommer une classe
 * `L1`. Un unique global ferait échouer la seconde école — même raison qu'en #473
 * (`seances`), #258 (`matieres`) et `fix_classes_unique_per_institution`.
 *
 * ## Idempotence, et refus bruyant
 *
 * Sous MySQL, `ADD UNIQUE` est une DDL à commit implicite : si elle échoue, la
 * migration est marquée en échec et tout `migrate` ultérieur la rejouerait. La
 * pose est donc conditionnée à l'absence de l'index.
 *
 * Et si des doublons préexistent, on **refuse** au lieu de choisir une survivante.
 * Contrairement à #541, aucun critère objectif ne désigne la bonne classe : un
 * code saisi à la main sur deux classes vivantes est une ambiguïté métier que la
 * migration n'a pas qualité pour trancher. Mesuré avant écriture : zéro doublon.
 */
return new class extends Migration
{
    private const INDEX = 'classes_institution_code_unique';

    public function up(): void
    {
        $doublons = DB::table('classes')
            ->select('institution_id', 'code')
            ->whereNotNull('code')
            ->where('code', '<>', '')
            ->groupBy('institution_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($doublons->isNotEmpty()) {
            throw new RuntimeException(
                'Des classes partagent déjà un code dans le même établissement : '
                .$doublons->count().' cas. Aucun critère ne désigne laquelle conserver — '
                .'à arbitrer manuellement avant de poser l\'unique (#848).'
            );
        }

        if ($this->aDejaLIndex()) {
            return;
        }

        Schema::table('classes', function (Blueprint $table): void {
            $table->unique(['institution_id', 'code'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->aDejaLIndex()) {
            return;
        }

        Schema::table('classes', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }

    private function aDejaLIndex(): bool
    {
        return collect(Schema::getIndexes('classes'))
            ->contains(fn (array $index): bool => $index['name'] === self::INDEX);
    }
};
