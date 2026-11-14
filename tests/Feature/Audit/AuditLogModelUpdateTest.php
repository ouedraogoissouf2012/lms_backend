<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3 : `AuditLogger::logModelUpdate` est la SEULE source de la forme d'une trace
 * de mise à jour (l'avant et l'après des attributs modifiés, hors `hidden`),
 * pour l'observateur comme pour une écriture conditionnelle.
 */
final class AuditLogModelUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_avant_et_l_apres_portent_les_attributs_modifies_sans_les_secrets(): void
    {
        $compte = User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'name' => 'Ancien nom']);
        $compte->name = 'Nouveau nom';
        $compte->password = 'un-secret';

        app(AuditLogger::class)->logModelUpdate($compte, array_keys($compte->getDirty()));

        $trace = $this->derniereTrace($compte);
        self::assertNotNull($trace);
        self::assertSame(['name' => 'Ancien nom'], $trace->before);
        self::assertSame(['name' => 'Nouveau nom'], $trace->after);
    }

    public function test_seul_un_secret_modifie_ne_trace_rien(): void
    {
        $compte = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);
        $compte->password = 'un-secret';

        app(AuditLogger::class)->logModelUpdate($compte, array_keys($compte->getDirty()));

        self::assertNull($this->derniereTrace($compte));
    }

    /**
     * D515 : la trace dit ce qui a été ÉCRIT. Une copie échue, STOCKÉE `en_cours`, se
     * LIT `verrouille` (`EcheanceDeLaCopie::status()`, Q4) ; son verrou par `save()`
     * se trace `en_cours` → `verrouille`, jamais `verrouille` → `verrouille`.
     */
    public function test_une_colonne_a_accesseur_de_lecture_se_trace_par_sa_valeur_stockee(): void
    {
        $copie = EvaluationSubmission::factory()->create([
            'status' => 'en_cours', 'submitted_at' => null, 'score' => null, 'note_sur_20' => null,
        ]);
        $copie->forceFill(['ends_at' => now()->subHour()])->save();
        $copie = EvaluationSubmission::query()->withoutGlobalScopes()->findOrFail($copie->getKey());
        self::assertSame('verrouille', $copie->status, 'la lecture déduit le verrou');

        $copie->status = 'verrouille';
        $copie->save();

        $trace = $this->derniereTrace($copie);
        self::assertNotNull($trace);
        self::assertSame('en_cours', $trace->before['status'] ?? null);
        self::assertSame('verrouille', $trace->after['status'] ?? null);
    }

    private function derniereTrace(Model $modele): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'update')
            ->where('auditable_type', $modele->getMorphClass())
            ->where('auditable_id', $modele->getKey())
            ->latest('id')
            ->first();
    }
}
