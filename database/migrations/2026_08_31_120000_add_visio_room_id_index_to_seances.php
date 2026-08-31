<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #469 — index sur `seances.visio_room_id`.
 *
 * La finalisation d'un enregistrement Jibri identifie la séance par son **salon**
 * (le fournisseur ne connaît aucun identifiant interne au LMS). Sans index, chaque
 * notification déclenche un balayage complet de `seances` — table qui grossit à
 * chaque séance de chaque établissement.
 *
 * ## Pourquoi un index SIMPLE, alors que la convention du dépôt est composite
 *
 * `2026_08_27_094500` et #519 posent des index **composites `institution_id`-leading**,
 * parce que la quasi-totalité des requêtes du LMS sont scopées par tenant.
 *
 * **Celle-ci ne peut pas l'être.** La route `POST /api/webhooks/visio/recording-ready`
 * est authentifiée par HMAC, pas par jeton porteur : `ResolveInstitution` ne pose
 * aucun tenant, et le service interroge déjà `withoutGlobalScope('institution')`
 * ({@see \App\Services\Visio\Recording\SeanceRecordingWebhookService} ligne 56).
 * Un index préfixé par `institution_id` ne serait donc jamais utilisé par la seule
 * requête que cet index existe pour servir.
 *
 * Ce n'est pas une entorse à l'isolation : `visio_room_id` vaut `lms_` + 20 octets
 * tirés de `random_bytes()`, soit **160 bits d'entropie**
 * ({@see \App\Services\Visio\SecureVisioRoomIdGenerator}). Deviner le salon d'un
 * autre établissement est hors de portée, et la connaissance du salon ne suffit
 * pas : il faut aussi le secret HMAC.
 *
 * L'index est donc à la fois nécessaire et hautement sélectif — une valeur, une ligne.
 *
 * ## Colonne nullable
 *
 * La majorité des séances n'ont pas de visio (`visio_room_id IS NULL`). Un index
 * BTREE stocke les NULL, mais la requête filtre toujours sur une valeur non nulle :
 * la sélectivité reste maximale sur la partie utile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->index('visio_room_id', 'seances_visio_room_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropIndex('seances_visio_room_id_idx');
        });
    }
};
