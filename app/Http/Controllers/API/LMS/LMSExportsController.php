<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\ExporterUneListeRequest;
use App\Services\Export\ExportsDeClasses;
use App\Services\Export\RenduDeTableau;
use App\Services\Export\Tableau;
use Closure;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exporter la liste des élèves d'une classe, et la liste des classes (#1656), en CSV ou en PDF. Aucune lecture du
 * mode ici : la liste vient du lecteur de la route JSON sœur, choisi par la capacité. Un refus de ce lecteur
 * (404 hors portée, 401 sans jeton KLASSCI) est rendu tel quel, en JSON ; une panne de KLASSCI, comme ailleurs.
 */
final class LMSExportsController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;
    use RespondsWithJson;

    public function __construct(
        private readonly ExportsDeClasses $exports,
        private readonly RenduDeTableau $rendu,
    ) {}

    public function eleves(ExporterUneListeRequest $request, int $classeId): Response
    {
        $lecteur = $this->authenticatedUser($request);

        return $this->exporter($request, fn (): Tableau|array => $this->exports->eleves($lecteur, $classeId));
    }

    public function classes(ExporterUneListeRequest $request): Response
    {
        $lecteur = $this->authenticatedUser($request);

        return $this->exporter($request, fn (): Tableau => $this->exports->classes($lecteur));
    }

    /** @param Closure(): (Tableau|array{status: int, payload: array<string, mixed>}) $lecture */
    private function exporter(ExporterUneListeRequest $request, Closure $lecture): Response
    {
        try {
            $resultat = $lecture();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        }

        if (! $resultat instanceof Tableau) {
            return $this->relayResponse($resultat);
        }

        return $this->rendu->rendre($resultat, $request->formatDExport());
    }
}
