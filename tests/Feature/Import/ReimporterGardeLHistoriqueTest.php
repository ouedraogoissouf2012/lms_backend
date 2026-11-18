<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\ImportRowStatus;
use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Classe;
use App\Models\ImportRow;
use App\Models\Institution;
use App\Models\User;
use App\Services\Import\ImportApplyService;
use App\Services\TenantManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #895, #1237 — réimporter un fichier garde l'historique de l'adhésion : sa date
 * d'inscription et son statut.
 *
 * Renvoyer le fichier entier pour corriger une ligne est le geste que #718 veut
 * permettre. `StudentEnrolmentService::inscrire()` met donc à jour une adhésion
 * existante, et les valeurs par défaut (« aujourd'hui », `actif`) ne valent qu'à
 * sa CRÉATION. Sans date dans le fichier, la date d'une adhésion existante reste
 * intacte (ADR-711-02 : la date d'inscription n'est pas `created_at`). Sans
 * statut, une adhésion `suspendu` ou `inactif` le reste : la décision de
 * l'établissement ne se défait pas par un fichier qui ne dit rien. Une colonne
 * absente et une cellule vide ne disent rien ; une valeur fournie corrige.
 *
 * L'import n'existe que pour l'école qui tient sa liste (#805) : un seul volet.
 * Le volet KLASSCI suit déjà cette règle (`ClasseStudentsSynchronizer` ne pose la
 * date qu'à l'insertion), et l'import y est refusé (`ImportRequiresLocalRosterTest`).
 */
final class ReimporterGardeLHistoriqueTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENTETE = "nom;prenom;email;role;code_classe;date_inscription;statut\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_une_reimportation_sans_date_garde_la_date_du_fichier_d_origine(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->travelTo('2026-10-01 10:00:00');
        $this->executer($importateur, self::ENTETE."Ouedraogo;Paul;paul@test.ci;;B2;15/09/2026;\n");

        $this->travelTo('2026-10-04 10:00:00');
        $this->executer($importateur, self::ENTETE."Ouedraogo;Paul;paul@test.ci;;B2;;\n");

        self::assertSame('2026-09-15', $this->dateDe($classe, 'paul@test.ci'));
    }

    public function test_une_reimportation_sans_colonne_de_date_garde_la_date_de_la_premiere_inscription(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->travelTo('2026-10-01 10:00:00');
        $this->executer($importateur, "nom;prenom;email;code_classe\nZongo;Lea;lea@test.ci;B2\n");

        $this->travelTo('2026-10-04 10:00:00');
        $this->executer($importateur, "nom;prenom;email;code_classe\nZongo;Lea;lea@test.ci;B2\n");

        self::assertSame('2026-10-01', $this->dateDe($classe, 'lea@test.ci'));
    }

    public function test_une_date_fournie_a_la_reimportation_corrige_la_date(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->travelTo('2026-10-01 10:00:00');
        $this->executer($importateur, self::ENTETE."Sawadogo;Ines;ines@test.ci;;B2;15/09/2026;\n");

        $this->travelTo('2026-10-04 10:00:00');
        $this->executer($importateur, self::ENTETE."Sawadogo;Ines;ines@test.ci;;B2;20/09/2026;\n");

        self::assertSame('2026-09-20', $this->dateDe($classe, 'ines@test.ci'));
    }

    public function test_un_nouvel_apprenant_de_la_reimportation_est_inscrit_du_jour(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->travelTo('2026-10-01 10:00:00');
        $this->executer($importateur, self::ENTETE."Kabore;Awa;awa@test.ci;;B2;;\n");

        $this->travelTo('2026-10-04 10:00:00');
        $this->executer($importateur, self::ENTETE."Kabore;Awa;awa@test.ci;;B2;;\nTraore;Ali;ali@test.ci;;B2;;\n");

        self::assertSame('2026-10-04', $this->dateDe($classe, 'ali@test.ci'));
    }

    public function test_une_reimportation_sans_colonne_de_statut_laisse_le_suspendu_suspendu(): void
    {
        // Un fichier ne pose pas `suspendu` (#920) : c'est une décision prise
        // ailleurs, que la réimportation ne doit pas défaire.
        [$importateur, $classe] = $this->ecole();
        $this->executer($importateur, "nom;prenom;email;code_classe\nYameogo;Rose;rose@test.ci;B2\n");
        $this->poserLeStatut($classe, 'rose@test.ci', 'suspendu');

        $this->executer($importateur, "nom;prenom;email;code_classe\nYameogo;Rose;rose@test.ci;B2\n");

        self::assertSame('suspendu', $this->statutDe($classe, 'rose@test.ci'));
    }

    public function test_une_reimportation_a_la_cellule_de_statut_vide_laisse_l_inactif_inactif(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->executer($importateur, self::ENTETE."Compaore;Jean;jean@test.ci;;B2;;inactif\n");

        $this->executer($importateur, self::ENTETE."Compaore;Jean;jean@test.ci;;B2;;\n");

        self::assertSame('inactif', $this->statutDe($classe, 'jean@test.ci'));
    }

    public function test_un_statut_fourni_a_la_reimportation_corrige_le_statut(): void
    {
        [$importateur, $classe] = $this->ecole();
        $this->executer($importateur, self::ENTETE."Ilboudo;Marc;marc@test.ci;;B2;;inactif\n");

        $this->executer($importateur, self::ENTETE."Ilboudo;Marc;marc@test.ci;;B2;;actif\n");

        self::assertSame('actif', $this->statutDe($classe, 'marc@test.ci'));
    }

    public function test_un_nouvel_apprenant_d_un_fichier_sans_colonne_de_statut_est_actif(): void
    {
        [$importateur, $classe] = $this->ecole();

        $this->executer($importateur, "nom;prenom;email;code_classe\nNikiema;Sali;sali@test.ci;B2\n");

        self::assertSame('actif', $this->statutDe($classe, 'sali@test.ci'));
    }

    public function test_un_nouvel_apprenant_a_la_cellule_de_statut_vide_est_actif(): void
    {
        [$importateur, $classe] = $this->ecole();

        $this->executer($importateur, self::ENTETE."Tapsoba;Eve;eve@test.ci;;B2;;\n");

        self::assertSame('actif', $this->statutDe($classe, 'eve@test.ci'));
    }

    /**
     * Le parcours complet, comme en production : analyse à blanc, confirmation,
     * puis exécution du job.
     */
    private function executer(User $importateur, string $csv): void
    {
        $importId = (int) $this->asTenant($importateur)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.import_id');

        $this->asTenant($importateur)
            ->postJson('/api/lms/imports/'.$importId.'/confirm')
            ->assertOk();

        (new ProcessImportJob($importId, (int) $importateur->institution_id))
            ->handle(app(TenantManager::class), app(ImportApplyService::class));

        // Une ligne refusée laisserait la date intacte : le test passerait sans rien prouver.
        self::assertSame(
            [],
            ImportRow::query()->where('import_id', $importId)->where('status', ImportRowStatus::Error->value)->pluck('code')->all(),
            'Une ligne de l\'import a été refusée.',
        );
    }

    /** @return array{0: User, 1: Classe} */
    private function ecole(): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);
        $importateur = User::factory()->coordinator()->create(['institution_id' => $ecole->id]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'code' => 'B2']);

        return [$importateur, $classe];
    }

    private function dateDe(Classe $classe, string $email): string
    {
        return substr((string) $this->adhesion($classe, $email)->value('date_inscription'), 0, 10);
    }

    private function statutDe(Classe $classe, string $email): string
    {
        return (string) $this->adhesion($classe, $email)->value('statut');
    }

    private function poserLeStatut(Classe $classe, string $email, string $statut): void
    {
        self::assertSame(1, $this->adhesion($classe, $email)->update(['statut' => $statut]));
    }

    private function adhesion(Classe $classe, string $email): Builder
    {
        $eleve = User::query()->withoutGlobalScopes()->where('email', $email)->firstOrFail();

        return DB::table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('user_id', $eleve->id);
    }
}
