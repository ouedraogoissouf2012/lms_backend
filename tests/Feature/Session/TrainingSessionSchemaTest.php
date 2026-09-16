<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\TrainingSessionPhase;
use App\Enums\TrainingSessionStatus;
use App\Models\Creneau;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #800 — l'entité racine du monde autonome prend corps.
 *
 * ## Le vocabulaire est figé, et ce fichier le garde
 *
 * `docs/LEXIQUE_V2.md` fixe Programme / Période / Créneau / Classe. Le renommer
 * après coup toucherait des dizaines de tables : les noms sont donc assertés
 * ici, pas seulement documentés.
 *
 *   - **Programme** = contenu versionnable, SANS dates ni tarif ;
 *   - **Période** (`training_sessions`) = le parcours daté, qui porte le tarif ;
 *   - **Créneau** = l'occurrence datée, assiette de l'émargement.
 *
 * ## Ce qui est stocké, et ce qui est dérivé (ADR-711-04)
 *
 * `status` est une décision HUMAINE : on la stocke. `phase` est une conséquence
 * des dates : on la dérive, et **aucune colonne `phase` ne doit exister**.
 * Mélanger les deux imposerait un job nocturne qui ferait diverger l'étiquette
 * en silence — ce test interdit la colonne pour que personne ne l'ajoute.
 *
 * ## L'ordre des cinq dates est gardé EN BASE
 *
 * Pas seulement dans un FormRequest : une écriture par job, par commande ou par
 * import contournerait la validation HTTP. La contrainte est portée par la base,
 * en CHECK sous MySQL et en trigger sous SQLite — l'ADR-711-04 prévoit
 * explicitement l'un « ou trigger équivalent ».
 *
 * Les nuls restent permis : une Période peut n'avoir pas encore de date de
 * certificat. Mais les dates PRÉSENTES doivent rester ordonnées, y compris
 * quand une date intermédiaire manque — d'où la formulation en `COALESCE`.
 *
 * @see docs/SCHEMA_CIBLE_V2.md
 * @see docs/adr/2026-09-06-711-04-status-phase.md
 */
final class TrainingSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function institution(): Institution
    {
        return Institution::factory()->create();
    }

    // ───────────────────────────────── le vocabulaire

    public function test_les_trois_tables_du_lexique_existent(): void
    {
        $this->assertTrue(Schema::hasTable('programs'), 'Programme : le contenu versionnable.');
        $this->assertTrue(Schema::hasTable('training_sessions'), 'Période : le parcours daté.');
        $this->assertTrue(Schema::hasTable('creneaux'), 'Créneau : l\'occurrence datée.');
    }

    public function test_le_programme_ne_porte_ni_dates_ni_tarif(): void
    {
        // Le tarif vit sur la Période (ADR-711-06) : le poser sur le Programme
        // obligerait à dupliquer le contenu par variante tarifaire.
        foreach (['starts_on', 'ends_on', 'tarif', 'price'] as $interdite) {
            $this->assertFalse(
                Schema::hasColumn('programs', $interdite),
                "`programs.{$interdite}` ne doit pas exister : le Programme est hors dates et hors tarif."
            );
        }
    }

    public function test_la_periode_porte_les_cinq_dates_le_statut_et_le_tarif(): void
    {
        foreach ([
            'enrollment_opens_at', 'enrollment_closes_at',
            'starts_on', 'ends_on', 'certificate_available_at',
            'status', 'min_enrollments', 'tarif',
        ] as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('training_sessions', $colonne),
                "`training_sessions.{$colonne}` manque."
            );
        }
    }

    public function test_la_phase_n_est_jamais_une_colonne(): void
    {
        $this->assertFalse(
            Schema::hasColumn('training_sessions', 'phase'),
            'La phase se DÉRIVE des dates (ADR-711-04). Une colonne divergerait en silence.'
        );
    }

    public function test_le_creneau_porte_l_assiette_de_l_emargement(): void
    {
        foreach (['training_session_id', 'starts_at', 'ends_at', 'formateur_id'] as $colonne) {
            $this->assertTrue(
                Schema::hasColumn('creneaux', $colonne),
                "`creneaux.{$colonne}` manque : sans lui, ni émargement ni décompte horaire."
            );
        }
    }

    // ───────────────────────────────── l'ordre des dates, gardé en base

    public function test_une_periode_aux_dates_ordonnees_est_acceptee(): void
    {
        $session = $this->periode([
            'enrollment_opens_at' => '2026-10-01',
            'enrollment_closes_at' => '2026-10-15',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-02-28',
            'certificate_available_at' => '2027-03-15',
        ]);

        $this->assertDatabaseHas('training_sessions', ['id' => $session->getKey()]);
    }

    public function test_une_fin_anterieure_au_debut_est_refusee_par_la_base(): void
    {
        $this->expectException(QueryException::class);

        $this->periode(['starts_on' => '2026-11-01', 'ends_on' => '2026-10-01']);
    }

    public function test_des_inscriptions_fermees_apres_le_debut_sont_refusees(): void
    {
        $this->expectException(QueryException::class);

        $this->periode(['enrollment_closes_at' => '2026-11-15', 'starts_on' => '2026-11-01']);
    }

    /**
     * Le trou intermédiaire : `enrollment_closes_at` est nul, mais l'ouverture
     * des inscriptions reste postérieure au début. Un enchaînement naïf par
     * paires laisserait passer — d'où le `COALESCE`.
     */
    public function test_l_ordre_tient_meme_quand_une_date_intermediaire_manque(): void
    {
        $this->expectException(QueryException::class);

        $this->periode([
            'enrollment_opens_at' => '2026-12-01',
            'enrollment_closes_at' => null,
            'starts_on' => '2026-11-01',
        ]);
    }

    public function test_les_dates_absentes_restent_permises(): void
    {
        $session = $this->periode([
            'enrollment_opens_at' => null,
            'enrollment_closes_at' => null,
            'starts_on' => '2026-11-01',
            'ends_on' => null,
            'certificate_available_at' => null,
        ]);

        $this->assertDatabaseHas('training_sessions', ['id' => $session->getKey()]);
    }

    // ───────────────────────────────── le tenant

    public function test_les_trois_tables_sont_inscrites_au_perimetre_tenant(): void
    {
        /** @var list<string> $inscrites */
        $inscrites = config('tenancy.institution_scoped_tables');

        foreach (['programs', 'training_sessions', 'creneaux'] as $table) {
            $this->assertContains(
                $table,
                $inscrites,
                "`{$table}` doit figurer dans config/tenancy.php : c'est la source unique "
                .'de vérité des tables tenant-scopées, et la garde des clés étrangères la lit.'
            );
        }
    }

    public function test_une_periode_ne_peut_pas_naitre_sans_institution(): void
    {
        // Renforcement délibéré : contrairement aux tables historiques, ces
        // tables neuves refusent `institution_id` nul. Un Programme sans
        // établissement n'a aucun sens, et la nullabilité est précisément ce
        // qui rend les uniques composites inopérants (#801).
        $this->expectException(QueryException::class);

        TrainingSession::factory()->create(['institution_id' => null]);
    }

    // ───────────────────────────────── le statut

    public function test_le_statut_par_defaut_est_brouillon(): void
    {
        $session = $this->periode([]);

        $this->assertSame(TrainingSessionStatus::Brouillon, $session->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function periode(array $attributs): TrainingSession
    {
        $institution = $this->institution();

        return TrainingSession::factory()->create(array_merge([
            'institution_id' => $institution->getKey(),
            'program_id' => Program::factory()->create([
                'institution_id' => $institution->getKey(),
            ])->getKey(),
        ], $attributs));
    }

    /**
     * L accessor est-il REELLEMENT branche ?
     *
     * Le test d absence de colonne prouve que la phase n est pas stockee ; il
     * ne prouve pas qu elle se calcule. Sans celui-ci, un accessor mal nomme
     * rendrait null en silence.
     */
    public function test_la_phase_se_lit_sur_le_modele_et_suit_les_dates(): void
    {
        Carbon::setTestNow('2026-12-15');

        $session = $this->periode([
            'enrollment_opens_at' => '2026-10-01',
            'enrollment_closes_at' => '2026-10-20',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-02-28',
            'certificate_available_at' => null,
        ]);

        $this->assertSame(TrainingSessionPhase::EnCours, $session->fresh()->phase);

        Carbon::setTestNow();
    }

    public function test_un_creneau_appartient_a_sa_periode(): void
    {
        $session = $this->periode([]);

        $creneau = Creneau::factory()->create([
            'institution_id' => $session->institution_id,
            'training_session_id' => $session->getKey(),
        ]);

        $this->assertSame($session->getKey(), $creneau->trainingSession->getKey());
    }
}
