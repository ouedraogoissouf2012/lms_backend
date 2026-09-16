<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #827 — la Classe se rattache à une Période, et l'adhésion devient datée.
 *
 * `docs/SCHEMA_CIBLE_V2.md:11-20` place la Classe SOUS la Période. Le lien
 * manquait : une Classe ignorait à quelle session datée elle appartenait — donc
 * ni budget opposable, ni assiduité rattachable.
 *
 * ## Zéro régression KLASSCI, contrainte dominante
 *
 * `classes` et `classe_etudiant` sont alimentées par le synchroniseur KLASSCI.
 * TOUTES les colonnes ajoutées ici sont donc nullables : une classe miroir n'a
 * pas de Période et ne doit pas en recevoir. C'est l'écriture LOCALE qui
 * imposera ces champs — jamais le schéma, sous peine de casser le sync.
 *
 * `date_inscription` reste nullable pour la même raison : l'ADR-711-02 la veut
 * obligatoire **à l'écriture locale**, pas en base.
 *
 * ## Le piège que seule la jambe MySQL de la CI attrape
 *
 * `classe_etudiant.statut` est un `varchar` sous SQLite — mesuré sur le schéma
 * réel — mais un **ENUM sous MySQL**, posé par `2025_10_14_160400`. Écrire
 * `suspendu` ou `expire` passerait donc en local et **tomberait en production**
 * sur `SQLSTATE[01000] 1265 Data truncated`.
 *
 * C'est exactement le défaut #605, découvert par la jambe MySQL (#574) : un
 * provider vidéo hors ENUM faisait échouer l'attache de replay en production
 * pendant que SQLite le masquait. On applique le même remède, et le même
 * patron : `2026_08_23_000001_normalize_chapters_video_provider_to_string.php`.
 *
 * @see docs/adr/2026-09-06-711-02-adhesion-datee.md
 * @see docs/adr/2026-09-06-711-06-commanditaire-financement.md
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rattacherLaClasseALaPeriode();
        $this->daterLAdhesion();
        $this->reserverLeFinancement();
        $this->libererLeStatut();
    }

    public function down(): void
    {
        $this->restaurerLEnumDeStatut();

        Schema::table('classe_etudiant', function (Blueprint $table): void {
            $table->dropColumn([
                'starts_on', 'ends_on',
                'commanditaire_id',
                'financement_source', 'financement_montant',
                'financement_reference', 'financement_statut',
            ]);
        });

        Schema::table('classes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('training_session_id');
        });
    }

    private function rattacherLaClasseALaPeriode(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            // NULLABLE : une classe miroir KLASSCI n'a pas de Période.
            // `nullOnDelete` et non `cascade` : supprimer une Période ne doit
            // pas emporter les apprenants d'une classe et leur historique.
            $table->foreignId('training_session_id')
                ->nullable()
                ->after('institution_id')
                ->constrained('training_sessions')
                ->nullOnDelete();
        });
    }

    private function daterLAdhesion(): void
    {
        Schema::table('classe_etudiant', function (Blueprint $table): void {
            // Sans validité temporelle, retirer un apprenant imposerait de
            // supprimer la ligne — et détruirait l'historique, l'inverse exact
            // de ce que la Période défend (ADR-711-02).
            $table->date('starts_on')->nullable()->after('date_inscription');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }

    private function reserverLeFinancement(): void
    {
        Schema::table('classe_etudiant', function (Blueprint $table): void {
            // Qui achète, distinct de l'apprenant.
            $table->unsignedBigInteger('commanditaire_id')->nullable()->after('ends_on');

            // Source en champ OUVERT, jamais une enum figée : le FDFP ivoirien
            // (0,4 % + 1,2 %) n'épuise pas les dispositifs, et figer la liste
            // imposerait une migration à chaque nouveau financeur (ADR-711-06).
            $table->string('financement_source', 191)->nullable()->after('commanditaire_id');

            // Entier : plus petite unité monétaire, jamais un flottant.
            $table->unsignedBigInteger('financement_montant')->nullable()->after('financement_source');
            $table->string('financement_reference', 191)->nullable()->after('financement_montant');
            $table->string('financement_statut', 40)->nullable()->after('financement_reference');
        });
    }

    /**
     * MySQL uniquement : SQLite porte déjà un `varchar` sans CHECK.
     */
    private function libererLeStatut(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE classe_etudiant MODIFY COLUMN statut VARCHAR(40) NOT NULL DEFAULT 'actif'"
        );
    }

    /**
     * La réversion n'est pas sans perte : restaurer l'ENUM échouera si des
     * lignes portent `suspendu` ou `expire` — précisément le cas que cette
     * migration débloque. Même franchise que le patron #605.
     */
    private function restaurerLEnumDeStatut(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE classe_etudiant MODIFY COLUMN statut ENUM('actif','inactif','abandonne') NOT NULL DEFAULT 'actif'"
        );
    }
};
