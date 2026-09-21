<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #846 (lot B) — le code d'inscription à une classe.
 *
 * [ADR-803-03](../../docs/adr/2026-09-15-803-03-trois-portes-un-service.md),
 * accepté : « Le code d'inscription appartient à la Classe. Il est court, à
 * casse insensible, et exclut les glyphes qu'on confond en le dictant au
 * téléphone. Il est révocable et régénérable sans toucher aux inscriptions déjà
 * faites. »
 *
 * ## Deux colonnes, pas une
 *
 * Révoquer n'est pas effacer. Un code retiré doit cesser d'ouvrir la classe
 * **sans** que la valeur disparaisse : sinon rien ne distingue « cette classe
 * n'a jamais eu de code » de « son code a été retiré », et l'unique cesserait
 * d'empêcher qu'un code tout juste dicté soit aussitôt réattribué ailleurs.
 *
 * ## À ne PAS confondre avec `classes.code`
 *
 * `code` désigne la CLASSE et sert à la retrouver dans un import
 * (`ImportApplyService:165`). `code_inscription` ouvre une inscription. Deux
 * colonnes, deux rôles, deux cycles de vie — l'une est saisie par un humain et
 * stable, l'autre est tirée au sort et révocable.
 *
 * ## Un unique SIMPLE suffit
 *
 * L'invariant est partiel — « un seul code par établissement, PARMI les classes
 * qui en portent un » — et SQL autorise déjà les `NULL` en doublon dans un index
 * unique. Mesuré sur SQLite lors de #860, où le même raisonnement s'appliquait à
 * `classes.code` : trois lignes sans valeur cohabitent, deux valeurs égales sont
 * refusées.
 *
 * `institution_id` dans la clé : deux écoles peuvent tirer le même code sans se
 * gêner, et un unique global ferait échouer la seconde.
 *
 * ## La casse n'est PAS déléguée au moteur
 *
 * Mesuré le 2026-09-21 : `SELECT ... WHERE c = 'abc'` sur `'ABC'` rend **0 ligne
 * sous SQLite**, et la ligne sous MySQL dont la collation par défaut est
 * insensible. S'en remettre au moteur produirait deux comportements — et des
 * tests verts sur un défaut réel. La normalisation en majuscules est faite en
 * PHP, à l'écriture comme à la lecture.
 */
return new class extends Migration
{
    private const INDEX = 'classes_institution_code_inscription_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('classes', 'code_inscription')) {
            Schema::table('classes', function (Blueprint $table): void {
                $table->string('code_inscription', 12)
                    ->nullable()
                    ->after('code')
                    ->comment('#846 — code court dicté pour rejoindre la classe. Majuscules, sans 0 1 I L O.');

                $table->timestamp('code_inscription_revoque_le')
                    ->nullable()
                    ->after('code_inscription')
                    ->comment('#846 — retiré à cette date : le code n\'ouvre plus, les inscriptions faites demeurent.');
            });
        }

        if (! $this->aDejaLIndex()) {
            Schema::table('classes', function (Blueprint $table): void {
                $table->unique(['institution_id', 'code_inscription'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->aDejaLIndex()) {
            Schema::table('classes', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (! Schema::hasColumn('classes', 'code_inscription')) {
            return;
        }

        $distribues = DB::table('classes')->whereNotNull('code_inscription')->count();

        if ($distribues > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$distribues} classe(s) portent un code d'inscription "
                .'déjà distribué, qu\'aucune colonne ne conserverait (#846).'
            );
        }

        Schema::table('classes', function (Blueprint $table): void {
            $table->dropColumn(['code_inscription', 'code_inscription_revoque_le']);
        });
    }

    private function aDejaLIndex(): bool
    {
        return collect(Schema::getIndexes('classes'))
            ->contains(fn (array $index): bool => $index['name'] === self::INDEX);
    }
};
