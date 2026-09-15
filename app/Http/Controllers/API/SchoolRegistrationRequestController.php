<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRegistrationRequest;
use App\Services\SchoolRegistration\SchoolRegistrationRequestService;
use Illuminate\Http\JsonResponse;

/**
 * Porte d'entrée publique du monde autonome (#803, ADR-803-01).
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
final class SchoolRegistrationRequestController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly SchoolRegistrationRequestService $demandes,
    ) {}

    /**
     * La réponse ne rend NI l'identifiant NI le statut de la demande.
     *
     * L'endpoint est anonyme : rendre un id offrirait de quoi énumérer les
     * dossiers, et rendre le statut confirmerait qu'une adresse a déjà déposé.
     *
     * Elle ne promet pas non plus de courriel. Le produit n'a aucun canal
     * d'envoi — `config/mail.php:17` vaut `log` et `app/` ne contient ni
     * `Mail::` ni `->notify()`. Annoncer un message qui n'arrivera jamais est
     * exactement le défaut tracé en #808 ; on ne le reproduit pas ici.
     */
    public function store(StoreSchoolRegistrationRequest $request): JsonResponse
    {
        $this->demandes->deposer($request->validated());

        return $this->successResponse(
            null,
            'Votre demande est enregistrée. Elle sera examinée par notre équipe.',
            201,
        );
    }
}
