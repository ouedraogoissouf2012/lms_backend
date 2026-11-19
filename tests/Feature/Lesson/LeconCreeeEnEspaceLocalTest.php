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
 * #873 — `POST /api/lessons` désigne la classe et la matière par leur id LOCAL, et seulement
 * par lui.
 *
 * Avant, la création acceptait aussi le numéro KLASSCI et tranchait « le local d'abord ».
 * Or les deux numérotations se recouvrent : en prod, 7 classes sur 21 et 18 matières ont un id
 * local égal au numéro KLASSCI d'une autre. Un numéro KLASSCI pouvait ranger la leçon dans une
 * AUTRE classe. Le seul écran qui crée une leçon envoie l'id local depuis #749 (la page de la
 * matière : `classes_concernees`, `matiere_id_local`), et la modification était déjà stricte.
 *
 * Contrat : un numéro qui n'est qu'un numéro KLASSCI est refusé, la classe comme toute classe
 * inconnue (403), la matière par sa règle (422).
 *
 * Les deux volets : l'école KLASSCI, et l'école autonome « basculée », dont les entités gardent
 * leur numéro KLASSCI.
 */
final class LeconCreeeEnEspaceLocalTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use SimuleLaMatiereKlassci;

    private const CLASSE_KLASSCI = 7001;

    private const MATIERE_KLASSCI = 9100;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_les_ids_locaux_creent_la_lecon(InstitutionMode $mode): void
    {
        [$moi, $classe, $matiere] = $this->monter($mode);

        $this->creer($moi, $classe->id, $matiere->id)->assertCreated()
            ->assertJsonPath('data.classe_id', $classe->id)
            ->assertJsonPath('data.matiere_id', $matiere->id);
    }

    #[DataProvider('volets')]
    public function test_un_numero_klassci_seul_de_classe_est_refuse(InstitutionMode $mode): void
    {
        [$moi, , $matiere] = $this->monter($mode);

        $this->creer($moi, self::CLASSE_KLASSCI, $matiere->id)->assertForbidden();

        self::assertSame(0, Lesson::query()->withoutGlobalScopes()->count());
    }

    #[DataProvider('volets')]
    public function test_un_numero_klassci_seul_de_matiere_est_refuse(InstitutionMode $mode): void
    {
        [$moi, $classe] = $this->monter($mode);

        $this->creer($moi, $classe->id, self::MATIERE_KLASSCI)->assertUnprocessable()->assertJsonValidationErrors('matiere_id');

        self::assertSame(0, Lesson::query()->withoutGlobalScopes()->count());
    }

    /** Une autre classe porte en numéro KLASSCI l'id local envoyé : l'id désigne la classe locale. */
    #[DataProvider('volets')]
    public function test_en_collision_l_id_designe_la_classe_locale(InstitutionMode $mode): void
    {
        [$moi, $classe, $matiere] = $this->monter($mode);
        $autre = Classe::factory()->create(['institution_id' => $classe->institution_id, 'klassci_id' => $classe->id, 'libelle' => 'Autre']);

        $this->creer($moi, $classe->id, $matiere->id)->assertCreated()->assertJsonPath('data.classe_id', $classe->id);

        self::assertSame(0, Lesson::query()->withoutGlobalScopes()->where('classe_id', $autre->id)->count());
    }

    #[DataProvider('volets')]
    public function test_une_classe_d_une_autre_ecole_est_refusee(InstitutionMode $mode): void
    {
        [$moi, , $matiere] = $this->monter($mode);
        $ailleurs = Classe::factory()->create(['institution_id' => Institution::factory()->create()->id]);

        $this->creer($moi, $ailleurs->id, $matiere->id)->assertForbidden();

        self::assertSame(0, Lesson::query()->withoutGlobalScopes()->count());
    }

    /**
     * L'enseignant, une classe et une matière liées : par le programme (école autonome), ou par
     * le miroir et KLASSCI simulé (école KLASSCI).
     *
     * @return array{0: User, 1: Classe, 2: Matiere}
     */
    private function monter(InstitutionMode $mode): array
    {
        if ($mode === InstitutionMode::Klassci) {
            $this->disableKlassciMiddleware();
            $ecole = $this->ecole(InstitutionMode::Klassci);
            $moi = User::factory()->create([
                'institution_id' => $ecole->id,
                'role' => 'enseignant',
                'klassci_id' => 910001,
                'klassci_token' => 'jeton-klassci',
            ]);
            $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::CLASSE_KLASSCI, 'libelle' => 'BTS 1']);
            $matiere = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::MATIERE_KLASSCI, 'libelle' => 'Maths']);
            $this->matiereKlassciProposant($matiere, $classe);

            return [$moi, $classe, $matiere];
        }

        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $matiere = $this->matiere($ecole, 'Maths');
        // École basculée : ses entités gardent leur numéro KLASSCI.
        $classe->forceFill(['klassci_id' => self::CLASSE_KLASSCI])->save();
        $matiere->forceFill(['klassci_id' => self::MATIERE_KLASSCI])->save();
        $this->programme($classe, $matiere, $moi);

        return [$moi, $classe, $matiere];
    }

    private function creer(User $auteur, int $classeId, int $matiereId): TestResponse
    {
        return $this->asTenant($auteur)->postJson('/api/lessons', [
            'title' => 'La proportionnalité',
            'type' => 'cours',
            'status' => 'draft',
            'classe_id' => $classeId,
            'matiere_id' => $matiereId,
        ]);
    }
}
