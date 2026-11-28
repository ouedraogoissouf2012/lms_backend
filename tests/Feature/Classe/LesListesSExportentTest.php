<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseRoster;
use App\Services\Export\FormatDExport;
use App\Services\Export\Tableau;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1656 — la liste des élèves d'une classe et la liste des classes s'exportent, en CSV et en PDF (pas de feuille
 * d'appel : tout est en ligne ; pas de XLSX : le CSV s'ouvre dans Excel, décision du 28/11).
 *
 * L'export lit EXACTEMENT ce que lit sa route JSON (`ClasseEtudiantsReader`, `InstitutionClassesReader`) : même
 * garde (`personnel`), même portée (l'enseignant hors de sa classe : 404), mêmes deux volets, même document. Le CSV
 * est en UTF-8 avec BOM et `;` : Excel l'ouvre sans casser les accents. Une cellule qui commencerait une formule
 * (`=`, `+`, `-`, `@`) est neutralisée. Au-delà de 2 000 lignes : 422 `export_too_large`.
 *
 * Les tests LISENT le corps : le CSV est relu cellule par cellule ; le PDF est un vrai PDF, et la page dont il
 * est tiré est lue. Vrai jeton.
 */
final class LesListesSExportentTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_les_eleves_d_une_classe_s_exportent_en_csv_tries_par_nom(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->eleve($classe, 'Zoé Traoré', 'zoe@test.ci');
        $this->eleve($classe, 'Awa Kaboré', 'awa@test.ci');
        $this->eleve($classe, 'Parti', 'parti@test.ci', 'abandonne');

        $reponse = $this->exporter($gestion, "/api/lms/classes/{$classe->id}/etudiants/export?format=csv")->assertOk();

        self::assertStringStartsWith('text/csv', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('.csv', (string) $reponse->headers->get('Content-Disposition'));
        self::assertSame([
            ['Nom', 'Email', 'Matricule', 'Statut'],
            ['Awa Kaboré', 'awa@test.ci', '', 'actif'],
            ['Zoé Traoré', 'zoe@test.ci', '', 'actif'],
        ], $this->lignesCsv($reponse));
    }

    public function test_une_formule_ne_part_jamais_telle_quelle_dans_le_csv(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->eleve($classe, '=HYPERLINK("http://x")', 'f@test.ci');

        $lignes = $this->lignesCsv($this->exporter($gestion, "/api/lms/classes/{$classe->id}/etudiants/export?format=csv")->assertOk());

        self::assertSame("'=HYPERLINK(\"http://x\")", $lignes[1][0]);
    }

    public function test_la_liste_des_classes_s_exporte_et_la_route_n_est_pas_prise_pour_une_classe(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $this->eleve($this->classe($ecole, 'Terminale B', 'TB'), 'Awa', 'awa@test.ci');
        $this->classe($ecole, 'Seconde A', 'SA');

        $reponse = $this->exporter($gestion, '/api/lms/classes/export?format=csv')->assertOk();

        $lignes = $this->lignesCsv($reponse);
        self::assertSame(['Libellé', 'Code', 'Filière', 'Niveau', 'Inscrits', 'Places'], $lignes[0]);
        self::assertSame([['Seconde A', 'SA'], ['Terminale B', 'TB']], array_map(static fn (array $l): array => array_slice($l, 0, 2), array_slice($lignes, 1)));
        self::assertSame('1', $lignes[2][4]);
    }

    public function test_le_pdf_est_un_vrai_pdf_tire_de_la_meme_page(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->eleve($classe, 'Awa Kaboré', 'awa@test.ci');

        $reponse = $this->exporter($gestion, "/api/lms/classes/{$classe->id}/etudiants/export?format=pdf")->assertOk();

        self::assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $reponse->getContent());

        $page = view('exports.tableau', ['tableau' => new Tableau('Élèves', ['Nom'], [['<b>Awa</b>']], 'eleves'), 'edite' => '28/11/2026'])->render();
        self::assertStringContainsString('&lt;b&gt;Awa&lt;/b&gt;', $page, 'La page échappe ce qu\'elle affiche.');
    }

    public function test_un_format_absent_ou_inconnu_est_refuse(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole, '6e A');

        $this->asTenant($gestion)->getJson("/api/lms/classes/{$classe->id}/etudiants/export")->assertStatus(422)->assertJsonValidationErrors(['format']);
        $this->asTenant($gestion)->getJson('/api/lms/classes/export?format=xlsx')->assertStatus(422)->assertJsonValidationErrors(['format']);
        self::assertSame(['csv', 'pdf'], array_map(static fn (FormatDExport $f): string => $f->value, FormatDExport::cases()));
    }

    public function test_l_enseignant_hors_de_sa_classe_et_l_eleve_n_exportent_pas(): void
    {
        [$ecole] = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant', 'klassci_token' => null]);
        $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_token' => null]);

        $this->exporter($enseignant, "/api/lms/classes/{$classe->id}/etudiants/export?format=csv")->assertNotFound();
        $this->exporter($eleve, "/api/lms/classes/{$classe->id}/etudiants/export?format=csv")->assertForbidden();
        $this->exporter($eleve, '/api/lms/classes/export?format=csv')->assertForbidden();
    }

    public function test_une_classe_d_une_autre_ecole_est_introuvable(): void
    {
        [, $gestion] = $this->ecole();
        [$ailleurs] = $this->ecole();
        $classe = $this->classe($ailleurs, 'Voisine');

        $this->exporter($gestion, "/api/lms/classes/{$classe->id}/etudiants/export?format=csv")->assertNotFound();
    }

    /** Volet KLASSCI : la même lecture (le roster de KLASSCI, sous le jeton du compte), le même document. */
    public function test_dans_une_ecole_klassci_le_document_a_la_meme_forme(): void
    {
        [, $gestion] = $this->ecole(InstitutionMode::Klassci);
        $gestion->forceFill(['klassci_token' => 'jeton-du-compte', 'last_klassci_sync' => now()])->save();
        $this->app->instance(ClasseRoster::class, new class implements ClasseRoster
        {
            public function etudiants(int $classeId, string $userToken): array
            {
                return [['nom_complet' => 'Issa Ouédraogo', 'email' => 'issa@test.ci', 'matricule' => 'M-01', 'statut' => 'actif']];
            }
        });

        $lignes = $this->lignesCsv($this->exporter($gestion, '/api/lms/classes/4242/etudiants/export?format=csv')->assertOk());

        self::assertSame([['Nom', 'Email', 'Matricule', 'Statut'], ['Issa Ouédraogo', 'issa@test.ci', 'M-01', 'actif']], $lignes);
    }

    private function exporter(User $compte, string $url): TestResponse
    {
        return $this->asTenant($compte)->get($url, ['Accept' => 'application/json']);
    }

    /** @return list<list<string>> */
    private function lignesCsv(TestResponse $reponse): array
    {
        $corps = (string) $reponse->getContent();
        self::assertStringStartsWith("\u{FEFF}", $corps, 'Le BOM manque : Excel casserait les accents.');
        $lignes = [];
        foreach (preg_split('/\r\n|\n/', trim(substr($corps, 3))) ?: [] as $ligne) {
            $lignes[] = str_getcsv($ligne, ';', '"', '');
        }

        return $lignes;
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null])];
    }

    private function classe(Institution $ecole, string $libelle, ?string $code = null): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'libelle' => $libelle, 'code' => $code, 'effectif' => 0]);
    }

    private function eleve(Classe $classe, string $nom, string $email, string $statut = 'actif'): void
    {
        $eleve = User::factory()->create(['institution_id' => $classe->institution_id, 'role' => 'etudiant', 'name' => $nom, 'email' => $email]);
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $classe->institution_id,
            'statut' => $statut, 'date_inscription' => '2026-01-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
