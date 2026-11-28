<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1681, relevé à la fusion de lms (#1784, 28/11) : `GET /notifications` filtre désormais par `type` sur une liste
 * BLANCHE (`Notification::TYPES`). La notification d'un justificatif tranché (`absence_justification_decided`) doit y
 * figurer, sinon l'élève ne pourrait pas filtrer ses décisions (422).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class FiltreDesDecisionsDeJustificatifTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_l_eleve_filtre_les_decisions_sur_ses_justificatifs(): void
    {
        $this->disableKlassciMiddleware();
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $this->notifier($eleve, Notification::TYPE_ABSENCE_JUSTIFICATION_DECIDED, 'Justificatif accepté');
        $this->notifier($eleve, Notification::TYPE_ANNONCE, 'Annonce');

        $reponse = $this->asTenant($eleve)->getJson('/api/notifications?type=absence_justification_decided')->assertOk();

        self::assertSame(['Justificatif accepté'], array_column($reponse->json('data'), 'title'));
        $this->assertMatchesOpenApiResponse($reponse, '/notifications', 'get');
        self::assertContains(Notification::TYPE_ABSENCE_JUSTIFICATION_DECIDED, Notification::TYPES);
    }

    private function notifier(User $destinataire, string $type, string $titre): void
    {
        Notification::query()->withoutGlobalScopes()->create([
            'user_id' => $destinataire->getKey(),
            'institution_id' => $destinataire->institution_id,
            'type' => $type,
            'title' => $titre,
            'message' => 'Texte.',
            'data' => [],
        ]);
    }
}
