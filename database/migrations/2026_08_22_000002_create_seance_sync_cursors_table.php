<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #582 — Position de reprise de la synchronisation des séances.
 *
 * La sync repartait toujours du premier enseignant et s'arrêtait au budget de
 * drain (#539) : au-delà du volume tenant dans 45 s, les enseignants suivants
 * n'étaient JAMAIS atteints. On persiste donc la position atteinte pour que la
 * passe suivante reprenne strictement après elle.
 *
 * ## Pourquoi une table et non le cache
 *
 * Le curseur est un état d'EXPLOITATION, pas un cache : `php artisan cache:clear`
 * fait partie de la procédure de déploiement, et rembobinerait le cycle à chaque
 * mise en production — réaffamant la queue de population. Le store cache étant
 * lui-même `database` en production, la table dédiée ne coûte pas plus cher et
 * reste inspectable.
 *
 * ## Pourquoi aucune clé étrangère
 *
 * `last_institution_id` / `last_user_id` sont une POSITION DE BALAYAGE, pas une
 * référence : une FK `nullOnDelete` rembobinerait un cycle entier à la
 * suppression d'un utilisateur, et une FK `restrict` empêcherait cette
 * suppression. Le préfixe `last_` écarte toute lecture comme colonne de tenant
 * (cette table est délibérément globale, hors `BelongsToInstitution`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seance_sync_cursors', function (Blueprint $table): void {
            $table->id();

            // Unique : garantit au niveau base qu'il n'existe qu'une seule ligne
            // par curseur nommé — impossible de dupliquer la position par erreur.
            $table->string('name', 64)->unique();

            $table->unsignedBigInteger('last_institution_id')->nullable()
                ->comment('Composante tenant de la position de balayage');
            $table->unsignedBigInteger('last_user_id')->nullable()
                ->comment('Composante enseignant de la position de balayage');

            $table->timestamp('cycle_started_at')
                ->comment('Début du cycle courant — référence du balayage d\'archivage');

            $table->json('tainted_institution_ids')
                ->comment('Tenants ayant subi une erreur durant le cycle : archivage renoncé');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seance_sync_cursors');
    }
};
