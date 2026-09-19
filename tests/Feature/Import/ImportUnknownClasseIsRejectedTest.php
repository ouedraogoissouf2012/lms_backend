<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\ImportRowStatus;
use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Classe;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Institution;
use App\Models\User;
use App\Services\Import\ImportApplyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #846 — un code de classe inconnu ne s'avale plus en silence.
 *
 * ## Le défaut
 *
 * `ImportApplyService::enroll` se terminait par `if ($classe === null) return;`.
 * L'import se déclarait réussi, la ligne était comptée acceptée, et l'étudiant
 * n'était dans aucune classe. Personne n'avait de raison d'aller vérifier :
 * **un rejet muet est pire qu'une erreur, parce qu'il n'appelle aucune
 * correction.**
 *
 * ADR-803-03 le nomme, et impose que le service commun « remonte ce cas comme
 * un rejet de ligne, pas l'avale ».
 *
 * ## Ce que ce fichier mesure
 *
 * Le parcours complet — analyse à blanc, confirmation, exécution asynchrone —
 * comme en production. Et le COMPTE du rapport, pas seulement l'état de la
 * ligne : c'est le compte que l'utilisateur lit.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class ImportUnknownClasseIsRejectedTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const HEADER = "nom;prenom;email;code_classe\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_un_code_de_classe_inconnu_rejette_la_ligne(): void
    {
        $importateur = $this->importateur();
        $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Kabore;Awa;awa@test.ci;CLASSE-QUI-N-EXISTE-PAS\n");

        $ligne = ImportRow::query()->firstOrFail();

        self::assertSame(ImportRowStatus::Error->value, $ligne->status);
        self::assertSame('classe_inconnue', $ligne->code);
        self::assertStringContainsString('CLASSE-QUI-N-EXISTE-PAS', (string) $ligne->message);
    }

    public function test_le_rapport_ne_compte_plus_cette_ligne_comme_reussie(): void
    {
        // Le compte est ce que l'utilisateur LIT. Une ligne rejetée comptée
        // « acceptée » est exactement le mensonge que #718 avait déjà corrigé
        // pour le rôle refusé.
        $importateur = $this->importateur();
        $this->classe($importateur, 'B2');

        $this->executer(
            $importateur,
            self::HEADER."Kabore;Awa;awa@test.ci;B2\nTraore;Ali;ali@test.ci;INCONNUE\n"
        );

        $import = Import::query()->firstOrFail();

        self::assertSame(1, (int) $import->ok_count);
        self::assertSame(1, (int) $import->error_count);
    }

    public function test_la_classe_d_une_AUTRE_ecole_est_inconnue_ici(): void
    {
        // Le service borne la recherche à l'établissement : un code valide
        // ailleurs ne doit pas rattacher un apprenant chez le voisin.
        $voisine = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        Classe::factory()->create(['institution_id' => $voisine->id, 'code' => 'VOISINE']);

        $importateur = $this->importateur();

        $this->executer($importateur, self::HEADER."Kabore;Awa;awa@test.ci;VOISINE\n");

        self::assertSame('classe_inconnue', ImportRow::query()->firstOrFail()->code);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('classe_etudiant')->count());
    }

    public function test_une_ligne_SANS_code_de_classe_reste_acceptee(): void
    {
        // Non-regression : un fichier sans colonne de classe cree des comptes
        // sans les rattacher. C'est legitime, et ce n'est pas un refus.
        $importateur = $this->importateur();

        $this->executer($importateur, "nom;prenom;email\nDoe;Jane;jane@test.ci\n");

        self::assertSame(ImportRowStatus::Ok->value, ImportRow::query()->firstOrFail()->status);
        self::assertNotNull(User::query()->where('email', 'jane@test.ci')->first());
    }

    public function test_un_code_connu_inscrit_toujours(): void
    {
        // Non-regression du chemin nominal, desormais servi par le service commun.
        $importateur = $this->importateur();
        $classe = $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Sawadogo;Ines;ines@test.ci;B2\n");

        $eleve = User::query()->where('email', 'ines@test.ci')->firstOrFail();

        self::assertTrue($classe->etudiants()->where('users.id', $eleve->id)->exists());
    }

    // ───────────────────────────────── harnais

    private function analyser(User $importateur, string $csv): TestResponse
    {
        return $this->asTenant($importateur)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    private function executer(User $importateur, string $csv): void
    {
        $id = (int) $this->analyser($importateur, $csv)->json('data.import_id');

        $this->asTenant($importateur)->postJson('/api/lms/imports/'.$id.'/confirm')->assertOk();

        (new ProcessImportJob($id, (int) $importateur->institution_id))
            ->handle(app(TenantManager::class), app(ImportApplyService::class));
    }

    private function importateur(): User
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        return User::factory()->coordinator()->create(['institution_id' => $ecole->id]);
    }

    private function classe(User $importateur, string $code): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $importateur->institution_id,
            'code' => $code,
        ]);
    }
}
