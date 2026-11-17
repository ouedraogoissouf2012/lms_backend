<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `POST /api/chapters/{chapterId}/upload` suit la règle de modification du chapitre
 * (`ChecksChapterOwnership`, celle de la suppression et de la restauration) : l'enseignant
 * auteur de la leçon, et lui seul (#1167). Un chapitre absent, ou d'un autre établissement,
 * rend 404.
 *
 * `POST /files/upload` garde sa règle (témoin) : la requête de fichier partagée n'a pas
 * changé.
 *
 * Mode asynchrone (`?async=1`, file feinte) : aucune conversion réelle. Les deux volets ;
 * vrai jeton porteur.
 */
final class UploadDeFichierDeChapitreTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Queue::fake();
        Storage::fake('local');
    }

    /** @return array<string, array{0: InstitutionMode}> */
    public static function volets(): array
    {
        return ['école autonome' => [InstitutionMode::Standalone], 'école KLASSCI' => [InstitutionMode::Klassci]];
    }

    #[DataProvider('volets')]
    public function test_l_auteur_de_la_lecon_depose_un_fichier(InstitutionMode $mode): void
    {
        [$ecole, $auteur, $chapitre] = $this->chapitreDeLAuteur($mode);

        $this->deposer($auteur, $chapitre->id)->assertAccepted();
    }

    /** L'admin d'école consulte le contenu, il n'y dépose rien (#1167). */
    #[DataProvider('volets')]
    public function test_un_administrateur_de_l_etablissement_ne_depose_pas_de_fichier(InstitutionMode $mode): void
    {
        [$ecole, , $chapitre] = $this->chapitreDeLAuteur($mode);

        $this->deposer($this->membre($ecole, 'superAdmin'), $chapitre->id)
            ->assertForbidden()
            ->assertJsonPath('reason', 'lesson_content_teacher_only');

        Queue::assertNothingPushed();
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    /** @return array<string, array{0: InstitutionMode, 1: string}> */
    public static function nonAuteurs(): array
    {
        $cas = [];
        foreach (self::volets() as $nom => [$mode]) {
            $cas["enseignant non auteur, $nom"] = [$mode, 'enseignant'];
            $cas["coordinateur non auteur, $nom"] = [$mode, 'coordinateur'];
        }

        return $cas;
    }

    #[DataProvider('nonAuteurs')]
    public function test_le_personnel_non_auteur_recoit_403(InstitutionMode $mode, string $role): void
    {
        [$ecole, , $chapitre] = $this->chapitreDeLAuteur($mode);

        $this->deposer($this->membre($ecole, $role), $chapitre->id)->assertForbidden();

        Queue::assertNothingPushed();
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_un_chapitre_absent_rend_404(): void
    {
        [$ecole, $auteur] = $this->chapitreDeLAuteur(InstitutionMode::Standalone);

        $this->deposer($auteur, 999999)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_un_chapitre_d_un_autre_etablissement_rend_404(): void
    {
        [, , $chapitre] = $this->chapitreDeLAuteur(InstitutionMode::Standalone);
        [$autreEcole] = $this->chapitreDeLAuteur(InstitutionMode::Standalone);

        $this->deposer($this->membre($autreEcole, 'enseignant'), $chapitre->id)->assertNotFound();

        Queue::assertNothingPushed();
    }

    /** Témoin : le dépôt d'un fichier libre garde sa règle. */
    public function test_le_depot_d_un_fichier_libre_reste_ouvert(): void
    {
        [$ecole] = $this->chapitreDeLAuteur(InstitutionMode::Standalone);

        $this->asTenant($this->membre($ecole, 'etudiant'))
            ->postJson('/api/files/upload', ['file' => UploadedFile::fake()->create('notes.pdf', 64, 'application/pdf')])
            ->assertSuccessful();
    }

    /** @return array{0: Institution, 1: User, 2: Chapter} */
    private function chapitreDeLAuteur(InstitutionMode $mode): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $auteur = $this->membre($ecole, 'enseignant');
        $lecon = Lesson::factory()->create(['institution_id' => $ecole->id, 'enseignant_id' => $auteur->id]);
        $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $ecole->id,
            'enseignant_id' => $auteur->id,
        ]);

        return [$ecole, $auteur, $chapitre];
    }

    private function membre(Institution $ecole, string $role): User
    {
        return User::factory()->for($ecole)->create(['role' => $role, 'last_klassci_sync' => now()]);
    }

    private function deposer(User $compte, int $chapitreId): TestResponse
    {
        return $this->asTenant($compte)->postJson("/api/chapters/{$chapitreId}/upload?async=1", [
            'file' => UploadedFile::fake()->create('cours.pdf', 128, 'application/pdf'),
        ]);
    }
}
