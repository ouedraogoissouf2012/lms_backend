<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\File;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Un élève ne voit pas qui a déposé les fichiers des autres (GHSA-gg7j).
 *
 * `GET /files` et `GET /files/{id}` chargeaient l'auteur avec son e-mail pour
 * tout fichier public — élèves compris — et `?user_id=` permettait de lister
 * les fichiers d'un camarade précis. Un fichier public reste lisible (c'est du
 * contenu partagé) ; son auteur, lui, n'est rendu qu'à son propriétaire et au
 * personnel.
 */
final class FichierAuteurFermeAuxElevesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $institution;

    private User $eleve;

    private User $camarade;

    private User $prof;

    private File $fichierDuCamarade;

    private File $fichierDeLEleve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->eleve = User::factory()->for($this->institution)->create(['role' => 'etudiant']);
        $this->camarade = User::factory()->for($this->institution)->create(['role' => 'etudiant']);
        $this->prof = User::factory()->for($this->institution)->create(['role' => 'enseignant']);

        $this->fichierDuCamarade = File::factory()->create([
            'user_id' => $this->camarade->id,
            'institution_id' => $this->institution->id,
            'is_public' => true,
        ]);
        $this->fichierDeLEleve = File::factory()->create([
            'user_id' => $this->eleve->id,
            'institution_id' => $this->institution->id,
            'is_public' => false,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fichiersListesPar(User $acteur): array
    {
        return $this->asTenant($acteur)->getJson('/api/files')->assertOk()->json('data.data');
    }

    public function test_un_eleve_ne_voit_pas_l_auteur_des_fichiers_des_autres(): void
    {
        $fichiers = collect($this->fichiersListesPar($this->eleve))->keyBy('id');

        self::assertCount(2, $fichiers, 'Le fichier public du camarade et le sien.');
        self::assertNull($fichiers[$this->fichierDuCamarade->id]['user'] ?? null, "L'auteur d'un fichier d'autrui est livré à un élève.");
        self::assertSame($this->eleve->name, $fichiers[$this->fichierDeLEleve->id]['user']['name'] ?? null, 'Son propre nom, lui, reste.');
    }

    public function test_un_eleve_ne_voit_pas_l_auteur_d_un_fichier_public_d_un_camarade(): void
    {
        $reponse = $this->asTenant($this->eleve)->getJson("/api/files/{$this->fichierDuCamarade->id}");

        $reponse->assertOk();
        self::assertNull($reponse->json('data.user'));
        self::assertStringNotContainsString($this->camarade->email, (string) $reponse->getContent());
    }

    public function test_un_eleve_ne_peut_pas_cibler_les_fichiers_d_un_camarade(): void
    {
        $this->asTenant($this->eleve)
            ->getJson("/api/files?user_id={$this->camarade->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->asTenant($this->eleve)
            ->getJson("/api/files?user_id={$this->eleve->id}")
            ->assertOk();
    }

    public function test_le_personnel_voit_toujours_l_auteur(): void
    {
        $fichiers = collect($this->fichiersListesPar($this->prof))->keyBy('id');

        self::assertSame($this->camarade->email, $fichiers[$this->fichierDuCamarade->id]['user']['email'] ?? null);

        $this->asTenant($this->prof)
            ->getJson("/api/files?user_id={$this->camarade->id}")
            ->assertOk();
    }
}
