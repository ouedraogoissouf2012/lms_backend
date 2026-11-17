<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use App\Services\FileConversion\ChapterArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Un enseignant ne lit que ses leçons (#1167, décision du user du 17/11 : « des
 * comptes bien séparés et distincts »).
 *
 * La porte de lecture d'une leçon et de ses chapitres (`ChapterReadGate`) le dit
 * une fois pour tous ses appelants : la fiche, les chapitres, un chapitre, son
 * document source, et les quiz de chapitre, qui passent par la même porte. Le
 * collègue de la même école reçoit 404, comme toute ressource qu'un compte ne
 * lit pas : un 403 dirait qu'elle existe. La gestion lit tout son établissement ;
 * l'élève, les leçons publiées de SA classe, et désormais les seuls quiz de ces
 * chapitres-là.
 *
 * Les deux types d'école, une leçon en brouillon ET une leçon publiée, vrai jeton.
 */
final class UnEnseignantNeLitQueSesLeconsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const SOURCE = 'le-document-source-du-chapitre';

    private const GESTION = ['coordinateur', 'admin', 'superAdmin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake(ChapterArtifactStorage::PRIVATE_DISK);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function voletsEtEtats(): iterable
    {
        foreach (['école du LMS' => InstitutionMode::Standalone, 'école KLASSCI' => InstitutionMode::Klassci] as $volet => $mode) {
            yield "$volet, brouillon" => [$mode, 'draft'];
            yield "$volet, publiée" => [$mode, 'published'];
        }
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('voletsEtEtats')]
    public function test_un_collegue_de_la_meme_ecole_ne_lit_rien_de_la_lecon(InstitutionMode $mode, string $etat): void
    {
        [$ecole, , $c] = $this->ecoleEtLecon($mode, $etat);
        $collegue = $this->membre($ecole, 'enseignant');

        foreach ($this->lectures($c) as $nom => $uri) {
            $this->assertIntrouvable($this->asTenant($collegue)->getJson($uri), "collègue, $nom");
        }

        $this->assertIntrouvable(
            $this->asTenant($collegue)->postJson("/api/knowledge-checks/{$c['quiz']->id}/start"),
            'collègue, jouer le quiz',
        );
    }

    /** Verrou : l'auteur lit tout, document source compris. */
    #[DataProvider('voletsEtEtats')]
    public function test_l_auteur_lit_toute_sa_lecon(InstitutionMode $mode, string $etat): void
    {
        [, $auteur, $c] = $this->ecoleEtLecon($mode, $etat);

        $this->assertLitTout($auteur, $c, 'auteur');
    }

    /** Verrou : la gestion lit tout son établissement. */
    #[DataProvider('voletsEtEtats')]
    public function test_la_gestion_lit_toute_la_lecon(InstitutionMode $mode, string $etat): void
    {
        [$ecole, , $c] = $this->ecoleEtLecon($mode, $etat);

        foreach (self::GESTION as $role) {
            $this->assertLitTout($this->membre($ecole, $role), $c, $role);
        }
    }

    /** Verrou : l'élève de la classe lit la leçon publiée, ses chapitres et son quiz. */
    #[DataProvider('volets')]
    public function test_l_eleve_de_la_classe_lit_la_lecon_publiee(InstitutionMode $mode): void
    {
        [$ecole, , $c] = $this->ecoleEtLecon($mode, 'published');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $c['classe'], 'actif');

        foreach ($this->lectures($c) as $nom => $uri) {
            $reponse = $this->asTenant($eleve)->getJson($uri);
            self::assertSame(200, $reponse->getStatusCode(), "élève de la classe, $nom : {$reponse->getContent()}");
        }
    }

    /**
     * L'élève d'une autre classe : 404 sur les quiz (nouveau, la même porte que
     * leur chapitre) comme sur la leçon et ses chapitres (témoin, inchangé).
     */
    #[DataProvider('volets')]
    public function test_l_eleve_d_une_autre_classe_ne_lit_ni_la_lecon_ni_ses_quiz(InstitutionMode $mode): void
    {
        [$ecole, , $c] = $this->ecoleEtLecon($mode, 'published');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe($ecole, '5e B'), 'actif');

        foreach ($this->lectures($c) as $nom => $uri) {
            $this->assertIntrouvable($this->asTenant($eleve)->getJson($uri), "élève d'une autre classe, $nom");
        }
    }

    /**
     * Une école, l'enseignant auteur, et sa leçon dans l'état demandé : un chapitre
     * avec son document source, et le quiz actif de ce chapitre.
     *
     * @return array{0: Institution, 1: User, 2: array{classe: Classe, lecon: Lesson, chapitre: Chapter, quiz: KnowledgeCheck}}
     */
    private function ecoleEtLecon(InstitutionMode $mode, string $etat): array
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $lecon = Lesson::factory()->{$etat}()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $auteur->id,
        ]);
        $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $ecole->id,
            'enseignant_id' => $auteur->id,
            'allow_download' => true,
        ]);
        $source = "chapters/{$chapitre->id}/original/source.docx";
        Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)->put($source, self::SOURCE);
        $chapitre->update(['file_original_path' => $source]);

        return [$ecole, $auteur, [
            'classe' => $classe,
            'lecon' => $lecon,
            'chapitre' => $chapitre->refresh(),
            'quiz' => KnowledgeCheck::factory()->create([
                'chapter_id' => $chapitre->id,
                'institution_id' => $ecole->id,
                'is_active' => true,
            ]),
        ]];
    }

    /**
     * Les lectures de la leçon : la fiche, ses chapitres, un chapitre, son document
     * source, et les trois lectures du quiz de chapitre.
     *
     * @param  array{classe: Classe, lecon: Lesson, chapitre: Chapter, quiz: KnowledgeCheck}  $c
     * @return array<string, string>
     */
    private function lectures(array $c): array
    {
        [$lecon, $chapitre, $quiz] = [$c['lecon']->id, $c['chapitre']->id, $c['quiz']->id];

        return [
            'la fiche' => "/api/lessons/$lecon",
            'les chapitres' => "/api/lessons/$lecon/chapters",
            'un chapitre' => "/api/chapters/$chapitre",
            'le document source' => "/api/chapters/$chapitre/original",
            'les quiz du chapitre' => "/api/knowledge-checks?chapter_id=$chapitre",
            'le quiz actif du chapitre' => "/api/knowledge-checks/chapter/$chapitre",
            'un quiz' => "/api/knowledge-checks/$quiz",
        ];
    }

    /** @param  array{classe: Classe, lecon: Lesson, chapitre: Chapter, quiz: KnowledgeCheck}  $c */
    private function assertLitTout(User $compte, array $c, string $cas): void
    {
        foreach ($this->lectures($c) as $nom => $uri) {
            $reponse = $this->asTenant($compte)->getJson($uri);
            self::assertSame(200, $reponse->getStatusCode(), "$cas, $nom : {$reponse->getContent()}");
        }

        self::assertSame(self::SOURCE, $this->asTenant($compte)->get("/api/chapters/{$c['chapitre']->id}/original")->streamedContent(), $cas);
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertIntrouvable(TestResponse $reponse, string $cas): void
    {
        self::assertSame(404, $reponse->getStatusCode(), "$cas : {$reponse->getContent()}");
    }
}
