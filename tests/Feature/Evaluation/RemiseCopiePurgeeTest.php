<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\RemetUneCopie;
use Tests\TestCase;

/**
 * Une copie disparue pendant la remise n'est pas « verrouillée » (revue de Fille 1 sur #1050, O1).
 *
 * Avant : quand l'`UPDATE` conditionnel perdait, `etatReel()` relisait le statut ; une copie
 * purgée entre-temps (`CleanOldEvaluations`) rendait `null`, lu comme VERROUILLEE. L'élève
 * recevait « le temps est écoulé, votre copie est gardée » pour une copie qui n'existait
 * plus. Désormais : l'état existant « aucune tentative en cours » (409, démarrez-la).
 *
 * Vraie route, vrai jeton (`asTenant`), les deux types d'école.
 */
final class RemiseCopiePurgeeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use RemetUneCopie;

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    #[DataProvider('ecoles')]
    public function test_une_copie_purgee_pendant_la_remise_dit_qu_aucune_tentative_n_est_en_cours(InstitutionMode $mode): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $copie = $this->demarrer($eleve, $evaluation);
        $fait = false;
        DB::beforeExecuting(function (string $requete) use (&$fait, $copie): void {
            if ($fait || preg_match('/^update [`"]evaluation_submissions[`"]/i', $requete) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->where('id', $copie)->delete();
        });

        $reponse = $this->remettre($eleve, $evaluation)->assertStatus(409);

        self::assertTrue($fait, 'la remise a tenté une écriture');
        self::assertNotSame('evaluation_attempt_locked', $reponse->json('reason'), 'une copie disparue passe pour verrouillée');
        self::assertNotSame('evaluation_attempt_conflict', $reponse->json('reason'));
        $reponse->assertJsonPath('message', 'Aucune tentative en cours pour cette évaluation. Démarrez-la avant de la rendre.');
        self::assertSame(0, DB::table('evaluation_submissions')->where('id', $copie)->count(), 'aucune copie recréée');
    }
}
