<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Enums\InstitutionMode;
use App\Models\File;
use App\Models\Institution;
use App\Models\User;
use App\Services\File\LecteurDeFichier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1768 — qui lit un fichier, et qui le modère : la table des rôles du produit.
 *
 * | Rôle \ fichier  | le sien | public de son école | privé d'autrui, son école | autre école |
 * |-----------------|---------|---------------------|---------------------------|-------------|
 * | élève           | oui     | oui                 | non                       | non         |
 * | enseignant      | oui     | oui                 | non                       | non         |
 * | coordinateur    | oui     | oui                 | oui                       | non         |
 * | admin d'école   | oui     | oui                 | oui                       | non         |
 *
 * La liste, la fiche, le téléchargement et les statistiques suivent la MÊME règle : la liste ne
 * montre jamais ce que la fiche refuse. Un fichier illisible répond comme un fichier absent (même
 * 404, même corps). Modérer : le propriétaire ou la gestion de son école. Vrai jeton (`asTenant`),
 * les deux types d'école.
 */
final class QuiLitUnFichierTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function lecteurs(): array
    {
        return [
            'élève' => ['etudiant', false],
            'enseignant' => ['enseignant', false],
            'coordinateur' => ['coordinateur', true],
            'admin d école' => ['superAdmin', true],
        ];
    }

    #[DataProvider('lecteurs')]
    public function test_la_fiche_le_telechargement_et_la_liste_suivent_la_meme_regle(string $role, bool $gestion): void
    {
        [$ecole, $ailleurs] = [$this->ecole(), $this->ecole()];
        $lecteur = $this->membre($ecole, $role);
        $autrui = $this->membre($ecole, 'etudiant');
        $attendus = [
            'le sien' => [$this->fichier($ecole, $lecteur, false), true],
            'public de son école' => [$this->fichier($ecole, $autrui, true), true],
            'privé d autrui' => [$this->fichier($ecole, $autrui, false), $gestion],
            'autre école' => [$this->fichier($ailleurs, $this->membre($ailleurs, 'etudiant'), true), false],
        ];

        foreach ($attendus as $cas => [$fichier, $lisible]) {
            $this->asTenant($lecteur)->getJson("/api/files/{$fichier->id}")->assertStatus($lisible ? 200 : 404);
            self::assertSame($lisible ? 200 : 404, $this->asTenant($lecteur)->get("/api/files/{$fichier->id}/download")->getStatusCode(), $cas);
        }

        $listes = $this->asTenant($lecteur)->getJson('/api/files?per_page=100')->assertOk()->json('data.data');
        self::assertIsArray($listes);
        $vus = array_map(static fn (array $f): int => (int) $f['id'], $listes);
        sort($vus);
        $lisibles = array_values(array_map(static fn (array $a): int => $a[0]->id, array_filter($attendus, static fn (array $a): bool => $a[1])));
        sort($lisibles);
        self::assertSame($lisibles, $vus, 'La liste montre autre chose que ce que la fiche rend.');
    }

    /** Un fichier illisible répond exactement comme un id absent : rien ne dit qu'il existe. */
    public function test_un_fichier_illisible_repond_comme_un_fichier_absent(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $prive = $this->fichier($ecole, $this->membre($ecole, 'etudiant'), false);

        $fiche = $this->asTenant($enseignant)->getJson("/api/files/{$prive->id}")->assertNotFound()->json();
        $modification = $this->asTenant($enseignant)->putJson("/api/files/{$prive->id}", ['description' => 'x'])->assertNotFound()->json();
        DB::table('files')->where('id', $prive->id)->delete();

        self::assertSame($this->asTenant($enseignant)->getJson("/api/files/{$prive->id}")->json(), $fiche);
        self::assertSame($this->asTenant($enseignant)->putJson("/api/files/{$prive->id}", ['description' => 'x'])->json(), $modification);
    }

    public function test_la_gestion_de_l_ecole_modere_le_fichier_d_autrui(): void
    {
        $ecole = $this->ecole();
        $fichier = $this->fichier($ecole, $this->membre($ecole, 'enseignant'), false);

        $this->asTenant($this->membre($ecole, 'coordinateur'))->putJson("/api/files/{$fichier->id}", ['description' => 'revue'])->assertOk();
        self::assertSame('revue', DB::table('files')->where('id', $fichier->id)->value('description'));
        $this->asTenant($this->membre($ecole, 'coordinateur'))->deleteJson("/api/files/{$fichier->id}")->assertOk();
    }

    /** Lire un fichier public ne donne pas le droit de le modifier : 403, son existence n'est pas cachée. */
    public function test_un_fichier_public_d_autrui_se_lit_sans_se_moderer(): void
    {
        $ecole = $this->ecole();
        $fichier = $this->fichier($ecole, $this->membre($ecole, 'etudiant'), true);

        $this->asTenant($this->membre($ecole, 'enseignant'))->putJson("/api/files/{$fichier->id}", ['description' => 'x'])->assertForbidden();
        $this->asTenant($this->membre($ecole, 'etudiant'))->deleteJson("/api/files/{$fichier->id}")->assertForbidden();
        self::assertNotNull(DB::table('files')->where('id', $fichier->id)->value('id'));
    }

    /** Les statistiques comptent ce que l'appelant lit : l'enseignant, pas les fichiers privés d'autrui. */
    public function test_les_statistiques_comptent_ce_que_l_appelant_lit(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $this->fichier($ecole, $enseignant, false);
        $this->fichier($ecole, $this->membre($ecole, 'etudiant'), true);
        $this->fichier($ecole, $this->membre($ecole, 'etudiant'), false);

        $this->asTenant($enseignant)->getJson('/api/files/stats')->assertOk()->assertJsonPath('data.total_files', 2);
        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api/files/stats')->assertOk()->assertJsonPath('data.total_files', 3);
    }

    /** Dans une école KLASSCI, les fichiers sont ceux du LMS : la même règle. */
    public function test_dans_une_ecole_klassci_la_meme_regle(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $prive = $this->fichier($ecole, $this->membre($ecole, 'etudiant'), false);

        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson("/api/files/{$prive->id}")->assertOk();
        $this->asTenant($this->membre($ecole, 'enseignant'))->getJson("/api/files/{$prive->id}")->assertNotFound();
    }

    /** La règle elle-même, sans la porte des routes : un compte sans école ne lit ni ne modère rien. */
    public function test_un_compte_sans_ecole_ne_lit_aucun_fichier(): void
    {
        $ecole = $this->ecole();
        $fichier = $this->fichier($ecole, $this->membre($ecole, 'etudiant'), true);
        $lecteur = app(LecteurDeFichier::class);

        foreach (['supradmin', 'superAdmin', 'coordinateur'] as $role) {
            $sansEcole = User::factory()->create(['institution_id' => null, 'role' => $role]);
            self::assertFalse($lecteur->peutLire($sansEcole, $fichier), $role);
            self::assertFalse($lecteur->peutModerer($sansEcole, $fichier), $role);
            self::assertSame(0, $lecteur->lisibles(File::query(), $sansEcole)->count(), $role);
        }
    }

    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    private function membre(Institution $ecole, string $role): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => null]);
    }

    private function fichier(Institution $ecole, User $depositaire, bool $public): File
    {
        $fichier = File::factory()->create(['institution_id' => $ecole->id, 'user_id' => $depositaire->id, 'is_public' => $public]);
        Storage::disk('local')->put($fichier->path, 'contenu');

        return $fichier;
    }
}
