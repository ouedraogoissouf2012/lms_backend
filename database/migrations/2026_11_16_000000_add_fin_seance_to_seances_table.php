<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La FIN réelle d'une séance KLASSCI, pour le verrou des séances passées.
 *
 * `seances` ne portait que le début (`date_seance`). Démarrer la visio d'une séance
 * KLASSCI ne lit que cette ligne : sans sa fin, le serveur ne pouvait que l'estimer
 * (+ 2 h, et couper un cours de 4 h) ou rappeler KLASSCI au démarrage. La fin réelle
 * de la programmation KLASSCI est écrite à l'activation et par le cache de synchro.
 *
 * Additive et nullable : aucune donnée réécrite. Une séance LOCALE n'a pas de fin
 * connue (NULL, estimée à l'affichage) ; une ligne KLASSCI antérieure reste à NULL
 * et n'est pas verrouillée (échec ouvert, déclaré).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->timestamp('fin_seance')->nullable()->after('date_seance')
                ->comment('Fin réelle de la séance (programmation KLASSCI), NULL si inconnue');
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropColumn('fin_seance');
        });
    }
};
