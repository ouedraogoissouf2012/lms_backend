<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #797 / #848 — `matieres.klassci_id` est NOT NULL **sans valeur par
 * défaut** : aucune matière ne peut naître sans identifiant KLASSCI.
 *
 * C'est le second des trois verrous de l'ADR-848-01. Le premier — la Classe —
 * n'exigeait que l'écrivain, sa table étant déjà prête. Celui-ci exige la
 * migration AVANT l'écrivain : sans elle, `Matiere::create()` échoue sur la
 * contrainte, quel que soit le code appelant.
 *
 * #710 avait levé six verrous du même genre (`classes`, `seances`,
 * `evaluations`, `users`, `institutions`) et laissé celui-ci.
 *
 * ## Ce qui ne casse PAS, vérifié
 *
 * L'unique `matieres_klassci_institution_unique` porte sur
 * `(klassci_id, institution_id)`. SQL autorise les `NULL` en doublon dans un
 * index unique : plusieurs matières locales d'un même établissement y
 * cohabitent, exactement comme les classes locales depuis #860.
 *
 * Aucun appelant ne déréférence `matiere->klassci_id` en supposant qu'il
 * existe. `Lesson.php:21` le documente déjà comme `int|null`, et les deux
 * lectures par identifiant — `KlassciMatiereClassesSource:203` et
 * `KlassciEnrollmentSource:99` — sont des `whereIn`, qu'une matière locale ne
 * satisfait simplement pas. C'est le comportement voulu : une matière locale
 * n'a rien à faire dans une correspondance KLASSCI.
 *
 * ## Aucun unique sur `code` ici, et c'est délibéré
 *
 * #860 en a posé un sur `classes.code` parce qu'`ImportApplyService:165`
 * retrouve une classe par `where('code', …)->first()` : deux codes identiques
 * y feraient inscrire des apprenants dans la mauvaise classe, en silence.
 *
 * Mesuré sur `matieres` : **aucun accès par code** dans tout `app/`. Poser un
 * unique « par symétrie » garderait un chemin qui n'existe pas, et
 * interdirait un doublon légitime sans qu'aucun défaut ne le motive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matieres', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_id')->nullable()->change();
        });
    }

    /**
     * Le retour arrière n'est possible qu'en l'absence de matière locale : une
     * ligne à `klassci_id` nul violerait la contrainte restaurée. On refuse
     * bruyamment plutôt que de laisser la migration mourir sur une erreur de
     * moteur, illisible.
     */
    public function down(): void
    {
        $locales = \Illuminate\Support\Facades\DB::table('matieres')
            ->whereNull('klassci_id')
            ->count();

        if ($locales > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$locales} matière(s) locale(s) existent, "
                .'et elles n\'ont pas d\'identifiant KLASSCI à inscrire (#848).'
            );
        }

        Schema::table('matieres', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_id')->nullable(false)->change();
        });
    }
};
