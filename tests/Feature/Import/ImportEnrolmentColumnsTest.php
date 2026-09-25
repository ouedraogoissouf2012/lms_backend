<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\ImportRowStatus;
use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Jobs\ProcessImportJob;
use App\Models\Classe;
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
 * #718 — les trois colonnes que le produit offrait sans que rien ne les lise.
 *
 * ## Le défaut que ce fichier ferme
 *
 * Le modèle CSV téléchargeable annonce huit colonnes ; le serveur en consommait
 * cinq. `ColumnMap::fromRequest()` n'itérait que sur ses champs canoniques et
 * laissait tomber le reste SANS un mot. Une école téléchargeait le modèle DU
 * PRODUIT, remplissait `role` et `statut`, voyait les colonnes correctement
 * appariées à l'écran, lisait « N acceptées » — et chaque compte naissait
 * `etudiant`, chaque inscription `actif`, sans date.
 *
 * Les tests ci-dessous mesurent le parcours complet : le fichier passe par
 * l'analyse à blanc puis par l'exécution asynchrone, comme en production.
 *
 * @see docs/adr/2026-09-16-718-02-colonnes-role-statut-date.md
 */
final class ImportEnrolmentColumnsTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const HEADER = "nom;prenom;email;role;code_classe;date_inscription;statut\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_la_colonne_role_cree_enfin_le_role_demande(): void
    {
        // Le cœur du défaut : avant, cette ligne produisait un `etudiant`.
        $importateur = $this->importateur('coordinator');
        $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Kabore;Awa;awa.prof@test.ci;enseignant;B2;;\n");

        $cree = User::query()->where('email', 'awa.prof@test.ci')->first();

        self::assertNotNull($cree);
        self::assertSame(Role::Enseignant->value, $cree->role);
    }

    public function test_sans_colonne_role_le_compte_reste_un_apprenant(): void
    {
        // Non-régression : le défaut de moindre privilège ne bouge pas.
        $importateur = $this->importateur('coordinator');
        $this->classe($importateur, 'B2');

        $this->executer($importateur, "nom;prenom;email;code_classe\nDoe;Jane;jane.sans.role@test.ci;B2\n");

        self::assertSame(
            Role::Etudiant->value,
            User::query()->where('email', 'jane.sans.role@test.ci')->first()?->role,
        );
    }

    public function test_un_role_au_dessus_du_plafond_est_refuse_des_l_analyse(): void
    {
        // La ligne est refusée AVANT toute écriture : c'est ce que l'analyse à
        // blanc existe pour faire. Sans plafond, un enseignant se fabriquerait
        // un administrateur en tapant un mot dans son tableur.
        $enseignant = $this->importateur('teacher');

        $rapport = $this->analyser($enseignant, self::HEADER."Traore;Ali;ali.admin@test.ci;admin;;;\n");

        $rapport->assertJsonPath('data.counts.ok', 0)
            ->assertJsonPath('data.counts.error', 1)
            ->assertJsonPath('data.rows.0.code', 'role_interdit');

        self::assertNull(User::query()->where('email', 'ali.admin@test.ci')->first());
    }

    public function test_le_statut_declare_atteint_le_pivot_et_sort_des_listes_d_appel(): void
    {
        // `classe_etudiant.statut` n'est pas décoratif : il filtre
        // `etudiantsActifs()`. Un abandon écrit « actif » remettait un absent
        // dans les listes d'appel et les visios.
        $importateur = $this->importateur('coordinator');
        $classe = $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Sawadogo;Ines;ines@test.ci;;B2;;abandonne\n");

        $eleve = User::query()->where('email', 'ines@test.ci')->firstOrFail();

        self::assertSame('abandonne', $classe->etudiants()->find($eleve->id)?->pivot->statut);
        self::assertFalse($classe->etudiantsActifs()->where('users.id', $eleve->id)->exists());
    }

    public function test_la_date_francophone_atteint_le_pivot_au_format_de_la_base(): void
    {
        $importateur = $this->importateur('coordinator');
        $classe = $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Ouedraogo;Paul;paul@test.ci;;B2;15/09/2026;\n");

        $eleve = User::query()->where('email', 'paul@test.ci')->firstOrFail();
        $inscrit = $classe->etudiants()->find($eleve->id);

        self::assertNotNull($inscrit);
        self::assertStringStartsWith('2026-09-15', (string) $inscrit->pivot->date_inscription);
    }

    public function test_la_ligne_ecrite_par_l_import_porte_son_etablissement(): void
    {
        // #885 : le synchroniseur KLASSCI et la porte par code écrivent
        // `institution_id` ; l'import, par `StudentEnrolmentService::inscrire()`,
        // le laissait NULL. Le service unique pose désormais la forme de la ligne.
        $importateur = $this->importateur('coordinator');
        $classe = $this->classe($importateur, 'B2');

        $this->executer($importateur, self::HEADER."Zongo;Lea;lea@test.ci;;B2;;\n");

        $eleve = User::query()->where('email', 'lea@test.ci')->firstOrFail();
        $ligne = \Illuminate\Support\Facades\DB::table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('user_id', $eleve->id)
            ->first();

        self::assertSame((int) $importateur->institution_id, (int) $ligne?->institution_id);
    }

    public function test_une_date_inexistante_refuse_la_ligne_au_lieu_de_la_reporter(): void
    {
        $importateur = $this->importateur('coordinator');

        $this->analyser($importateur, self::HEADER."Diallo;Sira;sira@test.ci;;;31/02/2026;\n")
            ->assertJsonPath('data.counts.error', 1)
            ->assertJsonPath('data.rows.0.code', 'date_invalide');
    }

    public function test_un_statut_hors_enum_refuse_la_ligne_avant_l_ecriture(): void
    {
        // Sans ce refus, la valeur partait vers un ENUM MySQL et faisait échouer
        // l'exécution asynchrone APRÈS écriture partielle.
        $importateur = $this->importateur('coordinator');

        $this->analyser($importateur, self::HEADER."Bamba;Koffi;koffi@test.ci;;;;suspendu\n")
            ->assertJsonPath('data.counts.error', 1)
            ->assertJsonPath('data.rows.0.code', 'statut_inconnu');
    }

    public function test_une_retrogradation_entre_l_analyse_et_l_execution_refuse_la_ligne(): void
    {
        // L'exécution est asynchrone et différée. Rejouer le rôle stocké sans le
        // revérifier rouvrirait par le différé ce que le plafond ferme à
        // l'analyse : il aurait suffi d'être rétrogradé après avoir déposé.
        $coordinateur = $this->importateur('coordinator');
        $rapport = $this->analyser($coordinateur, self::HEADER."Cisse;Mory;mory@test.ci;coordinateur;;;\n");

        $rapport->assertJsonPath('data.counts.ok', 1);
        $importId = (int) $rapport->json('data.import_id');

        $coordinateur->forceFill(['role' => Role::Enseignant->value])->save();

        $this->confirmerEtExecuter($coordinateur, $importId);

        self::assertNull(
            User::query()->where('email', 'mory@test.ci')->first(),
            'Un compte coordinateur a été créé par un enseignant rétrogradé.',
        );

        $ligne = ImportRow::query()->where('import_id', $importId)->firstOrFail();

        self::assertSame(ImportRowStatus::Error->value, $ligne->status);
        self::assertSame('role_interdit', $ligne->code);
    }

    public function test_les_compteurs_du_rapport_suivent_les_refus_de_l_execution(): void
    {
        // Sinon l'utilisateur relit « 1 acceptée » sur un import qui n'a rien
        // créé — le feu vert le plus trompeur possible.
        $coordinateur = $this->importateur('coordinator');
        $importId = (int) $this->analyser($coordinateur, self::HEADER."Cisse;Mory;mory2@test.ci;coordinateur;;;\n")
            ->json('data.import_id');

        $coordinateur->forceFill(['role' => Role::Enseignant->value])->save();
        $this->confirmerEtExecuter($coordinateur, $importId);

        $this->asTenant($coordinateur)
            ->getJson('/api/lms/imports/'.$importId)
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 0)
            ->assertJsonPath('data.counts.error', 1);
    }

    public function test_rejouer_le_fichier_corrige_le_pivot_sans_dupliquer_l_inscription(): void
    {
        // La propriété que #718 réclame : corriger trois lignes et renvoyer le
        // fichier entier. Un test d'existence suivi d'`attach` l'interdisait.
        $importateur = $this->importateur('coordinator');
        $classe = $this->classe($importateur, 'B2');
        $ligne = "Kone;Ama;ama@test.ci;;B2;;%s\n";

        $this->executer($importateur, self::HEADER.sprintf($ligne, 'actif'));
        $this->executer($importateur, self::HEADER.sprintf($ligne, 'abandonne'));

        $eleve = User::query()->where('email', 'ama@test.ci')->firstOrFail();

        self::assertSame(1, User::query()->where('email', 'ama@test.ci')->count());
        self::assertSame(1, $classe->etudiants()->where('users.id', $eleve->id)->count());
        self::assertSame('abandonne', $classe->etudiants()->find($eleve->id)?->pivot->statut);
    }

    /**
     * Dépose le fichier et rend la réponse de l'analyse à blanc.
     */
    private function analyser(User $importateur, string $csv): TestResponse
    {
        return $this->asTenant($importateur)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    /**
     * Le parcours complet : analyse, confirmation, puis exécution du job.
     */
    private function executer(User $importateur, string $csv): void
    {
        $this->confirmerEtExecuter(
            $importateur,
            (int) $this->analyser($importateur, $csv)->json('data.import_id'),
        );
    }

    private function confirmerEtExecuter(User $importateur, int $importId): void
    {
        $this->asTenant($importateur)
            ->postJson('/api/lms/imports/'.$importId.'/confirm')
            ->assertOk();

        (new ProcessImportJob($importId, (int) $importateur->institution_id))
            ->handle(app(TenantManager::class), app(ImportApplyService::class));
    }

    private function importateur(string $etat): User
    {
        // Importer suppose que l'école tient sa propre liste (#805).
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        return User::factory()->{$etat}()->create(['institution_id' => $ecole->id]);
    }

    private function classe(User $importateur, string $code): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $importateur->institution_id,
            'code' => $code,
        ]);
    }
}
