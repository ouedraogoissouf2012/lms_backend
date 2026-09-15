<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #803 — la porte d'entrée du monde autonome (ADR-803-01).
 *
 * Une personne extérieure dépose une demande d'ouverture d'école. La ligne est
 * INERTE : elle ne confère aucun droit, ne s'authentifie pas, et ne devient rien
 * tant qu'un supradmin plateforme n'a pas tranché.
 *
 * ## Hors périmètre multi-tenant, délibérément
 *
 * Cette table n'est PAS inscrite dans `config/tenancy.php`. Une demande
 * n'appartient à aucune institution — par définition, celle-ci n'existe pas
 * encore. Un scope global la filtrerait par `institution_id` et la ferait
 * disparaître de la file du supradmin, dont l'`institution_id` est NULL.
 *
 * ## Pourquoi pas une Institution « en attente »
 *
 * Créer l'institution dès la demande paraît plus simple, et c'est un piège :
 * elle occuperait immédiatement un `slug` unique — n'importe qui squatterait
 * « esbtp » sans rien prouver —, entrerait dans le graphe multi-tenant que
 * chaque scope devrait alors connaître, et un refus laisserait une institution
 * fantôme. D'où `slug_souhaite` : un souhait, jamais une réservation.
 *
 * ## L'unicité partielle, et sa colonne de garde
 *
 * Une seule demande `en_attente` par adresse, mais autant de demandes tranchées
 * que voulu — sinon un refus interdirait de redéposer un dossier.
 *
 * MySQL 8 n'a pas d'index unique partiel. On applique le patron déjà en vigueur
 * dans ce dépôt (`2026_08_23_100002`, #541) : une colonne générée valant 1 quand
 * la ligne est en attente, NULL sinon. SQL ne dédoublonnant pas les NULL, seules
 * les demandes en attente entrent en collision.
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
return new class extends Migration
{
    private const GUARD_COLUMN = 'en_attente_guard';

    public function up(): void
    {
        Schema::create('school_registration_requests', function (Blueprint $table): void {
            $table->id();

            // Le demandeur — futur superAdmin de l'établissement s'il est validé.
            $table->string('nom_demandeur', 191);
            $table->string('email_demandeur', 191);
            $table->string('telephone_demandeur', 40)->nullable();

            // L'établissement souhaité. `slug_souhaite` n'est JAMAIS réservé :
            // le supradmin l'arbitre à la validation, collision comprise.
            $table->string('nom_ecole', 191);
            $table->string('slug_souhaite', 50)->nullable();
            $table->text('usage_prevu');

            $table->string('statut', 20)->default('en_attente');

            // Traçabilité de la décision.
            $table->foreignId('decide_par_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_refus')->nullable();

            // Rempli à la validation seulement (ADR-803-02).
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            $table->timestamps();

            $table->index('statut');
        });

        // Colonne de garde + unicité partielle, en une étape séparée : `virtualAs`
        // ne peut pas référencer une colonne déclarée dans le même `create`.
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger(self::GUARD_COLUMN)
                ->nullable()
                ->virtualAs("case when statut = 'en_attente' then 1 end")
                ->comment('#803 — drapeau support de l\'unicité partielle « une seule demande en attente par adresse »');
        });

        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->unique(
                ['email_demandeur', self::GUARD_COLUMN],
                'school_requests_pending_email_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_registration_requests');
    }
};
