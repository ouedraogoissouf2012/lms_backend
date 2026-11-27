<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Annonce;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\PublierUneAnnonceRequest;
use App\Services\Annonce\AnnonceAUneClasse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/annonces` (#1650) : le personnel annonce à toute une classe.
 *
 * - une classe absente, supprimée ou d'une autre école : 404, du même corps dans tous les cas (isolation) ;
 * - un enseignant hors de ses classes : 403 ;
 * - sinon 201, avec le nombre d'élèves prévenus et si un courriel part.
 */
final class AnnonceController extends AuthenticatedController
{
    public function __construct(private readonly AnnonceAUneClasse $annonces) {}

    public function store(PublierUneAnnonceRequest $request): JsonResponse
    {
        $acteur = $this->authenticatedUser($request);
        $classe = $this->annonces->classeDe($acteur, $request->integer('classe_id'));

        if ($classe === null) {
            return $this->errorResponse('Classe introuvable.', 404);
        }

        if (! $this->annonces->autorise($acteur, $classe)) {
            return $this->errorResponse('Vous n\'annoncez qu\'à vos classes.', 403);
        }

        ['annonce' => $annonce, 'courriel' => $courriel] = $this->annonces->publier(
            $acteur,
            $classe,
            trim($request->string('titre')->toString()),
            trim($request->string('message')->toString()),
        );

        return $this->successResponse([
            'id' => $annonce->id,
            'classe_id' => $annonce->classe_id,
            'titre' => $annonce->titre,
            'destinataires' => $annonce->destinataires,
            'courriel' => $courriel,
        ], 'Annonce envoyée', 201);
    }
}
