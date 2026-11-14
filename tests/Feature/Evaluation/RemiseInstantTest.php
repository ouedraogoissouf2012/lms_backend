<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\RemetUneCopie;
use Tests\TestCase;

/**
 * La réponse de la remise rend le MÊME instant que la base (revue de Fille 1 sur #1050).
 *
 * Avant : la note s'écrit par un `UPDATE` conditionnel (C39). `Builder::update` pose
 * `updated_at` en base, mais `syncOriginal()` garde en mémoire celui d'avant, et la
 * réponse 201 rendait une date antérieure à celle de la base.
 *
 * Vraie route, vrai jeton (`asTenant`), les deux types d'école.
 */
final class RemiseInstantTest extends TestCase
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
    public function test_la_reponse_de_la_remise_rend_l_instant_ecrit_en_base(InstitutionMode $mode): void
    {
        $depart = Carbon::parse('2026-11-14 08:00:00');
        Carbon::setTestNow($depart);
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $copie = $this->demarrer($eleve, $evaluation);

        Carbon::setTestNow($depart->copy()->addMinutes(5));
        $reponse = $this->remettre($eleve, $evaluation)->assertCreated();

        $enBase = Carbon::parse((string) DB::table('evaluation_submissions')->where('id', $copie)->value('updated_at'));
        $rendu = Carbon::parse((string) $reponse->json('data.submission.updated_at'));
        self::assertTrue($enBase->equalTo($depart->copy()->addMinutes(5)), 'la base porte l\'instant de la remise');
        self::assertTrue($rendu->equalTo($enBase), "la réponse rend {$rendu->toIso8601String()}, la base {$enBase->toIso8601String()}");
        self::assertTrue(
            Carbon::parse((string) $reponse->json('data.submission.submitted_at'))->equalTo($enBase),
            'submitted_at et updated_at sont le même instant',
        );
    }
}
