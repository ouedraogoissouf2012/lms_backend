<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #846 (lot D) — une séance locale ne connaît pas sa classe.
 *
 * `seances` ne porte que des colonnes `klassci_*` et deux noms en texte libre.
 * `LocalSeanceCreator:32` écrit `'klassci_classe_id' => null` : une séance créée
 * hors KLASSCI n'a donc **aucun lien** vers une classe, seulement un
 * `classe_nom`.
 *
 * Conséquence mesurée : `canRead:25` court-circuite la porte étudiant quand
 * `klassci_classe_id` est nul, donc **tout apprenant d'une école autonome est
 * privé des enregistrements de ses propres cours** — sauf s'il était présent,
 * cas couvert par la troisième porte (les présences).
 *
 * ## Pourquoi cette table et pas `creneaux`
 *
 * `creneaux` a été créée par #800 pour le monde autonome, avec un modèle. Elle
 * n'a **aucun service** : mesure du 2026-09-19 sur `app/Services/` → 0 fichier.
 * En regard, **14 fichiers de service visio** dépendent de `Seance`, et
 * `LocalSeanceCreator` est câblé à une route vivante. Déplacer la séance locale
 * vers `creneaux` réécrirait ces quatorze fichiers pour un objet qui ne rend
 * aujourd'hui aucun service.
 *
 * ## Nullable, par nécessité et non par confort
 *
 * Une séance miroitée de KLASSCI n'a pas de classe locale à désigner : sa classe
 * est identifiée par `klassci_classe_id`, et la classe miroir correspondante
 * peut ne pas être encore synchronisée. Rendre la colonne obligatoire ferait
 * échouer la synchronisation.
 *
 * ## Pas de contrainte de clé étrangère, et c'est délibéré
 *
 * `classes` est alimentée par la synchronisation KLASSCI, qui supprime et
 * recrée. Une FK `restrictOnDelete` ferait échouer une synchronisation ; une FK
 * `cascadeOnDelete` détruirait des séances — donc leurs enregistrements — à la
 * disparition d'une classe miroir. L'intégrité est portée par le service
 * écrivain, qui vérifie l'appartenance à l'établissement, comme le fait déjà
 * `LocalClasseMatiereLinker`.
 *
 * L'index composite sert la seule lecture prévue : « les séances de cette classe
 * dans cet établissement ».
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
return new class extends Migration
{
    private const INDEX = 'seances_institution_classe_index';

    public function up(): void
    {
        if (! Schema::hasColumn('seances', 'classe_id')) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->unsignedBigInteger('classe_id')
                    ->nullable()
                    ->after('klassci_classe_id')
                    ->comment('#846 — classe LOCALE d\'une séance créée hors KLASSCI. Nulle pour une séance miroitée.');
            });
        }

        if (! $this->aDejaLIndex()) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->index(['institution_id', 'classe_id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->aDejaLIndex()) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('seances', 'classe_id')) {
            $rattachees = DB::table('seances')->whereNotNull('classe_id')->count();

            if ($rattachees > 0) {
                throw new RuntimeException(
                    "Retour arrière impossible : {$rattachees} séance(s) portent une classe locale, "
                    .'et aucune colonne ne pourrait la conserver (#846).'
                );
            }

            Schema::table('seances', function (Blueprint $table): void {
                $table->dropColumn('classe_id');
            });
        }
    }

    private function aDejaLIndex(): bool
    {
        return collect(Schema::getIndexes('seances'))
            ->contains(fn (array $index): bool => $index['name'] === self::INDEX);
    }
};
