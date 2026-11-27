<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1652 (PR B) — la collection `GET /classes` se filtre par statut et par places.
 *
 * `statut` : `active` (sans date d'archive) ou `archivee`. `places` : `pleine` (capacité
 * renseignée et atteinte par les adhésions ACTIVES, la règle de `Classe::isComplete()`) ou
 * `disponibles` (le reste, une capacité non renseignée comprise). Sans filtre : toutes les classes.
 * Une valeur inconnue : 422. Chaque ligne dit sa date d'archive (`archivee_le`).
 *
 * Volet KLASSCI : 403, filtre ou pas. Vrai jeton (`asTenant`).
 */
final class LaListeDesClassesSeFiltreTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_la_liste_se_filtre_par_statut(): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, 'Active');
        $this->classe($ecole, 'Archivée', archivee: true);
        $gestion = $this->gestion($ecole);

        self::assertSame(['Active', 'Archivée'], $this->libelles($gestion, ''));
        self::assertSame(['Active'], $this->libelles($gestion, '?statut=active'));
        self::assertSame(['Archivée'], $this->libelles($gestion, '?statut=archivee'));
    }

    public function test_chaque_ligne_dit_sa_date_d_archive(): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, 'Active');
        $this->classe($ecole, 'Archivée', archivee: true);

        $lignes = $this->asTenant($this->gestion($ecole))->getJson('/api/classes')->assertOk()->json('data');

        self::assertIsArray($lignes);
        self::assertNull($lignes[0]['archivee_le']);
        self::assertStringStartsWith('2026-11-20', (string) $lignes[1]['archivee_le']);
    }

    /** La règle de `isComplete()` : seules les adhésions actives occupent ; capacité 0 = sans limite. */
    public function test_la_liste_se_filtre_par_places(): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, 'Pleine', capacite: 2, actifs: 2);
        $this->classe($ecole, 'Libre', capacite: 2, actifs: 1, inactifs: 3);
        $this->classe($ecole, 'Sans limite', capacite: 0, actifs: 3);
        $gestion = $this->gestion($ecole);

        self::assertSame(['Pleine'], $this->libelles($gestion, '?places=pleine'));
        self::assertSame(['Libre', 'Sans limite'], $this->libelles($gestion, '?places=disponibles'));
        self::assertSame(['Libre'], $this->libelles($gestion, '?places=disponibles&statut=active&per_page=1'));
    }

    public function test_un_filtre_inconnu_est_refuse(): void
    {
        $gestion = $this->gestion($this->ecole());

        $this->asTenant($gestion)->getJson('/api/classes?statut=fermee')->assertStatus(422)->assertJsonValidationErrors(['statut']);
        $this->asTenant($gestion)->getJson('/api/classes?places=moitie')->assertStatus(422)->assertJsonValidationErrors(['places']);
    }

    /** Les filtres ne débordent pas de l'école : une classe pleine d'ailleurs ne sort pas. */
    public function test_les_classes_d_une_autre_ecole_ne_sortent_pas(): void
    {
        $ecole = $this->ecole();
        $this->classe($this->ecole(), 'Voisine', capacite: 1, actifs: 1);

        self::assertSame([], $this->libelles($this->gestion($ecole), '?places=pleine'));
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        $this->asTenant($this->gestion($this->ecole(InstitutionMode::Klassci)))->getJson('/api/classes?statut=archivee')
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    /** @return list<string> */
    private function libelles(User $gestion, string $filtres): array
    {
        $lignes = $this->asTenant($gestion)->getJson('/api/classes'.$filtres)->assertOk()->json('data');
        self::assertIsArray($lignes);

        return array_map(static fn (array $l): string => (string) $l['libelle'], $lignes);
    }

    private function classe(Institution $ecole, string $libelle, int $capacite = 0, int $actifs = 0, int $inactifs = 0, bool $archivee = false): void
    {
        $classe = Classe::factory()->create([
            'institution_id' => $ecole->id, 'klassci_id' => null, 'effectif' => $capacite, 'libelle' => $libelle,
        ]);
        DB::table('classes')->where('id', $classe->id)->update(['archivee_le' => $archivee ? '2026-11-20 09:00:00' : null]);

        foreach (array_merge(array_fill(0, $actifs, 'actif'), array_fill(0, $inactifs, 'inactif')) as $statut) {
            $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);
            DB::table('classe_etudiant')->insert([
                'classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $ecole->id,
                'statut' => $statut, 'date_inscription' => '2026-01-10', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    private function gestion(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }
}
