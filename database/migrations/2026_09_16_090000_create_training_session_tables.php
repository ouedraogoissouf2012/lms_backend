<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #800 — l'entité racine du monde autonome prend corps (ADR-711-01 à 711-06).
 *
 * Trois tables, et le vocabulaire de `docs/LEXIQUE_V2.md` :
 *
 *   - **Programme** : le contenu versionnable. SANS dates, SANS tarif.
 *   - **Période** (`training_sessions`) : le parcours daté. Porte le tarif,
 *     les inscriptions, l'assiduité.
 *   - **Créneau** : l'occurrence datée sous la Période — assiette de
 *     l'émargement et du décompte horaire.
 *
 * ## Le tarif vit sur la Période, jamais sur le Programme
 *
 * Le poser sur le Programme obligerait à dupliquer le contenu pour chaque
 * variante tarifaire — le défaut de LearnDash par une autre porte (ADR-711-06).
 *
 * ## `institution_id` est NOT NULL, contrairement aux tables historiques
 *
 * Renforcement délibéré. Les 31 tables déjà inscrites au périmètre tenant
 * portent une colonne nullable, héritée de `2026_02_11_000002` : c'est
 * précisément ce qui rend leurs uniques composites inopérants, puisque SQL ne
 * dédoublonne pas les NULL (#801).
 *
 * Ces tables-ci sont neuves : aucune ligne historique à accommoder, et un
 * Programme sans établissement n'a aucun sens — contrairement à un compte
 * plateforme. On ferme donc le défaut à la naissance plutôt que de le répéter.
 *
 * Conséquence assumée : une écriture hors contexte d'établissement échoue
 * bruyamment au lieu d'insérer une ligne orpheline. `BelongsToInstitution`
 * étant fail-open en lecture, c'est la base qui porte ici le fail-closed.
 *
 * ## L'ordre des cinq dates est gardé EN BASE
 *
 * Pas seulement dans un FormRequest : un job, une commande ou un import
 * contourneraient la validation HTTP.
 *
 * SQLite ne sait pas faire `ALTER TABLE ... ADD CONSTRAINT`. Le précédent du
 * dépôt (`2026_01_03_220000`) recrée la table entière pour contourner — on ne
 * le reproduit pas : une seule définition Blueprint, et le dialecte isolé à la
 * contrainte. CHECK sous MySQL, trigger sous SQLite. L'ADR-711-04 prévoit
 * explicitement l'un « ou trigger équivalent ».
 *
 * La formulation passe par `COALESCE` et non par un enchaînement de paires :
 * avec des paires, `opens > starts` passerait dès que `closes` est nul, puisque
 * chaque comparaison isolée rendrait NULL. Les nuls restent permis — une
 * Période peut n'avoir pas encore de date de certificat — mais les dates
 * PRÉSENTES restent ordonnées, y compris à travers un trou.
 *
 * @see docs/SCHEMA_CIBLE_V2.md
 * @see config/tenancy.php
 */
return new class extends Migration
{
    /** Les cinq dates, dans l'ordre chronologique imposé par l'ADR-711-04. */
    private const DATES = [
        'enrollment_opens_at',
        'enrollment_closes_at',
        'starts_on',
        'ends_on',
        'certificate_available_at',
    ];

    public function up(): void
    {
        $this->creerProgrammes();
        $this->creerPeriodes();
        $this->creerCreneaux();
        $this->poserLaContrainteDOrdre();
    }

    /**
     * Aucun retrait explicite de la contrainte : sous MySQL le CHECK disparaît
     * avec la table, sous SQLite les triggers aussi. Une étape de plus
     * n'ajouterait qu'un mode de panne.
     */
    public function down(): void
    {
        Schema::dropIfExists('creneaux');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('programs');
    }

    private function creerProgrammes(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();

            $table->string('titre', 191);
            $table->text('description')->nullable();

            // Versionnable : deux Périodes peuvent pointer deux versions du même
            // contenu sans que l'une réécrive l'histoire de l'autre.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'titre']);
        });
    }

    private function creerPeriodes(): void
    {
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();

            $table->string('libelle', 191);

            // Décision humaine. La phase, elle, se dérive des dates ci-dessous.
            $table->string('status', 20)->default('brouillon');

            foreach (self::DATES as $date) {
                $table->date($date)->nullable();
            }

            // Alerte de sous-inscription (ADR-711-04), pas un verrou.
            $table->unsignedInteger('min_enrollments')->nullable();

            // Le tarif vit ICI (ADR-711-06). Entier : la plus petite unité
            // monétaire, jamais un flottant sur de l'argent.
            $table->unsignedBigInteger('tarif')->nullable();
            $table->string('devise', 3)->nullable();

            // Compteur d'heures-stagiaires, stocké au niveau Période (ADR-711-01).
            $table->unsignedInteger('heures_stagiaires')->nullable();

            // Réservé (ADR-711-06) : qui achète, distinct de l'apprenant. Aucun
            // écran en V2 ; la colonne évite une reprise sur des Périodes déjà
            // en cours.
            $table->unsignedBigInteger('commanditaire_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'starts_on']);
        });
    }

    private function creerCreneaux(): void
    {
        Schema::create('creneaux', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('training_session_id')->constrained('training_sessions')->cascadeOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // Lieu OU salle virtuelle : les deux nullables, jamais exclusifs en
            // base — un créneau hybride existe (présentiel retransmis).
            $table->string('lieu', 191)->nullable();
            $table->string('salle_virtuelle', 500)->nullable();

            // Pas de FK vers `users` : le formateur peut être un intervenant
            // externe sans compte, cas courant en formation professionnelle.
            $table->unsignedBigInteger('formateur_id')->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'starts_at']);
            $table->index('training_session_id');
        });
    }

    /**
     * `enrollment_opens_at ≤ enrollment_closes_at ≤ starts_on ≤ ends_on ≤ certificate_available_at`,
     * chaque date comparée à la PROCHAINE RENSEIGNÉE.
     *
     * Le préfixe sert au trigger SQLite, où les colonnes se lisent `NEW.<col>`.
     * On le passe en paramètre plutôt que de réécrire le SQL produit : une
     * substitution a posteriori sur des noms de colonnes est fragile dès qu'un
     * nom devient le préfixe d'un autre.
     */
    private function expressionDOrdre(string $prefixe = ''): string
    {
        $conditions = [];

        foreach (self::DATES as $i => $date) {
            $suivantes = array_slice(self::DATES, $i + 1);

            if ($suivantes === []) {
                continue;
            }

            $colonne = $prefixe.$date;
            $cibles = implode(', ', array_map(
                static fn (string $d): string => $prefixe.$d,
                $suivantes
            ));

            // `COALESCE` exige au moins deux arguments sous SQLite : la
            // derniere comparaison se fait donc en direct.
            $prochaine = count($suivantes) === 1 ? $cibles : sprintf('COALESCE(%s)', $cibles);

            // `IS NULL OR` rend la condition VRAIE quand la date est absente :
            // les nuls restent permis, seules les dates présentes s'ordonnent.
            $conditions[] = sprintf('(%s IS NULL OR %s <= %s)', $colonne, $colonne, $prochaine);
        }

        return implode(' AND ', $conditions);
    }

    private function poserLaContrainteDOrdre(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $evenement) {
                DB::statement(sprintf(
                    'CREATE TRIGGER training_sessions_dates_order_%s '
                    .'BEFORE %s ON training_sessions FOR EACH ROW WHEN NOT (%s) '
                    ."BEGIN SELECT RAISE(ABORT, 'training_sessions : les cinq dates doivent rester ordonnees'); END",
                    $evenement,
                    strtoupper($evenement),
                    $this->expressionDOrdre('NEW.')
                ));
            }

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE training_sessions ADD CONSTRAINT training_sessions_dates_order CHECK (%s)',
            $this->expressionDOrdre()
        ));
    }
};
