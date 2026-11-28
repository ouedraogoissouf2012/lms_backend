<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\DocumentEmis;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1662 — le certificat de scolarité et l'attestation de fréquentation, en PDF.
 *
 * La gestion de l'école ÉMET un document pour un élève inscrit (une inscription ACTIVE) : il reçoit un numéro par
 * école, par type et par année (`CS-2026-0001`, `AF-2026-0001`), un code de vérification, et l'émission est
 * inscrite au journal d'audit. Une page PUBLIQUE confirme un code : elle ne rend que ce qui est imprimé.
 *
 * Volet KLASSCI : les documents officiels relèvent de KLASSCI, l'ERP qui fait autorité ; deux certificats numérotés
 * par deux systèmes seraient une source de litige → 403 `catalogue_managed_by_klassci`. Élève d'une autre école :
 * 404 ; non inscrit : 409 `student_not_enrolled`. L'attestation ne porte pas encore de taux de présence (dette
 * tracée : la règle vit dans le lot 6). Vrai jeton ; le PDF est lu (`%PDF-`), et la page dont il est tiré aussi.
 */
final class LesDocumentsDeScolariteTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-28 10:00:00');
    }

    public function test_un_certificat_s_emet_numerote_et_se_rend_en_pdf(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa Kaboré', '6e A');

        $reponse = $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertOk();

        self::assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $reponse->getContent());
        self::assertStringContainsString('CS-2026-0001', (string) $reponse->headers->get('Content-Disposition'));
        $document = DB::table('documents_emis')->first();
        self::assertSame(['certificat_scolarite', 'CS-2026-0001', $eleve->id], [$document?->type, $document?->numero, (int) $document?->eleve_id]);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{12}$/', (string) $document?->code_verification);
    }

    public function test_la_numerotation_suit_l_ecole_le_type_et_l_annee(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa', '6e A');
        [$autre, $gestionAutre] = $this->ecole();
        $eleveAutre = $this->eleveInscrit($autre, 'Issa', '5e B');

        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertOk();
        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertOk();
        $this->emettre($gestion, $eleve, 'attestation_frequentation')->assertOk();
        $this->emettre($gestionAutre, $eleveAutre, 'certificat_scolarite')->assertOk();

        self::assertSame(['CS-2026-0001', 'CS-2026-0002', 'AF-2026-0001'], DB::table('documents_emis')->where('institution_id', $ecole->id)->orderBy('id')->pluck('numero')->all());
        self::assertSame('CS-2026-0001', DB::table('documents_emis')->where('institution_id', $autre->id)->value('numero'));
    }

    public function test_la_page_imprimee_porte_l_ecole_l_eleve_la_classe_le_numero_et_la_verification(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa <Kaboré>', '6e A');
        $this->emettre($gestion, $eleve, 'attestation_frequentation')->assertOk();

        $document = DocumentEmis::query()->withoutGlobalScopes()->firstOrFail();
        $page = view('documents.scolarite', ['document' => $document])->render();

        foreach ([$ecole->name, 'Awa &lt;Kaboré&gt;', '6e A', 'AF-2026-0001', $document->code_verification, '/documents/verification/'] as $attendu) {
            self::assertStringContainsString($attendu, $page);
        }
        self::assertStringNotContainsString('taux', mb_strtolower($page), 'Aucun taux de présence fabriqué (dette tracée, lot 6).');
    }

    public function test_la_verification_publique_ne_rend_que_ce_qui_est_imprime(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa Kaboré', '6e A');
        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertOk();
        $code = (string) DB::table('documents_emis')->value('code_verification');

        $donnees = $this->anonyme()->getJson("/api/documents/verification/{$code}")->assertOk()->json('data');

        self::assertSame(['type', 'numero', 'emis_le', 'ecole', 'titulaire'], array_keys($donnees));
        self::assertSame(['certificat_scolarite', 'CS-2026-0001', '2026-11-28', $ecole->name, 'Awa Kaboré'], array_values($donnees));

        $inconnu = $this->getJson('/api/documents/verification/ZZZZZZZZZZZZ')->assertNotFound()->json();
        self::assertSame($inconnu, $this->getJson('/api/documents/verification/YYYYYYYYYYYY')->assertNotFound()->json());
    }

    public function test_un_eleve_sans_inscription_active_n_a_pas_de_document(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $parti = $this->eleveInscrit($ecole, 'Parti', '6e A', 'abandonne');
        $sansClasse = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);

        $this->emettre($gestion, $parti, 'certificat_scolarite')->assertStatus(409)->assertJsonPath('reason', 'student_not_enrolled');
        $this->emettre($gestion, $sansClasse, 'certificat_scolarite')->assertStatus(409)->assertJsonPath('reason', 'student_not_enrolled');
        self::assertSame(0, DB::table('documents_emis')->count());
    }

    public function test_un_eleve_d_une_autre_ecole_est_introuvable(): void
    {
        [, $gestion] = $this->ecole();
        [$ailleurs] = $this->ecole();
        $eleve = $this->eleveInscrit($ailleurs, 'Voisin', '6e Z');

        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertNotFound();
        self::assertSame(0, DB::table('documents_emis')->count());
    }

    public function test_une_ecole_klassci_releve_de_klassci(): void
    {
        [$ecole, $gestion] = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->eleveInscrit($ecole, 'Awa', '6e A');

        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame(0, DB::table('documents_emis')->count());
    }

    public function test_un_type_inconnu_et_un_role_hors_gestion_sont_refuses(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa', '6e A');
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant', 'klassci_token' => null]);

        $this->emettre($gestion, $eleve, 'bulletin')->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->emettre($enseignant, $eleve, 'certificat_scolarite')->assertForbidden();
        $this->emettre($eleve, $eleve, 'certificat_scolarite')->assertForbidden();
    }

    public function test_l_emission_est_inscrite_au_journal_d_audit(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $eleve = $this->eleveInscrit($ecole, 'Awa', '6e A');

        $this->emettre($gestion, $eleve, 'certificat_scolarite')->assertOk();

        $trace = DB::table('audit_logs')->where('action', 'document_emis')->first();
        self::assertNotNull($trace, 'L\'émission n\'est pas journalisée.');
        self::assertSame([$gestion->id, $ecole->id], [(int) $trace->user_id, (int) $trace->institution_id]);
    }

    private function emettre(User $compte, User $eleve, string $type): TestResponse
    {
        return $this->asTenant($compte)->post("/api/lms/eleves/{$eleve->id}/documents", ['type' => $type], ['Accept' => 'application/json']);
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null])];
    }

    private function eleveInscrit(Institution $ecole, string $nom, string $classe, string $statut = 'actif'): User
    {
        $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'name' => $nom]);
        $laClasse = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'libelle' => $classe, 'effectif' => 0]);
        DB::table('classe_etudiant')->insert([
            'classe_id' => $laClasse->id, 'user_id' => $eleve->id, 'institution_id' => $ecole->id,
            'statut' => $statut, 'date_inscription' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $eleve;
    }
}
