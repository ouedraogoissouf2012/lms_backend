<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1095 : l'élève INACTIF (D57) n'a toujours aucun accès, mais son refus dit son
 * motif, celui du tableau de bord : 409 `enrolment_not_active`, et non 404
 * « introuvable ».
 *
 * La règle : le 409 si et seulement si une inscription ACTIVE l'aurait admis, donc
 * une ligne non active dans la classe qui ouvre la ressource. Tout autre refus reste
 * le 404 d'avant : il ne révèle pas la ressource à qui elle n'était pas destinée.
 *
 * Chapitres et progression : les deux types d'école (en école KLASSCI, sans miroir
 * `user_classes` ; avec le miroir, l'élève est admis, D410, figé ailleurs). Les
 * fiches matière, classe et séance : l'école du LMS, la seule où le LMS les lit.
 *
 * Vrai jeton (`asTenant`).
 */
final class EleveInactifLitSonRefusTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private Classe $sienne;

    private Classe $autre;

    private Lesson $lecon;

    private Chapter $chapitre;

    private KnowledgeCheck $quiz;

    private User $auteur;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI, sans miroir' => [InstitutionMode::Klassci];
    }

    /** @return iterable<string, array{0: string}> */
    public static function statutsNonActifs(): iterable
    {
        foreach (['inactif', 'abandonne', 'suspendu', 'expire'] as $statut) {
            yield $statut => [$statut];
        }
    }

    #[DataProvider('volets')]
    public function test_l_inactif_lit_son_refus_sur_les_chapitres_et_la_progression(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $eleve = $this->eleve($this->sienne, 'inactif');

        foreach ($this->lectures() as $nom => $uri) {
            $this->assertRefusMotive($this->asTenant($eleve)->getJson($uri), $nom);
        }
        foreach ($this->ecritures() as $nom => [$uri, $corps]) {
            $this->assertRefusMotive($this->asTenant($eleve)->postJson($uri, $corps), $nom);
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $eleve->id)->count(), 'lesson_progress');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $eleve->id)->count(), 'chapter_progress');
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $eleve->id)->count(), 'tentatives');
    }

    #[DataProvider('statutsNonActifs')]
    public function test_tout_statut_non_actif_porte_le_motif(string $statut): void
    {
        $this->monter(InstitutionMode::Standalone);
        $eleve = $this->eleve($this->sienne, $statut);

        $this->assertRefusMotive($this->asTenant($eleve)->getJson("/api/lessons/{$this->lecon->id}/chapters"), $statut);
        $this->assertRefusMotive($this->asTenant($eleve)->getJson("/api/lms/classes/{$this->sienne->id}"), $statut);
    }

    /**
     * Verrou : l'élève inactif d'une AUTRE classe de la même école, l'élève sans
     * aucune ligne, et une leçon non publiée de sa propre classe gardent le 404.
     */
    #[DataProvider('volets')]
    public function test_le_404_reste_a_qui_la_lecon_n_etait_pas_ouverte(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $inactifAilleurs = $this->eleve($this->autre, 'inactif');
        $sansLigne = $this->membre($this->ecole, 'etudiant');
        $brouillon = Lesson::factory()->create(['institution_id' => $this->ecole->id, 'classe_id' => $this->sienne->id, 'status' => 'draft']);

        foreach ([$inactifAilleurs, $sansLigne] as $eleve) {
            foreach ($this->lectures() as $nom => $uri) {
                $this->asTenant($eleve)->getJson($uri)->assertNotFound();
            }
            $this->asTenant($eleve)->postJson("/api/lessons/{$this->lecon->id}/progress", ['progress_percentage' => 30])->assertNotFound();
        }
        $this->asTenant($this->eleve($this->sienne, 'inactif'))->getJson("/api/lessons/{$brouillon->id}/chapters")->assertNotFound();
    }

    /**
     * Verrou : une ligne non active hors de son établissement ne révèle rien. La leçon
     * d'une autre école est masquée par la portée de l'établissement : sa liste de
     * chapitres rend le 404 d'une leçon absente (elle rendait 500).
     */
    public function test_une_ligne_non_active_d_une_autre_ecole_garde_le_404(): void
    {
        $this->monter(InstitutionMode::Standalone);
        $ailleurs = $this->ecole();
        $etranger = $this->membre($ailleurs, 'etudiant');
        $this->inscrire($etranger, $this->sienne, 'inactif');

        $this->asTenant($etranger)->getJson("/api/lessons/{$this->lecon->id}/chapters")->assertNotFound();
        $this->asTenant($etranger)->getJson("/api/lessons/{$this->lecon->id}/chapter-progress")->assertNotFound();
        $this->asTenant($etranger)->getJson("/api/lessons/{$this->lecon->id}/progress")->assertNotFound();
    }

    /** Une leçon qui n'existe pas : le 404 « Chapitres introuvables » d'une leçon illisible (elle rendait 500). */
    public function test_une_lecon_inexistante_rend_404(): void
    {
        $this->monter(InstitutionMode::Standalone);
        $eleve = $this->eleve($this->sienne, 'actif');

        $this->asTenant($eleve)->getJson('/api/lessons/999999/chapters')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Chapitres introuvables']);
        $this->asTenant($eleve)->getJson('/api/lessons/999999/chapter-progress')->assertNotFound();
        $this->asTenant($eleve)->getJson('/api/lessons/999999/progress')->assertNotFound();
    }

    /** Témoin : l'élève actif de la classe lit, comme avant. */
    #[DataProvider('volets')]
    public function test_l_eleve_actif_lit_comme_avant(InstitutionMode $mode): void
    {
        $this->monter($mode);
        $eleve = $this->eleve($this->sienne, 'actif');

        // Le document source est exclu : ce chapitre texte n'en a pas, son 404 le dit.
        foreach (array_diff_key($this->lectures(), ['document source' => true]) as $nom => $uri) {
            self::assertSame(200, $this->asTenant($eleve)->getJson($uri)->status(), $nom);
        }
    }

    /** Les fiches de l'école du LMS : la matière et la classe d'une AUTRE classe gardent le 404. */
    public function test_les_fiches_d_une_autre_classe_gardent_le_404(): void
    {
        $this->monter(InstitutionMode::Standalone);
        $eleve = $this->eleve($this->sienne, 'inactif');
        $maths = $this->matiere($this->ecole, 'Maths');
        $this->programme($this->autre, $maths, $this->auteur);

        $this->asTenant($eleve)->getJson("/api/lms/matieres/{$maths->id}")->assertNotFound();
        $this->asTenant($eleve)->getJson("/api/lms/classes/{$this->autre->id}")->assertNotFound();
        $this->asTenant($eleve)->getJson("/api/lms/classes/local/{$this->autre->id}")->assertNotFound();
        $this->asTenant($eleve)->getJson("/api/lms/seances/{$this->seance($this->autre)->id}/details")->assertNotFound();
    }

    /** Une séance que même un élève actif ne lirait pas (masquée, désactivée) garde le 404. */
    public function test_une_seance_masquee_ou_desactivee_garde_le_404(): void
    {
        $this->monter(InstitutionMode::Standalone);
        $eleve = $this->eleve($this->sienne, 'inactif');
        $masquee = $this->seance($this->sienne);
        SeanceUserHidden::query()->create(['seance_id' => $masquee->id, 'user_id' => $eleve->id, 'hidden_at' => now(), 'institution_id' => $this->ecole->id]);
        $desactivee = $this->seance($this->sienne, ['is_active' => false]);

        $this->asTenant($eleve)->getJson("/api/lms/seances/{$masquee->id}/details")->assertNotFound();
        $this->asTenant($eleve)->getJson("/api/lms/seances/{$desactivee->id}/details")->assertNotFound();
        $this->assertRefusMotive($this->asTenant($eleve)->getJson("/api/lms/seances/{$this->seance($this->sienne)->id}/details"), 'séance de sa classe');
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertRefusMotive(TestResponse $reponse, string $cas): void
    {
        self::assertSame(409, $reponse->status(), "$cas : statut");
        self::assertSame('enrolment_not_active', $reponse->json('reason'), "$cas : motif");
        self::assertNull($reponse->json('data'), "$cas : aucune donnée");
    }

    /** @return array<string, string> */
    private function lectures(): array
    {
        return [
            'chapitres de la leçon' => "/api/lessons/{$this->lecon->id}/chapters",
            'un chapitre' => "/api/chapters/{$this->chapitre->id}",
            'document source' => "/api/chapters/{$this->chapitre->id}/original",
            'progression des chapitres' => "/api/lessons/{$this->lecon->id}/chapter-progress",
            'progression d un chapitre' => "/api/chapters/{$this->chapitre->id}/progress",
            'progression de la leçon' => "/api/lessons/{$this->lecon->id}/progress",
        ];
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    private function ecritures(): array
    {
        return [
            'progression' => ["/api/lessons/{$this->lecon->id}/progress", ['progress_percentage' => 30]],
            'complétion' => ["/api/lessons/{$this->lecon->id}/complete", []],
            'temps du chapitre' => ["/api/chapters/{$this->chapitre->id}/time", ['time_spent_seconds' => 60]],
            'complétion du chapitre' => ["/api/chapters/{$this->chapitre->id}/complete", ['time_spent_seconds' => 30]],
            'quiz : démarrage' => ["/api/knowledge-checks/{$this->quiz->id}/start", []],
        ];
    }

    private function monter(InstitutionMode $mode): void
    {
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole($mode);
        $this->sienne = $this->classe($this->ecole, '6e A');
        $this->autre = $this->classe($this->ecole, '6e B');
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->lecon = Lesson::factory()->published()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->sienne->id, 'enseignant_id' => $this->auteur->id,
        ]);
        $this->chapitre = Chapter::factory()->create([
            'lesson_id' => $this->lecon->id, 'institution_id' => $this->ecole->id,
            'enseignant_id' => $this->auteur->id, 'content_type' => 'text', 'order' => 0,
        ]);
        $this->quiz = KnowledgeCheck::factory()->create(['chapter_id' => $this->chapitre->id, 'institution_id' => $this->ecole->id]);
    }

    private function eleve(Classe $classe, string $statut): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $classe, $statut);

        return $eleve;
    }

    /** @param  array<string, mixed>  $attributs */
    private function seance(Classe $classe, array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'created_by' => $this->auteur->id,
            'classe_id' => $classe->id,
            'date_seance' => now()->addDay()->setTime(9, 0),
            'is_active' => true,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }
}
