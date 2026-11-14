<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\RemetUneCopie;
use Tests\TestCase;

/**
 * C39 : deux remises simultanées DANS le délai ne notent la copie qu'une fois.
 *
 * Avant : la remise lisait la copie `en_cours`, la notait, puis l'écrivait par un
 * `save()` sans condition. Deux `/submit` simultanés (double clic, nouvel essai, deux
 * onglets) la notaient deux fois ; la seconde écriture écrasait les réponses, la note
 * et `submitted_at` de la première, et les deux répondaient 201.
 *
 * Désormais, la note est calculée en mémoire, puis TOUT s'écrit en une seule
 * instruction conditionnelle (`… WHERE status = en_cours`) : une seule transition. La
 * perdante relit la copie et rend son état réel, 409 `evaluation_attempt_conflict`,
 * comme la course perdue hors délai (C28, O1).
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class RemiseDoubleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use RemetUneCopie;

    public function test_ecole_du_lms_la_remise_qui_perd_la_course_dans_le_delai_dit_le_conflit_sans_rien_ecraser(): void
    {
        $this->assertUneSeuleTransition(...$this->eleveEtEvaluation(InstitutionMode::Standalone));
    }

    public function test_ecole_klassci_la_remise_qui_perd_la_course_dans_le_delai_dit_le_conflit_sans_rien_ecraser(): void
    {
        $this->assertUneSeuleTransition(...$this->eleveEtEvaluation(InstitutionMode::Klassci));
    }

    /** Témoin : sans course, la remise dans le délai note la copie et répond 201. */
    public function test_sans_course_la_remise_note_la_copie(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation(InstitutionMode::Standalone);
        $copie = $this->demarrer($eleve, $evaluation);

        $this->remettre($eleve, $evaluation)->assertCreated()->assertJsonPath('data.note_sur_20', '20.00');

        $apres = DB::table('evaluation_submissions')->where('id', $copie)->first();
        self::assertSame('soumis', $apres?->status);
        self::assertNotNull($apres?->submitted_at);
        self::assertSame(20.0, (float) $apres?->note_sur_20);
    }

    private function assertUneSeuleTransition(User $eleve, Evaluation $evaluation): void
    {
        $copie = $this->demarrer($eleve, $evaluation);
        $gagnante = ['status' => 'soumis', 'answers' => json_encode(['gagnante' => 'x']), 'score' => '99.00', 'note_sur_20' => '19.80', 'submitted_at' => now()->subSecond()];
        $fait = false;
        DB::beforeExecuting(function (string $requete) use (&$fait, $copie, $gagnante): void {
            if ($fait || preg_match('/^update [`"]evaluation_submissions[`"]/i', $requete) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->where('id', $copie)->update($gagnante);
        });

        $this->remettre($eleve, $evaluation)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'evaluation_attempt_conflict');

        self::assertTrue($fait, 'la remise a tenté une écriture');
        $apres = DB::table('evaluation_submissions')->where('id', $copie)->first();
        self::assertSame('soumis', $apres?->status);
        self::assertSame(['gagnante' => 'x'], json_decode((string) $apres?->answers, true), 'les réponses de la gagnante ont été écrasées');
        self::assertSame(19.8, (float) $apres?->note_sur_20, 'la note de la gagnante a été écrasée');
    }
}
