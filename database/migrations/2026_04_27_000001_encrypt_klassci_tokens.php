<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Encrypt KLASSCI tokens at rest using Laravel's AES-256-CBC encryption.
     * Étapes:
     * 1. Ajouter les colonnes encrypted
     * 2. Migrer les données de l'ancien vers le nouveau
     * 3. Supprimer les anciennes colonnes
     *
     * Référence: Laravel 12.x Encryption (AES-256-CBC with MAC signing)
     * OWASP 2025 Compliance: Bearer tokens encrypted at rest
     */
    public function up(): void
    {
        // Step 1: Add new encrypted columns
        Schema::table('users', function (Blueprint $table) {
            $table->text('klassci_token_encrypted')->nullable()->after('klassci_token');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->text('klassci_api_token_encrypted')->nullable()->after('klassci_api_token');
        });

        // Step 2: Migrate data using models with encrypted casts
        $this->migrateUserTokens();
        $this->migrateInstitutionTokens();

        // Step 3: Drop old columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('klassci_token');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('klassci_api_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore old columns (data remains encrypted - intentional)
        Schema::table('users', function (Blueprint $table) {
            $table->text('klassci_token')->nullable()->after('password');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->text('klassci_api_token')->nullable()->after('klassci_api_url');
        });

        // Copy encrypted data back (no decryption)
        \DB::table('users')
            ->whereNotNull('klassci_token_encrypted')
            ->update([
                'klassci_token' => \DB::raw('klassci_token_encrypted')
            ]);

        \DB::table('institutions')
            ->whereNotNull('klassci_api_token_encrypted')
            ->update([
                'klassci_api_token' => \DB::raw('klassci_api_token_encrypted')
            ]);

        // Drop encrypted columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('klassci_token_encrypted');
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('klassci_api_token_encrypted');
        });
    }

    /**
     * Migrate user tokens from plaintext to encrypted columns.
     * The 'encrypted' cast automatically encrypts on save.
     */
    private function migrateUserTokens(): void
    {
        // #566 : `withoutGlobalScopes()` — cette migration de données PRÉCÈDE l'ajout
        // du trait SoftDeletes sur User ; sans ça, le SoftDeletingScope injecterait
        // `deleted_at is null` alors que la colonne n'existe pas encore à cette étape
        // de `migrate:fresh`. Une migration opère sur les lignes brutes, pas sur les
        // scopes du modèle courant (qui évolue indépendamment).
        \App\Models\User::withoutGlobalScopes()->whereNotNull('klassci_token')
            ->chunk(100, function ($users) {
                foreach ($users as $user) {
                    if ($user->klassci_token) {
                        $user->klassci_token_encrypted = $user->klassci_token;
                        $user->saveQuietly();
                    }
                }
            });
    }

    /**
     * Migrate institution tokens from plaintext to encrypted columns.
     * The 'encrypted' cast automatically encrypts on save.
     */
    private function migrateInstitutionTokens(): void
    {
        // #567 : `withoutGlobalScopes()` — cette migration de données PRÉCÈDE l'ajout
        // du trait SoftDeletes sur Institution ; sans ça, le SoftDeletingScope
        // injecterait `deleted_at is null` alors que la colonne n'existe pas encore à
        // cette étape de `migrate:fresh`. Une migration opère sur les lignes brutes.
        \App\Models\Institution::withoutGlobalScopes()->whereNotNull('klassci_api_token')
            ->chunk(100, function ($institutions) {
                foreach ($institutions as $institution) {
                    if ($institution->klassci_api_token) {
                        $institution->klassci_api_token_encrypted = $institution->klassci_api_token;
                        $institution->saveQuietly();
                    }
                }
            });
    }
};
