<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Institution;
use App\Services\Audit\AuditLogger;
use App\Services\Suivi\Decrochage\ReglagesDuDecrochage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * #1704 : `GET|PUT /api/lms/suivi/decrochage/reglages`, les seuils de l'alerte de décrochage de
 * l'école de l'appelant, réservés à son administration (`role:admin,superAdmin`).
 *
 * Le PUT remplace les trois seuils, bornés par {@see ReglagesDuDecrochage::regles()}, sous
 * `settings.decrochage` seulement : les autres réglages de l'école restent intacts. Écriture sous
 * verrou de la ligne de l'école, journalisée (`decrochage_settings_updated`, avant et après).
 */
final class ReglagesDuDecrochageController extends AuthenticatedController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(ReglagesDuDecrochage::deLEcole($this->ecoleDe($request)?->id)->enTableau());
    }

    public function update(Request $request): JsonResponse
    {
        $saisie = $request->validate(ReglagesDuDecrochage::regles());
        $ecole = $this->ecoleDe($request);
        if (! $ecole instanceof Institution) {
            return $this->errorResponse('Établissement introuvable.', 404);
        }

        $seuils = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_intersect_key($saisie, ReglagesDuDecrochage::SEUILS));
        $avant = DB::transaction(static function () use ($ecole, $seuils): array {
            $verrouillee = Institution::query()->whereKey($ecole->id)->lockForUpdate()->firstOrFail();
            $reglages = is_array($verrouillee->settings) ? $verrouillee->settings : [];
            $avant = ReglagesDuDecrochage::depuis($reglages[ReglagesDuDecrochage::CLE] ?? null)->enTableau();
            $reglages[ReglagesDuDecrochage::CLE] = $seuils;
            $verrouillee->forceFill(['settings' => $reglages])->save();

            return $avant;
        });
        $apres = ReglagesDuDecrochage::depuis($seuils)->enTableau();
        $this->audit->logSecurityEvent('decrochage_settings_updated', $ecole, ['avant' => $avant, 'apres' => $apres]);

        return $this->successResponse($apres, 'Seuils enregistrés.');
    }

    private function ecoleDe(Request $request): ?Institution
    {
        $id = $this->authenticatedUser($request)->institution_id;

        return is_numeric($id) ? Institution::query()->find((int) $id) : null;
    }
}
