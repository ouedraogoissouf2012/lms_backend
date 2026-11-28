<?php

declare(strict_types=1);

use App\Enums\TypeDeSeance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1668 — le type d'une séance ({@see TypeDeSeance}), en chaîne adossée à
 * l'énumération (convention #522).
 *
 * Nullable, par nécessité : une séance existante n'a pas de type, et aucun n'est fabriqué ;
 * une séance KLASSCI n'en a que si KLASSCI en donne un sans ambiguïté.
 *
 * Aucun index : le seul filtre (`type` de la liste à venir) s'applique à une fenêtre de dates
 * de l'établissement, déjà bornée par l'index de tête `(institution_id, …)`.
 *
 * Rejouable : la colonne n'est ajoutée ou retirée que si elle est dans l'état contraire.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seances', 'type_seance')) {
            Schema::table('seances', static function (Blueprint $table): void {
                $table->string('type_seance', 32)->nullable()->after('titre')
                    ->comment('#1668 — TypeDeSeance ; null sans type connu, jamais fabriqué.');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seances', 'type_seance')) {
            Schema::table('seances', static function (Blueprint $table): void {
                $table->dropColumn('type_seance');
            });
        }
    }
};
