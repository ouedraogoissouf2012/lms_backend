<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\SimuleLaMatiereKlassci;
use Tests\TestCase;

/**
 * La classe d'une leçon est une classe de sa matière (#1103).
 *
 * Le back accepte exactement les classes qu'il propose sur la page de la matière
 * (`classes_concernees`) : une autre classe rend 422 sur `classe_id`, motif
 * `classe_hors_matiere`, et la leçon n'est jamais rangée ailleurs. L'édition suit
 * la règle de la création.
 *
 * Les deux volets : l'école autonome (le programme `classe_matiere`, le formateur
 * désigné) et l'école KLASSCI (le calcul de la page matière, KLASSCI simulé).
 */
final class ClasseDeLaLeconTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use SimuleLaMatiereKlassci;

    private const MATIERE_KLASSCI = 9100;

    /** Le motif du refus, que le front lit dans son catalogue. */
    private const MOTIF = 'classe_hors_matiere';

    // ───────────────────────── école autonome

    public function test_ecole_autonome_une_classe_de_la_matiere_est_acceptee(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        $this->creer($moi, $classe->id, $maths->id)->assertCreated()->assertJsonPath('data.classe_id', $classe->id);
    }

    public function test_ecole_autonome_une_classe_hors_de_la_matiere_est_refusee(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $horsMatiere = $this->classe($ecole, '6e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        $this->creer($moi, $horsMatiere->id, $maths->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame(0, Lesson::query()->count());
    }

    public function test_ecole_autonome_une_classe_ou_l_enseignant_n_enseigne_pas_la_matiere_est_refusee(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        $sienne = $this->classe($ecole, '6e A');
        $celleDuCollegue = $this->classe($ecole, '6e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sienne, $maths, $moi);
        $this->programme($celleDuCollegue, $maths, $collegue);

        $this->creer($moi, $celleDuCollegue->id, $maths->id)->assertUnprocessable()->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame(0, Lesson::query()->count());
    }

    public function test_ecole_autonome_la_gestion_choisit_toute_classe_de_la_matiere(): void
    {
        $ecole = $this->ecole();
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $formateur = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $formateur);

        $this->creer($coordinateur, $classe->id, $maths->id)->assertCreated();
    }

    // ───────────────────────── école KLASSCI

    /**
     * Le parcours de l'écran : chaque classe que la page propose est acceptée,
     * et c'est bien celle-là qui est rangée.
     */
    public function test_ecole_klassci_chaque_classe_proposee_par_la_page_est_acceptee(): void
    {
        [$ecole, $moi, $maths] = $this->ecoleKlassci();
        $premiere = $this->classeKlassci($ecole, 'BTS 1', 7001);
        $seconde = $this->classeKlassci($ecole, 'BTS 2', 7002);
        $this->miroirLieALaMatiere($maths, $premiere, $seconde);

        $page = $this->asTenant($moi)->getJson('/api/lms/matieres/'.self::MATIERE_KLASSCI)->assertOk()->json('data');
        self::assertIsArray($page);
        $proposees = array_column($page['classes_concernees'], 'id');
        self::assertEqualsCanonicalizing([$premiere->id, $seconde->id], $proposees);

        foreach ($proposees as $classeId) {
            $this->creer($moi, $classeId, $page['matiere_id_local'])->assertCreated()->assertJsonPath('data.classe_id', $classeId);
        }
    }

    public function test_ecole_klassci_une_classe_hors_de_la_matiere_est_refusee(): void
    {
        [$ecole, $moi, $maths] = $this->ecoleKlassci();
        $classe = $this->classeKlassci($ecole, 'BTS 1', 7001);
        $horsMatiere = $this->classeKlassci($ecole, 'Licence 1', 7002);
        $this->miroirLieALaMatiere($maths, $classe);

        $this->creer($moi, $horsMatiere->id, $maths->id)->assertUnprocessable()->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame(0, Lesson::query()->count());
    }

    /**
     * Un entier qui désigne, dans l'espace local, une classe hors de la matière
     * (et, dans l'espace KLASSCI, une classe de la matière) : la résolution retient
     * la classe locale, que la règle refuse. La leçon n'est jamais rangée ailleurs.
     */
    public function test_ecole_klassci_un_identifiant_qui_designe_une_classe_hors_de_la_matiere_est_refuse(): void
    {
        [$ecole, $moi, $maths] = $this->ecoleKlassci();
        $horsMatiere = $this->classeKlassci($ecole, 'Licence 1', 7002);
        $classe = $this->classeKlassci($ecole, 'BTS 1', (int) $horsMatiere->id);
        $this->miroirLieALaMatiere($maths, $classe);

        $this->creer($moi, (int) $horsMatiere->id, $maths->id)->assertUnprocessable()->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame(0, Lesson::query()->where('classe_id', $horsMatiere->id)->count());
    }

    // ───────────────────────── l'édition suit la règle de la création

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_l_edition_vers_une_classe_de_la_matiere_est_acceptee(InstitutionMode $mode): void
    {
        [$ecole, $moi, $maths, $classe] = $this->ecoleDuVolet($mode);
        $autre = $this->classeDuVolet($ecole, $mode, 'Seconde classe', 7003);
        $this->lierDansLeVolet($mode, $autre, $maths, $moi);
        $lecon = $this->lecon($classe, $maths, $moi, 'published');

        $this->modifier($moi, $lecon, ['classe_id' => $autre->id])->assertOk();
        self::assertSame($autre->id, $lecon->fresh()?->classe_id);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: bool}> */
    public static function classesHorsDeLaMatiere(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "$volet, classe hors de la matière" => [$mode, false];
            yield "$volet, classe inconnue de la matière" => [$mode, true];
        }
    }

    #[DataProvider('classesHorsDeLaMatiere')]
    public function test_l_edition_vers_une_classe_hors_de_la_matiere_est_refusee(InstitutionMode $mode, bool $inconnue): void
    {
        [$ecole, $moi, $maths, $classe] = $this->ecoleDuVolet($mode);
        $cible = $inconnue
            ? Classe::factory()->create(['institution_id' => Institution::factory()->create(['mode' => $mode])->id, 'klassci_id' => null])
            : $this->classeDuVolet($ecole, $mode, 'Hors matière', 7004);
        $lecon = $this->lecon($classe, $maths, $moi, 'published');

        $this->modifier($moi, $lecon, ['classe_id' => $cible->id])->assertUnprocessable()->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame($classe->id, $lecon->fresh()?->classe_id);
    }

    #[DataProvider('volets')]
    public function test_l_edition_vers_une_matiere_qui_n_a_pas_la_classe_est_refusee(InstitutionMode $mode): void
    {
        [$ecole, $moi, $maths, $classe] = $this->ecoleDuVolet($mode);
        $autreMatiere = Matiere::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $mode === InstitutionMode::Klassci ? self::MATIERE_KLASSCI + 1 : null,
            'libelle' => 'Physique',
        ]);
        $lecon = $this->lecon($classe, $maths, $moi, 'published');

        $this->modifier($moi, $lecon, ['matiere_id' => $autreMatiere->id])->assertUnprocessable()->assertJsonValidationErrors('classe_id')->assertJsonPath('reason', self::MOTIF);
        self::assertSame($maths->id, $lecon->fresh()?->matiere_id);
    }

    // ───────────────────────── témoin

    /** Sans matière, la création garde sa règle : une classe de l'établissement. */
    public function test_sans_matiere_la_creation_garde_sa_regle(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');

        $this->creer($moi, $classe->id, null)->assertCreated();
    }

    // ───────────────────────── fixtures

    private function creer(User $auteur, int $classeId, ?int $matiereId): TestResponse
    {
        return $this->asTenant($auteur)->postJson('/api/lessons', array_filter([
            'title' => 'La proportionnalité',
            'type' => 'cours',
            'status' => 'draft',
            'classe_id' => $classeId,
            'matiere_id' => $matiereId,
        ], static fn (mixed $valeur): bool => $valeur !== null));
    }

    /** @param  array<string, int>  $champs */
    private function modifier(User $auteur, Lesson $lecon, array $champs): TestResponse
    {
        return $this->asTenant($auteur)->patchJson("/api/lessons/{$lecon->id}", $champs);
    }

    /** @return array{0: Institution, 1: User, 2: Matiere} */
    private function ecoleKlassci(): array
    {
        $this->disableKlassciMiddleware();
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $moi = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'enseignant',
            'klassci_id' => 910001,
            'klassci_token' => 'jeton-klassci',
        ]);
        $maths = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::MATIERE_KLASSCI, 'libelle' => 'Maths']);
        // KLASSCI simulé : la matière, sans séance ; les classes de la page viennent du miroir.
        $this->klassciConnaitLaMatiere(self::MATIERE_KLASSCI);

        return [$ecole, $moi, $maths];
    }

    /** @return array{0: Institution, 1: User, 2: Matiere, 3: Classe} */
    private function ecoleDuVolet(InstitutionMode $mode): array
    {
        if ($mode === InstitutionMode::Klassci) {
            [$ecole, $moi, $maths] = $this->ecoleKlassci();
        } else {
            $ecole = $this->ecole();
            $moi = $this->membre($ecole, 'enseignant');
            $maths = $this->matiere($ecole, 'Maths');
        }
        $classe = $this->classeDuVolet($ecole, $mode, 'Première classe', 7001);
        $this->lierDansLeVolet($mode, $classe, $maths, $moi);

        return [$ecole, $moi, $maths, $classe];
    }

    /** La classe devient une classe de la matière : le programme local, ou le miroir KLASSCI. */
    private function lierDansLeVolet(InstitutionMode $mode, Classe $classe, Matiere $matiere, User $formateur): void
    {
        if ($mode === InstitutionMode::Klassci) {
            $this->miroirLieALaMatiere($matiere, $classe);
        } else {
            $this->programme($classe, $matiere, $formateur);
        }
    }

    private function classeDuVolet(Institution $ecole, InstitutionMode $mode, string $libelle, int $klassciId): Classe
    {
        return $mode === InstitutionMode::Klassci ? $this->classeKlassci($ecole, $libelle, $klassciId) : $this->classe($ecole, $libelle);
    }

    private function classeKlassci(Institution $ecole, string $libelle, int $klassciId): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId, 'libelle' => $libelle]);
    }
}
