<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La pagination de la liste des Périodes est validée, jamais ramenée (#911).
 *
 * `?per_page=abc` valait 1 en silence (`Request::integer` puis un `max(…, 1)` en
 * ligne). La règle du dépôt (#548, #924) refuse en 422 une taille hors bornes :
 * la liste des Périodes suit celle des classes locales, que le même écran lit.
 */
final class PaginationDesPeriodesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIODES = '/api/training-sessions';

    /** @return iterable<string, array{0: string}> */
    public static function taillesRefusees(): iterable
    {
        yield 'non numérique' => ['abc'];
        yield 'zéro' => ['0'];
        yield 'au-delà du maximum' => ['101'];
    }

    #[DataProvider('taillesRefusees')]
    public function test_une_taille_hors_bornes_est_refusee(string $taille): void
    {
        $this->withToken($this->jeton($this->responsable()))
            ->getJson(self::PERIODES.'?per_page='.$taille)
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_sans_taille_la_page_en_porte_vingt_cinq(): void
    {
        $this->withToken($this->jeton($this->responsable()))
            ->getJson(self::PERIODES)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_une_taille_valide_est_appliquee(): void
    {
        $responsable = $this->responsable();
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);
        TrainingSession::factory()->count(7)->create([
            'institution_id' => $responsable->institution_id,
            'program_id' => $programme->getKey(),
        ]);

        $reponse = $this->withToken($this->jeton($responsable))
            ->getJson(self::PERIODES.'?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 7);

        self::assertCount(5, (array) $reponse->json('data'));
    }

    private function responsable(): User
    {
        return User::factory()->create([
            // #1184 : les sessions de formation n'existent que dans une école autonome.
            'institution_id' => Institution::factory()->standalone()->create()->getKey(),
            'role' => 'superAdmin',
        ]);
    }

    private function jeton(User $user): string
    {
        return $user->createToken('pagination-911')->plainTextToken;
    }
}
