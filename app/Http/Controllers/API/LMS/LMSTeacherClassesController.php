<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\TeacherClassesReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/lms/teacher/classes — les classes de l'enseignant (#712).
 *
 * La source est choisie au point de liaison selon le type d'école (ADR-EI-01).
 * Elle est reçue par la MÉTHODE : Laravel garde le contrôleur sur l'objet Route,
 * et une source reçue par le constructeur resterait celle de la première école
 * servie.
 */
final class LMSTeacherClassesController extends AuthenticatedController
{
    public function index(Request $request, TeacherClassesReader $classes): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'success' => true,
            'data' => $classes->listFor($user),
        ]);
    }
}
