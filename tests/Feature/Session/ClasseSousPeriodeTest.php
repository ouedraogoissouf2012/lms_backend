<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #827 — la Classe se rattache à une Période, et l'adhésion devient datée.
 *
 * `docs/SCHEMA_CIBLE_V2.md:11-20` place la Classe SOUS la Période. Le lien
 * manquait : une Classe ignorait à quelle session datée elle appartenait, donc
 * ni budget opposable, ni assiduité rattachable — les deux différenciateurs que
 * le plan revendique.
 *
 * ## La contrainte dominante : zéro régression KLASSCI
 *
 * `classe_etudiant` est alimentée par `StudentClassSynchronizer`, et `classes`
 * par le sync KLASSCI. Toute colonne ajoutée reste donc NULLABLE en base : une
 * classe miroir n'a pas de Période, et ne doit pas en recevoir. C'est
 * l'écriture LOCALE qui imposera ces champs, jamais le schéma.
 *
 * ## Le piège que seule la jambe MySQL de la CI attrape
 *
 * `classe_etudiant.statut` est un `varchar` sous SQLite mais un **ENUM sous
 * MySQL** — mesuré. Ajouter `suspendu` et `expire` passerait donc tous les
 * tests locaux et **casserait en production** : c'est exactement le défaut
 * #574/#605, où un provider hors ENUM tronquait silencieusement sous MySQL
 * strict.
 *
 * Le test d'acceptation des nouveaux statuts n'a donc de valeur QUE sur la
 * jambe MySQL. Sous SQLite il passerait même sans la migration — on le dit ici
 * plutôt que de laisser croire qu'il prouve quelque chose partout.
 *
 * @see docs/adr/2026-09-06-711-02-adhesion-datee.md
 * @see docs/adr/2026-09-06-711-06-commanditaire-financement.md
 */
final class ClasseSousPeriodeTest extends TestCase
{
    use RefreshDatabase;

    private function periode(Institution $institution): TrainingSession
    {
        return TrainingSession::factory()->create([
            'institution_id' => $institution->getKey(),
            'program_id' => Program::factory()->create([
                'institution_id' => $institution->getKey(),
            ])->getKey(),
        ]);
    }

    // ───────────────────────────────── le lien Classe → Période

    public function test_la_classe_porte_un_lien_vers_sa_periode(): void
    {
        $this->assertTrue(
            Schema::hasColumn('classes', 'training_session_id'),
            'Sans ce lien, une Classe ignore à quelle session datée elle appartient.'
        );
    }

    public function test_une_classe_locale_se_rattache_a_sa_periode(): void
    {
        $institution = Institution::factory()->create();
        $periode = $this->periode($institution);

        $classe = Classe::factory()->create([
            'institution_id' => $institution->getKey(),
            'training_session_id' => $periode->getKey(),
        ]);

        $this->assertSame($periode->getKey(), $classe->fresh()->trainingSession?->getKey());
    }

    public function test_une_classe_miroir_klassci_reste_valide_sans_periode(): void
    {
        // Zéro régression : les classes existantes n'ont pas de Période et ne
        // doivent pas en recevoir. La colonne est nullable pour cette raison.
        $classe = Classe::factory()->create(['training_session_id' => null]);

        $this->assertDatabaseHas('classes', [
            'id' => $classe->getKey(),
            'training_session_id' => null,
        ]);
    }

    // ───────────────────────────────── l'adhésion datée

    public function test_l_adhesion_porte_ses_propres_dates(): void
    {
        foreach (['starts_on', 'ends_on'] as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('classe_etudiant', $colonne),
                "`classe_etudiant.{$colonne}` manque : sans validité temporelle, retirer "
                .'un apprenant imposerait de supprimer la ligne et détruirait l\'historique.'
            );
        }
    }

    public function test_les_colonnes_de_financement_sont_reservees(): void
    {
        // ADR-711-06 : réserver coûte une migration ; ajouter plus tard impose
        // une reprise sur des Périodes déjà en cours.
        foreach ([
            'commanditaire_id',
            'financement_source',
            'financement_montant',
            'financement_reference',
            'financement_statut',
        ] as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('classe_etudiant', $colonne),
                "`classe_etudiant.{$colonne}` manque (ADR-711-06)."
            );
        }
    }

    /**
     * Ce test ne prouve rien sous SQLite — il n'a de valeur que sur la jambe
     * MySQL de la CI, seule à porter l'ENUM.
     */
    public function test_les_statuts_etendus_sont_acceptes(): void
    {
        $institution = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $institution->getKey()]);
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
        ]);

        foreach (['suspendu', 'expire'] as $statut) {
            DB::table('classe_etudiant')->insert([
                'classe_id' => $classe->getKey(),
                'user_id' => $etudiant->getKey(),
                'institution_id' => $institution->getKey(),
                'statut' => $statut,
                'date_inscription' => '2026-11-01',
                'annee_universitaire_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertDatabaseHas('classe_etudiant', [
                'user_id' => $etudiant->getKey(),
                'statut' => $statut,
            ]);

            DB::table('classe_etudiant')->where('statut', $statut)->delete();
        }
    }

    public function test_les_statuts_historiques_restent_acceptes(): void
    {
        $institution = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $institution->getKey()]);
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
        ]);

        // Zéro régression : le sync KLASSCI écrit ces trois-là.
        foreach (['actif', 'inactif', 'abandonne'] as $statut) {
            DB::table('classe_etudiant')->insert([
                'classe_id' => $classe->getKey(),
                'user_id' => $etudiant->getKey(),
                'institution_id' => $institution->getKey(),
                'statut' => $statut,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertDatabaseHas('classe_etudiant', [
                'user_id' => $etudiant->getKey(),
                'statut' => $statut,
            ]);

            DB::table('classe_etudiant')->where('statut', $statut)->delete();
        }
    }

    public function test_date_inscription_reste_nullable_en_base(): void
    {
        // L'ADR la veut obligatoire À L'ÉCRITURE LOCALE, pas en base : la
        // rendre NOT NULL casserait le sync KLASSCI, qui ne la renseigne pas.
        $institution = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $institution->getKey()]);
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
        ]);

        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->getKey(),
            'user_id' => $etudiant->getKey(),
            'institution_id' => $institution->getKey(),
            'statut' => 'actif',
            'date_inscription' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('classe_etudiant', [
            'user_id' => $etudiant->getKey(),
            'date_inscription' => null,
        ]);
    }
}
