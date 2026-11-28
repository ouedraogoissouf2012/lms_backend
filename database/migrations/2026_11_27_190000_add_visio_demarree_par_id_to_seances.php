<?php

declare(strict_types=1);

use App\Services\Attendances\Heures\HeuresEmargees;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1682 — qui a démarré la visio d'une séance (ARRÊT 1 bis validé le 27/11). Posé par `VisioSessionService` au
 * démarrage, effacé par la désactivation. Les heures de l'enseignant ({@see HeuresEmargees})
 * le lisent : une séance compte si SON enseignant l'a démarrée et qu'elle s'est terminée.
 *
 * Aucune reconstitution : une séance démarrée avant ce relevé garde `null` et compte 0 (décision de la superviseure).
 * L'auteur supprimé laisse `null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', static function (Blueprint $table): void {
            $table->foreignId('visio_demarree_par_id')->nullable()->after('visio_started_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seances', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visio_demarree_par_id');
        });
    }
};
