<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1648 (PR B) — une classe et une matière locales portent une filière et un niveau d'études LOCAUX.
 *
 * Champs `filiere_id` et `niveau_id` (ids locaux de #1648 A) à la création et à la modification, nuls compris ;
 * stockés dans des colonnes PROPRES (`*_locale_id`, clé étrangère), jamais dans `filiere_id` / `niveau_id`, qui
 * portent des ids KLASSCI : deux espaces d'id ne se mélangent jamais. Un élément d'une autre école : 422 sous son
 * champ. Les lectures les rendent dans la forme du volet KLASSCI : la matière en `combinaisons` (ce que filtre
 * l'écran Matières), la classe en `filiere` / `niveau`. Un élément employé ne se supprime plus : 409. Vrai jeton.
 *
 * La ligne de `GET /classes` (l'écran des codes d'inscription, et le formulaire « Modifier la classe ») porte AUSSI
 * `filiere` et `niveau`, clés toujours présentes (`null` sans rattachement), lus en UN chargement par table : sans
 * eux, l'écran ne pourrait pas montrer le rattachement, et un enregistrement le détacherait en silence.
 */
final class LaClasseEtLaMatiereSeRattachentALaStructureTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_une_classe_se_cree_et_se_modifie_avec_sa_filiere_et_son_niveau(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$filiere, $niveau] = [$this->filiere($ecole, 'Commerce'), $this->niveau($ecole, 'L1')];

        $id = $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'L1 Commerce', 'filiere_id' => $filiere, 'niveau_id' => $niveau])
            ->assertCreated()->assertJsonPath('data.filiere.id', $filiere)->assertJsonPath('data.niveau.nom', 'L1')->json('data.id');
        self::assertSame([$filiere, $niveau], $this->colonnes('classes', $id));
        self::assertNull(DB::table('classes')->where('id', $id)->value('filiere_id'), 'L\'id local a fui dans la colonne KLASSCI.');

        $this->asTenant($gestion)->patchJson("/api/classes/{$id}", ['filiere_id' => null])->assertOk()->assertJsonPath('data.filiere', null);
        self::assertSame([null, $niveau], $this->colonnes('classes', $id));
    }

    public function test_une_matiere_se_rattache_et_l_ecran_matieres_la_lit_en_combinaisons(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$filiere, $niveau] = [$this->filiere($ecole, 'Commerce', 'COM'), $this->niveau($ecole, 'L1')];

        $id = $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Comptabilité', 'filiere_id' => $filiere])->assertCreated()->json('data.id');
        $this->asTenant($gestion)->patchJson("/api/matieres/{$id}", ['niveau_id' => $niveau])->assertOk();

        $ligne = collect($this->asTenant($gestion)->getJson('/api/admin/matieres')->assertOk()->json('data.matieres'))->firstWhere('id', $id);
        self::assertSame([['filiere' => ['id' => $filiere, 'nom' => 'Commerce', 'code' => 'COM'], 'niveau' => ['id' => $niveau, 'nom' => 'L1', 'code' => null]]], $ligne['combinaisons'] ?? null);
    }

    public function test_un_element_d_une_autre_ecole_est_refuse_sous_son_champ(): void
    {
        [, $gestion] = $this->ecole();
        [$ailleurs] = $this->ecole();
        $filiereVoisine = $this->filiere($ailleurs, 'Voisine');
        $niveauVoisin = $this->niveau($ailleurs, 'Voisin');

        $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'Intrusion', 'filiere_id' => $filiereVoisine])
            ->assertStatus(422)->assertJsonValidationErrors(['filiere_id']);
        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Intrusion', 'niveau_id' => $niveauVoisin])
            ->assertStatus(422)->assertJsonValidationErrors(['niveau_id']);
        self::assertSame(0, DB::table('classes')->where('libelle', 'Intrusion')->count() + DB::table('matieres')->where('libelle', 'Intrusion')->count());
    }

    public function test_un_element_employe_ne_se_supprime_plus(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$filiere, $niveau] = [$this->filiere($ecole, 'Commerce'), $this->niveau($ecole, 'L1')];
        $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'L1 Commerce', 'filiere_id' => $filiere])->assertCreated();
        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Compta', 'niveau_id' => $niveau])->assertCreated();

        $this->asTenant($gestion)->deleteJson("/api/filieres/{$filiere}")->assertStatus(409)->assertJsonPath('reason', 'field_in_use');
        $this->asTenant($gestion)->deleteJson("/api/niveaux-etudes/{$niveau}")->assertStatus(409)->assertJsonPath('reason', 'level_in_use');
        self::assertSame([1, 1], [DB::table('filieres')->count(), DB::table('niveaux_etudes')->count()]);
    }

    public function test_la_liste_des_classes_rend_la_filiere_et_le_niveau_dans_la_forme_klassci(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $filiere = $this->filiere($ecole, 'Commerce', 'COM');
        $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'L1 Commerce', 'filiere_id' => $filiere])->assertCreated();

        $ligne = $this->asTenant($gestion)->getJson('/api/proxy/classes')->assertOk()->json('data.0');

        self::assertSame(['id' => $filiere, 'nom' => 'Commerce', 'code' => 'COM'], $ligne['filiere'] ?? null);
        self::assertTrue(is_array($ligne) && array_key_exists('niveau', $ligne), 'La clé niveau manque.');
        self::assertNull($ligne['niveau']);
    }

    public function test_la_ligne_de_get_classes_porte_filiere_et_niveau_en_un_seul_chargement(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $niveau = $this->niveau($ecole, 'L1');
        foreach (['A', 'B', 'C'] as $lettre) {
            $filiere = $this->filiere($ecole, "Filière {$lettre}", "F{$lettre}");
            $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => "Classe {$lettre}", 'filiere_id' => $filiere, 'niveau_id' => $niveau])->assertCreated();
        }
        $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'Classe sans rattachement'])->assertCreated();

        $lectures = $this->lecturesDeLaStructure(fn () => $this->asTenant($gestion)->getJson('/api/classes')->assertOk()->json('data'));

        $lignes = collect($lectures['reponse'])->keyBy('libelle');
        self::assertSame(['id' => $this->idDe('filieres', 'Filière B'), 'nom' => 'Filière B', 'code' => 'FB'], $lignes['Classe B']['filiere'] ?? 'absente');
        self::assertSame(['id' => $niveau, 'nom' => 'L1', 'code' => null], $lignes['Classe B']['niveau'] ?? 'absent');
        $seule = $lignes['Classe sans rattachement'] ?? [];
        self::assertTrue(array_key_exists('filiere', $seule) && array_key_exists('niveau', $seule), 'Les clés sont toujours présentes.');
        self::assertSame([null, null], [$seule['filiere'], $seule['niveau']]);
        self::assertSame(['filieres' => 1, 'niveaux_etudes' => 1], $lectures['requetes'], 'Un chargement par table, jamais une requête par classe.');
    }

    public function test_l_archive_rend_la_meme_ligne_avec_son_rattachement(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $filiere = $this->filiere($ecole, 'Commerce', 'COM');
        $id = $this->asTenant($gestion)->postJson('/api/classes', ['libelle' => 'L1 Commerce', 'filiere_id' => $filiere])->assertCreated()->json('data.id');

        $this->asTenant($gestion)->postJson("/api/classes/{$id}/archiver")->assertOk()
            ->assertJsonPath('data.filiere', ['id' => $filiere, 'nom' => 'Commerce', 'code' => 'COM'])
            ->assertJsonPath('data.niveau', null);
    }

    /**
     * Les SELECT sur `filieres` et `niveaux_etudes` pendant `$geste`, comptés par table (guillemets retirés : SQLite et
     * MySQL ne citent pas pareil).
     *
     * @return array{reponse: mixed, requetes: array{filieres: int, niveaux_etudes: int}}
     */
    private function lecturesDeLaStructure(callable $geste): array
    {
        $requetes = ['filieres' => 0, 'niveaux_etudes' => 0];
        DB::listen(static function ($requete) use (&$requetes): void {
            $sql = strtolower(str_replace(['"', '`'], '', $requete->sql));
            foreach (array_keys($requetes) as $table) {
                if (str_starts_with($sql, 'select') && str_contains($sql, " from {$table} ")) {
                    $requetes[$table]++;
                }
            }
        });

        return ['reponse' => $geste(), 'requetes' => $requetes];
    }

    private function idDe(string $table, string $libelle): int
    {
        return (int) DB::table($table)->where('libelle', $libelle)->value('id');
    }

    /** @return array{0: int|null, 1: int|null} */
    private function colonnes(string $table, int $id): array
    {
        $ligne = DB::table($table)->where('id', $id)->first(['filiere_locale_id', 'niveau_locale_id']);

        return [$ligne?->filiere_locale_id === null ? null : (int) $ligne->filiere_locale_id, $ligne?->niveau_locale_id === null ? null : (int) $ligne->niveau_locale_id];
    }

    private function filiere(Institution $ecole, string $libelle, ?string $code = null): int
    {
        return (int) DB::table('filieres')->insertGetId(['institution_id' => $ecole->id, 'libelle' => $libelle, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function niveau(Institution $ecole, string $libelle): int
    {
        return (int) DB::table('niveaux_etudes')->insertGetId(['institution_id' => $ecole->id, 'libelle' => $libelle, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null])];
    }
}
