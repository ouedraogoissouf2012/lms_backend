<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\RemetUneCopie;
use Tests\TestCase;

/**
 * B3 (#1050) : les transitions conditionnelles de la remise laissent une trace
 * dans le journal d'audit.
 *
 * Avant : la notation (C39) et le verrou (C28) s'écrivaient par un `UPDATE …
 * WHERE status = en_cours`, qui ne passe pas par le modèle : l'observateur
 * d'audit ne les voyait pas. La remise d'une copie notée, et le refus d'une
 * remise hors délai, n'existaient pas dans `audit_logs`.
 *
 * Après : une transition GAGNÉE trace l'avant et l'après de ce qu'elle a écrit,
 * par la même forme que l'observateur (`AuditLogger::logModelUpdate`). Une
 * transition perdue, ou un refus sans écriture, ne trace rien.
 *
 * Vraie route, vrai jeton, les deux types d'école.
 */
final class RemiseTraceeDansLAuditTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use RemetUneCopie;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function modes(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('modes')]
    public function test_la_remise_dans_le_delai_trace_la_notation(InstitutionMode $mode): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $copie = $this->demarrer($eleve, $evaluation);

        $this->remettre($eleve, $evaluation)->assertCreated();

        $traces = $this->traces($copie, 'soumis');
        self::assertCount(1, $traces);
        $trace = $traces[0];
        self::assertSame((int) $eleve->getKey(), (int) $trace->user_id, 'l\'auteur est l\'élève');
        self::assertSame('en_cours', $trace->before['status'] ?? null);
        self::assertNull($trace->before['submitted_at'] ?? null);
        self::assertSame('20.00', $trace->after['note_sur_20'] ?? null);
        self::assertNotNull($trace->after['submitted_at'] ?? null);
        self::assertArrayHasKey('answers', $trace->after ?? []);
    }

    #[DataProvider('modes')]
    public function test_la_remise_hors_delai_trace_le_verrou_une_seule_fois(InstitutionMode $mode): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addHours(2));

        $this->remettre($eleve, $evaluation)->assertStatus(409)->assertJsonPath('reason', 'evaluation_attempt_locked');
        // Une seconde remise sur la copie verrouillée n'écrit rien : rien à tracer.
        $this->remettre($eleve, $evaluation)->assertStatus(409);

        $traces = $this->traces($copie, 'verrouille');
        self::assertCount(1, $traces);
        self::assertSame('en_cours', $traces[0]->before['status'] ?? null);
        self::assertSame([$this->questionDe($evaluation) => 'a'], $traces[0]->after['answers'] ?? null);
        self::assertArrayNotHasKey('note_sur_20', $traces[0]->after ?? [], 'un verrou ne note pas');
    }

    public function test_la_remise_qui_perd_la_course_ne_trace_rien(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation(InstitutionMode::Standalone);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->gagnanteConcurrente($copie);

        $this->remettre($eleve, $evaluation)->assertStatus(409)->assertJsonPath('reason', 'evaluation_attempt_conflict');

        self::assertCount(0, $this->traces($copie, 'soumis'), 'la perdante n\'a rien écrit : elle ne trace rien');
    }

    public function test_une_remise_sans_tentative_ouverte_ne_trace_rien(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation(InstitutionMode::Standalone);
        $avant = AuditLog::query()->withoutGlobalScopes()->where('auditable_type', (new EvaluationSubmission)->getMorphClass())->count();

        $this->remettre($eleve, $evaluation)->assertStatus(409);

        self::assertSame($avant, AuditLog::query()->withoutGlobalScopes()->where('auditable_type', (new EvaluationSubmission)->getMorphClass())->count());
    }

    /** @return list<AuditLog> les traces `update` de la copie dont l'après porte ce statut */
    private function traces(int $copie, string $statut): array
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'update')
            ->where('auditable_type', (new EvaluationSubmission)->getMorphClass())
            ->where('auditable_id', $copie)
            ->get()
            ->filter(static fn (AuditLog $log): bool => ($log->after['status'] ?? null) === $statut)
            ->values()
            ->all();
    }

    /** Une remise concurrente gagne juste avant l'écriture conditionnelle, sans passer par le modèle. */
    private function gagnanteConcurrente(int $copie): void
    {
        $fait = false;
        DB::beforeExecuting(function (string $requete) use (&$fait, $copie): void {
            if ($fait || preg_match('/^update [`"]evaluation_submissions[`"]/i', $requete) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->where('id', $copie)->update([
                'status' => 'soumis', 'answers' => json_encode(['gagnante' => 'x']), 'submitted_at' => now()->subSecond(),
            ]);
        });
    }

    private function questionDe(Evaluation $evaluation): string
    {
        return (string) $evaluation->questions()->firstOrFail()->getKey();
    }
}
