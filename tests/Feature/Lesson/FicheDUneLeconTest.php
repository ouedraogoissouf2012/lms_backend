<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * `GET /api/lessons/{id}` : la fiche d'une leçon suit la règle de lecture de ses
 * chapitres (`ChapterReadGate::lectureLecon`), et non une règle à part.
 *
 * - une leçon de sa classe, inscription active : lue ;
 * - une leçon d'une autre classe, ou non publiée : introuvable (404), comme ses chapitres ;
 * - une leçon de sa classe, inscription non active : 409 `enrolment_not_active` ;
 * - le personnel de l'établissement : inchangé.
 *
 * Les colonnes mortes de `lessons` (copiées dans `chapters` en 2025-10, comme
 * `duration_minutes`, #945) ne sont plus servies : description, prérequis et objectifs
 * restent.
 *
 * Les deux volets ; vrai jeton porteur.
 */
final class FicheDUneLeconTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    /** Les colonnes mortes de `lessons`. */
    private const COLONNES_MORTES = ['content', 'content_type', 'video_url', 'video_provider', 'pdf_url', 'audio_url', 'presentation_url', 'external_link', 'attachments'];

    private Institution $ecole;

    private Classe $classe;

    private Classe $autreClasse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->autreClasse = $this->classe($this->ecole, '6e B');
    }

    public function test_l_eleve_lit_la_fiche_d_une_lecon_de_sa_classe(): void
    {
        $eleve = $this->eleveDe($this->classe, 'actif');

        $this->asTenant($eleve)->getJson("/api/lessons/{$this->lecon($this->classe)->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Une description');
    }

    public function test_les_colonnes_mortes_ne_sont_plus_servies(): void
    {
        $eleve = $this->eleveDe($this->classe, 'actif');
        $lecon = $this->lecon($this->classe);
        DB::table('lessons')->where('id', $lecon->id)->update([
            'content' => 'Texte', 'content_type' => 'text', 'video_url' => 'https://exemple.test/v', 'pdf_url' => 'https://exemple.test/p',
            'audio_url' => 'https://exemple.test/a', 'presentation_url' => 'https://exemple.test/s', 'external_link' => 'https://exemple.test/l',
            'attachments' => json_encode(['https://exemple.test/f']),
        ]);

        $fiche = $this->asTenant($eleve)->getJson("/api/lessons/{$lecon->id}")->assertOk()->json('data');
        $liste = $this->asTenant($eleve)->getJson('/api/lessons?per_page=100')->assertOk()->json('data.data.0');

        foreach (self::COLONNES_MORTES as $colonne) {
            self::assertArrayNotHasKey($colonne, $fiche, "fiche : {$colonne}");
            self::assertArrayNotHasKey($colonne, $liste, "liste : {$colonne}");
        }
    }

    public function test_une_lecon_d_une_autre_classe_est_introuvable(): void
    {
        $eleve = $this->eleveDe($this->classe, 'actif');

        $this->asTenant($eleve)->getJson("/api/lessons/{$this->lecon($this->autreClasse)->id}")->assertNotFound();
    }

    public function test_une_lecon_non_publiee_est_introuvable_pour_l_eleve_de_la_classe(): void
    {
        $eleve = $this->eleveDe($this->classe, 'actif');
        $brouillon = $this->lecon($this->classe, ['status' => LessonStatus::Draft, 'published_at' => null]);

        $this->asTenant($eleve)->getJson("/api/lessons/{$brouillon->id}")->assertNotFound();
    }

    public function test_l_eleve_a_l_inscription_non_active_lit_son_refus(): void
    {
        $eleve = $this->eleveDe($this->classe, 'inactif');

        $this->asTenant($eleve)->getJson("/api/lessons/{$this->lecon($this->classe)->id}")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'enrolment_not_active');
    }

    /** @return array<string, array{0: string}> */
    public static function personnel(): array
    {
        return ['enseignant' => ['enseignant'], 'coordinateur' => ['coordinateur'], 'superAdmin' => ['superAdmin']];
    }

    #[DataProvider('personnel')]
    public function test_le_personnel_de_l_etablissement_lit_toute_fiche(string $role): void
    {
        $brouillon = $this->lecon($this->autreClasse, ['status' => LessonStatus::Draft, 'published_at' => null]);

        $this->asTenant($this->membre($this->ecole, $role))->getJson("/api/lessons/{$brouillon->id}")->assertOk();
    }

    /** École KLASSCI : l'élève admis par le miroir de sa classe (D410) lit la fiche. */
    public function test_ecole_klassci_l_eleve_admis_par_le_miroir_lit_la_fiche(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4100]);
        $eleve = User::factory()->for($ecole)->create(['role' => 'etudiant', 'last_klassci_sync' => now()]);
        UserClass::create(['user_id' => $eleve->id, 'klassci_classe_id' => 4100, 'institution_id' => $ecole->id]);

        $this->asTenant($eleve)->getJson("/api/lessons/{$this->lecon($classe, [], $ecole)->id}")->assertOk();
    }

    private function eleveDe(Classe $classe, string $statut): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $classe, $statut);

        return $eleve;
    }

    /** @param  array<string, mixed>  $attributs */
    private function lecon(Classe $classe, array $attributs = [], ?Institution $ecole = null): Lesson
    {
        return Lesson::factory()->create($attributs + [
            'institution_id' => ($ecole ?? $this->ecole)->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $this->membre($ecole ?? $this->ecole, 'enseignant')->id,
            'description' => 'Une description',
            'status' => LessonStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }
}
