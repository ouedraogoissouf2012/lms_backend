<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #803 — le jeton d'activation à usage unique (ADR-803-02).
 *
 * ## Pourquoi cette table existe
 *
 * Le produit n'a AUCUN canal de courriel : `config/mail.php:17` vaut `log`, et
 * `app/` ne contient ni `Mail::` ni `->notify()`. Il ne peut donc transmettre
 * aucun secret. Un mot de passe généré devrait circuler de vive voix ou par
 * messagerie — et y resterait valide indéfiniment.
 *
 * Un lien consommé, lui, cesse d'être dangereux. Le titulaire pose SON mot de
 * passe ; personne d'autre ne l'a jamais connu.
 *
 * ## Table dédiée, et non colonnes sur `users`
 *
 * Le mécanisme sert trois parcours : le propriétaire d'une école validée,
 * l'étudiant créé par import (`ImportApplyService:69` pose un mot de passe que
 * personne ne reçoit), et la réinitialisation de mot de passe (#808). Les
 * accrocher au modèle `User` mêlerait trois cycles de vie distincts à celui du
 * compte.
 *
 * ## `consumed_at` : l'usage unique ne se déduit pas de l'expiration
 *
 * Une URL signée expirante reste rejouable autant de fois qu'on veut pendant sa
 * fenêtre. Si le lien transite par une messagerie de groupe — le cas nominal
 * ici, puisqu'il est transmis hors bande — plusieurs personnes peuvent l'ouvrir.
 * Seule une marque de consommation en base fait échouer le second usage.
 *
 * ## Empreinte déterministe, pas bcrypt
 *
 * `token_hash` porte un SHA-256, car la recherche se fait PAR l'empreinte :
 * bcrypt, salé, imposerait de parcourir toute la table. Le compromis est sans
 * risque ici — le jeton est un aléa de 64 caractères, pas un mot de passe
 * humain : il n'a ni faible entropie ni réutilisation à craindre.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_tokens', function (Blueprint $table): void {
            $table->id();

            // Un jeton sans titulaire n'a aucun sens : il disparaît avec lui.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Purge des jetons périmés, et lecture « ce compte a-t-il un jeton
            // vivant ? » sans parcourir la table.
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_tokens');
    }
};
